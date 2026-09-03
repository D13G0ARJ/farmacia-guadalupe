<?php

declare(strict_types=1);

namespace App\Domain\Rates;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/** Cotización obtenida de un proveedor externo (§9.2). */
final readonly class RateQuote
{
    public function __construct(
        public BigDecimal $rate,
        public CarbonImmutable $fetchedAt,
        public string $origin,
    ) {}
}
