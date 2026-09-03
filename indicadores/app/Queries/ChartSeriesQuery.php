<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Charts\ChartSpecBuilder;
use App\Domain\Shared\Period;

/** Especificaciones de ECharts para la pantalla de gráficas (UC-10, §14). */
final class ChartSeriesQuery
{
    public function __construct(
        private readonly MonthRecordsQuery $months,
        private readonly ChartSpecBuilder $builder,
    ) {}

    /**
     * @param  list<string>  $ids  identificadores del catálogo (g1…g8)
     * @return array<string, array<string, mixed>> por id, en el orden pedido
     */
    public function specs(?int $branchId, Period $period, array $ids): array
    {
        $month = $this->months->run($branchId, $period);

        $specs = [];
        foreach ($ids as $id) {
            $specs[$id] = $this->builder->build($id, $month->rows, $month->period)->toArray();
        }

        return $specs;
    }
}
