<?php

declare(strict_types=1);

namespace App\Domain\Records\Exceptions;

use App\Domain\Shared\Period;

/** RN-13: el mes está cerrado. */
final class PeriodClosedException extends RecordException
{
    public static function for(Period $period): self
    {
        return new self("{$period->label()} está cerrado. Pide la reapertura para editarlo.");
    }
}
