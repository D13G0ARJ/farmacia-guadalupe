<?php

declare(strict_types=1);

namespace App\Livewire\Imports;

use App\Actions\Imports\ConfirmImport;
use App\Actions\Imports\ImportWorkbook;
use App\Domain\Imports\Exceptions\ImportException;
use App\Domain\Imports\ParsedMonth;
use App\Domain\Imports\ParsedRow;
use App\Domain\Indicators\DailyMetrics;
use App\Domain\Indicators\DailyRecordData;
use App\Domain\Indicators\IndicatorCalculator;
use App\Domain\Shared\Formatter;
use App\Enums\AnomalySeverity;
use App\Enums\ImportStatus;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Support\CurrentBranch;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * UC-15: asistente de importación en tres pasos (Archivos → Revisión → Confirmación, §10.4).
 * Los archivos se leen al subirlos (sin cola): son pequeños y así el hosting no necesita un worker.
 */
#[Layout('layouts.app')]
#[Title('Importar')]
class ImportWizard extends Component
{
    use WithFileUploads;

    public const MAX_FILES = 24;

    public int $step = 1;

    public ?int $branchId = null;

    /** @var list<TemporaryUploadedFile> */
    public array $files = [];

    #[Locked]
    public string $groupId = '';

    /** @var list<int> */
    #[Locked]
    public array $batchIds = [];

    /** @var array<int, array<string, string>> decisión por lote y anomalía */
    public array $decisions = [];

    /** @var array<int, bool> lotes que el usuario decide no importar */
    public array $skip = [];

    public bool $closeAfter = false;

    public ?int $expanded = null;

