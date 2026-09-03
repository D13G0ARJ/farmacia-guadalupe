<?php

declare(strict_types=1);

namespace App\Domain\Rates;

/**
 * Frontera con el proveedor de la tasa BCV (§9.2). Se enlaza en AppServiceProvider
 * según `indicadores.rates.provider`.
 */
interface ExchangeRateProvider
{
    /** Cotización vigente en este momento, o null si ninguna fuente respondió. Nunca lanza. */
    public function fetch(): ?RateQuote;
}
