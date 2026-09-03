<?php

declare(strict_types=1);

namespace App\Actions\Rates;

use App\Domain\Rates\RateResolver;
use App\Domain\Shared\Period;
use App\Models\DailyRecord;
use App\Models\User;
use App\Support\PeriodSummaryCache;
use Illuminate\Support\Facades\DB;

/**
 * Acción explícita (§9.3, RN-06): vuelve a fijar el snapshot de tasa de cada registro del mes
 * a partir de la tabla de tasas. Devuelve cuántos registros cambiaron. Queda en bitácora.
 */
final class RecalculateMonthRates
{
    public function __construct(
        private readonly RateResolver $resolver,
        private readonly PeriodSummaryCache $cache,
    ) {}

    public function handle(int $branchId, Period $period, User $user): int
    {
        return DB::transaction(function () use ($branchId, $period, $user): int {
            $changed = 0;

            $records = DailyRecord::query()->forBranch($branchId)->forPeriod($period)->orderBy('date')->get();

            foreach ($records as $record) {
                $resolution = $this->resolver->forDate($record->date);
                if ($resolution === null) {
                    continue;
                }

                if ($resolution->rate->isEqualTo($record->exchange_rate) && $resolution->source === $record->exchange_rate_source) {
                    continue;
                }

                $record->fill([
                    'exchange_rate' => $resolution->rate,
                    'exchange_rate_source' => $resolution->source,
                    'updated_by' => $user->id,
                ])->save();
                $changed++;
            }

            $this->cache->forget($branchId, $period);

            return $changed;
        });
    }
}
