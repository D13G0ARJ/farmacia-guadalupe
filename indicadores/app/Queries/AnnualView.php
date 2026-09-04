<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Indicators\Indicator;
use App\Domain\Indicators\PeriodSummary;
use App\Domain\Shared\Decimal;
use Brick\Math\BigDecimal;

/**
 * Tabla anual (UC-13, §2.4): 12 resúmenes mensuales del año, los del año anterior para comparar y
 * el agregado del año calculado sobre todos los días (ponderado, nunca promedio de promedios).
 */
final readonly class AnnualView
{
    /**
     * @param  array<int, PeriodSummary>  $months  1..12
     * @param  array<int, PeriodSummary>  $previousYear  1..12 del año anterior
     * @param  list<int>  $years  años con datos, para el selector
     */
    public function __construct(
        public int $year,
        public ?int $branchId,
        public array $months,
        public array $previousYear,
        public PeriodSummary $annual,
        public array $years,
    ) {}

    public function hasData(int $month): bool
    {
        return isset($this->months[$month]) && ! $this->months[$month]->isEmpty();
    }

    public function value(Indicator $indicator, int $month): ?BigDecimal
    {
        return $this->hasData($month) ? $this->months[$month]->value($indicator) : null;
    }

    public function previousYearValue(Indicator $indicator, int $month): ?BigDecimal
    {
        $summary = $this->previousYear[$month] ?? null;

        return $summary === null || $summary->isEmpty() ? null : $summary->value($indicator);
    }

    public function yearValue(Indicator $indicator): ?BigDecimal
    {
        return $this->annual->isEmpty() ? null : $this->annual->value($indicator);
    }

    /** Variación frente al mes anterior (diciembre del año previo para enero). */
    public function vsPreviousMonth(Indicator $indicator, int $month): ?BigDecimal
    {
        $previous = $month === 1 ? $this->previousYearValue($indicator, 12) : $this->value($indicator, $month - 1);

        return Decimal::variation($previous, $this->value($indicator, $month));
    }

    public function vsLastYear(Indicator $indicator, int $month): ?BigDecimal
    {
        return Decimal::variation($this->previousYearValue($indicator, $month), $this->value($indicator, $month));
    }

    public function monthsWithData(): int
    {
        return count(array_filter(range(1, 12), fn (int $m) => $this->hasData($m)));
    }

    public function isEmpty(): bool
    {
        return $this->monthsWithData() === 0;
    }
}
