<?php

declare(strict_types=1);

namespace App\Actions\Records;

use App\Domain\Records\Exceptions\PeriodClosedException;
use App\Domain\Shared\Period;
use App\Models\DailyRecord;
use App\Models\PeriodEvent;
use App\Models\User;

/** Borra un día. La bitácora conserva el snapshot completo (§5.2) para una eventual restauración. */
final class DeleteDailyRecord
{
    public function handle(DailyRecord $record, User $user): void
    {
        $period = Period::of($record->date);
        if (PeriodEvent::isClosed($record->branch_id, $period)) {
            throw PeriodClosedException::for($period);
        }

        activity()
            ->performedOn($record)
            ->causedBy($user)
            // La fecha va como Y-m-d: serializada como instante UTC cambiaría de día al restaurar en America/Caracas.
            ->withProperties(['old' => [...$record->only(DailyRecord::LOGGED), 'date' => $record->date->toDateString(), 'branch_id' => $record->branch_id]])
            ->event('deleted')
            ->log('deleted');

        // Sin el registro automático del trait: dejaría un segundo evento "deleted" vacío que taparía el snapshot.
        $record->disableLogging()->delete();
    }
}
