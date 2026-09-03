<?php

declare(strict_types=1);

namespace App\Domain\Records\Exceptions;

use Carbon\CarbonImmutable;

/** RN-01 / UC-02 A1: ya existe un registro para esa sede y fecha. */
final class DuplicateDayException extends RecordException
{
    public static function for(CarbonImmutable $date): self
    {
        return new self('El '.$date->format('d/m/Y').' ya está cargado. Ábrelo para editarlo.');
    }
}
