<?php

declare(strict_types=1);

namespace App\Domain\Indicators;

use App\Enums\DayStatus;
use App\Models\DailyRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Entrada del motor de indicadores: los siete datos primarios de un día (§6.2).
 * Inmutable y sin dependencia de Eloquent salvo el mapeador de conveniencia.
 */
final readonly class DailyRecordData
{
    public function __construct(
        public CarbonImmutable $date,
        public ?int $branchId,
        public DayStatus $status,
        public BigDecimal $salesBs,
        public BigDecimal $rate,
        public int $transactions,
        public int $units,
        public ?int $inventoryUnits,
        public ?BigDecimal $inventoryValueUsd,
        public int $shifts,
        public ?string $notes = null,
    ) {}

    public static function fromModel(DailyRecord $record): self
    {
        return new self(
            date: $record->date,
            branchId: $record->branch_id,
            status: $record->status,
            salesBs: $record->sales_bs,
            rate: $record->exchange_rate,
            transactions: $record->transactions,
            units: $record->units,
            inventoryUnits: $record->inventory_units,
            inventoryValueUsd: $record->inventory_value_usd,
            shifts: $record->shifts,
            notes: $record->notes,
        );
    }

    /**
     * @param  array{date: string, sales_bs: string|int, exchange_rate: string|int, transactions: int, units: int, inventory_units?: int|null, inventory_value_usd?: string|null, shifts: int, status?: string, notes?: string|null, branch_id?: int|null}  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            date: CarbonImmutable::parse($row['date'])->startOfDay(),
            branchId: $row['branch_id'] ?? null,
            status: DayStatus::from($row['status'] ?? DayStatus::Normal->value),
            salesBs: BigDecimal::of((string) $row['sales_bs']),
            rate: BigDecimal::of((string) $row['exchange_rate']),
            transactions: $row['transactions'],
            units: $row['units'],
            inventoryUnits: $row['inventory_units'] ?? null,
            inventoryValueUsd: isset($row['inventory_value_usd']) ? BigDecimal::of((string) $row['inventory_value_usd']) : null,
            shifts: $row['shifts'],
            notes: $row['notes'] ?? null,
        );
    }

    public function hasInventory(): bool
    {
        return $this->inventoryUnits !== null || $this->inventoryValueUsd !== null;
    }

    public function isClosed(): bool
    {
        return $this->status === DayStatus::Closed;
    }

    public function isAtypical(): bool
    {
        return $this->status === DayStatus::Atypical;
    }
}
