<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Indicators\Indicator;
use App\Enums\Currency;
use App\Models\Branch;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
{
    protected $model = Goal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'indicator' => Indicator::SalesUsd,
            'period' => '2025-09-01',
            'period_type' => 'month',
            'target' => '20000.0000',
            'currency' => Currency::Usd,
            'created_by' => User::factory(),
        ];
    }
}
