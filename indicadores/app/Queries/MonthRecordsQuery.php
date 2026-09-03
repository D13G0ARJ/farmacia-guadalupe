<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Indicators\DailyMetrics;
use App\Domain\Indicators\DailyRecordData;
use App\Domain\Indicators\IndicatorCalculator;
use App\Domain\Shared\Period;
use App\Models\DailyRecord;
use App\Models\PeriodEvent;
use App\Support\PeriodSummaryCache;
use Carbon\CarbonImmutable;

/**
 * Cuadro del mes (UC-09) y calendario (UC-06). `$branchId` null = consolidado (todas las sedes).
 * Los agregados salen de la cache (§7.3); las filas siempre se calculan (30 filas, trivial).
 */
final class MonthRecordsQuery
{
    public function __construct(
        private readonly IndicatorCalculator $calculator,
        private readonly PeriodSummaryCache $cache,
    ) {}

    public function run(?int $branchId, Period $period, bool $excludeAtypical = false): MonthView
    {
        $records = DailyRecord::query()
            ->forBranch($branchId)
            ->forPeriod($period)
            ->orderBy('date')
            ->orderBy('branch_id')
            ->get();

        $data = $records->map(fn (DailyRecord $r) => DailyRecordData::fromModel($r))->all();
        $rows = $this->calculator->daily($data);

        $summary = $this->cache->remember(
            $branchId,
            $period,
            $excludeAtypical,
            fn () => $this->calculator->summarize($data, $excludeAtypical),
        );

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row->data->date->toDateString()] = $row;
        }

        $today = CarbonImmutable::today();
        $missing = [];
        if ($branchId !== null) {
            foreach ($period->dates() as $date) {
                if ($date->lte($today) && ! isset($byDate[$date->toDateString()])) {
                    $missing[] = $date;
                }
            }
        }

        return new MonthView(
            period: $period,
            branchId: $branchId,
            rows: $rows,
            byDate: $byDate,
            summary: $summary,
            missingDates: $missing,
            isClosed: $branchId !== null && PeriodEvent::isClosed($branchId, $period),
            today: $today,
        );
    }

    /** Último día cargado antes de una fecha (referencia "Ayer:" del formulario, §13.5). */
    public function previousLoaded(int $branchId, CarbonImmutable $date): ?DailyMetrics
    {
        $record = DailyRecord::query()
            ->forBranch($branchId)
            ->where('date', '<', $date->toDateString())
            ->orderByDesc('date')
            ->first();

        return $record === null ? null : DailyMetrics::derive(DailyRecordData::fromModel($record));
    }
}
