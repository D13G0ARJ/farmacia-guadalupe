<?php

declare(strict_types=1);

namespace App\Livewire\Annual;

use App\Domain\Indicators\Indicator;
use App\Domain\Indicators\Unit;
use App\Domain\Shared\Formatter;
use App\Models\DailyRecord;
use App\Queries\AnnualComparisonQuery;
use App\Support\CurrencyContext;
use App\Support\CurrentBranch;
use App\Support\PeriodContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * UC-13: la tabla anual del Excel (12 indicadores × 12 meses) con total del año, variaciones al
 * pasar el cursor y exportación.
 */
#[Layout('layouts.app')]
#[Title('Año')]
class AnnualComparison extends Component
{
    use AuthorizesRequests;

    public const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    #[Url(as: 'anio')]
    public int $year = 0;

    public function mount(): void
    {
        $this->authorize('viewAny', DailyRecord::class);
        $this->normalizeYear();
    }

    public function updatedYear(): void
    {
        $this->normalizeYear();
    }

    #[On('context-changed')]
    public function refreshContext(): void
    {
        // Sede y moneda se leen en render; el año se elige aquí.
    }

    public function previousYear(): void
    {
        $this->year--;
        $this->normalizeYear();
    }

    public function nextYear(): void
    {
        $this->year++;
        $this->normalizeYear();
    }

    /** Un año fuera de rango (URL editada, flechas sin freno) vuelve al del período activo. */
    private function normalizeYear(): void
    {
        if ($this->year < 2000 || $this->year > 2100) {
            $this->year = app(PeriodContext::class)->current()->start->year;
        }
    }

    public function render(AnnualComparisonQuery $query, CurrencyContext $currency, Formatter $formatter): View
    {
        $user = auth()->user();
        $branch = app(CurrentBranch::class)->resolve($user);
        $annual = $query->run($branch?->id, $this->year);

        $rows = array_values(array_filter(Indicator::annualOrder(), fn (Indicator $i) => match ($i->unit()) {
            Unit::Bs => $currency->showsBs(),
            Unit::Usd => $currency->showsUsd() || $i === Indicator::InventoryValueUsd,
            default => true,
        }));

        return view('livewire.annual.annual-comparison', [
            'annual' => $annual,
            'branch' => $branch,
            'rows' => $rows,
            'months' => self::MONTHS,
            'formatter' => $formatter,
            'today' => CarbonImmutable::today(),
            'canExport' => $user->can('reports.export'),
        ]);
    }
}
