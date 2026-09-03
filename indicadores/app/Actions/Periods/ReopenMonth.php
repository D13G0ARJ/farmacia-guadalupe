<?php

declare(strict_types=1);

namespace App\Actions\Periods;

use App\Domain\Periods\Exceptions\PeriodStateException;
use App\Domain\Shared\Period;
use App\Enums\PeriodAction;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\PeriodEvent;
use App\Models\User;
use App\Notifications\MonthReopened;
use Illuminate\Support\Facades\Notification;

/**
 * Reabre un mes cerrado (UC-07, RN-13): motivo obligatorio, evento en `period_events`, bitácora y
 * aviso por correo a dirección.
 */
final class ReopenMonth
{
    private const MIN_REASON = 5;

    public function handle(Branch $branch, Period $period, string $reason, User $user): PeriodEvent
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON) {
            throw PeriodStateException::reasonRequired();
        }
        if (! PeriodEvent::isClosed($branch->id, $period)) {
            throw PeriodStateException::notClosed($period);
        }

        $event = PeriodEvent::query()->create([
            'branch_id' => $branch->id,
            'period' => $period->start->toDateString(),
            'action' => PeriodAction::Reopened,
            'reason' => mb_substr($reason, 0, 300),
            'user_id' => $user->id,
        ]);

        $recipients = User::query()
            ->role([Role::Direccion->value, Role::Admin->value])
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->get();
        Notification::send($recipients, new MonthReopened($event));

        return $event;
    }
}
