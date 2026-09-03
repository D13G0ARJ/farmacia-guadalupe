<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Shared\Period;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\PeriodEvent;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Autorización de registros diarios (§15). Combina permiso, acceso a la sede, mes cerrado y la
 * ventana de edición del operador (`operator_edit_window_days`).
 */
final class DailyRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RecordsView->value);
    }

    public function view(User $user, DailyRecord $record): bool
    {
        return $user->can(Permission::RecordsView->value) && $this->accessesBranch($user, $record->branch_id);
    }

    public function create(User $user, Branch $branch): bool
    {
        return $user->can(Permission::RecordsCreate->value) && $this->accessesBranch($user, $branch->id);
    }

    public function update(User $user, DailyRecord $record): bool
    {
        return $user->can(Permission::RecordsUpdate->value)
            && $this->accessesBranch($user, $record->branch_id)
            && ! PeriodEvent::isClosed($record->branch_id, Period::of($record->date))
            && $this->withinOperatorWindow($user, $record);
    }

    public function delete(User $user, DailyRecord $record): bool
    {
        return $user->can(Permission::RecordsDelete->value)
            && $this->accessesBranch($user, $record->branch_id)
            && ! PeriodEvent::isClosed($record->branch_id, Period::of($record->date));
    }

    public function markAtypical(User $user, DailyRecord $record): bool
    {
        return $user->can(Permission::RecordsMarkAtypical->value)
            && $this->accessesBranch($user, $record->branch_id)
            && ! PeriodEvent::isClosed($record->branch_id, Period::of($record->date));
    }

    private function accessesBranch(User $user, int $branchId): bool
    {
        return $user->canSeeAllBranches() || $user->branches()->whereKey($branchId)->exists();
    }

    /** El operador solo edita días recientes; supervisión y dirección no tienen ventana (§15.1). */
    private function withinOperatorWindow(User $user, DailyRecord $record): bool
    {
        if (! $user->hasRole(Role::Operador->value) || $user->hasAnyRole([Role::Supervision->value, Role::Direccion->value, Role::Admin->value])) {
            return true;
        }

        $window = (int) Setting::get('operator_edit_window_days');

        return $record->date->gte(CarbonImmutable::today()->subDays($window));
    }
}
