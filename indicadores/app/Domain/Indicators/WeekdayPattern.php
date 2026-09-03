<?php

declare(strict_types=1);

namespace App\Domain\Indicators;

use App\Domain\Shared\Decimal;
use App\Domain\Shared\Period;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

/**
 * Peso relativo de cada día de la semana sobre la venta en divisa (§6.4, RN-18).
 * Se calcula sobre el histórico excluyendo días atípicos y cerrados. Si no hay
 * suficientes semanas completas, `isReliable()` es false y la proyección usa el método lineal.
 */
final class WeekdayPattern
{
    public const MIN_WEEKS = 8;

    /** @param  array<int, BigDecimal>  $weights  ISO 1..7 → peso (suman 1) */
    private function __construct(
        public readonly array $weights,
        public readonly int $weeksObserved,
        public readonly int $minWeeks,
    ) {}

    /** @param  iterable<DailyMetrics>  $metrics */
    public static function fromMetrics(iterable $metrics, int $minWeeks = self::MIN_WEEKS): self
    {
        $byDay = array_fill(1, 7, []);
        $weeks = [];

        foreach ($metrics as $m) {
            if ($m->data->isClosed() || $m->data->isAtypical() || $m->salesUsd === null) {
                continue;
            }
            $byDay[$m->weekday][] = $m->salesUsd;
            $weeks[$m->data->date->format('o-W')] = true;
        }

        $means = [];
        foreach ($byDay as $day => $values) {
            $means[$day] = Decimal::average($values) ?? BigDecimal::zero();
        }

        $total = Decimal::sum($means);
        $weights = [];
        foreach ($means as $day => $mean) {
            $weights[$day] = $total->isZero()
                ? BigDecimal::of('1')->dividedBy(7, 6, RoundingMode::HalfUp)
                : $mean->dividedBy($total, 6, RoundingMode::HalfUp);
        }

        return new self($weights, count($weeks), $minWeeks);
    }

    /** Patrón plano: todos los días pesan igual. */
    public static function flat(): self
    {
        $w = BigDecimal::of('1')->dividedBy(7, 6, RoundingMode::HalfUp);

        return new self(array_fill(1, 7, $w), 0, self::MIN_WEEKS);
    }

    public function isReliable(): bool
    {
        return $this->weeksObserved >= $this->minWeeks;
    }

    public function weightFor(CarbonInterface $date): BigDecimal
    {
        return $this->weights[$date->dayOfWeekIso];
    }

    /** Día ISO con mayor peso (1 = lunes … 7 = domingo). */
    public function strongestDay(): int
    {
        $best = 1;
        foreach ($this->weights as $day => $weight) {
            if ($weight->isGreaterThan($this->weights[$best])) {
                $best = $day;
            }
        }

        return $best;
    }

    /**
     * Fracción del peso del mes acumulada hasta `$upTo` inclusive (para "esperado a la fecha", §8.2).
     */
    public function shareOfPeriod(Period $period, CarbonInterface $upTo): BigDecimal
    {
        $elapsed = BigDecimal::zero();
        $total = BigDecimal::zero();

        foreach ($period->dates() as $date) {
            $w = $this->weightFor($date);
            $total = $total->plus($w);
            if ($date->lte($upTo)) {
                $elapsed = $elapsed->plus($w);
            }
        }

        return $total->isZero() ? BigDecimal::zero() : $elapsed->dividedBy($total, 6, RoundingMode::HalfUp);
    }

    /** Suma de pesos de los días del período posteriores a `$after`. */
    public function remainingWeight(Period $period, CarbonInterface $after): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($period->dates() as $date) {
            if ($date->gt($after)) {
                $sum = $sum->plus($this->weightFor($date));
            }
        }

        return $sum;
    }
}
