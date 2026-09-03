<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Actions\Records\DeleteDailyRecord;
use App\Actions\Records\RegisterClosedDay;
use App\Actions\Records\RegisterDailyRecord;
use App\Actions\Records\UpdateDailyRecord;
use App\Domain\Rates\RateResolver;
use App\Domain\Records\Exceptions\RecordException;
use App\Domain\Records\WarningDetector;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Enums\Currency;
use App\Livewire\Forms\DailyRecordForm;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\PeriodEvent;
use App\Models\Setting;
use App\Queries\MonthRecordsQuery;
use App\Queries\WarningContextQuery;
use App\Support\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * UC-02 a UC-05: cargar, editar, marcar atípico y registrar día cerrado.
 * La vista previa de derivados se calcula en el navegador (RN-26); el servidor recalcula al guardar.
 */
#[Layout('layouts.app')]
#[Title('Cargar día')]
class DailyForm extends Component
{
    use AuthorizesRequests;

    public DailyRecordForm $form;

    #[Locked]
    public int $branchId;

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public ?string $expectedUpdatedAt = null;

    public bool $periodClosed = false;

    /** "05/10": desde cuándo está cerrado el mes (microcopy §13.6). */
    public ?string $closedSince = null;

    /** Solo lectura sin ser mes cerrado: el operador fuera de su ventana de edición (§15.1). */
    public bool $readOnly = false;

    public ?string $readOnlyReason = null;

    public bool $canDelete = false;

    /** "Ana · mié 24/09/2025 18:02": última edición según la bitácora (RN-14). */
    public ?string $lastEdit = null;

    public bool $inventoryDay = true;

    public bool $showInventory = true;

    public ?string $rateLabel = null;

    public string $rateTone = 'neutral';

    public bool $rateEdited = false;

    /** @var list<array{code: string, field: string, message: string}> */
    public array $warnings = [];

    public bool $acknowledged = false;

    public ?string $error = null;

    /** @var array<string, string> */
    public array $reference = [];

    public string $weekdayLabel = '';

    public function mount(?string $date = null): void
    {
        $branch = app(CurrentBranch::class)->resolve(auth()->user())
            ?? abort(403, 'No tienes una sede asignada.');
        $this->branchId = $branch->id;

        $this->authorize('create', [DailyRecord::class, $branch]);

        $target = $this->resolveInitialDate($branch, $date);
        $this->form->date = $target->toDateString();
        $this->form->shifts = (string) $branch->default_shifts;

        $existing = DailyRecord::query()->forBranch($branch->id)->where('date', $target->toDateString())->first();
        if ($existing !== null) {
            $user = auth()->user();
            $this->recordId = $existing->id;
            $this->expectedUpdatedAt = $existing->updated_at?->toIso8601String();
            $this->form->fillFromRecord($existing, app(Formatter::class));
            $this->rateEdited = true;
            $this->canDelete = $user->can('delete', $existing);
            $this->lastEdit = $this->describeLastEdit($existing);

            // Sin permiso de edición (ventana del operador, §15.1) el día se muestra en solo lectura; el mes cerrado se avisa aparte.
            if (! $user->can('update', $existing) && ! PeriodEvent::isClosed($branch->id, Period::of($target))) {
                $this->readOnly = true;
                $window = (int) Setting::get('operator_edit_window_days', $branch->id);
                $this->readOnlyReason = "Solo puedes editar los últimos {$window} días. Pide el cambio a supervisión.";
            }
        }

        $this->refreshDateDependents();
    }

