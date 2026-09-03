<?php

declare(strict_types=1);

namespace App\Domain\Records\Exceptions;

/** UC-02 A4: no hay tasa publicada ni arrastre; hay que escribirla. */
final class RateUnavailableException extends RecordException
{
    public static function create(): self
    {
        return new self('No hay tasa BCV para esta fecha. Escríbela para continuar.');
    }
}
