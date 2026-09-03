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

        if ($problems !== []) {
            throw InvalidRecordException::because($problems);
        }
    }
}
