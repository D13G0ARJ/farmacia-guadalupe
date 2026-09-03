<?php

declare(strict_types=1);

namespace App\Domain\Indicators;

use Brick\Math\BigDecimal;

/**
 * Agregados de un período (§6.2, §2.3). Los ratios son ponderados (RN-04) y la venta en
 * divisa es la suma de las conversiones diarias (RN-05). Nunca un promedio de promedios.
 */
final readonly class PeriodSummary
{
    /**
     * @param  array{salesBs: BigDecimal, salesUsd: BigDecimal, transactions: int, units: int, shifts: int}  $sumsAll
     * @param  array{salesBs: BigDecimal, salesUsd: BigDecimal, transactions: int, units: int, shifts: int}  $sumsFiltered
     */
    public function __construct(
        public int $days,
        public int $daysWithInventory,
        public int $excludedAtypical,
        public int $closedDays,
        public array $sumsAll,
        public array $sumsFiltered,
        public ?BigDecimal $avgTicketBs,
        public ?BigDecimal $avgTicketUsd,
        public ?BigDecimal $unitsPerTransaction,
        public ?BigDecimal $transactionsPerShift,
        public ?BigDecimal $salesPerShiftUsd,
        public ?BigDecimal $avgRate,
        public ?BigDecimal $avgRateSimple,
        public ?BigDecimal $inventoryAvgUnits,
        public ?BigDecimal $inventoryAvgValueUsd,
        public ?int $inventoryLastUnits,
        public ?BigDecimal $inventoryLastValueUsd,
        public ?BigDecimal $rateFirst,
        public ?BigDecimal $rateLast,
        public ?BigDecimal $rateVariationPct,
    ) {}

    public static function empty(): self
    {
        $zero = ['salesBs' => BigDecimal::zero(), 'salesUsd' => BigDecimal::zero(), 'transactions' => 0, 'units' => 0, 'shifts' => 0];

        return new self(0, 0, 0, 0, $zero, $zero, null, null, null, null, null, null, null, null, null, null, null, null, null, null);
    }

    public function isEmpty(): bool
    {
        return $this->days === 0;
    }

    /**
     * Representación escalar para la cache: los stores de Laravel no deserializan objetos
     * (`cache.serializable_classes`), así que los decimales viajan como cadenas.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $sums = static fn (array $s): array => [
            'salesBs' => (string) $s['salesBs'],
            'salesUsd' => (string) $s['salesUsd'],
            'transactions' => $s['transactions'],
            'units' => $s['units'],
            'shifts' => $s['shifts'],
        ];
        $str = static fn (?BigDecimal $d): ?string => $d?->__toString();

        return [
            'days' => $this->days,
            'daysWithInventory' => $this->daysWithInventory,
            'excludedAtypical' => $this->excludedAtypical,
            'closedDays' => $this->closedDays,
            'sumsAll' => $sums($this->sumsAll),
            'sumsFiltered' => $sums($this->sumsFiltered),
            'avgTicketBs' => $str($this->avgTicketBs),
            'avgTicketUsd' => $str($this->avgTicketUsd),
            'unitsPerTransaction' => $str($this->unitsPerTransaction),
            'transactionsPerShift' => $str($this->transactionsPerShift),
            'salesPerShiftUsd' => $str($this->salesPerShiftUsd),
            'avgRate' => $str($this->avgRate),
            'avgRateSimple' => $str($this->avgRateSimple),
            'inventoryAvgUnits' => $str($this->inventoryAvgUnits),
            'inventoryAvgValueUsd' => $str($this->inventoryAvgValueUsd),
            'inventoryLastUnits' => $this->inventoryLastUnits,
            'inventoryLastValueUsd' => $str($this->inventoryLastValueUsd),
            'rateFirst' => $str($this->rateFirst),
            'rateLast' => $str($this->rateLast),
            'rateVariationPct' => $str($this->rateVariationPct),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $sums = static fn (array $s): array => [
            'salesBs' => BigDecimal::of($s['salesBs']),
            'salesUsd' => BigDecimal::of($s['salesUsd']),
            'transactions' => (int) $s['transactions'],
            'units' => (int) $s['units'],
            'shifts' => (int) $s['shifts'],
        ];
        $dec = static fn (?string $v): ?BigDecimal => $v === null ? null : BigDecimal::of($v);

        return new self(
            (int) $data['days'],
            (int) $data['daysWithInventory'],
            (int) $data['excludedAtypical'],
            (int) $data['closedDays'],
            $sums($data['sumsAll']),
            $sums($data['sumsFiltered']),
            $dec($data['avgTicketBs']),
            $dec($data['avgTicketUsd']),
            $dec($data['unitsPerTransaction']),
            $dec($data['transactionsPerShift']),
            $dec($data['salesPerShiftUsd']),
            $dec($data['avgRate']),
            $dec($data['avgRateSimple']),
            $dec($data['inventoryAvgUnits']),
            $dec($data['inventoryAvgValueUsd']),
            $data['inventoryLastUnits'] === null ? null : (int) $data['inventoryLastUnits'],
            $dec($data['inventoryLastValueUsd']),
            $dec($data['rateFirst']),
            $dec($data['rateLast']),
            $dec($data['rateVariationPct']),
        );
    }

    /** Valor del período para un indicador, según su agregación (§7.1). */
    public function value(Indicator $indicator): ?BigDecimal
    {
        return match ($indicator) {
            Indicator::SalesBs => $this->sumsAll['salesBs'],
            Indicator::SalesUsd => $this->sumsAll['salesUsd'],
            Indicator::Transactions => BigDecimal::of($this->sumsAll['transactions']),
            Indicator::Units => BigDecimal::of($this->sumsAll['units']),
            Indicator::Shifts => BigDecimal::of($this->sumsAll['shifts']),
            Indicator::AvgTicketBs => $this->avgTicketBs,
            Indicator::AvgTicketUsd => $this->avgTicketUsd,
            Indicator::UnitsPerTransaction => $this->unitsPerTransaction,
            Indicator::TransactionsPerShift => $this->transactionsPerShift,
            Indicator::SalesPerShiftUsd => $this->salesPerShiftUsd,
            Indicator::AvgRate => $this->avgRate,
            Indicator::InventoryUnits => $this->inventoryAvgUnits,
            Indicator::InventoryValueUsd => $this->inventoryAvgValueUsd,
            Indicator::RateVariationPct => $this->rateVariationPct,
        };
    }
}
