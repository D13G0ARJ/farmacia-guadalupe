<?php

declare(strict_types=1);

use App\Enums\PeriodAction;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\PeriodEvent;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-09-16 10:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('el operador edita solo dentro de su ventana de días (§15.1)', function (): void {
    $operador = userWithRole(Role::Operador);
    $branch = $operador->branches->first();
    $recent = DailyRecord::factory()->for($branch)->create(['date' => '2025-09-14', 'created_by' => $operador->id]);
    $old = DailyRecord::factory()->for($branch)->create(['date' => '2025-09-01', 'created_by' => $operador->id]);

    expect($operador->can('update', $recent))->toBeTrue()
        ->and($operador->can('update', $old))->toBeFalse()
        ->and($operador->can('delete', $recent))->toBeFalse();

    Setting::put('operator_edit_window_days', 30);
    expect($operador->can('update', $old))->toBeTrue();
});

it('supervisión edita y borra sin ventana, pero nadie edita un mes cerrado', function (): void {
    $supervisor = userWithRole(Role::Supervision);
    $branch = $supervisor->branches->first();
    $old = DailyRecord::factory()->for($branch)->create(['date' => '2025-08-01', 'created_by' => $supervisor->id]);

    expect($supervisor->can('update', $old))->toBeTrue()
        ->and($supervisor->can('delete', $old))->toBeTrue();

    PeriodEvent::query()->create(['branch_id' => $branch->id, 'period' => '2025-08-01', 'action' => PeriodAction::Closed, 'user_id' => $supervisor->id]);

    expect($supervisor->can('update', $old))->toBeFalse()
        ->and($supervisor->can('delete', $old))->toBeFalse()
        ->and($supervisor->can('markAtypical', $old))->toBeFalse();
});

it('un usuario no ve ni crea en sedes que no tiene asignadas (RN-23)', function (): void {
    $operador = userWithRole(Role::Operador);
    $other = Branch::factory()->create();
    $foreign = DailyRecord::factory()->for($other)->create(['created_by' => $operador->id]);

    expect($operador->can('view', $foreign))->toBeFalse()
        ->and($operador->can('create', [DailyRecord::class, $other]))->toBeFalse()
        ->and($operador->can('create', [DailyRecord::class, $operador->branches->first()]))->toBeTrue();
});

it('dirección ve todas las sedes y puede reabrir; supervisión cierra pero no reabre', function (): void {
    $direccion = userWithRole(Role::Direccion);
    $supervision = userWithRole(Role::Supervision, $direccion->branches->first());
    $other = Branch::factory()->create();

    expect($direccion->can('view', DailyRecord::factory()->for($other)->create(['created_by' => $direccion->id])))->toBeTrue()
        ->and($direccion->can('reopen', [$other]))->toBeTrue()
        ->and($supervision->can('close', [$supervision->branches->first()]))->toBeTrue()
        ->and($supervision->can('reopen', [$supervision->branches->first()]))->toBeFalse();
});
