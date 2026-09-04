<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Indicators\DailyMetrics;
use App\Domain\Indicators\IndicatorCalculator;
use App\Domain\Shared\Period;
use App\Models\DailyRecord;
use Carbon\CarbonImmutable;

/**
 * Comparativa anual (UC-13): reutiliza los resúmenes mensuales en cache y agrega el año entero
 * sobre todos los días para que los ratios sigan ponderados (RN-04).
 */
final class AnnualComparisonQuery
{
    public function __construct(
        private readonly MonthRecordsQuery $months,
        private readonly IndicatorCalculator $calculator,
    ) {}

    public function run(?int $branchId, int $year): AnnualView
    {
        $summaries = [];
        $data = [];
        for ($m = 1; $m <= 12; $m++) {
            $view = $this->months->run($branchId, Period::of(sprintf('%d-%02d', $year, $m)));
            $summaries[$m] = $view->summary;
            foreach ($view->rows as $row) {
                /** @var DailyMetrics $row */
                $data[] = $row->data;
            }
        }

        $previous = [];
        for ($m = 1; $m <= 12; $m++) {
            $previous[$m] = $this->months->run($branchId, Period::of(sprintf('%d-%02d', $year - 1, $m)))->summary;
        }

        return new AnnualView(
            year: $year,
            branchId: $branchId,
            months: $summaries,
            previousYear: $previous,
            annual: $this->calculator->summarize($data),
            years: $this->years($branchId, $year),
        );
    }

    /**
     * Años con datos (siempre incluye el pedido y el actual).
     *
     * @return list<int>
     */
    private function years(?int $branchId, int $year): array
    {
        $query = DailyRecord::query()->forBranch($branchId);
        $min = $query->min('date');
        $max = DailyRecord::query()->forBranch($branchId)->max('date');

        $years = [$year, CarbonImmutable::today()->year];
        if ($min !== null && $max !== null) {
            $from = CarbonImmutable::parse((string) $min)->year;
            $to = CarbonImmutable::parse((string) $max)->year;
            for ($y = $from; $y <= $to; $y++) {
                $years[] = $y;
            }
        }
        $years = array_values(array_unique($years));
        sort($years);

        return $years;
    }
}
