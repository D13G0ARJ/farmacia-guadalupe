<?php

declare(strict_types=1);

namespace App\Actions\Rates;

use App\Enums\RateSource;
use App\Models\ExchangeRate;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Crea o actualiza la tasa de una fecha. Una tasa manual nunca es sobrescrita por una
 * automática (§9.3); una manual sí puede corregir cualquiera.
 */
final class UpsertExchangeRate
{
    public function handle(CarbonImmutable $date, BigDecimal $rate, RateSource $source, ?User $setBy = null, ?CarbonImmutable $fetchedAt = null): ExchangeRate
    {
        $existing = ExchangeRate::query()->where('date', $date->toDateString())->first();

        if ($existing !== null && $existing->source === RateSource::Manual && $source !== RateSource::Manual) {
            return $existing;
        }

        if ($source === RateSource::Bcv) {
            $rate = self::official($rate);
        }

        $attributes = [
            'rate' => $rate,
            'source' => $source,
            'set_by' => $source === RateSource::Manual ? $setBy?->id : null,
            'fetched_at' => $source === RateSource::Bcv ? ($fetchedAt ?? CarbonImmutable::now()) : null,
        ];

        if ($existing === null) {
            return ExchangeRate::query()->create(['date' => $date, ...$attributes]);
        }

        $existing->fill($attributes)->save();

        return $existing;
    }

    /**
     * Regla de la farmacia (RN-27): la tasa automática del BCV se toma con dos decimales cortando el
     * tercero, nunca redondeando. 853,499 se guarda como 853,49, igual que la copiaban a mano.
     */
    public static function official(BigDecimal $rate): BigDecimal
    {
        return $rate->toScale(2, RoundingMode::Down);
    }
}
