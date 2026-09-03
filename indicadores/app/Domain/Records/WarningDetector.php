<?php

declare(strict_types=1);

namespace App\Domain\Records;

use App\Domain\Shared\Decimal;
use App\Domain\Shared\Formatter;
use App\Enums\Currency;
use Brick\Math\BigDecimal;

/**
 * Advertencias blandas (RN-16) con la comparación concreta en el mensaje (§13.6).
 * Puro: recibe el día y su contexto, devuelve la lista. Se muestra mientras se escribe.
 */
final class WarningDetector
{
    public function __construct(private readonly Formatter $formatter) {}

    /** @return list<Warning> */
    public function detect(DailyRecordInput $input, WarningContext $ctx): array
    {
        $warnings = [];

        $rateWarning = $this->rateWarning($input, $ctx);
        if ($rateWarning !== null) {
            $warnings[] = $rateWarning;
        }

        $salesWarning = $this->salesWarning($input, $ctx);
        if ($salesWarning !== null) {
            $warnings[] = $salesWarning;
        }

        if ($input->units < $input->transactions && $input->transactions > 0) {
            $warnings[] = new Warning('units_lt_transactions', 'units',
                "Hay menos unidades ({$this->formatter->number($input->units)}) que transacciones ({$this->formatter->number($input->transactions)}).");
        }

        if ($input->transactions > 0 && $input->salesBs->isZero()) {
            $warnings[] = new Warning('sales_zero_with_transactions', 'sales_bs',
                "Hay {$this->formatter->number($input->transactions)} transacciones pero la venta es cero.");
        }

        if ($input->salesBs->isPositive() && $input->transactions === 0) {
            $warnings[] = new Warning('transactions_zero_with_sales', 'transactions',
                'Hay venta pero ninguna transacción registrada.');
        }

        if ($ctx->countsInventoryToday && $input->inventoryUnits === null && $input->inventoryValueUsd === null) {
            $warnings[] = new Warning('inventory_missing', 'inventory_units',
                'Hoy toca conteo de inventario y está vacío. Puedes guardar igual.');
        }

        return $warnings;
    }

    private function rateWarning(DailyRecordInput $input, WarningContext $ctx): ?Warning
    {
        if ($input->rate === null || $ctx->previous === null) {
            return null;
        }

        $variation = Decimal::variation($ctx->previous->rate, $input->rate);
        if ($variation === null) {
            return null;
        }

        $threshold = BigDecimal::of($ctx->rateDeviationPct)->dividedBy(100, 4);
        if (! $variation->abs()->isGreaterThan($threshold)) {
            return null;
        }

        $direction = $variation->isNegative() ? 'menor' : 'mayor';
        $pct = $this->formatter->pct($variation->abs(), 0, signed: false);
        $previous = $this->formatter->number($ctx->previous->rate, 2);

        return new Warning('rate_deviation', 'rate', "Es {$pct} {$direction} que ayer ({$previous}). Revísala.");
    }

    private function salesWarning(DailyRecordInput $input, WarningContext $ctx): ?Warning
    {
        if ($ctx->recentSalesBs === []) {
            return null;
        }

        $average = Decimal::average($ctx->recentSalesBs, 2);
        $variation = Decimal::variation($average, $input->salesBs);
        if ($average === null || $variation === null) {
            return null;
        }

        $threshold = BigDecimal::of($ctx->salesDeviationPct)->dividedBy(100, 4);
        if (! $variation->abs()->isGreaterThan($threshold)) {
            return null;
        }

        $direction = $variation->isNegative() ? 'menor' : 'mayor';
        $pct = $this->formatter->pct($variation->abs(), 0, signed: false);
        $days = count($ctx->recentSalesBs);
        $avg = $this->formatter->money($average, Currency::Bs, 0);

        return new Warning('sales_deviation', 'sales_bs', "Es {$pct} {$direction} que el promedio de los últimos {$days} días ({$avg}).");
    }
}
