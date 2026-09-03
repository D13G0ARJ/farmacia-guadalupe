<?php

declare(strict_types=1);

namespace App\Domain\Goals\Exceptions;

use DomainException;

/** Meta inválida: indicador sin meta, valor no numérico o no positivo (RN-17). */
final class InvalidGoalException extends DomainException
{
    public static function unsupported(string $indicator): self
    {
        return new self("El indicador «{$indicator}» no admite meta.");
    }

    public static function notPositive(): self
    {
        return new self('La meta debe ser un número mayor que cero.');
    }
}
