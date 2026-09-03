<?php

declare(strict_types=1);

namespace App\Livewire\Charts;

use App\Domain\Shared\Period;
use App\Models\DailyRecord;
use App\Queries\ChartSeriesQuery;
use App\Support\CurrencyContext;
use App\Support\CurrentBranch;
use App\Support\PeriodContext;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
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
    ];

    /** Familias que llegan en fases posteriores (G10 tasa, G11 año). */
    public const SOON = ['tasa' => 'Tasa', 'anio' => 'Año'];

    #[Url]
    public string $tab = 'ventas';

    public string $period = '';

    /** @var array<string, array<string, mixed>> */
    public array $specs = [];

    public function mount(): void
    {
        $this->authorize('viewAny', DailyRecord::class);
        $this->period = app(PeriodContext::class)->current()->key();
        $this->normalizeTab();
    }

    public function updatedTab(): void
    {
        $this->normalizeTab();
    }

    #[On('context-changed')]
    public function refreshContext(): void
    {
        $this->period = app(PeriodContext::class)->current()->key();
    }

    public function render(ChartSeriesQuery $query, CurrencyContext $currency): View
    {
        $user = auth()->user();
        $branch = app(CurrentBranch::class)->resolve($user);
        $period = Period::of($this->period);

        $this->specs = $query->specs($branch?->id, $period, $this->chartsFor($this->tab, $currency));

        return view('livewire.charts.charts-page', [
            'branch' => $branch,
            'periodLabel' => $period->label(),
            'tabs' => self::TABS,
            'soon' => self::SOON,
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

    private function normalizeTab(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'ventas';
        }
    }
}
