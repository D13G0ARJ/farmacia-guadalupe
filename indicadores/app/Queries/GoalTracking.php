<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Goals\GoalProgress;
use App\Domain\Shared\Period;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/** Seguimiento de todas las metas de un mes (UC-12). */
final readonly class GoalTracking
{
    /**
     * @param  array<string, GoalProgress>  $progress  por `Indicator::value`, todos los indicadores con meta posible
     * @param  array<string, BigDecimal>  $targets  metas definidas por `Indicator::value`
     */
    public function __construct(
        public Period $period,
        public ?int $branchId,
        public CarbonImmutable $cutoff,
        public bool $patternReliable,
        public int $patternWeeks,
        public array $progress,
        public array $targets,
    ) {}

    public function for(string $indicator): ?GoalProgress
    {
        return $this->progress[$indicator] ?? null;
    }

    public function hasAnyGoal(): bool
    {
        return $this->targets !== [];
    }
}
