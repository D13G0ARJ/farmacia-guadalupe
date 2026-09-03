<?php

declare(strict_types=1);

namespace App\Livewire\Goals;

use App\Actions\Goals\SuggestGoal;
use App\Actions\Goals\UpsertGoal;
use App\Domain\Goals\Exceptions\InvalidGoalException;
use App\Domain\Indicators\Indicator;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Models\Goal;
use App\Queries\GoalProgressQuery;
use App\Support\CurrentBranch;
use App\Support\PeriodContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * UC-11 (definir metas) y UC-12 (seguirlas). Vista "Este mes": una fila por indicador con la meta
 * editable en línea, sugerencia, actual, esperado, proyección y estado. Vista "Año": cuadrícula
 * indicador × mes que se guarda en lote.
 */
#[Layout('layouts.app')]
#[Title('Metas')]
class GoalsManager extends Component
{
    use AuthorizesRequests;

    /** Orden de las filas: primero lo que dirección mira a diario (§13.7). */
    public const INDICATORS = [
        Indicator::SalesUsd, Indicator::Transactions, Indicator::Units, Indicator::AvgTicketUsd,
        Indicator::UnitsPerTransaction, Indicator::TransactionsPerShift, Indicator::SalesPerShiftUsd,
        Indicator::SalesBs, Indicator::AvgTicketBs,
    ];

    #[Url]
    public string $view = 'month';

    public string $period = '';

    public int $year = 2025;

    /** @var array<string, string> meta del mes por indicador, formateada es-VE */
    public array $targets = [];

    /** @var array<string, array<string, string>> indicador → 'YYYY-MM' → meta formateada */
    public array $grid = [];

    /** @var array<string, array<string, string>> */
    public array $gridOriginal = [];

    public string $growth = '5';

    public function mount(): void
    {
        $this->authorize('viewAny', Goal::class);
        $this->normalizeView();
        $this->syncPeriod();
    }

    #[On('context-changed')]
    public function refreshContext(): void
    {
        $this->syncPeriod();
    }

    /** Al cambiar de vista se recargan ambas: lo editado en una debe verse en la otra. */
    public function updatedView(): void
    {
        $this->normalizeView();
        $this->loadMonth();
        $this->loadYear();
    }

    /** Edición en línea de la vista del mes: cada campo se guarda al perder el foco. */
    public function updatedTargets(mixed $value, string $key): void
    {
        $this->authorizeManage();
        $indicator = Indicator::from($key);
        $period = Period::of($this->period);

        try {
            $goal = app(UpsertGoal::class)->handle($this->branchId(), $indicator, $period, is_scalar($value) ? (string) $value : null, auth()->user());
            $this->dispatch('toast', type: 'success', message: $goal === null ? 'Meta eliminada.' : 'Meta guardada.');
            $this->loadMonth();
            $this->loadYear();
        } catch (InvalidGoalException $e) {
            $this->addError('targets.'.$key, $e->getMessage());
        }
    }

    public function copyPreviousMonth(): void
    {
        $this->authorizeManage();
        $period = Period::of($this->period);
        $previous = app(GoalProgressQuery::class)->targets($this->branchId(), $period->previous());

        if ($previous === []) {
            $this->dispatch('toast', type: 'info', message: 'No hay metas en '.mb_strtolower($period->previous()->label()).'.');

            return;
        }

        foreach ($previous as $key => $target) {
            app(UpsertGoal::class)->handle($this->branchId(), Indicator::from($key), $period, (string) $target, auth()->user());
        }

        $this->dispatch('toast', type: 'success', message: count($previous).' metas copiadas de '.mb_strtolower($period->previous()->label()).'.');
        $this->loadMonth();
        $this->loadYear();
    }

    public function previousYear(): void
    {
        $this->year--;
        $this->loadYear();
    }

    public function nextYear(): void
    {
        $this->year++;
        $this->loadYear();
    }

    /** Rellena la cuadrícula con las metas del año anterior; no guarda hasta pulsar Guardar. */
    public function copyPreviousYear(): void
    {
        $this->authorizeManage();
        $query = app(GoalProgressQuery::class);
        $formatter = app(Formatter::class);
        $copied = 0;

        foreach ($this->months() as $key) {
            $source = Period::of(($this->year - 1).substr($key, 4));
            foreach ($query->targets($this->branchId(), $source) as $indicator => $target) {
                $this->grid[$indicator][$key] = $formatter->number($target, Indicator::from($indicator)->precision());
                $copied++;
            }
        }

        $this->dispatch('toast', type: $copied > 0 ? 'success' : 'info', message: $copied > 0 ? "{$copied} metas traídas de ".($this->year - 1).'. Revisa y guarda.' : 'No hay metas en '.($this->year - 1).'.');
    }

