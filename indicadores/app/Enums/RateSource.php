<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origen de una tasa de cambio (RN-06, RN-07).
 */
enum RateSource: string
{
    case Bcv = 'bcv';
    case Manual = 'manual';
    case Carried = 'carried';

    public function label(): string
    {
        return match ($this) {
            self::Bcv => 'BCV',
            self::Manual => 'Manual',
            self::Carried => 'Arrastrada',
        };
    }
}
