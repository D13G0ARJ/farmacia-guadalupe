<?php

declare(strict_types=1);

namespace App\Domain\Records;

use App\Domain\Records\Exceptions\InvalidRecordException;
use App\Enums\DayStatus;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Lo que el usuario escribe en el formulario (UC-02). La tasa es opcional: si no viene,
 * la acción la resuelve (publicada o arrastrada). `status` permite marcar atípico al crear.
 */
final readonly class DailyRecordInput
{
    public function __construct(
        public int $branchId,
        public CarbonImmutable $date,
        public BigDecimal $salesBs,
        public ?BigDecimal $rate,
        public int $transactions,
        public int $units,
        public ?int $inventoryUnits,
        public ?BigDecimal $inventoryValueUsd,
        public int $shifts,
        public ?string $notes = null,
        public DayStatus $status = DayStatus::Normal,
    ) {}

    /** Topes de las columnas de `daily_records` (A3): pasarse devolvía un 500 del motor. */
    public const MAX_SALES_BS = '999999999999.99';

    public const MAX_RATE = '99999999.9999';

    public const MAX_INVENTORY_VALUE_USD = '999999999999.99';

    public const MAX_UNSIGNED_INT = 4294967295;

    /** RN-15: validaciones duras. Lanza con todos los problemas juntos. */
    public function assertValid(CarbonImmutable $today): void
    {
        $problems = [];

        if ($this->date->gt($today)) {
            $problems[] = 'No se puede cargar un día que no ha ocurrido.';
        }
        if ($this->salesBs->isNegative()) {
            $problems[] = 'La venta no puede ser negativa.';
        }
        if ($this->rate !== null && ! $this->rate->isPositive()) {
            $problems[] = 'La tasa debe ser mayor que cero.';
        }
        if ($this->transactions < 0 || $this->units < 0) {
            $problems[] = 'Transacciones y unidades no pueden ser negativas.';
        }
        if ($this->shifts < 0 || $this->shifts > 6) {
            $problems[] = 'Las jornadas deben estar entre 0 y 6.';
        }
        if ($this->inventoryUnits !== null && $this->inventoryUnits < 0) {
            $problems[] = 'Las unidades en inventario no pueden ser negativas.';
        }
        if ($this->inventoryValueUsd !== null && $this->inventoryValueUsd->isNegative()) {
            $problems[] = 'La valuación del inventario no puede ser negativa.';
        }
        if ($this->status === DayStatus::Atypical && mb_strlen(trim((string) $this->notes)) < 10) {
            $problems[] = 'Un día atípico necesita un motivo de al menos 10 caracteres.';
        }
        if ($this->salesBs->isGreaterThan(BigDecimal::of(self::MAX_SALES_BS))) {
            $problems[] = 'La venta es demasiado grande. Revisa el valor.';
        }
        if ($this->rate !== null && $this->rate->isGreaterThan(BigDecimal::of(self::MAX_RATE))) {
            $problems[] = 'La tasa es demasiado grande. Revisa el valor.';
        }
        if ($this->transactions > self::MAX_UNSIGNED_INT || $this->units > self::MAX_UNSIGNED_INT) {
            $problems[] = 'Transacciones o unidades son demasiado grandes. Revisa el valor.';
        }
        if ($this->inventoryUnits !== null && $this->inventoryUnits > self::MAX_UNSIGNED_INT) {
            $problems[] = 'Las unidades en inventario son demasiado grandes. Revisa el valor.';
        }
        if ($this->inventoryValueUsd !== null && $this->inventoryValueUsd->isGreaterThan(BigDecimal::of(self::MAX_INVENTORY_VALUE_USD))) {
            $problems[] = 'La valuación del inventario es demasiado grande. Revisa el valor.';
        }

        if ($problems !== []) {
            throw InvalidRecordException::because($problems);
        }
    }
}
