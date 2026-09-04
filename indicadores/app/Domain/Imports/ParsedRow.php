<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * Una fila de datos del archivo, sin interpretar: los primarios como cadenas decimales o enteros
 * (nunca flotantes) y los derivados que traía el archivo, solo para verificar sus fórmulas (§10.2).
 */
final readonly class ParsedRow
{
    /**
     * @param  array<string, float|null>  $fileDerived  D, H, I, J, M tal como venían en el archivo
     */
    public function __construct(
        public int $row,
        public string $date,
        public ?string $weekdayLetter,
        public ?string $salesBs,
        public ?string $rate,
        public ?int $transactions,
        public ?int $units,
        public ?int $inventoryUnits,
        public ?string $inventoryValueUsd,
        public ?int $shifts,
        public array $fileDerived = [],
    ) {}

    /** Tiene los cinco primarios obligatorios (C, E, F, G, N). */
    public function isComplete(): bool
    {
        return $this->salesBs !== null && $this->rate !== null && $this->transactions !== null && $this->units !== null && $this->shifts !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'row' => $this->row,
            'date' => $this->date,
            'weekdayLetter' => $this->weekdayLetter,
            'salesBs' => $this->salesBs,
            'rate' => $this->rate,
            'transactions' => $this->transactions,
            'units' => $this->units,
            'inventoryUnits' => $this->inventoryUnits,
            'inventoryValueUsd' => $this->inventoryValueUsd,
            'shifts' => $this->shifts,
            'fileDerived' => $this->fileDerived,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            row: (int) $data['row'],
            date: (string) $data['date'],
            weekdayLetter: $data['weekdayLetter'] ?? null,
            salesBs: $data['salesBs'] ?? null,
            rate: $data['rate'] ?? null,
            transactions: $data['transactions'] ?? null,
            units: $data['units'] ?? null,
            inventoryUnits: $data['inventoryUnits'] ?? null,
            inventoryValueUsd: $data['inventoryValueUsd'] ?? null,
            shifts: $data['shifts'] ?? null,
            fileDerived: (array) ($data['fileDerived'] ?? []),
        );
    }
}
