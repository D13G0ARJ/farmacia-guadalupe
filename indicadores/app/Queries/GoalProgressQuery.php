<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Goals\GoalProjector;
use App\Domain\Indicators\DailyRecordData;
use App\Domain\Indicators\Indicator;
use App\Domain\Indicators\IndicatorCalculator;
use App\Domain\Indicators\WeekdayPattern;
use App\Domain\Shared\Period;
use App\Models\DailyRecord;
use App\Models\Goal;
use App\Models\Setting;
use Brick\Math\BigDecimal;

/**
 * Seguimiento de metas del mes (UC-12): metas definidas, patrón semanal del histórico y proyección
 * por indicador. La fecha de corte es el último día cargado del mes en curso; en meses pasados, el fin de mes.
 */
final class GoalProgressQuery
{
    /** Meses de histórico para el patrón semanal (§6.4). */
    private const PATTERN_MONTHS = 6;

    public function __construct(
        private readonly MonthRecordsQuery $months,
        private readonly IndicatorCalculator $calculator,
        private readonly GoalProjector $projector,
    ) {}

    public function run(?int $branchId, Period $period, ?MonthView $month = null): GoalTracking
    {
        $month ??= $this->months->run($branchId, $period);
        $targets = $this->targets($branchId, $period);
        $pattern = $this->pattern($branchId, $period);

        $lastLoaded = $month->rows === [] ? null : $month->rows[array_key_last($month->rows)]->data->date;
        $cutoff = $period->contains($month->today)
            ? ($lastLoaded ?? $month->today)
            : ($month->today->lt($period->start) ? $period->start->subDay() : $period->end());

        $onTrack = (int) Setting::get('goal_on_track_pct', $branchId);
        $atRisk = (int) Setting::get('goal_at_risk_pct', $branchId);

        $progress = [];
        foreach (Indicator::cases() as $indicator) {
            if (! $indicator->supportsGoal()) {
                continue;
            }
            $progress[$indicator->value] = $this->projector->project(
                $indicator,
                $targets[$indicator->value] ?? null,
                $month->rows,
                $period,
                $pattern,
                $cutoff,
                $onTrack,
                $atRisk,
            );
        }

        return new GoalTracking(
            period: $period,
            branchId: $branchId,
            cutoff: $cutoff,
            patternReliable: $pattern->isReliable(),
            patternWeeks: $pattern->weeksObserved,
            progress: $progress,
            targets: $targets,
        );
    }

    /** @return array<string, BigDecimal> */
    public function targets(?int $branchId, Period $period): array
    {
        $targets = [];
        $goals = Goal::query()
            ->where('period', $period->start->toDateString())
            ->where(fn ($q) => $branchId === null ? $q->whereNull('branch_id') : $q->where('branch_id', $branchId))
            ->get();

        foreach ($goals as $goal) {
            $targets[$goal->indicator->value] = $goal->target;
        }

        return $targets;
    }

    private function pattern(?int $branchId, Period $period): WeekdayPattern
    {
        $from = $period->start->subMonths(self::PATTERN_MONTHS);
        $records = DailyRecord::query()
            ->forBranch($branchId)
            ->whereBetween('date', [$from->toDateString(), $period->end()->toDateString()])
            ->orderBy('date')
            ->get();

        return WeekdayPattern::fromMetrics(
            $this->calculator->daily($records->map(fn (DailyRecord $r) => DailyRecordData::fromModel($r))->all()),
        );
    }
}
