<?php

declare(strict_types=1);

namespace App\Domain\Goals;

/** Estado de una meta según la proyección (§8.2) o, para ratios, según el valor a la fecha. */
enum GoalStatus: string
{
    case OnTrack = 'on_track';
    case AtRisk = 'at_risk';
    case OffTrack = 'off_track';
    case Pending = 'pending';
    case NoGoal = 'no_goal';

    public function label(): string
    {
        return match ($this) {
            self::OnTrack => 'En meta',
            self::AtRisk => 'En riesgo',
            self::OffTrack => 'Fuera de meta',
            self::Pending => 'Sin datos aún',
            self::NoGoal => 'Sin meta',
        };
    }

    /** Tono de la paleta (§13.1): verde / ámbar / rojo; gris sin meta o sin datos. */
    public function tone(): string
    {
        return match ($this) {
            self::OnTrack => 'success',
            self::AtRisk => 'warning',
            self::OffTrack => 'danger',
            self::Pending, self::NoGoal => 'neutral',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::OnTrack => 'check',
            self::AtRisk, self::OffTrack => 'warning',
            self::Pending, self::NoGoal => 'minus',
        };
    }
}
