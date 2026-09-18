<?php

declare(strict_types=1);

namespace App\Actions\Rates;

use App\Domain\Rates\Providers\BcvHistoryProvider;
use App\Enums\RateSource;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Completa el histórico de tasas desde los libros oficiales del BCV (§9.2): crea solo los días
 * que no tienen tasa; las manuales y las ya publicadas se respetan.
 */
final class BackfillBcvRates
{
    /** Fechas por consulta y por transacción: un trimestre hábil cabe de sobra. */
    private const CHUNK = 100;

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

        // Un `whereIn` con años de fechas revienta el límite de parámetros: se pregunta por trimestres (M5).
        $existing = [];
        foreach (array_chunk(array_keys($rates), self::CHUNK) as $dates) {
            foreach (ExchangeRate::query()->whereIn('date', $dates)->pluck('date') as $d) {
                $existing[CarbonImmutable::parse((string) $d)->toDateString()] = true;
            }
        }

        $created = 0;
        $fetchedAt = CarbonImmutable::now();
        // Una transacción por tramo: un corte a mitad no deja el histórico entero sin guardar.
        foreach (array_chunk($rates, self::CHUNK, preserve_keys: true) as $chunk) {
            $created += DB::transaction(function () use ($chunk, $existing, $fetchedAt): int {
                $done = 0;
                foreach ($chunk as $date => $rate) {
                    if (isset($existing[$date])) {
                        continue;
                    }
                    $this->upsert->handle(CarbonImmutable::parse((string) $date), $rate, RateSource::Bcv, fetchedAt: $fetchedAt);
                    $done++;
                }

                return $done;
            });
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
