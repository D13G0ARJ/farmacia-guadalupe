<?php

declare(strict_types=1);

namespace App\Domain\Goals;

use App\Domain\Indicators\Aggregation;
use App\Domain\Indicators\DailyMetrics;
use App\Domain\Indicators\Indicator;
use App\Domain\Indicators\IndicatorCalculator;
use App\Domain\Indicators\WeekdayPattern;
use App\Domain\Shared\Period;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;

/**
 * Proyección de metas (§8.2, RN-18). Puro.
 *
 * Indicadores que suman: `actual` = Σ a la fecha de corte (los atípicos cuentan en el total pero no
 * marcan el ritmo); `expected` = meta × cuota de peso transcurrida; `projection` = actual + ritmo ×
 * peso restante. Con patrón semanal fiable los pesos son los del patrón; si no, cada día pesa 1 (lineal).
 * Ratios (ticket, unidades por compra): el valor ponderado a la fecha no acumula; el estado sale de actual/meta.
 */
final class GoalProjector
{
    private const SCALE = 6;

    public function __construct(private readonly IndicatorCalculator $calculator) {}

    /** @param  list<DailyMetrics>  $rows  filas del mes ordenadas por fecha */
    public function project(
        Indicator $indicator,
        ?BigDecimal $target,
        array $rows,
        Period $period,
        WeekdayPattern $pattern,
        CarbonInterface $cutoff,
        int $onTrackPct = 100,
        int $atRiskPct = 90,
    ): GoalProgress {
        $target = $target !== null && $target->isPositive() ? $target : null;
        $cutoff = CarbonImmutable::instance($cutoff)->startOfDay();
        if ($cutoff->gt($period->end())) {
            $cutoff = $period->end();
        }

        $dates = $period->dates();
        $elapsed = array_values(array_filter($dates, fn (CarbonImmutable $d) => $d->lte($cutoff)));
        $remaining = array_values(array_filter($dates, fn (CarbonImmutable $d) => $d->gt($cutoff)));
        $toDate = array_values(array_filter($rows, fn (DailyMetrics $m) => $m->data->date->lte($cutoff)));

        if ($indicator->aggregation() === Aggregation::WeightedRatio) {
            return $this->projectRatio($indicator, $target, $toDate, $cutoff, count($elapsed), count($remaining), $onTrackPct, $atRiskPct, $pattern);
        }

        $method = $pattern->isReliable() ? 'weekday' : 'linear';
        $weight = fn (CarbonInterface $d): BigDecimal => $method === 'weekday' ? $pattern->weightFor($d) : BigDecimal::one();

        $actual = BigDecimal::zero();
        $baseValue = BigDecimal::zero();
        $baseWeight = BigDecimal::zero();
        foreach ($toDate as $m) {
            $value = $m->data->isClosed() ? null : $m->value($indicator);
            if ($value === null) {
                continue;
            }
            $actual = $actual->plus($value);
            if (! $m->data->isAtypical()) {
                $baseValue = $baseValue->plus($value);
                $baseWeight = $baseWeight->plus($weight($m->data->date));
            }
        }

        $rate = $baseWeight->isZero() ? null : $baseValue->dividedBy($baseWeight, self::SCALE, RoundingMode::HalfUp);
        $totalWeight = $this->sumWeights($dates, $weight);
        $elapsedWeight = $this->sumWeights($elapsed, $weight);
        $remainingWeight = $this->sumWeights($remaining, $weight);

        $projection = $rate === null ? $actual : $actual->plus($rate->multipliedBy($remainingWeight));
        $share = $totalWeight->isZero() ? BigDecimal::zero() : $elapsedWeight->dividedBy($totalWeight, self::SCALE, RoundingMode::HalfUp);
        $expected = $target?->multipliedBy($share);
        $hasData = $toDate !== [];

        $strongest = $pattern->strongestDay();

        return new GoalProgress(
            indicator: $indicator,
            target: $target,
            actual: $hasData ? $actual : null,
            expected: $expected,
            projection: $hasData ? $projection : null,
            gap: $target === null || ! $hasData ? null : $target->minus($projection),
            status: $this->status($target, $hasData ? $projection : null, $onTrackPct, $atRiskPct),
            method: $method,
            daysElapsed: count($elapsed),
            daysRemaining: count($remaining),
            cutoff: $cutoff,
            series: $this->series($indicator, $target, $toDate, $dates, $cutoff, $weight, $totalWeight, $rate),
            strongestDay: $method === 'weekday' ? $strongest : null,
            remainingStrongDays: $method === 'weekday' ? count(array_filter($remaining, fn (CarbonImmutable $d) => $d->dayOfWeekIso === $strongest)) : 0,
            patternWeeks: $pattern->weeksObserved,
        );
    }

