<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Acción sobre un mes (RN-13). El estado del mes es la última acción registrada.
 */
enum PeriodAction: string
{
    case Closed = 'closed';
    case Reopened = 'reopened';
}
