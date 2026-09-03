<?php

declare(strict_types=1);

namespace App\Domain\Rates;

use App\Enums\RateSource;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;

/**
 * Tasa para una fecha: publicada ese día → si no, la última publicada anterior (arrastre, RN-07)
 * → si no hay ninguna, null y el formulario exige tasa manual (UC-02 A4).
 */
final class RateResolver
{
    public function forDate(CarbonImmutable $date): ?RateResolution
    {
        $exact = ExchangeRate::query()->where('date', $date->toDateString())->first();

        if ($exact !== null) {
            return new RateResolution($exact->rate, $exact->source, $exact->date);
        }

        $previous = ExchangeRate::query()
            ->where('date', '<', $date->toDateString())
            ->orderByDesc('date')
            ->first();

        if ($previous === null) {
            return null;
        }

        return new RateResolution($previous->rate, RateSource::Carried, $previous->date);
    }
}
