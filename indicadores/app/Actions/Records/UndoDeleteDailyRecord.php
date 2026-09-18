<?php

declare(strict_types=1);

namespace App\Actions\Records;

use App\Domain\Records\Exceptions\DuplicateDayException;
use App\Domain\Records\Exceptions\InvalidRecordException;
use App\Domain\Records\Exceptions\PeriodClosedException;
use App\Domain\Shared\Period;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\PeriodEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
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
        $branchId = (int) $old['branch_id'];

        // El día vuelve a la sede de la que salió, no a la que el usuario tenga abierta (M12):
        // hay que poder cargar en esa sede (RN-23) y haber sido quien lo borró.
        $branch = Branch::query()->find($branchId);
        if ($branch === null || ! Gate::forUser($user)->allows('create', [DailyRecord::class, $branch])) {
            throw InvalidRecordException::because(['No puedes restaurar días de esa sede.']);
        }
        if ($activity->causer_id !== $user->id && ! $user->can(Permission::RecordsDelete->value)) {
            throw InvalidRecordException::because(['Solo quien borró el día puede deshacerlo.']);
        }

        $period = Period::of($date);
        if (PeriodEvent::isClosed($branchId, $period)) {
            throw PeriodClosedException::for($period);
        }
        if (DailyRecord::query()->forBranch($branchId)->where('date', $date->toDateString())->exists()) {
            throw DuplicateDayException::for($date);
        }

        try {
            $record = DailyRecord::query()->create([
                ...$old,
                'branch_id' => $branchId,
                'date' => $date->toDateString(),
                'created_by' => $user->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otro usuario recargó ese día entre la comprobación y el guardado (A4).
            throw DuplicateDayException::for($date);
        }

        $activity->update(['properties' => collect($activity->properties)->put('undone_at', now()->toIso8601String())]);

        return $record;
    }
}
