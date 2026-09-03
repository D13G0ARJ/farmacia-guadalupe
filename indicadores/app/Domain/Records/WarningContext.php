<?php

declare(strict_types=1);

namespace App\Domain\Records;

use App\Domain\Indicators\DailyRecordData;
use Brick\Math\BigDecimal;

/** Lo que el detector necesita del entorno del día (lo reúne WarningContextQuery). */
final readonly class WarningContext
{
    /**
     * @param  list<BigDecimal>  $recentSalesBs  ventas de los últimos días normales cargados (hasta 14)
     */
    public function __construct(
        public ?DailyRecordData $previous,
        public array $recentSalesBs,
        public bool $countsInventoryToday,
        public int $salesDeviationPct,
        public int $rateDeviationPct,
    ) {}
}
