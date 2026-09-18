<?php

declare(strict_types=1);

namespace App\Livewire\Charts;

use App\Domain\Charts\ChartSpecBuilder;
use App\Domain\Indicators\Indicator;
use App\Domain\Shared\Period;
use App\Models\DailyRecord;
use App\Queries\AnnualComparisonQuery;
use App\Queries\ChartSeriesQuery;
use App\Support\CurrencyContext;
use App\Support\CurrentBranch;
use App\Support\PeriodContext;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * UC-10: gráficas por familia en pestañas (§13.7). Un solo componente con todas las
 * especificaciones de la pestaña activa: cambiar período, sede o moneda es una sola petición.
 */
#[Layout('layouts.app')]
#[Title('Gráficas')]
class ChartsPage extends Component
{
    use AuthorizesRequests;

    /** @var array<string, array{label: string, charts: list<string>}> */
    public const TABS = [
        'ventas' => ['label' => 'Ventas', 'charts' => ['g2', 'g1', 'g8']],
        'operacion' => ['label' => 'Operación', 'charts' => ['g3', 'g6', 'g4', 'g5']],
        'inventario' => ['label' => 'Inventario', 'charts' => ['g7']],
        'tasa' => ['label' => 'Tasa', 'charts' => ['g10']],
        'anio' => ['label' => 'Año', 'charts' => ['g11']],
    ];

    /**
     * Sin tipo: la URL puede traer cualquier cosa (`?tab[]=x`) y un tipo declarado reventaría al
     * hidratar; `normalize()` la deja siempre en una pestaña válida (M23).
     *
     * @var string|array<mixed>
     */
    #[Url]
    public $tab = 'ventas';

    /**
     * Indicador de la comparativa interanual (G11).
     *
     * @var string|array<mixed>
     */
    #[Url(as: 'indicador')]
    public $annualIndicator = 'sales_usd';

    #[Locked]
    public string $period = '';

    /** @var array<string, array<string, mixed>> */
    #[Locked]
    public array $specs = [];

    public function mount(): void
    {
        $this->authorize('viewAny', DailyRecord::class);
        $this->period = app(PeriodContext::class)->current()->key();
        $this->normalize();
    }

    public function updatedTab(): void
    {
        $this->normalize();
    }

    public function updatedAnnualIndicator(): void
    {
        $this->normalize();
    }

    #[On('context-changed')]
    public function refreshContext(): void
    {
        $this->period = app(PeriodContext::class)->current()->key();
    }

    public function render(ChartSeriesQuery $query, AnnualComparisonQuery $annualQuery, ChartSpecBuilder $builder, CurrencyContext $currency): View
    {
        $user = auth()->user();
        $branchContext = app(CurrentBranch::class);

        if (! $branchContext->hasAccess($user)) {
            return view('livewire.shared.no-branch');
        }

        $branch = $branchContext->resolve($user);
        $period = Period::of($this->period);
        $tab = $this->tabKey();

        if ($tab === 'anio') {
            $indicator = Indicator::from($this->indicatorKey());
            $annual = $annualQuery->run($branch?->id, $period->start->year);
            $current = [];
            $previous = [];
            for ($m = 1; $m <= 12; $m++) {
                $current[$m] = $annual->value($indicator, $m);
                $previous[$m] = $annual->previousYearValue($indicator, $m);
            }
            $this->specs = ['g11' => $builder->annualComparison($annual->year, $current, $previous, $indicator)->toArray()];
        } else {
            $this->specs = $query->specs($branch?->id, $period, $this->chartsFor($tab, $currency));
        }

        return view('livewire.charts.charts-page', [
            'branch' => $branch,
            'periodLabel' => $tab === 'anio' ? 'Año '.$period->start->year : $period->label(),
            'tabs' => self::TABS,
            'annualIndicators' => Indicator::annualOrder(),
        ]);
    }

    /**
     * Gráficas de la pestaña; en Ventas la moneda activa decide cuál va primero (§14).
     *
     * @return list<string>
     */
    private function chartsFor(string $tab, CurrencyContext $currency): array
    {
        $charts = self::TABS[$tab]['charts'];
        if ($tab === 'ventas' && $currency->current() === CurrencyContext::BS) {
            $charts = ['g1', 'g2', 'g8'];
        }

        return $charts;
    }

    private function normalize(): void
    {
        $this->tab = $this->tabKey();
        $this->annualIndicator = $this->indicatorKey();
    }

    /** Pestaña válida: lo que venga de la URL o del cliente no se usa sin pasar por aquí (M23). */
    private function tabKey(): string
    {
        return is_string($this->tab) && array_key_exists($this->tab, self::TABS) ? $this->tab : 'ventas';
    }

    private function indicatorKey(): string
    {
        $indicator = is_string($this->annualIndicator) ? Indicator::tryFrom($this->annualIndicator) : null;

        return $indicator !== null && in_array($indicator, Indicator::annualOrder(), true)
            ? $indicator->value
            : Indicator::SalesUsd->value;
    }
}
