<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportStatus: string
{
    case Parsed = 'parsed';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
