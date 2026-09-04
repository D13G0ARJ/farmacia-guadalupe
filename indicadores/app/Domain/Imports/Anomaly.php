<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Enums\AnomalySeverity;
use App\Enums\AnomalyType;

/** Una anomalía detectada en un archivo (§10.3), con su decisión propuesta. */
final readonly class Anomaly
{
    public function __construct(
        public AnomalyType $type,
        public string $message,
        public ?string $date = null,
        public ?int $row = null,
    ) {}

    /** Clave estable para guardar la decisión del usuario. */
    public function id(): string
    {
        return $this->type->value.($this->date !== null ? ':'.$this->date : ($this->row !== null ? ':r'.$this->row : ''));
    }

    public function severity(): AnomalySeverity
    {
        return $this->type->severity();
    }

    public function defaultDecision(): ?string
    {
        return $this->type->options()[0]['value'] ?? null;
    }

    /** @return array{type: string, message: string, date: string|null, row: int|null} */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'message' => $this->message, 'date' => $this->date, 'row' => $this->row];
    }

    /** @param  array{type: string, message: string, date?: string|null, row?: int|null}  $data */
    public static function fromArray(array $data): self
    {
        return new self(AnomalyType::from($data['type']), $data['message'], $data['date'] ?? null, $data['row'] ?? null);
    }
}
