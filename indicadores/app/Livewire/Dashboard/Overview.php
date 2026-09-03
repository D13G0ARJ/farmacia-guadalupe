<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Domain\Indicators\Indicator;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Models\DailyRecord;
use App\Models\Goal;
use App\Queries\DashboardQuery;
use App\Support\CurrencyContext;
use App\Support\CurrentBranch;
use App\Support\PeriodContext;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Panel principal (UC-08, §13.7): héroe del mes, KPI con variaciones y sparklines, fila secundaria,
 * venta diaria y mapa de calor, avisos del mes. El héroe con meta y proyección llega en la Fase 3.
 */
#[Layout('layouts.app')]
#[Title('Panel')]
class Overview extends Component
{
    use AuthorizesRequests;

    public string $period = '';

    /** @var array<string, array<string, mixed>> especificaciones de ECharts que leen los paneles (Alpine `$wire.specs`) */
    public array $specs = [];

    public function mount(): void
    {
        $this->authorize('viewAny', DailyRecord::class);

        // Inicio por rol (§13.8): el operador aterriza en Mes; el panel es de supervisión y dirección.
        $home = auth()->user()->homeRoute();
        if ($home !== 'dashboard') {
            $this->redirectRoute($home);

            return;
        }

        $this->period = app(PeriodContext::class)->current()->key();
    }

    #[On('context-changed')]
    public function refreshContext(): void
    {
        $this->period = app(PeriodContext::class)->current()->key();
    }

    public function render(DashboardQuery $query, Formatter $formatter, CurrencyContext $currency): View
    {
        $user = auth()->user();
        $branch = app(CurrentBranch::class)->resolve($user);
        $period = Period::of($this->period);
        $dashboard = $query->run($branch?->id, $period);

        $this->specs = $dashboard->charts;

        return view('livewire.dashboard.overview', [
            'dashboard' => $dashboard,
            'view' => $dashboard->month,
            'branch' => $branch,
            'primary' => $this->primary($currency),
            'secondary' => $this->secondary($currency),
            'formatter' => $formatter,
            'canCreate' => $branch !== null && $user->can('create', [DailyRecord::class, $branch]),
            'canSeeGoals' => $user->can('viewAny', Goal::class),
            'canManageGoals' => $user->can('manage', [Goal::class, $branch]),
        ]);
    }

    /**
     * Tarjetas primarias: con la moneda en Bs, la venta y el ticket se muestran en Bs en grande
     * y en $ debajo (§13.8).
     *
     * @return list<Indicator>
     */
    private function primary(CurrencyContext $currency): array
    {
        return $currency->current() === CurrencyContext::BS
            ? [Indicator::SalesBs, Indicator::Transactions, Indicator::AvgTicketBs, Indicator::UnitsPerTransaction]
            : Indicator::primary();
    }

    /** @return list<Indicator> */
    private function secondary(CurrencyContext $currency): array
    {
        return $currency->current() === CurrencyContext::BS
            ? [Indicator::SalesUsd, Indicator::Units, Indicator::TransactionsPerShift, Indicator::InventoryValueUsd]
            : Indicator::secondary();
    }
}
