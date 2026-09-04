<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Rates\RateResolver;
use App\Domain\Shared\Decimal;
use App\Domain\Shared\Period;
use App\Enums\RateSource;
use App\Models\ExchangeRate;
use App\Models\Setting;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Pantalla Tasa BCV (§9.4, UC-16): una fila por día del mes hasta hoy (o hasta la última tasa
 * publicada si el BCV ya adelantó la de mañana), con origen, variación diaria y quién la fijó.
 */
final class RatesMonthQuery
{
    public function __construct(private readonly RateResolver $resolver) {}

    /**
     * @return array{rows: list<array{date: CarbonImmutable, rate: BigDecimal|null, source: RateSource|null, carriedFrom: CarbonImmutable|null, variation: BigDecimal|null, setter: string|null, fetchedAt: CarbonImmutable|null}>, first: BigDecimal|null, last: BigDecimal|null, published: int, manual: int, status: array{provider: string, lastAttempt: string|null, lastSuccess: string|null, lastError: string|null}}
     */
    public function run(Period $period): array
    {
        $rates = ExchangeRate::query()
            ->with('setter')
            ->whereBetween('date', [$period->start->toDateString(), $period->end()->toDateString()])
            ->orderBy('date')
            ->get()
            ->keyBy(fn (ExchangeRate $r) => $r->date->toDateString());

        $today = CarbonImmutable::today();
        $lastPublished = $rates->keys()->last();
        $until = $period->end();
        if ($period->contains($today)) {
            $until = $today;
            if ($lastPublished !== null && CarbonImmutable::parse($lastPublished)->gt($until)) {
                $until = CarbonImmutable::parse($lastPublished);
            }
        } elseif ($period->start->gt($today)) {
            $until = $period->start->subDay();
        }

        $rows = [];
        $previousRate = null;
        $published = 0;
        $manual = 0;
        for ($date = $period->start; $date->lte($until); $date = $date->addDay()) {
            $rate = $rates->get($date->toDateString());
            $resolution = $rate === null ? $this->resolver->forDate($date) : null;
            $value = $rate->rate ?? $resolution?->rate;

            if ($rate !== null) {
                $published++;
                if ($rate->source === RateSource::Manual) {
                    $manual++;
                }
            }

            $rows[] = [
                'date' => $date,
                'rate' => $value,
                'source' => $rate->source ?? ($resolution !== null ? RateSource::Carried : null),
                'carriedFrom' => $rate === null ? $resolution?->sourceDate : null,
                'variation' => $rate === null ? null : Decimal::variation($previousRate, $rate->rate),
                'setter' => $rate?->setter?->name,
                'fetchedAt' => $rate?->fetched_at,
            ];

            if ($rate !== null) {
                $previousRate = $rate->rate;
            }
        }

        $publishedRates = $rates->values();

        return [
            'rows' => $rows,
            'first' => $publishedRates->first()?->rate,
            'last' => $publishedRates->last()?->rate,
            'published' => $published,
            'manual' => $manual,
            'status' => [
                'provider' => (string) config('indicadores.rates.provider', 'bcv'),
                'lastAttempt' => Setting::get('rates_last_attempt_at'),
                'lastSuccess' => Setting::get('rates_last_success_at'),
                'lastError' => Setting::get('rates_last_error'),
            ],
        ];
    }
}
