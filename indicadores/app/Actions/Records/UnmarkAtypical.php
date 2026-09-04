<?php

declare(strict_types=1);

namespace App\Actions\Records;

use App\Domain\Shared\Period;
use App\Enums\DayStatus;
use App\Models\DailyRecord;
use App\Models\PeriodEvent;
use App\Models\User;
use App\Support\PeriodSummaryCache;

/**
 * "Deshacer" del marcado atípico (§13.8): el día vuelve a normal y conserva su observación.
 * Devuelve false si ya no estaba marcado o si el mes está cerrado (RN-13).
 */
final class UnmarkAtypical
{
    public function __construct(private readonly PeriodSummaryCache $cache) {}

    public function handle(DailyRecord $record, User $user): bool
    {
        if ($record->status !== DayStatus::Atypical || PeriodEvent::isClosed($record->branch_id, Period::of($record->date))) {
            return false;
        }

        $record->status = DayStatus::Normal;
        $record->updated_by = $user->id;
        $record->save();

        $this->cache->forget($record->branch_id, Period::of($record->date));

        return true;
    }
}
