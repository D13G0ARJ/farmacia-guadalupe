<?php

declare(strict_types=1);

namespace App\Actions\Periods;

use App\Domain\Periods\Exceptions\PeriodStateException;
use App\Domain\Shared\Period;
use App\Enums\PeriodAction;
use App\Models\Branch;
use App\Models\PeriodEvent;
use App\Models\User;
use App\Queries\MonthRecordsQuery;
use Carbon\CarbonImmutable;

/**
 * Cierra un mes (UC-07, RN-13): exige que el mes haya empezado y tenga al menos un día; si faltan
 * días por cargar, pide confirmación explícita. Deja el evento en `period_events` y en la bitácora.
 */
final class CloseMonth
{
    public function __construct(private readonly MonthRecordsQuery $months) {}

    public function handle(Branch $branch, Period $period, User $user, bool $confirmMissing = false): PeriodEvent
    {
        if ($period->start->gt(CarbonImmutable::today())) {
            throw PeriodStateException::future($period);
        }
        if (PeriodEvent::isClosed($branch->id, $period)) {
            throw PeriodStateException::alreadyClosed($period);
        }

        $month = $this->months->run($branch->id, $period);
        if ($month->loadedDays() === 0) {
            throw PeriodStateException::empty($period);
        }
        if ($month->missingDates !== [] && ! $confirmMissing) {
            throw PeriodStateException::missingDays($period, $month->missingDates);
        }

        return PeriodEvent::query()->create([
            'branch_id' => $branch->id,
            'period' => $period->start->toDateString(),
            'action' => PeriodAction::Closed,
            'reason' => match (count($month->missingDates)) {
                0 => null,
                1 => 'Cerrado con 1 día sin cargar.',
                default => 'Cerrado con '.count($month->missingDates).' días sin cargar.',
            },
            'user_id' => $user->id,
        ]);
    }
}
