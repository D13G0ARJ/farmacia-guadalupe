<?php

declare(strict_types=1);

namespace App\Actions\Records;

use App\Actions\Rates\UpsertExchangeRate;
use App\Domain\Rates\RateResolver;
use App\Domain\Records\DailyRecordInput;
use App\Domain\Records\Exceptions\DuplicateDayException;
use App\Domain\Records\Exceptions\PeriodClosedException;
use App\Domain\Records\Exceptions\RateUnavailableException;
use App\Domain\Shared\Period;
use App\Enums\RateSource;
use App\Models\DailyRecord;
use App\Models\PeriodEvent;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * UC-02: registra un día. Resuelve la tasa si no viene (publicada → arrastre), congela el
 * snapshot (RN-06) y, si el usuario escribió una tasa para una fecha sin publicación, la guarda
 * como manual (§9.3). La unicidad (RN-01) la garantiza la base de datos.
 */
final class RegisterDailyRecord
{
    public function __construct(
        private readonly RateResolver $resolver,
        private readonly UpsertExchangeRate $upsertRate,
    ) {}

    public function handle(DailyRecordInput $input, User $user): DailyRecord
    {
        $input->assertValid(CarbonImmutable::today());

        $period = Period::of($input->date);
        if (PeriodEvent::isClosed($input->branchId, $period)) {
            throw PeriodClosedException::for($period);
        }

        if (DailyRecord::query()->forBranch($input->branchId)->where('date', $input->date->toDateString())->exists()) {
            throw DuplicateDayException::for($input->date);
        }

        return DB::transaction(function () use ($input, $user): DailyRecord {
            [$rate, $source] = $this->resolveRate($input, $user);

            return DailyRecord::query()->create([
                'branch_id' => $input->branchId,
                'date' => $input->date,
                'status' => $input->status,
                'sales_bs' => $input->salesBs,
                'exchange_rate' => $rate,
                'exchange_rate_source' => $source,
                'transactions' => $input->transactions,
                'units' => $input->units,
                'inventory_units' => $input->inventoryUnits,
                'inventory_value_usd' => $input->inventoryValueUsd,
                'shifts' => $input->shifts,
                'notes' => $input->notes,
                'created_by' => $user->id,
            ]);
        });
    }

    /**
     * Sin tasa escrita: publicada o arrastrada. Con tasa escrita igual a la resuelta: se conserva
     * el origen (no se crea una "manual" redundante). Distinta: se guarda como manual (§9.3).
     *
     * @return array{0: BigDecimal, 1: RateSource}
     */
    private function resolveRate(DailyRecordInput $input, User $user): array
    {
        $resolution = $this->resolver->forDate($input->date);

        if ($input->rate === null) {
            $resolution ??= throw RateUnavailableException::create();

            return [$resolution->rate, $resolution->source];
        }

        if ($resolution !== null && $resolution->rate->isEqualTo($input->rate)) {
            return [$resolution->rate, $resolution->source];
        }

        $this->upsertRate->handle($input->date, $input->rate, RateSource::Manual, $user);

        return [$input->rate, RateSource::Manual];
    }
}
