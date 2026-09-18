<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Actions\Records\DeleteDailyRecord;
use App\Actions\Records\RegisterClosedDay;
use App\Actions\Records\RegisterDailyRecord;
use App\Actions\Records\UnmarkAtypical;
use App\Actions\Records\UpdateDailyRecord;
use App\Domain\Rates\RateResolver;
use App\Domain\Records\Exceptions\PeriodClosedException;
use App\Domain\Records\Exceptions\RecordException;
use App\Domain\Records\WarningDetector;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Enums\Currency;
use App\Enums\DayStatus;
use App\Enums\RateSource;
use App\Livewire\Forms\DailyRecordForm;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\PeriodEvent;
use App\Models\Setting;
use App\Queries\MonthRecordsQuery;
use App\Queries\WarningContextQuery;
use App\Support\CurrentBranch;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
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

    /** Aviso de tasa arrastrada desde hace más de una semana (§9.3); entra como advertencia al guardar. */
    public ?string $rateStaleMessage = null;

    /** @var list<array{code: string, field: string, message: string}> */
    public array $warnings = [];

    public bool $acknowledged = false;

    public ?string $error = null;

    /** @var array<string, string> */
    public array $reference = [];

    public string $weekdayLabel = '';

    /** Carga en secuencia de los días atrasados (§13.8): al guardar sigue con el siguiente faltante. */
    #[Url(as: 'faltantes')]
    public bool $sequence = false;

    /** La sede se consulta varias veces por petición (advertencias, tasa, guardado): se resuelve una sola vez. */
    private ?Branch $branch = null;

    public function mount(?string $date = null): void
    {
        $branch = app(CurrentBranch::class)->resolve(auth()->user())
            ?? abort(403, 'No tienes una sede asignada.');
        $this->branchId = $branch->id;
        $this->branch = $branch;

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
            // La tasa guardada se muestra tal cual; solo se manda al dominio si el usuario la cambia (A1).
            $this->rateEdited = false;
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
        $this->error = null;
        $record = DailyRecord::query()->findOrFail($this->recordId ?? 0);

        // El mes pudo cerrarse entre abrir el formulario y borrar (A2): aviso claro, nunca un 403 crudo.
        $blocked = $this->blockReason('delete');
        if ($blocked !== null) {
            $this->error = $blocked;
            $this->refreshDateDependents();

            return;
        }

        $date = $record->date;

        try {
            $this->authorize('delete', $record);
            $action->handle($record, auth()->user());
        } catch (RecordException $e) {
            $this->error = $e->getMessage();

            return;
        } catch (AuthorizationException) {
            $this->error = $this->blockReason('delete') ?? 'No puedes borrar este día. Pide el cambio a supervisión.';
            $this->refreshDateDependents();

            return;
        }

        $activityId = Activity::query()
            ->where('subject_type', DailyRecord::class)
            ->where('subject_id', $record->id)
            ->where('event', 'deleted')
            ->latest('id')
            ->value('id');

        session()->put('toast', [
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
            // Otro día es otra carga: lo escrito para el anterior no puede quedarse pegado (M13).
            $this->form->resetTypedFields();
            $this->error = null;
            $this->refreshDateDependents();

            return;
        }

        if ($property === 'form.rate') {
            $this->rateEdited = $this->form->rateChanged(app(Formatter::class));
            $this->refreshRateLabel();
        }

        $this->refreshWarnings();
    }

    public function save(RegisterDailyRecord $register, UpdateDailyRecord $update, Formatter $formatter): void
    {
        $this->error = null;

        // El mes pudo cerrarse (o vencer la ventana del operador) entre abrir el formulario y guardar (A2).
        $blocked = $this->blockReason('update');
        if ($blocked !== null) {
            $this->error = $blocked;
            $this->refreshDateDependents();

            return;
        }

        $this->form->validate();
        $this->refreshWarnings(saving: true);
        if ($this->warnings !== [] && ! $this->acknowledged) {
            return;
        }

        $branch = $this->branch();
        $input = $this->form->toInput($branch->id, $formatter, includeRate: $this->rateEdited);
        $user = auth()->user();
        $wasAtypical = false;
        $wasEdit = $this->recordId !== null;

        try {
            if ($this->recordId !== null) {
                $record = DailyRecord::query()->findOrFail($this->recordId);
                $this->authorize('update', $record);
                $wasAtypical = $record->status === DayStatus::Atypical;
                $expected = $this->expectedUpdatedAt === null ? null : CarbonImmutable::parse($this->expectedUpdatedAt);
                $saved = $update->handle($record, $input, $user, $expected);
                $message = 'Día actualizado.';
            } else {
                $this->authorize('create', [DailyRecord::class, $branch]);
                $saved = $register->handle($input, $user);
                $message = 'Día guardado.';
            }
        } catch (RecordException $e) {
            $this->error = $e->getMessage();

            return;
        } catch (AuthorizationException) {
            $this->error = $this->blockReason('update') ?? 'No tienes permiso para guardar este día.';
            $this->refreshDateDependents();

            return;
        }

        // Deshacer del marcado atípico (§13.8): 10 s desde el aviso, sin volver a abrir el día.
        $undo = null;
        if ($saved->status === DayStatus::Atypical && ! $wasAtypical) {
            $message = 'Día marcado como atípico. No se usará en la proyección.';
            $undo = ['label' => 'Deshacer', 'event' => 'undo-atypical', 'params' => ['record' => $saved->id]];
        }

        $this->finish($branch, $input->date, $message, $undo, wasEdit: $wasEdit);
    }

    /**
     * Motivo por el que este día ya no se puede guardar o borrar, con el mismo texto que usan las
     * acciones del dominio (A2). Null si todo sigue en orden.
     */
    private function blockReason(string $ability): ?string
    {
        try {
            $date = CarbonImmutable::parse($this->form->date)->startOfDay();
        } catch (InvalidArgumentException) {
            return null;
        }

        $period = Period::of($date);
        if (PeriodEvent::isClosed($this->branchId, $period)) {
            return PeriodClosedException::for($period)->getMessage();
        }

        $user = auth()->user();
        $record = $this->recordId === null ? null : DailyRecord::query()->find($this->recordId);

        if ($record !== null) {
            if ($user->can($ability, $record)) {
                return null;
            }

            $window = (int) Setting::get('operator_edit_window_days', $this->branchId);

            return $ability === 'delete'
                ? 'No puedes borrar este día. Pide el cambio a supervisión.'
                : "Solo puedes editar los últimos {$window} días. Pide el cambio a supervisión.";
        }

        return $user->can('create', [DailyRecord::class, $this->branch()])
            ? null
            : 'No tienes permiso para cargar días en esta sede.';
    }

    /** La sede del formulario, resuelta una sola vez por petición. */
    private function branch(): Branch
    {
        return $this->branch ??= Branch::query()->findOrFail($this->branchId);
    }

    /** "Deshacer" del aviso: el día vuelve a normal y conserva su observación. */
    #[On('undo-atypical')]
    public function undoAtypical(UnmarkAtypical $action, int $record): void
    {
        $model = DailyRecord::query()->findOrFail($record);
        $this->authorize('markAtypical', $model);

        if (! $action->handle($model, auth()->user())) {
            $this->dispatch('toast', type: 'warning', message: 'Ese día ya no está marcado como atípico.');

            return;
        }

        if ($this->recordId === $model->id) {
            $this->form->atypical = false;
        }
        $this->dispatch('toast', type: 'success', message: 'Marca de atípico retirada del '.app(Formatter::class)->date($model->date, 'short').'.');
    }

    public function saveAnyway(RegisterDailyRecord $register, UpdateDailyRecord $update, Formatter $formatter): void
    {
        $this->acknowledged = true;
        $this->save($register, $update, $formatter);
    }

    public function registerClosed(RegisterClosedDay $action, string $reason): void
    {
        $this->error = null;
        $branch = $this->branch();

        $blocked = $this->blockReason('create');
        if ($blocked !== null) {
            $this->error = $blocked;
            $this->refreshDateDependents();

            return;
        }

        $date = CarbonImmutable::parse($this->form->date);
        // Si esa fecha no tiene tasa publicada ni arrastre, vale la que el usuario escribió (M14).
        $parsed = app(Formatter::class)->parseNumber($this->form->rate);
        $rate = $this->rateEdited && $parsed !== null ? BigDecimal::of($parsed) : null;

        try {
            $this->authorize('create', [DailyRecord::class, $branch]);
            $action->handle($branch->id, $date, $reason, auth()->user(), $rate);
        } catch (RecordException $e) {
            $this->error = $e->getMessage();

            return;
        } catch (AuthorizationException) {
            $this->error = $this->blockReason('create') ?? 'No tienes permiso para cargar días en esta sede.';
            $this->refreshDateDependents();

            return;
        }

        $this->finish($branch, $date, 'Día registrado como cerrado.');
    }

    /** Al plegar el inventario, lo escrito no puede guardarse a escondidas (M18). */
    public function toggleInventory(): void
    {
        $this->showInventory = ! $this->showInventory;

        if (! $this->showInventory) {
            $this->form->inventory_units = '';
            $this->form->inventory_value_usd = '';
            $this->refreshWarnings();
        }
    }

    public function render(): View
    {
        $date = $this->form->date === '' ? CarbonImmutable::today() : CarbonImmutable::parse($this->form->date);

        return view('livewire.records.daily-form', [
            'isEdit' => $this->recordId !== null,
            'period' => Period::of($date),
            'sequenceInfo' => $this->sequence ? $this->sequenceInfo($date) : null,
            'canReopen' => auth()->user()->can('reopen', $this->branch()),
        ]);
    }

    /**
     * Posición dentro de los días faltantes del mes, con el anterior y el siguiente (§13.8).
     *
     * @return array{position: int|null, total: int, previous: string|null, next: string|null}
     */
    private function sequenceInfo(CarbonImmutable $date): array
    {
        $missing = app(MonthRecordsQuery::class)->run($this->branchId, Period::of($date))->missingDates;
        $keys = array_map(fn (CarbonImmutable $d) => $d->toDateString(), $missing);
        $index = array_search($date->toDateString(), $keys, true);

        $previous = null;
        $next = null;
        foreach ($keys as $key) {
            if ($key < $date->toDateString()) {
                $previous = $key;
            } elseif ($key > $date->toDateString() && $next === null) {
                $next = $key;
            }
        }

        return [
            'position' => $index === false ? null : $index + 1,
            'total' => count($keys),
            'previous' => $previous,
            'next' => $next,
        ];
    }

    private function resolveInitialDate(Branch $branch, ?string $date): CarbonImmutable
    {
        if ($date !== null) {
            try {
                $parsed = CarbonImmutable::createFromFormat('Y-m-d', $date);
            } catch (InvalidArgumentException) {
                abort(404);
            }

            // "2026-02-30" desbordaba en silencio al 02/03 (B16): solo vale si la fecha vuelve igual.
            if ($parsed === null || $parsed->format('Y-m-d') !== $date) {
                abort(404);
            }

            return $parsed->startOfDay();
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

        $branch = $this->branch();
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

        $this->rateStaleMessage = null;
        if ($this->rateEdited) {
            $this->rateLabel = 'Manual';
            $this->rateTone = 'warning';

            return;
        }

        // Día ya cargado y tasa sin tocar: manda el snapshot guardado (RN-06), no lo que resuelva hoy el
        // resolutor. Reescribirlo era lo que convertía cualquier edición en una tasa manual (A1).
        if ($this->recordId !== null) {
            $record = DailyRecord::query()->find($this->recordId);
            if ($record !== null) {
                $this->rateLabel = $record->exchange_rate_source->label();
                $this->rateTone = match ($record->exchange_rate_source) {
                    RateSource::Bcv => 'brand',
                    RateSource::Manual => 'warning',
                    RateSource::Carried => 'neutral',
                };

                return;
            }
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

        $this->form->rate = $formatter->numberFlexible($resolution->rate);
        $this->rateLabel = $resolution->label($formatter);
        $this->rateTone = $resolution->isStale() ? 'danger' : ($resolution->isCarried() ? 'neutral' : 'brand');
        // Arrastre de más de una semana: se propone, pero exige confirmarlo al guardar (§9.3).
        $this->rateStaleMessage = $resolution->isStale()
            ? 'La tasa propuesta es del '.$resolution->sourceDate->format('d/m/Y').' ('.$resolution->ageLabel().'): no hubo consultas al BCV desde entonces. Escribe la de hoy o confírmala.'
            : null;
    }

    /** Mientras se escribe se omite el aviso de inventario vacío: solo tiene sentido al intentar guardar. */
    private function refreshWarnings(bool $saving = false): void
    {
        $branch = $this->branch();
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
        if ($this->rateStaleMessage !== null && ! $this->rateEdited && $this->recordId === null) {
            array_unshift($this->warnings, ['code' => 'rate_stale', 'field' => 'rate', 'message' => $this->rateStaleMessage]);
        }
    }

    /** @param  array{label: string, event: string, params: array<string, mixed>}|null  $undo */
    private function finish(Branch $branch, CarbonImmutable $saved, string $message, ?array $undo = null, bool $wasEdit = false): void
    {
        $next = app(MonthRecordsQuery::class)->run($branch->id, Period::of($saved))->firstMissingDate();

        session()->put('toast', ['type' => 'success', 'message' => $message, 'action' => $undo]);
        session()->put('saved_date', $saved->toDateString());

        // Quien editaba un día vuelve al mes: saltar al primer faltante era una desviación sin pedirla (M17).
        // Recarga completa (sin wire:navigate): garantiza que el aviso en sesión se muestre siempre.
        if ($next !== null && ! $wasEdit) {
            $this->redirectRoute('records.create', array_filter(['date' => $next->toDateString(), 'faltantes' => $this->sequence ? 1 : null]));

            return;
        }

        $this->redirectRoute('month', ['period' => Period::of($saved)->key()]);
    }
}
