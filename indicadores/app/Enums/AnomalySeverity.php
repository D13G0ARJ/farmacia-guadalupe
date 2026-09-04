<?php

declare(strict_types=1);

namespace App\Enums;

/** Severidad de una anomalía de importación (§10.3). Solo las altas bloquean la confirmación. */
enum AnomalySeverity: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';

    public function label(): string
    {
        return match ($this) {
            self::High => 'Debe resolverse',
            self::Medium => 'Revisar',
            self::Low => 'Aviso',
            self::Info => 'Informativa',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::High => 'danger',
            self::Medium => 'warning',
            self::Low => 'neutral',
            self::Info => 'brand',
        };
    }

    public function blocks(): bool
    {
        return $this === self::High;
    }
}