    /** UC-03: borrar el día con confirmación y "Deshacer" de 10 s en el aviso (§13.8). */
    public function deleteDay(DeleteDailyRecord $action): void
    {
        $record = DailyRecord::query()->findOrFail($this->recordId ?? 0);
        $this->authorize('delete', $record);
        $branch = Branch::query()->findOrFail($this->branchId);
        $date = $record->date;

        try {
            $action->handle($record, auth()->user());
        } catch (RecordException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $activityId = Activity::query()
            ->where('subject_type', DailyRecord::class)
            ->where('subject_id', $record->id)
            ->where('event', 'deleted')
            ->latest('id')
            ->value('id');

        session()->flash('toast', [
            'type' => 'success',
            'message' => 'Día '.app(Formatter::class)->date($date, 'short').' borrado.',
            'action' => $activityId === null ? null : ['label' => 'Deshacer', 'event' => 'undo-delete', 'params' => ['activity' => (int) $activityId]],
        ]);

        $this->redirectRoute('month', ['period' => Period::of($date)->key()]);
    }

    private function describeLastEdit(DailyRecord $record): ?string
    {
        $activity = Activity::query()
            ->where('subject_type', DailyRecord::class)
            ->where('subject_id', $record->id)
            ->latest('id')
            ->with('causer')
            ->first();

        if ($activity === null || $activity->created_at === null) {
            return null;
        }

        $who = $activity->causer?->getAttribute('name') ?? 'Sistema';
        $formatter = app(Formatter::class);

        return $who.' · '.$formatter->date($activity->created_at, 'weekday_full').' '.$activity->created_at->format('H:i');
    }

    /** Si cambia la sede en la barra de contexto, el formulario se reabre para esa sede (nunca guarda en la anterior). */
    #[On('context-changed')]
    public function reopenForContext(): void
    {
        $current = app(CurrentBranch::class)->resolve(auth()->user());

        if ($current === null || $current->id !== $this->branchId) {
            $this->redirectRoute('records.create', ['date' => $this->form->date]);
        }
    }

    /** Cualquier cambio en el formulario invalida el "guardar de todos modos" y recalcula advertencias. */
    public function updated(string $property): void
    {
        if (! str_starts_with($property, 'form.')) {
            return;
        }

        $this->acknowledged = false;

        if ($property === 'form.date') {
            $this->recordId = null;
            $this->expectedUpdatedAt = null;
            $this->rateEdited = false;
            $this->form->rate = '';
            $this->refreshDateDependents();

            return;
        }

        if ($property === 'form.rate') {
            $this->rateEdited = trim($this->form->rate) !== '';
            $this->refreshRateLabel();
        }

        $this->refreshWarnings();
    }

    public function save(RegisterDailyRecord $register, UpdateDailyRecord $update, Formatter $formatter): void
    {
        $this->error = null;
        $this->form->validate();
        $this->refreshWarnings(saving: true);
        if ($this->warnings !== [] && ! $this->acknowledged) {
            return;
        }

        $branch = Branch::query()->findOrFail($this->branchId);
        $input = $this->form->toInput($branch->id, $formatter, includeRate: $this->rateEdited);
        $user = auth()->user();

        try {
            if ($this->recordId !== null) {
                $record = DailyRecord::query()->findOrFail($this->recordId);
                $this->authorize('update', $record);
                $expected = $this->expectedUpdatedAt === null ? null : CarbonImmutable::parse($this->expectedUpdatedAt);
                $update->handle($record, $input, $user, $expected);
                $message = 'Día actualizado.';
            } else {
                $this->authorize('create', [DailyRecord::class, $branch]);
                $register->handle($input, $user);
                $message = 'Día guardado.';
            }
        } catch (RecordException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->finish($branch, $input->date, $message);
    }

    public function saveAnyway(RegisterDailyRecord $register, UpdateDailyRecord $update, Formatter $formatter): void
    {
        $this->acknowledged = true;
        $this->save($register, $update, $formatter);
    }

    public function registerClosed(RegisterClosedDay $action, string $reason): void
    {
        $this->error = null;
        $branch = Branch::query()->findOrFail($this->branchId);
        $this->authorize('create', [DailyRecord::class, $branch]);

        try {
            $action->handle($branch->id, CarbonImmutable::parse($this->form->date), $reason, auth()->user());
        } catch (RecordException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->finish($branch, CarbonImmutable::parse($this->form->date), 'Día registrado como cerrado.');
    }

    public function toggleInventory(): void
    {
        $this->showInventory = ! $this->showInventory;
    }

    public function render(): View
    {
        return view('livewire.records.daily-form', [
            'isEdit' => $this->recordId !== null,
            'period' => Period::of($this->form->date === '' ? CarbonImmutable::today() : CarbonImmutable::parse($this->form->date)),
        ]);
    }

    private function resolveInitialDate(Branch $branch, ?string $date): CarbonImmutable
    {
        if ($date !== null) {
            try {
                return CarbonImmutable::parse($date)->startOfDay();
            } catch (InvalidArgumentException) {
                abort(404);
            }
        }

        $view = app(MonthRecordsQuery::class)->run($branch->id, Period::current());

        return $view->firstMissingDate() ?? CarbonImmutable::today();
    }

    private function refreshDateDependents(): void
    {
        try {
            $date = CarbonImmutable::parse($this->form->date)->startOfDay();
        } catch (InvalidArgumentException) {
            return;
        }

        $branch = Branch::query()->findOrFail($this->branchId);
        $formatter = app(Formatter::class);

        $this->weekdayLabel = $formatter->date($date, 'long');
        $this->periodClosed = PeriodEvent::isClosed($branch->id, Period::of($date));
        $this->closedSince = null;
        if ($this->periodClosed) {
            $closedAt = PeriodEvent::query()
                ->where('branch_id', $branch->id)
                ->where('period', Period::of($date)->start->toDateString())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('created_at');
            $this->closedSince = $closedAt === null ? null : CarbonImmutable::parse((string) $closedAt)->format('d/m');
        }
        $this->inventoryDay = $branch->countsInventoryOn($date);
        $this->showInventory = $this->inventoryDay || $this->form->inventory_units !== '' || $this->form->inventory_value_usd !== '';

        $previous = app(MonthRecordsQuery::class)->previousLoaded($branch->id, $date);
        $this->reference = $previous === null ? [] : [
            'label' => 'Último día cargado, '.$formatter->date($previous->data->date, 'weekday'),
            'sales_bs' => $formatter->money($previous->data->salesBs, Currency::Bs),
            'rate' => $formatter->number($previous->data->rate, 2),
            'transactions' => $formatter->number($previous->data->transactions),
            'units' => $formatter->number($previous->data->units),
            'inventory_units' => $previous->data->inventoryUnits === null ? '—' : $formatter->number($previous->data->inventoryUnits),
            'inventory_value_usd' => $formatter->money($previous->data->inventoryValueUsd, Currency::Usd, 0),
            'shifts' => $formatter->number($previous->data->shifts),
        ];

        $this->refreshRateLabel();
        $this->refreshWarnings();
    }

    private function refreshRateLabel(): void
    {
        $formatter = app(Formatter::class);

        if ($this->rateEdited) {
            $this->rateLabel = 'Manual';
            $this->rateTone = 'warning';

            return;
        }

        try {
            $date = CarbonImmutable::parse($this->form->date);
        } catch (InvalidArgumentException) {
            return;
        }

        $resolution = app(RateResolver::class)->forDate($date);

        if ($resolution === null) {
            $this->form->rate = '';
            $this->rateLabel = 'Sin tasa BCV: escríbela';
            $this->rateTone = 'danger';

            return;
        }

        $this->form->rate = $formatter->number($resolution->rate, 2);
        $this->rateLabel = $resolution->label($formatter);
        $this->rateTone = $resolution->isCarried() ? 'neutral' : 'brand';
    }

    /** Mientras se escribe se omite el aviso de inventario vacío: solo tiene sentido al intentar guardar. */
    private function refreshWarnings(bool $saving = false): void
    {
        $branch = Branch::query()->findOrFail($this->branchId);
        $formatter = app(Formatter::class);
        $input = $this->form->tryInput($branch->id, $formatter);

        if ($input === null) {
            $this->warnings = [];

            return;
        }

        $context = app(WarningContextQuery::class)->for($branch, $input->date);
        $warnings = app(WarningDetector::class)->detect($input, $context);
        if (! $saving) {
            $warnings = array_values(array_filter($warnings, fn ($w) => $w->code !== 'inventory_missing'));
        }

        $this->warnings = array_map(fn ($w) => ['code' => $w->code, 'field' => $w->field, 'message' => $w->message], $warnings);
    }

    private function finish(Branch $branch, CarbonImmutable $saved, string $message): void
    {
        $next = app(MonthRecordsQuery::class)->run($branch->id, Period::of($saved))->firstMissingDate();

        session()->flash('toast', ['type' => 'success', 'message' => $message]);
        session()->flash('saved_date', $saved->toDateString());

        // Recarga completa (sin wire:navigate): garantiza que el aviso en sesión se muestre siempre.
        if ($next !== null) {
            $this->redirectRoute('records.create', ['date' => $next->toDateString()]);

            return;
        }

        $this->redirectRoute('month', ['period' => Period::of($saved)->key()]);
    }
}
