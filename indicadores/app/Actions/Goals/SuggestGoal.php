<?php

declare(strict_types=1);

namespace App\Actions\Goals;

use App\Domain\Indicators\DailyRecordData;
use App\Domain\Indicators\Indicator;
use App\Domain\Indicators\IndicatorCalculator;
use App\Domain\Shared\Decimal;
use App\Domain\Shared\Period;
use App\Models\DailyRecord;
use App\Models\Setting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Sugerencia de meta (§8.1): promedio del indicador en los últimos tres meses con datos
 * × (1 + crecimiento configurable `goal_growth_pct`). Null sin histórico.
 */
final class SuggestGoal
{
    private const MONTHS = 3;

    public function __construct(private readonly IndicatorCalculator $calculator) {}

    public function for(?int $branchId, Indicator $indicator, Period $period): ?BigDecimal
    {
        $values = [];
        $cursor = $period;
        $lookedBack = 0;

        while (count($values) < self::MONTHS && $lookedBack < 12) {
            $cursor = $cursor->previous();
            $lookedBack++;

            $records = DailyRecord::query()->forBranch($branchId)->forPeriod($cursor)->orderBy('date')->get();
            if ($records->isEmpty()) {
                continue;
            }

            $value = $this->calculator
                ->summarize($records->map(fn (DailyRecord $r) => DailyRecordData::fromModel($r))->all())
                ->value($indicator);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        $average = Decimal::average($values);
        if ($average === null) {
            return null;
        }

        $growth = BigDecimal::of((string) ((int) Setting::get('goal_growth_pct', $branchId)))->dividedBy(100, 4, RoundingMode::HalfUp);

        return $average->multipliedBy(BigDecimal::one()->plus($growth))->toScale($indicator->precision(), RoundingMode::HalfUp);
    }
}
