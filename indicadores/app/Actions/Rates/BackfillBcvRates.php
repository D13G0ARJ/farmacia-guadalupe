<?php

declare(strict_types=1);

namespace App\Actions\Rates;

use App\Domain\Rates\Providers\BcvHistoryProvider;
use App\Enums\RateSource;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;

/**
 * Completa el histórico de tasas desde los libros oficiales del BCV (§9.2): crea solo los días
 * que no tienen tasa; las manuales y las ya publicadas se respetan.
 */
final class BackfillBcvRates
{
    public function __construct(
        private readonly BcvHistoryProvider $history,
        private readonly UpsertExchangeRate $upsert,
    ) {}

    /**
     * @return array{created: int, existing: int, found: int, from: string|null, to: string|null, files: list<string>, failed: list<string>}
     */
    public function handle(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $result = $this->history->fetch($from->startOfDay(), $to->startOfDay());
        $rates = $result['rates'];

        $existing = ExchangeRate::query()
            ->whereIn('date', array_keys($rates))
            ->pluck('date')
            ->map(fn ($d) => CarbonImmutable::parse((string) $d)->toDateString())
            ->all();
        $existing = array_flip($existing);

        $created = 0;
        $fetchedAt = CarbonImmutable::now();
        foreach ($rates as $date => $rate) {
            if (isset($existing[$date])) {
                continue;
            }
            $this->upsert->handle(CarbonImmutable::parse($date), $rate, RateSource::Bcv, fetchedAt: $fetchedAt);
            $created++;
        }

        return [
            'created' => $created,
            'existing' => count($rates) - $created,
            'found' => count($rates),
            'from' => $rates === [] ? null : array_key_first($rates),
            'to' => $rates === [] ? null : array_key_last($rates),
            'files' => $result['files'],
            'failed' => $result['failed'],
        ];
    }
}
