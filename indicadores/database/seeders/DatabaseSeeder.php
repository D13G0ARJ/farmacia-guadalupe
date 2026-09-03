<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Datos base del sistema (§17 Fase 0). El DemoSeeder se ejecuta aparte:
 * `php artisan db:seed --class=DemoSeeder` (carga septiembre 2025 desde el fixture).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            BranchSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
