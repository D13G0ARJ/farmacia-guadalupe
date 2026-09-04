<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Usuarios de demostración, uno por rol, para enseñar el sistema y para el recorrido en navegador.
 * Contraseña: "password". Idempotente. No se siembra en producción.
 */
class DemoUsersSeeder extends Seeder
{
    /** @var list<array{name: string, email: string, role: Role}> */
    public const USERS = [
        ['name' => 'Ana Operadora', 'email' => 'operador@guadalupe.local', 'role' => Role::Operador],
        ['name' => 'Luis Supervisor', 'email' => 'supervision@guadalupe.local', 'role' => Role::Supervision],
        ['name' => 'María Directora', 'email' => 'direccion@guadalupe.local', 'role' => Role::Direccion],
    ];

    public function run(): void
    {
        $main = Branch::query()->where('code', BranchSeeder::MAIN_CODE)->firstOrFail();

        foreach (self::USERS as $data) {
            $user = User::query()->updateOrCreate(
                ['email' => $data['email']],
                ['name' => $data['name'], 'password' => 'password', 'email_verified_at' => now(), 'is_active' => true],
            );
            $user->syncRoles([$data['role']->value]);
            $user->branches()->syncWithoutDetaching([$main->id]);
        }
    }
}
