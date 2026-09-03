<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DayStatus;
use App\Enums\RateSource;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyRecord>
 */
class DailyRecordFactory extends Factory
{
    protected $model = DailyRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $transactions = fake()->numberBetween(100, 160);

        return [
            'branch_id' => Branch::factory(),
            'date' => fake()->unique()->dateTimeBetween('-60 days', '-1 day')->format('Y-m-d'),
            'status' => DayStatus::Normal,
            'sales_bs' => (string) fake()->numberBetween(80000, 120000).'.'.fake()->numerify('##'),
            'exchange_rate' => '150.0000',
            'exchange_rate_source' => RateSource::Manual,
            'transactions' => $transactions,
            'units' => (int) round($transactions * 1.95),
            'inventory_units' => fake()->numberBetween(9000, 10500),
            'inventory_value_usd' => (string) fake()->numberBetween(21000, 24000).'.00',
            'shifts' => 3,
            'created_by' => User::factory(),
        ];
    }

    public function atypical(string $reason = 'Corte de electricidad durante la mañana'): static
    {
        return $this->state(['status' => DayStatus::Atypical, 'notes' => $reason]);
    }

    public function closed(string $reason = 'Feriado nacional'): static
    {
        return $this->state([
            'status' => DayStatus::Closed, 'sales_bs' => '0', 'transactions' => 0, 'units' => 0,
            'inventory_units' => null, 'inventory_value_usd' => null, 'shifts' => 0, 'notes' => $reason,
        ]);
    }

    public function withoutInventory(): static
    {
        return $this->state(['inventory_units' => null, 'inventory_value_usd' => null]);
    }
}
