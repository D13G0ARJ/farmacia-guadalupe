<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Indicators\Indicator;
use App\Models\Branch;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Metas de demostración para septiembre 2025 (y la venta de agosto para "copiar mes anterior"). Idempotente. */
class DemoGoalsSeeder extends Seeder
{
    /** @var array<string, array<string, string>> período → indicador → meta */
    public const GOALS = [
        '2025-09-01' => [
            'sales_usd' => '20000',
            'transactions' => '4000',
            'units' => '8000',
            'avg_ticket_usd' => '5',
            'units_per_transaction' => '2.1',
            'transactions_per_shift' => '42',
        ],
        '2025-08-01' => [
            'sales_usd' => '18500',
            'transactions' => '3800',
        ],
    ];

    public function run(): void
    {
        $branch = Branch::query()->where('code', BranchSeeder::MAIN_CODE)->firstOrFail();
        $admin = User::query()->orderBy('id')->firstOrFail();

        foreach (self::GOALS as $period => $goals) {
            foreach ($goals as $indicator => $target) {
                $indicator = Indicator::from($indicator);
                Goal::query()->updateOrCreate(
                    ['branch_id' => $branch->id, 'indicator' => $indicator->value, 'period' => $period],
                    ['target' => $target, 'currency' => $indicator->goalCurrency(), 'created_by' => $admin->id],
                );
            }
        }
    }
}
