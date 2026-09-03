<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Branch;
use App\Models\User;

/** Cerrar y reabrir meses (RN-13, §15.1). */
final class PeriodPolicy
{
    public function close(User $user, Branch $branch): bool
    {
        return $user->can(Permission::PeriodsClose->value) && $this->accessesBranch($user, $branch);
    }

    public function reopen(User $user, Branch $branch): bool
    {
        return $user->can(Permission::PeriodsReopen->value) && $this->accessesBranch($user, $branch);
    }

    private function accessesBranch(User $user, Branch $branch): bool
    {
        return $user->canSeeAllBranches() || $user->branches()->whereKey($branch->id)->exists();
    }
}
