<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\BranchSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/** Siembra roles y la sede principal (idempotente) y devuelve la sede. */
function mainBranch(): Branch
{
    test()->seed([RolesAndPermissionsSeeder::class, BranchSeeder::class]);

    return Branch::query()->where('code', BranchSeeder::MAIN_CODE)->firstOrFail();
}

/** Usuario verificado con un rol y acceso a la sede indicada (o a la principal). */
function userWithRole(Role $role, ?Branch $branch = null): User
{
    $branch ??= mainBranch();
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($role->value);
    $user->branches()->attach($branch->id);

    return $user;
}