    /** @param  list<DailyMetrics>  $toDate */
    private function projectRatio(Indicator $indicator, ?BigDecimal $target, array $toDate, CarbonImmutable $cutoff, int $daysElapsed, int $daysRemaining, int $onTrackPct, int $atRiskPct, WeekdayPattern $pattern): GoalProgress
    {
        $summary = $this->calculator->summarize(array_map(fn (DailyMetrics $m) => $m->data, $toDate), excludeAtypical: true);
        $actual = $summary->isEmpty() ? null : $summary->value($indicator);

        return new GoalProgress(
            indicator: $indicator,
            target: $target,
            actual: $actual,
            expected: $target,
            projection: $actual,
            gap: $target === null || $actual === null ? null : $target->minus($actual),
            status: $this->status($target, $actual, $onTrackPct, $atRiskPct),
            method: 'ratio',
            daysElapsed: $daysElapsed,
            daysRemaining: $daysRemaining,
            cutoff: $cutoff,
            series: null,
            strongestDay: null,
            remainingStrongDays: 0,
            patternWeeks: $pattern->weeksObserved,
        );
    }

    private function status(?BigDecimal $target, ?BigDecimal $value, int $onTrackPct, int $atRiskPct): GoalStatus
    {
        if ($target === null) {
            return GoalStatus::NoGoal;
        }
        if ($value === null) {
            return GoalStatus::Pending;
        }

        $ratio = $value->dividedBy($target, self::SCALE, RoundingMode::HalfUp);

        if ($ratio->isGreaterThanOrEqualTo(BigDecimal::of($onTrackPct)->dividedBy(100, 4, RoundingMode::HalfUp))) {
            return GoalStatus::OnTrack;
        }
        if ($ratio->isGreaterThanOrEqualTo(BigDecimal::of($atRiskPct)->dividedBy(100, 4, RoundingMode::HalfUp))) {
            return GoalStatus::AtRisk;
        }

        return GoalStatus::OffTrack;
    }

    /**
     * Curvas para G9 (acumulado real, esperado, proyección) y meta diaria para G2 (§14).
     *
     * @param  list<DailyMetrics>  $toDate
     * @param  list<CarbonImmutable>  $dates
     * @param  Closure(CarbonInterface): BigDecimal  $weight
     * @return array{dates: list<string>, actual: list<float|null>, expected: list<float|null>, projected: list<float|null>, daily: list<float|null>, cutoffIndex: int}
     */
    private function series(Indicator $indicator, ?BigDecimal $target, array $toDate, array $dates, CarbonImmutable $cutoff, Closure $weight, BigDecimal $totalWeight, ?BigDecimal $rate): array
    {
        $byDate = [];
        foreach ($toDate as $m) {
            $byDate[$m->data->date->toDateString()] = $m->data->isClosed() ? null : $m->value($indicator);
        }

        $keys = [];
        $actual = [];
        $expected = [];
        $projected = [];
        $daily = [];
        $runningActual = BigDecimal::zero();
        $runningWeight = BigDecimal::zero();
        $afterCutoffWeight = BigDecimal::zero();
        $cutoffIndex = 0;
        $hasData = $toDate !== [];

        foreach ($dates as $i => $date) {
            $keys[] = $date->toDateString();
            $w = $weight($date);
            $runningWeight = $runningWeight->plus($w);
            $unitShare = $totalWeight->isZero() ? BigDecimal::zero() : $w->dividedBy($totalWeight, self::SCALE, RoundingMode::HalfUp);
            $cumShare = $totalWeight->isZero() ? BigDecimal::zero() : $runningWeight->dividedBy($totalWeight, self::SCALE, RoundingMode::HalfUp);

            $daily[] = $target?->multipliedBy($unitShare)->toFloat();
            $expected[] = $target?->multipliedBy($cumShare)->toFloat();

            if ($date->lte($cutoff)) {
                $cutoffIndex = $i;
                $value = $byDate[$date->toDateString()] ?? null;
                if ($value !== null) {
                    $runningActual = $runningActual->plus($value);
                }
                $actual[] = $hasData ? $runningActual->toFloat() : null;
                $projected[] = $date->equalTo($cutoff) && $hasData ? $runningActual->toFloat() : null;
            } else {
                $actual[] = null;
                $afterCutoffWeight = $afterCutoffWeight->plus($w);
                $projected[] = $rate === null || ! $hasData ? null : $runningActual->plus($rate->multipliedBy($afterCutoffWeight))->toFloat();
            }
        }

        return ['dates' => $keys, 'actual' => $actual, 'expected' => $expected, 'projected' => $projected, 'daily' => $daily, 'cutoffIndex' => $cutoffIndex];
    }

    /**
     * @param  list<CarbonImmutable>  $dates
     * @param  Closure(CarbonInterface): BigDecimal  $weight
     */
    private function sumWeights(array $dates, Closure $weight): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($dates as $date) {
            $sum = $sum->plus($weight($date));
        }

        return $sum;
    }
}
