<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Sede '.fake()->unique()->city(),
            'code' => 'GUA-'.fake()->unique()->numerify('##'),
            'legal_name' => 'FARMACIA GUADALUPE, C.A.',
            'inventory_days' => Branch::DEFAULT_INVENTORY_DAYS,
            'default_shifts' => 3,
            'is_active' => true,
        ];
    }
}
