<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Actions\Periods\CloseMonth;
use App\Actions\Periods\ReopenMonth;
use App\Actions\Records\UndoDeleteDailyRecord;
use App\Actions\Records\UnmarkAtypical;
use App\Domain\Indicators\DailyMetrics;
use App\Domain\Indicators\Indicator;
use App\Domain\Periods\Exceptions\PeriodStateException;
use App\Domain\Records\Exceptions\RecordException;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Enums\PeriodAction;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\PeriodEvent;
use App\Queries\MonthRecordsQuery;
use App\Queries\MonthView;
use App\Support\CurrencyContext;
use App\Support\CurrentBranch;
use App\Support\PeriodContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * UC-06 (calendario con estado por día), UC-09 (cuadro de indicadores con totales ponderados) y
 * UC-07 (cerrar y reabrir el mes, con su historial).
 */
#[Layout('layouts.app')]
#[Title('Mes')]
class MonthOverview extends Component
{
    use AuthorizesRequests;

    public string $period = '';

    public bool $excludeAtypical = false;

    /** Confirmación explícita para cerrar con días faltantes (UC-07). */
    public bool $confirmMissing = false;

    public string $reopenReason = '';

    /** Orden del cuadro (UC-09): 'date' o el valor de un indicador. */
    public string $sort = 'date';

    public string $dir = 'asc';

    /** Búsqueda por fecha en el cuadro: "16", "16/09" o "mar". */
    public string $search = '';

    public function mount(?string $period = null): void
    {
        $this->authorize('viewAny', DailyRecord::class);

        $context = app(PeriodContext::class);

        if ($period !== null) {
            try {
                $context->set(Period::of($period));
            } catch (InvalidArgumentException) {
                abort(404);
            }
        }

        $this->period = $context->current()->key();
    }

    #[On('context-changed')]
    public function refreshContext(): void
    {
        $this->period = app(PeriodContext::class)->current()->key();
        $this->confirmMissing = false;
        $this->reopenReason = '';
        $this->resetErrorBag();
    }

    public function closeMonth(CloseMonth $action): void
    {
        $branch = $this->branchOrFail();
        $this->authorize('close', $branch);
        $this->resetErrorBag('close');
        $period = Period::of($this->period);

        try {
            $action->handle($branch, $period, auth()->user(), $this->confirmMissing);
        } catch (PeriodStateException $e) {
            $this->addError('close', $e->getMessage());

            return;
        }

        $this->confirmMissing = false;
        $this->dispatch('month-state-changed');
        $this->dispatch('toast', type: 'success', message: $period->label().' cerrado. Nadie podrá editarlo sin reabrirlo.');
    }

    public function reopenMonth(ReopenMonth $action): void
    {
        $branch = $this->branchOrFail();
        $this->authorize('reopen', $branch);
        $this->resetErrorBag('reopenReason');
        $period = Period::of($this->period);

        try {
            $action->handle($branch, $period, $this->reopenReason, auth()->user());
        } catch (PeriodStateException $e) {
            $this->addError('reopenReason', $e->getMessage());

            return;
        }

        $this->reopenReason = '';
        $this->dispatch('month-state-changed');
        $this->dispatch('toast', type: 'success', message: $period->label().' reabierto.');
    }

