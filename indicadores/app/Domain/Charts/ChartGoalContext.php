<?php

declare(strict_types=1);

namespace App\Domain\Charts;

use App\Domain\Goals\GoalProgress;

/**
 * Lo que las gráficas necesitan de las metas (§14): meta diaria esperada por indicador (G1/G2)
 * y las curvas acumuladas de la venta en dólares (G9).
 */
final readonly class ChartGoalContext
{
    /**
     * @param  array<string, list<float|null>>  $dailyExpected  por `Indicator::value`, un valor por día del mes
     * @param  array{dates: list<string>, actual: list<float|null>, expected: list<float|null>, projected: list<float|null>, cutoffIndex: int, target: float, method: string}|null  $cumulative
     */
    public function __construct(
        public array $dailyExpected = [],
        public ?array $cumulative = null,
    ) {}

    /**
     * @param  array<string, GoalProgress>  $progress  por `Indicator::value`
     */
    public static function fromProgress(array $progress, string $cumulativeIndicator = 'sales_usd'): self
    {
        $daily = [];
        $cumulative = null;

        foreach ($progress as $key => $item) {
            if (! $item->hasGoal() || $item->series === null) {
                continue;
            }
            $daily[$key] = $item->series['daily'];
            if ($key === $cumulativeIndicator && $item->target !== null) {
                $cumulative = [
                    'dates' => $item->series['dates'],
                    'actual' => $item->series['actual'],
                    'expected' => $item->series['expected'],
                    'projected' => $item->series['projected'],
                    'cutoffIndex' => $item->series['cutoffIndex'],
                    'target' => $item->target->toFloat(),
                    'method' => $item->method,
                ];
            }
        }

        return new self($daily, $cumulative);
    }
}
