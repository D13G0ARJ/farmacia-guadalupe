<?php

declare(strict_types=1);

namespace App\Actions\Records;

use App\Domain\Records\Exceptions\DuplicateDayException;
use App\Domain\Records\Exceptions\InvalidRecordException;
use App\Domain\Records\Exceptions\PeriodClosedException;
use App\Domain\Shared\Period;
use App\Models\DailyRecord;
use App\Models\PeriodEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/**
 * "Deshacer" tras borrar un día (§13.8): recrea el registro desde el snapshot que dejó
 * `DeleteDailyRecord` en la bitácora, mientras la ventana de deshacer siga abierta.
 */
final class UndoDeleteDailyRecord
{
    public const WINDOW_MINUTES = 15;

    public function handle(int $activityId, User $user): DailyRecord
    {
        $activity = Activity::query()
            ->whereKey($activityId)
            ->where('subject_type', DailyRecord::class)
            ->where('event', 'deleted')
            ->first();

        if ($activity === null || $activity->created_at?->lt(CarbonImmutable::now()->subMinutes(self::WINDOW_MINUTES))) {
            throw InvalidRecordException::because(['Ya no se puede deshacer ese borrado.']);
        }

        /** @var array<string, mixed> $old */
        $old = $activity->properties['old'] ?? [];
        if (! isset($old['branch_id'], $old['date'])) {
            throw InvalidRecordException::because(['El borrado no dejó datos para restaurar.']);
        }

        $date = CarbonImmutable::parse((string) $old['date']);
        $period = Period::of($date);
        if (PeriodEvent::isClosed((int) $old['branch_id'], $period)) {
            throw PeriodClosedException::for($period);
        }
        if (DailyRecord::query()->forBranch((int) $old['branch_id'])->where('date', $date->toDateString())->exists()) {
            throw DuplicateDayException::for($date);
        }

        $record = DailyRecord::query()->create([
            ...$old,
            'date' => $date->toDateString(),
            'created_by' => $user->id,
        ]);

        $activity->update(['properties' => collect($activity->properties)->put('undone_at', now()->toIso8601String())]);

        return $record;
    }
}
