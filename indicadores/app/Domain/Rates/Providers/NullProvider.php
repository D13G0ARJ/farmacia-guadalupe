<?php

declare(strict_types=1);

namespace App\Domain\Rates\Providers;

use App\Domain\Rates\ExchangeRateProvider;
use App\Domain\Rates\RateQuote;

/** Sin proveedor: la tasa se carga siempre a mano (P1, §9.2). */
final class NullProvider implements ExchangeRateProvider
{
    public function fetch(): ?RateQuote
    {
        return null;
    }
}