    /** @var array<int, array<string, mixed>> */
    public array $results = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can(Permission::ImportsRun->value), 403);
        $this->branchId = app(CurrentBranch::class)->resolve(auth()->user())?->id;
        $this->groupId = (string) Str::uuid();
    }

    public function analyze(ImportWorkbook $action): void
    {
        abort_unless(auth()->user()->can(Permission::ImportsRun->value), 403);
        $this->validate([
            'branchId' => ['required', 'integer', 'exists:branches,id'],
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'extensions:xlsx', 'mimes:xlsx', 'max:5120'],
        ], [
            'branchId.required' => 'Elige la sede a la que pertenecen los archivos.',
            'files.required' => 'Elige al menos un archivo .xlsx.',
            'files.max' => 'Máximo '.self::MAX_FILES.' archivos por lote.',
            'files.*.extensions' => 'Solo se aceptan archivos .xlsx (sin macros).',
            'files.*.mimes' => 'Solo se aceptan archivos .xlsx (sin macros).',
            'files.*.max' => 'Cada archivo debe pesar 5 MB o menos.',
        ]);

        $branch = Branch::query()->findOrFail($this->branchId);
        abort_unless(auth()->user()->accessibleBranches()->contains('id', $branch->id), 403);

        $this->batchIds = [];
        $this->decisions = [];
        $this->skip = [];
        foreach ($this->files as $file) {
            // El nombre temporal de Livewire lleva metadatos codificados que el lector de Excel no abre: se lee desde una copia limpia.
            $clean = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
            copy($file->getRealPath(), $clean);
            try {
                $batch = $action->handle($clean, $file->getClientOriginalName(), $branch, auth()->user(), $this->groupId);
            } finally {
                @unlink($clean);
            }
            $this->batchIds[] = $batch->id;
            $this->decisions[$batch->id] = [];
            if ($batch->status === ImportStatus::Parsed && $batch->parsed_payload !== null) {
                foreach (ParsedMonth::fromArray($batch->parsed_payload)->anomalies as $anomaly) {
                    // Las altas se dejan sin decisión a propósito: la persona debe elegir.
                    if ($anomaly->severity() !== AnomalySeverity::High && $anomaly->defaultDecision() !== null) {
                        $this->decisions[$batch->id][$anomaly->id()] = (string) $anomaly->defaultDecision();
                    }
                }
            }
            $file->delete();
        }

        $this->files = [];
        $this->expanded = count($this->batchIds) === 1 ? $this->batchIds[0] : null;
        $this->step = 2;
    }

    public function toggle(int $batchId): void
    {
        $this->expanded = $this->expanded === $batchId ? null : $batchId;
    }

    public function confirm(ConfirmImport $action): void
    {
        abort_unless(auth()->user()->can(Permission::ImportsRun->value), 403);
        $this->resetErrorBag();
        $results = [];

        foreach ($this->batches() as $batch) {
            if (($this->skip[$batch->id] ?? false) || $batch->status !== ImportStatus::Parsed) {
                continue;
            }
            try {
                $results[$batch->id] = $action->handle($batch, $this->decisions[$batch->id] ?? [], auth()->user(), $this->closeAfter);
            } catch (ImportException $e) {
                $this->addError('batch.'.$batch->id, $e->getMessage());
                $this->expanded = $batch->id;
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $this->results = $results;
        $this->step = 3;
        $imported = count(array_filter($results, fn (array $r) => ! $r['rejected']));
        $this->dispatch('toast', type: 'success', message: $imported === 1 ? 'Mes importado.' : "{$imported} meses importados.");
    }

    public function restart(): void
    {
        $this->reset('files', 'batchIds', 'decisions', 'skip', 'closeAfter', 'expanded', 'results');
        $this->groupId = (string) Str::uuid();
        $this->step = 1;
    }

    public function render(Formatter $formatter, IndicatorCalculator $calculator): View
    {
        $user = auth()->user();
        $branches = $user->accessibleBranches();
        $batches = $this->batches();

        $review = [];
        $blocking = 0;
        foreach ($batches as $batch) {
            $item = ['batch' => $batch, 'month' => null, 'groups' => [], 'preview' => [], 'pending' => 0, 'complete' => 0];
            if ($batch->status === ImportStatus::Parsed && $batch->parsed_payload !== null) {
                $month = ParsedMonth::fromArray($batch->parsed_payload);
                $item['month'] = $month;
                foreach ($month->anomalies as $anomaly) {
                    $item['groups'][$anomaly->severity()->value][] = $anomaly;
                    if ($anomaly->severity() === AnomalySeverity::High && $anomaly->type->options() !== [] && ! isset($this->decisions[$batch->id][$anomaly->id()]) && ! ($this->skip[$batch->id] ?? false)) {
                        $item['pending']++;
                    }
                }
                $item['preview'] = $this->preview($month, $calculator);
                $item['complete'] = count(array_filter($month->rows, fn (ParsedRow $r) => $r->isComplete()));
                $blocking += $item['pending'];
            }
            $review[] = $item;
        }

        return view('livewire.imports.import-wizard', [
            'branches' => $branches,
            'review' => $review,
            'blocking' => $blocking,
            'importable' => count(array_filter($review, fn (array $i) => $i['month'] !== null && ! ($this->skip[$i['batch']->id] ?? false))),
            'formatter' => $formatter,
            'severities' => AnomalySeverity::cases(),
        ]);
    }

    /** @return Collection<int, ImportBatch> */
    private function batches(): Collection
    {
        if ($this->batchIds === []) {
            return new Collection;
        }

        return ImportBatch::query()->whereIn('id', $this->batchIds)->where('user_id', auth()->id())->orderBy('id')->get();
    }

    /**
     * Filas normalizadas con los derivados recalculados (§10.4 paso 3).
     *
     * @return list<DailyMetrics>
     */
    private function preview(ParsedMonth $month, IndicatorCalculator $calculator): array
    {
        $data = [];
        foreach ($month->rows as $row) {
            // La tasa "0" no permite derivar y no debe entrar a la vista previa.
            if (! $row->isComplete() || BigDecimal::of((string) $row->rate)->isZero()) {
                continue;
            }
            $data[] = DailyRecordData::fromArray([
                'date' => $row->date,
                'sales_bs' => (string) $row->salesBs,
                'exchange_rate' => (string) $row->rate,
                'transactions' => (int) $row->transactions,
                'units' => (int) $row->units,
                'inventory_units' => $row->inventoryUnits,
                'inventory_value_usd' => $row->inventoryValueUsd,
                'shifts' => (int) $row->shifts,
            ]);
        }

        return $calculator->daily($data);
    }
}