    /** Clic en un encabezado del cuadro: ordena por esa columna; el segundo clic invierte. */
    public function sortBy(string $key): void
    {
        if ($key !== 'date' && Indicator::tryFrom($key) === null) {
            return;
        }
        if ($this->sort === $key) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';

            return;
        }
        $this->sort = $key;
        $this->dir = $key === 'date' ? 'asc' : 'desc';
    }

    /** "Deshacer" del marcado atípico desde el aviso (§13.8). */
    #[On('undo-atypical')]
    public function undoAtypical(UnmarkAtypical $action, int $record): void
    {
        $model = DailyRecord::query()->findOrFail($record);
        $this->authorize('markAtypical', $model);

        if (! $action->handle($model, auth()->user())) {
            $this->dispatch('toast', type: 'warning', message: 'Ese día ya no está marcado como atípico.');

            return;
        }

        $this->dispatch('toast', type: 'success', message: 'Marca de atípico retirada del '.app(Formatter::class)->date($model->date, 'short').'.');
    }

    /** "Deshacer" del aviso tras borrar un día (§13.8): recrea el registro desde la bitácora. */
    #[On('undo-delete')]
    public function undoDelete(UndoDeleteDailyRecord $action, int $activity): void
    {
        $branch = $this->branchOrFail();
        $this->authorize('create', [DailyRecord::class, $branch]);

        try {
            $record = $action->handle($activity, auth()->user());
        } catch (RecordException $e) {
            $this->dispatch('toast', type: 'danger', message: $e->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', message: 'Día '.app(Formatter::class)->date($record->date, 'short').' restaurado.');
    }

    public function render(MonthRecordsQuery $query, Formatter $formatter, CurrencyContext $currency): View
    {
        $user = auth()->user();
        $branch = app(CurrentBranch::class)->resolve($user);
        $period = Period::of($this->period);
        $view = $query->run($branch?->id, $period, $this->excludeAtypical);

        $events = $branch === null ? collect() : PeriodEvent::query()
            ->with('user')
            ->where('branch_id', $branch->id)
            ->where('period', $period->start->toDateString())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
        $lastEvent = $events->first();

        return view('livewire.records.month-overview', [
            'view' => $view,
            'rows' => $this->filterAndSort($view->rows, $formatter),
            'branch' => $branch,
            'weeks' => $this->weeks($view),
            'columns' => $this->columns($currency),
            'formatter' => $formatter,
            'currency' => $currency,
            'canCreate' => $branch !== null && $user->can('create', [DailyRecord::class, $branch]),
            'canClose' => $branch !== null && ! $view->isClosed && $period->start->lte(CarbonImmutable::today()) && $view->loadedDays() > 0 && $user->can('close', $branch),
            'canReopen' => $branch !== null && $view->isClosed && $user->can('reopen', $branch),
            'closedEvent' => $view->isClosed && $lastEvent?->action === PeriodAction::Closed ? $lastEvent : null,
            'events' => $events,
        ]);
    }

    /**
     * Filas del cuadro filtradas por la búsqueda y ordenadas por la columna elegida (UC-09).
     * Los totales no cambian: se calculan sobre el mes completo.
     *
     * @param  list<DailyMetrics>  $rows
     * @return list<DailyMetrics>
     */
    private function filterAndSort(array $rows, Formatter $formatter): array
    {
        $needle = mb_strtolower(trim($this->search));
        if ($needle !== '') {
            $rows = array_values(array_filter($rows, function (DailyMetrics $m) use ($needle, $formatter): bool {
                $date = $m->data->date;
                $candidates = [(string) $date->day, $date->format('d'), $date->format('d/m'), $date->format('d/m/Y'), $formatter->date($date, 'weekday'), $formatter->weekday($date)];
                foreach ($candidates as $candidate) {
                    if (str_starts_with(mb_strtolower($candidate), $needle)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        $indicator = Indicator::tryFrom($this->sort);
        usort($rows, function (DailyMetrics $a, DailyMetrics $b) use ($indicator): int {
            if ($indicator === null) {
                $cmp = $a->data->date <=> $b->data->date;
            } else {
                $va = $a->value($indicator);
                $vb = $b->value($indicator);
                // Sin valor (día cerrado, inventario sin conteo) siempre al final
                $cmp = match (true) {
                    $va === null && $vb === null => 0,
                    $va === null => 1,
                    $vb === null => -1,
                    default => $va->compareTo($vb) * ($this->dir === 'asc' ? 1 : -1),
                };

                return $cmp !== 0 ? $cmp : $a->data->date <=> $b->data->date;
            }

            return $this->dir === 'asc' ? $cmp : -$cmp;
        });

        return $rows;
    }

    private function branchOrFail(): Branch
    {
        return app(CurrentBranch::class)->resolve(auth()->user())
            ?? abort(403, 'El consolidado no se cierra: elige una sede.');
    }

    /**
     * Semanas del mes (lunes a domingo) con huecos al inicio y al final.
     *
     * @return list<list<CarbonImmutable|null>>
     */
    private function weeks(MonthView $view): array
    {
        $weeks = [];
        $week = array_fill(0, $view->period->start->dayOfWeekIso - 1, null);

        foreach ($view->period->dates() as $date) {
            $week[] = $date;
            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
        }
        if ($week !== []) {
            $weeks[] = array_pad($week, 7, null);
        }

        return $weeks;
    }

    /**
     * Columnas de la tabla según la moneda activa, agrupadas como en el Excel (§13.5).
     *
     * @return list<array{group: string, indicator: Indicator, derived: bool}>
     */
    private function columns(CurrencyContext $currency): array
    {
        $columns = [];
        if ($currency->showsBs()) {
            $columns[] = ['group' => 'Ventas', 'indicator' => Indicator::SalesBs, 'derived' => false];
        }
        if ($currency->showsUsd()) {
            $columns[] = ['group' => 'Ventas', 'indicator' => Indicator::SalesUsd, 'derived' => true];
        }
        $columns[] = ['group' => 'Ventas', 'indicator' => Indicator::AvgRate, 'derived' => false];
        $columns[] = ['group' => 'Operación', 'indicator' => Indicator::Transactions, 'derived' => false];
        $columns[] = ['group' => 'Operación', 'indicator' => Indicator::Units, 'derived' => false];
        $columns[] = ['group' => 'Operación', 'indicator' => Indicator::Shifts, 'derived' => false];
        $columns[] = ['group' => 'Operación', 'indicator' => Indicator::TransactionsPerShift, 'derived' => true];
        if ($currency->showsBs()) {
            $columns[] = ['group' => 'Promedios', 'indicator' => Indicator::AvgTicketBs, 'derived' => true];
        }
        if ($currency->showsUsd()) {
            $columns[] = ['group' => 'Promedios', 'indicator' => Indicator::AvgTicketUsd, 'derived' => true];
        }
        $columns[] = ['group' => 'Promedios', 'indicator' => Indicator::UnitsPerTransaction, 'derived' => true];
        $columns[] = ['group' => 'Inventario', 'indicator' => Indicator::InventoryUnits, 'derived' => false];
        $columns[] = ['group' => 'Inventario', 'indicator' => Indicator::InventoryValueUsd, 'derived' => false];

        return $columns;
    }
}
