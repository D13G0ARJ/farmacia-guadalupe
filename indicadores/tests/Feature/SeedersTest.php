<?php

declare(strict_types=1);

use App\Enums\DayStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('crea roles con los permisos de §15.1', function (): void {
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    expect($admin->hasRole(Role::Admin->value))->toBeTrue()
        ->and($admin->can(Permission::PeriodsReopen->value))->toBeTrue()
        ->and($admin->branches)->toHaveCount(1);

    $operador = User::factory()->create()->assignRole(Role::Operador->value);

    expect($operador->can(Permission::RecordsCreate->value))->toBeTrue()
        ->and($operador->can(Permission::PeriodsClose->value))->toBeFalse()
        ->and($operador->can(Permission::GoalsView->value))->toBeFalse()
        ->and($operador->homeRoute())->toBe('month');
});

it('la sede principal no cuenta inventario los sábados (H3)', function (): void {
    $this->seed(DatabaseSeeder::class);
    $branch = Branch::query()->firstOrFail();

    expect($branch->countsInventoryOn(CarbonImmutable::parse('2025-09-06')))->toBeFalse() // sábado
        ->and($branch->countsInventoryOn(CarbonImmutable::parse('2025-09-07')))->toBeTrue() // domingo
        ->and($branch->countsInventoryOn(CarbonImmutable::parse('2025-09-16')))->toBeTrue(); // martes
});

it('el DemoSeeder carga septiembre 2025 con los valores dorados (§2.6)', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoSeeder::class);

    $records = DailyRecord::query()->orderBy('date')->get();

    $sumBs = $records->reduce(fn (BigDecimal $c, DailyRecord $r) => $c->plus($r->sales_bs), BigDecimal::zero());

    expect($records)->toHaveCount(30)
        ->and((string) $sumBs)->toBe('3012770.86')
        ->and($records->sum('transactions'))->toBe(3853)
        ->and($records->sum('units'))->toBe(7543)
        ->and($records->sum('shifts'))->toBe(93)
        ->and($records->whereNull('inventory_units'))->toHaveCount(5)
        ->and($records->where('status', DayStatus::Atypical)->pluck('date')->map->toDateString()->all())->toBe(['2025-09-16'])
        ->and(ExchangeRate::query()->count())->toBe(30)
        ->and((string) $records->first()->exchange_rate)->toBe('148.4400')
        ->and((string) $records->last()->exchange_rate)->toBe('177.6100');
});

it('el DemoSeeder es idempotente', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(DailyRecord::query()->count())->toBe(30)
        ->and(ExchangeRate::query()->count())->toBe(30);
});
