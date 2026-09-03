<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de un registro diario (RN-11, RN-12).
 */
enum DayStatus: string
{
    case Normal = 'normal';
    case Atypical = 'atypical';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Atypical => 'Atípico',
            self::Closed => 'Cerrado',
        };
    }

    /** Un día cerrado no operó: no entra en ratios ni en el patrón semanal. */
    public function countsInRatios(): bool
    {
        return $this !== self::Closed;
    }
}