    /** Aplica +X % a todas las metas escritas en la cuadrícula; no guarda hasta pulsar Guardar. */
    public function increaseAll(): void
    {
        $this->authorizeManage();
        $formatter = app(Formatter::class);
        $pct = $formatter->parseNumber($this->growth);
        if ($pct === null) {
            $this->addError('growth', 'Escribe un porcentaje, por ejemplo 5.');

            return;
        }
        $factor = BigDecimal::one()->plus(BigDecimal::of($pct)->dividedBy(100, 6, RoundingMode::HalfUp));

        foreach ($this->grid as $indicator => $months) {
            $precision = Indicator::from($indicator)->precision();
            foreach ($months as $key => $value) {
                $number = $formatter->parseNumber($value);
                if ($number === null) {
                    continue;
                }
                $this->grid[$indicator][$key] = $formatter->number(BigDecimal::of($number)->multipliedBy($factor)->toScale($precision, RoundingMode::HalfUp), $precision);
            }
        }
    }

    public function saveYear(): void
    {
        $this->authorizeManage();
        $saved = 0;
        $errors = 0;

        foreach ($this->grid as $indicator => $months) {
            foreach ($months as $key => $value) {
                if (trim((string) $value) === trim($this->gridOriginal[$indicator][$key] ?? '')) {
                    continue;
                }
                try {
                    app(UpsertGoal::class)->handle($this->branchId(), Indicator::from($indicator), Period::of($key), (string) $value, auth()->user());
                    $saved++;
                } catch (InvalidGoalException $e) {
                    $this->addError("grid.{$indicator}.{$key}", $e->getMessage());
                    $errors++;
                }
            }
        }

        if ($errors === 0) {
            $this->dispatch('toast', type: 'success', message: $saved === 0 ? 'No había cambios.' : ($saved === 1 ? '1 meta guardada.' : "{$saved} metas guardadas."));
            $this->loadYear();
        } else {
            $this->dispatch('toast', type: 'warning', message: "{$errors} metas con error; corrígelas y vuelve a guardar.");
        }
    }

    public function render(GoalProgressQuery $query, SuggestGoal $suggest, Formatter $formatter): View
    {
        $user = auth()->user();
        $branch = app(CurrentBranch::class)->resolve($user);
        $period = Period::of($this->period);
        $tracking = $query->run($branch?->id, $period);

        $suggestions = [];
        if ($this->view === 'month') {
            foreach (self::INDICATORS as $indicator) {
                $suggestion = $suggest->for($branch?->id, $indicator, $period);
                $suggestions[$indicator->value] = $suggestion === null ? null : $formatter->number($suggestion, $indicator->precision());
            }
        }

        return view('livewire.goals.goals-manager', [
            'branch' => $branch,
            'periodObj' => $period,
            'tracking' => $tracking,
            'indicators' => self::INDICATORS,
            'suggestions' => $suggestions,
            'months' => $this->months(),
            'formatter' => $formatter,
            'canManage' => $user->can('manage', [Goal::class, $branch]),
        ]);
    }

    /** @return list<string> claves 'YYYY-MM' del año en pantalla */
    private function months(): array
    {
        return array_map(fn (int $m) => sprintf('%04d-%02d', $this->year, $m), range(1, 12));
    }

    private function syncPeriod(): void
    {
        $period = app(PeriodContext::class)->current();
        $this->period = $period->key();
        $this->year = $period->start->year;
        $this->loadMonth();
        $this->loadYear();
    }

    private function loadMonth(): void
    {
        $formatter = app(Formatter::class);
        $targets = app(GoalProgressQuery::class)->targets($this->branchId(), Period::of($this->period));

        $this->targets = [];
        foreach (self::INDICATORS as $indicator) {
            $target = $targets[$indicator->value] ?? null;
            $this->targets[$indicator->value] = $target === null ? '' : $formatter->number($target, $indicator->precision());
        }
        $this->resetErrorBag('targets');
    }

    private function loadYear(): void
    {
        $formatter = app(Formatter::class);
        $branchId = $this->branchId();
        $goals = Goal::query()
            ->whereBetween('period', [$this->year.'-01-01', $this->year.'-12-01'])
            ->where(fn ($q) => $branchId === null ? $q->whereNull('branch_id') : $q->where('branch_id', $branchId))
            ->get();

        $grid = [];
        foreach (self::INDICATORS as $indicator) {
            foreach ($this->months() as $key) {
                $grid[$indicator->value][$key] = '';
            }
        }
        foreach ($goals as $goal) {
            $grid[$goal->indicator->value][$goal->period->format('Y-m')] = $formatter->number($goal->target, $goal->indicator->precision());
        }

        $this->grid = $grid;
        $this->gridOriginal = $grid;
        $this->resetErrorBag('grid');
    }

    private function branchId(): ?int
    {
        return app(CurrentBranch::class)->resolve(auth()->user())?->id;
    }

    private function authorizeManage(): void
    {
        $this->authorize('manage', [Goal::class, app(CurrentBranch::class)->resolve(auth()->user())]);
    }

    private function normalizeView(): void
    {
        if (! in_array($this->view, ['month', 'year'], true)) {
            $this->view = 'month';
        }
    }
}
