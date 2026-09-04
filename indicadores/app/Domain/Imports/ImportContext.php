<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/** Lo que el detector necesita de la base de datos, para seguir siendo puro (§10.3). */
final readonly class ImportContext
{
    /**
     * @param  list<int>  $inventoryDays  días ISO con conteo en la sede
     * @param  array<string, string>  $existingRates  'Y-m-d' => tasa ya registrada
     * @param  string|null  $alreadyImportedAt  fecha legible de una importación previa del mismo archivo (hash)
     */
    public function __construct(
        public array $inventoryDays,
        public int $existingRecords,
        public array $existingRates,
        public ?string $alreadyImportedAt,
        public int $salesDeviationPct = 35,
        public int $rateDeviationPct = 10,
    ) {}
}
