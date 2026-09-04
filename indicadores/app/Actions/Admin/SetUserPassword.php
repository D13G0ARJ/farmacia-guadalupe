<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Fija una contraseña nueva a un usuario (UC-17): la escribe el administrador o se genera una
 * legible para entregarla en persona. La contraseña nunca queda en la bitácora.
 */
final class SetUserPassword
{
    public function handle(User $user, string $password, User $actor): User
    {
        $user->password = $password;
        $user->setRememberToken(Str::random(60));
        $user->save();

        activity()->performedOn($user)->causedBy($actor)->event('password_reset')->log('password_reset');

        return $user;
    }

    /** Contraseña temporal fácil de dictar: sin caracteres ambiguos (0/O, 1/l). */
    public static function suggest(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
