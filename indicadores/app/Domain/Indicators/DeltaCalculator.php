<?php

declare(strict_types=1);

namespace App\Domain\Indicators;

use App\Domain\Shared\Decimal;

/**
 * Variaciones de un período frente a otro (§7.2). Puro: recibe resúmenes ya calculados.
 * La equivalencia de días (mes en curso vs mismos días del mes anterior) la resuelve quien
 * construye el resumen de referencia; aquí solo se anota en `comparedDays`.
 */
final class DeltaCalculator
{
    /**
     * @param  iterable<Indicator>  $indicators
     * @return array<string, Delta> indexado por `Indicator::value`
     */
    public function compare(PeriodSummary $current, ?PeriodSummary $previous, string $against, iterable $indicators, ?int $comparedDays = null): array
    {
        $deltas = [];
        foreach ($indicators as $indicator) {
            $deltas[$indicator->value] = $this->delta($indicator, $current, $previous, $against, $comparedDays);
        }

        return $deltas;
    }

    public function delta(Indicator $indicator, PeriodSummary $current, ?PeriodSummary $previous, string $against, ?int $comparedDays = null): Delta
    {
        $now = $current->value($indicator);
        $before = $previous === null || $previous->isEmpty() ? null : $previous->value($indicator);

        $usd = $this->usdCounterpart($indicator);
        $usdVariation = $usd === null || $previous === null || $previous->isEmpty()
            ? null
            : Decimal::variation($previous->value($usd), $current->value($usd));

        return new Delta(
            indicator: $indicator,
            current: $now,
            previous: $before,
            variation: Decimal::variation($before, $now),
            against: $against,
            comparedDays: $comparedDays,
            usdVariation: $usdVariation,
        );
    }

    /** Para un indicador en Bs, el equivalente en $ que aísla el efecto de la tasa (§7.2). */
    private function usdCounterpart(Indicator $indicator): ?Indicator
    {
        return match ($indicator) {
            Indicator::SalesBs => Indicator::SalesUsd,
            Indicator::AvgTicketBs => Indicator::AvgTicketUsd,
            default => null,
        };
    }
}
