<?php

declare(strict_types=1);

namespace App\Actions\Records;

use App\Actions\Rates\UpsertExchangeRate;
use App\Domain\Records\DailyRecordInput;
use App\Domain\Records\Exceptions\PeriodClosedException;
use App\Domain\Records\Exceptions\StaleRecordException;
use App\Domain\Shared\Period;
use App\Enums\RateSource;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\PeriodEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * UC-03: edita un día con bloqueo optimista. `$expectedUpdatedAt` es el `updated_at` que el
 * formulario leyó; si difiere, alguien editó en medio y no se sobrescribe en silencio.
 */
final class UpdateDailyRecord
{
    public function __construct(private readonly UpsertExchangeRate $upsertRate) {}

    public function handle(DailyRecord $record, DailyRecordInput $input, User $user, ?CarbonImmutable $expectedUpdatedAt): DailyRecord
    {
        $input->assertValid(CarbonImmutable::today());

        $period = Period::of($record->date);
        if (PeriodEvent::isClosed($record->branch_id, $period)) {
            throw PeriodClosedException::for($period);
        }

        return DB::transaction(function () use ($record, $input, $user, $expectedUpdatedAt): DailyRecord {
            $fresh = DailyRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            if ($expectedUpdatedAt !== null && $fresh->updated_at !== null && ! $fresh->updated_at->equalTo($expectedUpdatedAt)) {
                throw StaleRecordException::for($fresh);
            }

            $attributes = [
                'status' => $input->status,
                'sales_bs' => $input->salesBs,
                'transactions' => $input->transactions,
                'units' => $input->units,
                'inventory_units' => $input->inventoryUnits,
                'inventory_value_usd' => $input->inventoryValueUsd,
                'shifts' => $input->shifts,
                'notes' => $input->notes,
                'updated_by' => $user->id,
            ];

            if ($input->rate !== null && ! $input->rate->isEqualTo($fresh->exchange_rate)) {
                $published = ExchangeRate::query()->where('date', $fresh->date->toDateString())->first();
                if ($published === null || ! $published->rate->isEqualTo($input->rate)) {
                    $this->upsertRate->handle($fresh->date, $input->rate, RateSource::Manual, $user);
                    $attributes['exchange_rate_source'] = RateSource::Manual;
                } else {
                    $attributes['exchange_rate_source'] = $published->source;
                }
                $attributes['exchange_rate'] = $input->rate;
            }

            $fresh->fill($attributes)->save();

            return $fresh;
        });
    }
}
