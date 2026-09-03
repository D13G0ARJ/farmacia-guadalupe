<?php

declare(strict_types=1);

namespace App\Domain\Indicators;

enum Unit: string
{
    case Bs = 'bs';
    case Usd = 'usd';
    case Count = 'count';
    case Ratio = 'ratio';
    case Percent = 'percent';
    case RatePerUsd = 'rate';

    public function suffix(): string
    {
        return match ($this) {
            self::Bs => 'Bs',
            self::Usd => '$',
            self::Count, self::Ratio => '',
            self::Percent => '%',
            self::RatePerUsd => 'Bs/$',
        };
    }
}
