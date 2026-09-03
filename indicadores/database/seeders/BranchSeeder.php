<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public const MAIN_CODE = 'GUA-01';

    public function run(): void
    {
        Branch::query()->updateOrCreate(
            ['code' => self::MAIN_CODE],
            [
                'name' => 'Sede Principal',
                'legal_name' => 'FARMACIA GUADALUPE, C.A.',
                'inventory_days' => Branch::DEFAULT_INVENTORY_DAYS,
                'default_shifts' => 3,
                'is_active' => true,
            ],
        );
    }
}
