<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Domain\Admin\Exceptions\AdminException;
use App\Enums\Role;
use App\Models\User;

/**
 * Activa o desactiva el acceso de un usuario (UC-17). Nunca el propio, nunca el último administrador.
 * Un usuario desactivado no puede entrar y, si tiene sesión abierta, la pierde en la siguiente petición.
 */
final class ToggleUserActive
{
    public function handle(User $user, bool $active, User $actor): User
    {
        if ($user->is($actor) && ! $active) {
            throw AdminException::cannotDeactivateSelf();
        }

        $isLastAdmin = $user->hasRole(Role::Admin->value)
            && User::query()->role(Role::Admin->value)->where('is_active', true)->whereKeyNot($user->id)->doesntExist();
        if (! $active && $isLastAdmin) {
            throw AdminException::lastAdmin();
        }

        $user->is_active = $active;
        $user->save();

        activity()
            ->performedOn($user)
            ->causedBy($actor)
            ->event($active ? 'activated' : 'deactivated')
            ->log($active ? 'activated' : 'deactivated');

        return $user;
    }
}
