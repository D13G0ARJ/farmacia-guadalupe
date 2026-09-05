<?php

declare(strict_types=1);

namespace App\Domain\Rates;

use App\Domain\Shared\Formatter;
use App\Enums\RateSource;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/** Tasa aplicable a una fecha y de dónde salió (§9.1, RN-07). */
final readonly class RateResolution
{
    /** Más de una semana de arrastre ya no cubre un fin de semana ni un feriado: es un hueco sin consultas. */
    public const STALE_AFTER_DAYS = 7;

    public function __construct(
        public BigDecimal $rate,
        public RateSource $source,
        public CarbonImmutable $sourceDate,
        public ?CarbonImmutable $forDate = null,
    ) {}

    public function isCarried(): bool
    {
        return $this->source === RateSource::Carried;
    }

    /** Días entre la fecha pedida y la tasa de la que se arrastra (0 si es del mismo día). */
    public function ageDays(): int
    {
        return $this->forDate === null ? 0 : (int) $this->sourceDate->diffInDays($this->forDate, true);
    }

    /** Arrastrada desde hace más de una semana: se propone, pero conviene confirmarla (§9.3). */
    public function isStale(): bool
    {
        return $this->isCarried() && $this->ageDays() > self::STALE_AFTER_DAYS;
    }

    /** "hace 3 semanas", "hace 11 meses", "hace 2 años". */
    public function ageLabel(): string
    {
        $days = $this->ageDays();

        return match (true) {
            $days >= 365 => 'hace '.intdiv($days, 365).(intdiv($days, 365) === 1 ? ' año' : ' años'),
            $days >= 60 => 'hace '.intdiv($days, 30).' meses',
            $days >= 30 => 'hace 1 mes',
            $days >= 14 => 'hace '.intdiv($days, 7).' semanas',
            default => "hace {$days} días",
        };
    }

    /** Texto de la insignia junto al campo (§13.5): "BCV 25/08" · "Arrastrada del vie 22/08" · "Manual". */
    public function label(Formatter $formatter): string
    {
        return match ($this->source) {
            RateSource::Bcv => 'BCV '.$this->sourceDate->format('d/m'),
            RateSource::Carried => $this->isStale()
                ? 'Arrastrada del '.$this->sourceDate->format('d/m/Y').' ('.$this->ageLabel().')'
                : 'Arrastrada del '.$formatter->date($this->sourceDate, 'weekday'),
            RateSource::Manual => 'Manual',
        };
    }
}
