<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/** Resultado de leer un archivo (§10.2): mes detectado, filas y anomalías. */
final readonly class ParsedMonth
{
    /**
     * @param  list<ParsedRow>  $rows
     * @param  list<Anomaly>  $anomalies
     */
    public function __construct(
        public string $period,
        public ?string $monthName,
        public ?string $legalName,
        public array $rows,
        public array $anomalies = [],
    ) {}

    /** @param  list<Anomaly>  $extra */
    public function withAnomalies(array $extra): self
    {
        return new self($this->period, $this->monthName, $this->legalName, $this->rows, [...$this->anomalies, ...$extra]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'monthName' => $this->monthName,
            'legalName' => $this->legalName,
            'rows' => array_map(fn (ParsedRow $r) => $r->toArray(), $this->rows),
            'anomalies' => array_map(fn (Anomaly $a) => $a->toArray(), $this->anomalies),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            period: (string) $data['period'],
            monthName: $data['monthName'] ?? null,
            legalName: $data['legalName'] ?? null,
            rows: array_map(fn (array $r) => ParsedRow::fromArray($r), (array) ($data['rows'] ?? [])),
            anomalies: array_map(fn (array $a) => Anomaly::fromArray($a), (array) ($data['anomalies'] ?? [])),
        );
    }
}
