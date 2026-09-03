<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Branch;
use App\Models\User;

/** Ver y definir metas (§15.1). El operador no las ve; supervisión las ve; dirección las define. */
final class GoalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::GoalsView->value);
    }

    public function manage(User $user, ?Branch $branch = null): bool
    {
        if (! $user->can(Permission::GoalsManage->value)) {
            return false;
        }

        return $branch === null
            ? $user->canSeeAllBranches()
            : $user->canSeeAllBranches() || $user->branches()->whereKey($branch->id)->exists();
    }
}
