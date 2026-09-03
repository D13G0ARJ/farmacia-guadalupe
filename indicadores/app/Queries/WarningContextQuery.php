<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Indicators\DailyRecordData;
use App\Domain\Records\WarningContext;
use App\Enums\DayStatus;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/** Reúne lo que necesita WarningDetector para un día de una sede (RN-16). */
final class WarningContextQuery
{
    private const RECENT_DAYS = 14;

    public function for(Branch $branch, CarbonImmutable $date): WarningContext
    {
        $recent = DailyRecord::query()
            ->forBranch($branch->id)
            ->where('date', '<', $date->toDateString())
            ->where('status', DayStatus::Normal)
            ->orderByDesc('date')
            ->limit(self::RECENT_DAYS)
            ->get();

        $previous = DailyRecord::query()
            ->forBranch($branch->id)
            ->where('date', '<', $date->toDateString())
            ->where('status', '!=', DayStatus::Closed)
            ->orderByDesc('date')
            ->first();

        return new WarningContext(
            previous: $previous === null ? null : DailyRecordData::fromModel($previous),
            recentSalesBs: $recent->map(fn (DailyRecord $r) => $r->sales_bs)->values()->all(),
            countsInventoryToday: $branch->countsInventoryOn($date),
            salesDeviationPct: (int) ($branch->sales_deviation_pct ?? Setting::get('sales_deviation_pct', $branch->id)),
            rateDeviationPct: (int) Setting::get('rate_deviation_pct'),
        );
    }
}
