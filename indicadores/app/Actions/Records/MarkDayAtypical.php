<?php

declare(strict_types=1);

namespace App\Actions\Records;

use App\Domain\Records\Exceptions\InvalidRecordException;
use App\Domain\Records\Exceptions\PeriodClosedException;
use App\Domain\Shared\Period;
use App\Enums\DayStatus;
use App\Models\DailyRecord;
use App\Models\PeriodEvent;
use App\Models\User;

/** UC-04: marca o desmarca un día como atípico (RN-11). El motivo es obligatorio al marcar. */
final class MarkDayAtypical
{
    public function handle(DailyRecord $record, bool $atypical, ?string $reason, User $user): DailyRecord
    {
        $period = Period::of($record->date);
        if (PeriodEvent::isClosed($record->branch_id, $period)) {
            throw PeriodClosedException::for($period);
        }

        if ($record->status === DayStatus::Closed) {
            throw InvalidRecordException::because(['Un día cerrado no puede marcarse como atípico.']);
        }

        if ($atypical && mb_strlen(trim((string) $reason)) < 10) {
            throw InvalidRecordException::because(['Un día atípico necesita un motivo de al menos 10 caracteres.']);
        }

        $record->fill([
            'status' => $atypical ? DayStatus::Atypical : DayStatus::Normal,
            'notes' => $atypical ? trim((string) $reason) : $record->notes,
            'updated_by' => $user->id,
        ])->save();

        return $record;
    }
}
