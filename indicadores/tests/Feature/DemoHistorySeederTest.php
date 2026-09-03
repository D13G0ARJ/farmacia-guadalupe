<?php

declare(strict_types=1);

use App\Models\DailyRecord;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoHistorySeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('siembra un agosto sintético derivado de septiembre sin tocar el mes auditado', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class, DemoHistorySeeder::class]);

    $august = DailyRecord::query()->whereBetween('date', ['2025-08-01', '2025-08-31'])->orderBy('date')->get();
    $first = $august->first();

    expect($august)->toHaveCount(31)
        ->and(DailyRecord::query()->whereBetween('date', ['2025-09-01', '2025-09-30'])->count())->toBe(30)
        ->and((string) $first?->sales_bs)->toBe('82950.16') // 91.154,02 × 0,91
        ->and((string) $first?->exchange_rate)->toBe('136.5648') // 148,44 × 0,92
        ->and($first?->notes)->toContain('demostración')
        ->and($august->firstWhere('date', '2025-08-02')?->inventory_units)->toBeNull(); // sábado
});

it('es idempotente', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class, DemoHistorySeeder::class, DemoHistorySeeder::class]);

    expect(DailyRecord::query()->whereBetween('date', ['2025-08-01', '2025-08-31'])->count())->toBe(31);
});
