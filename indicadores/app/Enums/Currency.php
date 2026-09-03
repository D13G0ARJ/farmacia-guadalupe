<?php

declare(strict_types=1);

namespace App\Enums;

enum Currency: string
{
    case Bs = 'BS';
    case Usd = 'USD';
    case None = 'NONE';

    public function symbol(): string
    {
        return match ($this) {
            self::Bs => 'Bs',
            self::Usd => '$',
            self::None => '',
        };
    }
}
