<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Usuario administrador inicial. Credenciales desde config/indicadores.php (.env ADMIN_*).
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array{name: string, email: string, password: string} $admin */
        $admin = config('indicadores.admin');

        $user = User::query()->updateOrCreate(
            ['email' => $admin['email']],
            [
                'name' => $admin['name'],
                'password' => $admin['password'],
                'email_verified_at' => now(),
                'is_active' => true,
            ],
        );

        $user->syncRoles([Role::Admin->value]);

        $main = Branch::query()->where('code', BranchSeeder::MAIN_CODE)->first();
        if ($main !== null) {
            $user->branches()->syncWithoutDetaching([$main->id]);
        }
    }
}
