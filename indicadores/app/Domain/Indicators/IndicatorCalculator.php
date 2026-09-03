<?php

declare(strict_types=1);

namespace App\Domain\Indicators;

use App\Domain\Shared\Decimal;
use Brick\Math\BigDecimal;

/**
 * Motor de indicadores (§6.3). Puro: recibe DTOs y devuelve DTOs; se prueba sin base de datos
 * contra los valores dorados de §2.6.
 */
final class IndicatorCalculator
{
    /**
     * @param  iterable<DailyRecordData>  $records
     * @return list<DailyMetrics>
     */
    public function daily(iterable $records): array
    {
        $metrics = [];
        foreach ($records as $record) {
            $metrics[] = DailyMetrics::derive($record);
        }

        usort($metrics, fn (DailyMetrics $a, DailyMetrics $b) => $a->data->date <=> $b->data->date);

        return $metrics;
    }

    /**
     * Agregados del período. Con `$excludeAtypical`, los días atípicos salen de ratios y
     * promedios pero **no** de las sumas (RN-11). Los días cerrados nunca entran en ratios.
     *
     * @param  iterable<DailyRecordData>  $records
     */
    public function summarize(iterable $records, bool $excludeAtypical = false): PeriodSummary
    {
        $all = $this->daily($records);

        if ($all === []) {
            return PeriodSummary::empty();
        }

        $forRatios = array_values(array_filter(
            $all,
            fn (DailyMetrics $m) => ! $m->data->isClosed() && ! ($excludeAtypical && $m->data->isAtypical()),
        ));

        $sumsAll = $this->sums($all);
        $sumsFiltered = $this->sums($forRatios);

        $withInventory = array_values(array_filter($all, fn (DailyMetrics $m) => ! $m->data->isClosed() && $m->data->hasInventory()));
        $lastInventory = $withInventory === [] ? null : end($withInventory);

        $rates = array_map(fn (DailyMetrics $m) => $m->data->rate, $all);
        $rateFirst = $rates[0];
        $rateLast = $rates[array_key_last($rates)];

        return new PeriodSummary(
            days: count($all),
            daysWithInventory: count($withInventory),
            excludedAtypical: $excludeAtypical ? count(array_filter($all, fn (DailyMetrics $m) => $m->data->isAtypical())) : 0,
            closedDays: count(array_filter($all, fn (DailyMetrics $m) => $m->data->isClosed())),
            sumsAll: $sumsAll,
            sumsFiltered: $sumsFiltered,
            avgTicketBs: Decimal::divide($sumsFiltered['salesBs'], $sumsFiltered['transactions']),
            avgTicketUsd: Decimal::divide($sumsFiltered['salesUsd'], $sumsFiltered['transactions']),
            unitsPerTransaction: Decimal::divide($sumsFiltered['units'], $sumsFiltered['transactions']),
            transactionsPerShift: Decimal::divide($sumsFiltered['transactions'], $sumsFiltered['shifts']),
            salesPerShiftUsd: Decimal::divide($sumsFiltered['salesUsd'], $sumsFiltered['shifts']),
            avgRate: Decimal::divide($sumsFiltered['salesBs'], $sumsFiltered['salesUsd']),
            avgRateSimple: Decimal::average($rates),
            inventoryAvgUnits: Decimal::average(array_map(fn (DailyMetrics $m) => $m->data->inventoryUnits, $withInventory), 1),
            inventoryAvgValueUsd: Decimal::average(array_map(fn (DailyMetrics $m) => $m->data->inventoryValueUsd, $withInventory), 2),
            inventoryLastUnits: $lastInventory?->data->inventoryUnits,
            inventoryLastValueUsd: $lastInventory?->data->inventoryValueUsd,
            rateFirst: $rateFirst,
            rateLast: $rateLast,
            rateVariationPct: Decimal::variation($rateFirst, $rateLast),
        );
    }

    /**
     * @param  list<DailyMetrics>  $metrics
     * @return array{salesBs: BigDecimal, salesUsd: BigDecimal, transactions: int, units: int, shifts: int}
     */
    private function sums(array $metrics): array
    {
        return [
            'salesBs' => Decimal::sum(array_map(fn (DailyMetrics $m) => $m->data->salesBs, $metrics)),
            'salesUsd' => Decimal::sum(array_map(fn (DailyMetrics $m) => $m->salesUsd, $metrics)),
            'transactions' => array_sum(array_map(fn (DailyMetrics $m) => $m->data->transactions, $metrics)),
            'units' => array_sum(array_map(fn (DailyMetrics $m) => $m->data->units, $metrics)),
            'shifts' => array_sum(array_map(fn (DailyMetrics $m) => $m->data->shifts, $metrics)),
        ];
    }
}
