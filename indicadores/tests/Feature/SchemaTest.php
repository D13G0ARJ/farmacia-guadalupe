<?php

declare(strict_types=1);

use App\Domain\Indicators\Indicator;
use App\Enums\Currency;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\Goal;
use App\Models\User;
use Brick\Math\BigDecimal;
use Database\Seeders\BranchSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('crea todas las tablas del modelo de datos (§5)', function (): void {
    foreach ([
        'branches', 'exchange_rates', 'daily_records', 'goals', 'period_events',
        'import_batches', 'settings', 'branch_user', 'roles', 'permissions', 'activity_log',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Falta la tabla {$table}");
    }
});

it('no permite dos registros diarios para la misma sede y fecha (RN-01)', function (): void {
    $this->seed([RolesAndPermissionsSeeder::class, BranchSeeder::class]);
    $branch = Branch::query()->firstOrFail();
    $user = User::factory()->create();

    $attributes = [
        'branch_id' => $branch->id, 'date' => '2025-09-01', 'sales_bs' => '91154.02',
        'exchange_rate' => '148.44', 'exchange_rate_source' => 'manual', 'transactions' => 119,
        'units' => 300, 'shifts' => 4, 'created_by' => $user->id,
    ];

    DailyRecord::query()->create($attributes);

    expect(fn () => DailyRecord::query()->create($attributes))->toThrow(QueryException::class);
});

it('no permite metas duplicadas del consolidado gracias a branch_key (§5.2, 4.1)', function (): void {
    $user = User::factory()->create();

    $make = fn () => Goal::query()->create([
        'branch_id' => null, 'indicator' => Indicator::SalesUsd, 'period' => '2025-09-01',
        'target' => '20000', 'currency' => Currency::Usd, 'created_by' => $user->id,
    ]);

    $make();

    expect($make)->toThrow(QueryException::class);
});

it('los montos se leen como BigDecimal, nunca como float (RN-19)', function (): void {
    $this->seed([RolesAndPermissionsSeeder::class, BranchSeeder::class]);
    $user = User::factory()->create();

    $record = DailyRecord::query()->create([
        'branch_id' => Branch::query()->firstOrFail()->id, 'date' => '2025-09-01', 'sales_bs' => '91154.02',
        'exchange_rate' => '148.44', 'exchange_rate_source' => 'manual', 'transactions' => 119,
        'units' => 300, 'shifts' => 4, 'created_by' => $user->id,
    ])->fresh();

    expect($record->sales_bs)->toBeInstanceOf(BigDecimal::class)
        ->and((string) $record->sales_bs)->toBe('91154.02')
        ->and((string) $record->exchange_rate)->toBe('148.4400')
        ->and($record->date->toDateString())->toBe('2025-09-01');
});
