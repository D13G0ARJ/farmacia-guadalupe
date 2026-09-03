<?php

declare(strict_types=1);

namespace App\Domain\Indicators;

use App\Domain\Shared\Decimal;
use Brick\Math\BigDecimal;

/**
 * Un día con sus cinco derivados calculados (§6.2, RN-03).
 * Las divisiones con denominador cero devuelven null, como el "-" del Excel.
 */
final readonly class DailyMetrics
{
    private function __construct(
        public DailyRecordData $data,
        public int $weekday,
        public ?BigDecimal $salesUsd,
        public ?BigDecimal $avgTicketBs,
        public ?BigDecimal $avgTicketUsd,
        public ?BigDecimal $unitsPerTransaction,
        public ?BigDecimal $transactionsPerShift,
        public ?BigDecimal $salesPerShiftUsd,
    ) {}

    public static function derive(DailyRecordData $d): self
    {
        $salesUsd = Decimal::divide($d->salesBs, $d->rate);

        return new self(
            data: $d,
            weekday: $d->date->dayOfWeekIso,
            salesUsd: $salesUsd,
            avgTicketBs: Decimal::divide($d->salesBs, $d->transactions),
            avgTicketUsd: Decimal::divide($salesUsd, $d->transactions),
            unitsPerTransaction: Decimal::divide($d->units, $d->transactions),
            transactionsPerShift: Decimal::divide($d->transactions, $d->shifts),
            salesPerShiftUsd: Decimal::divide($salesUsd, $d->shifts),
        );
    }

    /** Valor del día para un indicador; null si no aplica o no es calculable. */
    public function value(Indicator $indicator): ?BigDecimal
    {
        return match ($indicator) {
            Indicator::SalesBs => $this->data->salesBs,
            Indicator::SalesUsd => $this->salesUsd,
            Indicator::Transactions => BigDecimal::of($this->data->transactions),
            Indicator::Units => BigDecimal::of($this->data->units),
            Indicator::AvgTicketBs => $this->avgTicketBs,
            Indicator::AvgTicketUsd => $this->avgTicketUsd,
            Indicator::UnitsPerTransaction => $this->unitsPerTransaction,
            Indicator::TransactionsPerShift => $this->transactionsPerShift,
            Indicator::Shifts => BigDecimal::of($this->data->shifts),
            Indicator::AvgRate => $this->data->rate,
            Indicator::InventoryUnits => $this->data->inventoryUnits === null ? null : BigDecimal::of($this->data->inventoryUnits),
            Indicator::InventoryValueUsd => $this->data->inventoryValueUsd,
            Indicator::SalesPerShiftUsd => $this->salesPerShiftUsd,
            Indicator::RateVariationPct => null,
        };
    }
}
