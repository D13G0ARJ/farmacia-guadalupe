<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Goals\GoalProgress;
use App\Domain\Indicators\Delta;
use App\Domain\Indicators\PeriodSummary;
use App\Domain\Shared\Period;

/**
 * Modelo de lectura del panel (UC-08): el mes, sus variaciones, las sparklines de 14 días,
 * los avisos y las gráficas del panel (G2 y G8).
 */
final readonly class DashboardView
{
    /**
     * @param  array<string, Delta>  $vsPrevious  por `Indicator::value`, frente al mes anterior
     * @param  array<string, Delta>  $vsYear  frente al mismo mes del año anterior
     * @param  array<string, list<float>>  $sparklines  últimos 14 días cargados por indicador
     * @param  list<array{tone: string, icon: string, text: string, href: string|null, action: string|null}>  $notices
     * @param  array<string, array<string, mixed>>  $charts  especificaciones de ECharts por id
     * @param  int|null  $partialDays  días comparados cuando el mes en curso está incompleto (§7.2)
     */
    public function __construct(
        public Period $period,
        public ?int $branchId,
        public MonthView $month,
        public array $vsPrevious,
        public array $vsYear,
        public array $sparklines,
        public array $notices,
        public array $charts,
        public ?int $partialDays,
        public GoalTracking $goals,
    ) {}

    /** Seguimiento de la meta principal (venta en dólares) para el héroe (§13.5). */
    public function salesGoal(): ?GoalProgress
    {
        return $this->goals->for('sales_usd');
    }

    public function summary(): PeriodSummary
    {
        return $this->month->summary;
    }

    public function isEmpty(): bool
    {
        return $this->month->loadedDays() === 0;
    }
}
