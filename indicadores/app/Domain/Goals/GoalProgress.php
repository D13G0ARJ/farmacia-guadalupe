<?php

declare(strict_types=1);

namespace App\Domain\Goals;

use App\Domain\Indicators\Indicator;
use App\Domain\Shared\Decimal;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Seguimiento de una meta a la fecha de corte (§8.2): avance, esperado, proyección, brecha y estado.
 * `series` trae las curvas acumuladas para G9 y la meta diaria para G2; solo en indicadores que suman.
 */
final readonly class GoalProgress
{
    /**
     * @param  'weekday'|'linear'|'ratio'  $method
     * @param  array{dates: list<string>, actual: list<float|null>, expected: list<float|null>, projected: list<float|null>, daily: list<float|null>, cutoffIndex: int}|null  $series
     */
    public function __construct(
        public Indicator $indicator,
        public ?BigDecimal $target,
        public ?BigDecimal $actual,
        public ?BigDecimal $expected,
        public ?BigDecimal $projection,
        public ?BigDecimal $gap,
        public GoalStatus $status,
        public string $method,
        public int $daysElapsed,
        public int $daysRemaining,
        public CarbonImmutable $cutoff,
        public ?array $series,
        public ?int $strongestDay,
        public int $remainingStrongDays,
        public int $patternWeeks,
    ) {}

    public function hasGoal(): bool
    {
        return $this->target !== null;
    }

    /** actual / meta (fracción). */
    public function pctOfTarget(): ?BigDecimal
    {
        return Decimal::divide($this->actual, $this->target);
    }

    /** actual / esperado a la fecha (fracción): "vas al 104 % de lo esperado". */
    public function pctOfExpected(): ?BigDecimal
    {
        return Decimal::divide($this->actual, $this->expected);
    }

    /** proyección / meta (fracción): "al ritmo actual cierra en 94 %". */
    public function pctProjected(): ?BigDecimal
    {
        return Decimal::divide($this->projection, $this->target);
    }

    /** esperado / meta (fracción): posición de la marca "esperado hoy" en la barra. */
    public function expectedShare(): ?BigDecimal
    {
        return Decimal::divide($this->expected, $this->target);
    }

    public function isComplete(): bool
    {
        return $this->daysRemaining === 0;
    }

    public function accumulates(): bool
    {
        return $this->method !== 'ratio';
    }
}
