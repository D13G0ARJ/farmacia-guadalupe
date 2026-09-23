<?php

declare(strict_types=1);

namespace App\Domain\Rates;

use App\Enums\RateSource;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;

/**
 * Tasa para una fecha (RN-07): publicada ese día → si no, la del siguiente día hábil publicado, porque el
 * BCV publica el viernes en la tarde la tasa que rige el lunes (y si el lunes es feriado, la del martes):
 * sábado, domingo y feriados usan esa → si aún no está publicada (o es una fecha vieja sin nada después
 * cerca), la última publicada anterior → si no hay ninguna, null y el formulario exige tasa manual
 * (UC-02 A4). Un arrastre hacia atrás de más de una semana se marca como viejo (`isStale`).
 */
final class RateResolver
{
    /** Hasta cuántos días adelante se busca la siguiente publicación: cubre fin de semana, feriado y puente. */
    public const LOOK_AHEAD_DAYS = 7;

    public function forDate(CarbonImmutable $date): ?RateResolution
    {
        $exact = ExchangeRate::query()->where('date', $date->toDateString())->first();

        if ($exact !== null) {
            return new RateResolution($exact->rate, $exact->source, $exact->date, $date);
        }

        $next = ExchangeRate::query()
            ->where('date', '>', $date->toDateString())
            ->where('date', '<=', $date->addDays(self::LOOK_AHEAD_DAYS)->toDateString())
            ->orderBy('date')
            ->first();

        if ($next !== null) {
            return new RateResolution($next->rate, RateSource::Carried, $next->date, $date);
        }

        $previous = ExchangeRate::query()
            ->where('date', '<', $date->toDateString())
            ->orderByDesc('date')
            ->first();

        if ($previous === null) {
            return null;
        }

        return new RateResolution($previous->rate, RateSource::Carried, $previous->date, $date);
    }
}
