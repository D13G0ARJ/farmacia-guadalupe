<?php

declare(strict_types=1);

namespace App\Domain\Records\Exceptions;

/** RN-15: validación dura que la interfaz debió impedir; defensa en profundidad. */
final class InvalidRecordException extends RecordException
{
    /** @param  list<string>  $problems */
    public static function because(array $problems): self
    {
        return new self(implode(' ', $problems));
    }
}
