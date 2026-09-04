<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Domain\Admin\Exceptions\AdminException;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Crea o actualiza un usuario con su rol y sus sedes (UC-17, §15.1). Los usuarios nacen
 * verificados: los crea el administrador, no hay registro público ni dependencia del correo.
 */
final class SaveUser
{
    /**
     * @param  array{name: string, email: string, role: Role, branch_ids: list<int>, password?: string|null, is_active?: bool}  $data
     */
    public function handle(array $data, User $actor, ?User $user = null): User
    {
        return DB::transaction(function () use ($data, $actor, $user): User {
            $isNew = $user === null;
            $user ??= new User;

            if (! $isNew && $user->hasRole(Role::Admin->value) && $data['role'] !== Role::Admin && $this->isLastActiveAdmin($user)) {
                throw AdminException::cannotDemoteLastAdmin();
            }

            $user->fill(['name' => trim($data['name']), 'email' => mb_strtolower(trim($data['email']))]);
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            if ($isNew) {
                $user->is_active = $data['is_active'] ?? true;
                $user->forceFill(['email_verified_at' => now()]);
            }
            $user->save();

            $user->syncRoles([$data['role']->value]);
            $user->branches()->sync($data['branch_ids']);

            activity()
                ->performedOn($user)
                ->causedBy($actor)
                ->event($isNew ? 'created' : 'updated')
                ->withProperties(['attributes' => ['role' => $data['role']->value, 'branch_ids' => $data['branch_ids']]])
                ->log($isNew ? 'created' : 'updated');

            return $user->refresh();
        });
    }

    private function isLastActiveAdmin(User $user): bool
    {
        return User::query()->role(Role::Admin->value)->where('is_active', true)->whereKeyNot($user->id)->doesntExist();
    }
}
