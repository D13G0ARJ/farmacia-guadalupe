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
    public function __construct(
        public BigDecimal $rate,
        public RateSource $source,
        public CarbonImmutable $sourceDate,
    ) {}

    public function isCarried(): bool
    {
        return $this->source === RateSource::Carried;
    }

    /** Texto de la insignia junto al campo (§13.5): "BCV 25/08" · "Arrastrada del vie 22/08" · "Manual". */
    public function label(Formatter $formatter): string
    {
        return match ($this->source) {
            RateSource::Bcv => 'BCV '.$this->sourceDate->format('d/m'),
            RateSource::Carried => 'Arrastrada del '.$formatter->date($this->sourceDate, 'weekday'),
            RateSource::Manual => 'Manual',
        };
    }
}
