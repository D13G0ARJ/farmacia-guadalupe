<?php

declare(strict_types=1);

use App\Actions\Records\DeleteDailyRecord;
use App\Actions\Records\RegisterClosedDay;
use App\Actions\Records\RegisterDailyRecord;
use App\Actions\Records\UndoDeleteDailyRecord;
use App\Actions\Records\UpdateDailyRecord;
use App\Domain\Records\DailyRecordInput;
use App\Domain\Records\Exceptions\DuplicateDayException;
use App\Domain\Records\Exceptions\InvalidRecordException;
use App\Enums\DayStatus;
use App\Enums\RateSource;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-09-16 10:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

/** @param  array<string, mixed>  $overrides */
function robustInput(int $branchId, string $date, array $overrides = []): DailyRecordInput
{
    return new DailyRecordInput(...array_merge([
        'branchId' => $branchId, 'date' => CarbonImmutable::parse($date), 'salesBs' => BigDecimal::of('91154.02'),
        'rate' => null, 'transactions' => 119, 'units' => 300, 'inventoryUnits' => 9029,
        'inventoryValueUsd' => BigDecimal::of('21848.73'), 'shifts' => 4,
    ], $overrides));
}

/**
 * Simula la carrera de dos usuarios: la fila aparece justo después de la comprobación previa
 * (el `select exists`) y antes del INSERT de la acción.
 */
function insertDuplicateAfterCheck(int $branchId, string $date, User $user): void
{
    $done = false;

    DB::listen(function ($query) use (&$done, $branchId, $date, $user): void {
        if ($done || ! str_contains($query->sql, 'daily_records') || ! str_contains($query->sql, 'exists')) {
            return;
        }
        $done = true;

        DB::table('daily_records')->insert([
            'branch_id' => $branchId, 'date' => $date, 'status' => DayStatus::Normal->value,
            'sales_bs' => '1000.00', 'exchange_rate' => '148.4400', 'exchange_rate_source' => RateSource::Bcv->value,
            'transactions' => 1, 'units' => 1, 'shifts' => 1, 'created_by' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });
}

it('dos usuarios cargando el mismo día a la vez reciben el aviso, no un error 500 (A4)', function (): void {
    $user = userWithRole(Role::Operador);
    $branchId = $user->branches->first()->id;
    ExchangeRate::query()->create(['date' => '2025-09-01', 'rate' => '148.44', 'source' => RateSource::Bcv]);
    insertDuplicateAfterCheck($branchId, '2025-09-01', $user);

    expect(fn () => app(RegisterDailyRecord::class)->handle(robustInput($branchId, '2025-09-01'), $user))
        ->toThrow(DuplicateDayException::class, 'El 01/09/2025 ya está cargado');
});

it('la misma carrera al registrar un día cerrado también avisa (A4)', function (): void {
    $user = userWithRole(Role::Operador);
    $branchId = $user->branches->first()->id;
    ExchangeRate::query()->create(['date' => '2025-09-05', 'rate' => '152.82', 'source' => RateSource::Bcv]);
    insertDuplicateAfterCheck($branchId, '2025-09-07', $user);

    expect(fn () => app(RegisterClosedDay::class)->handle($branchId, CarbonImmutable::parse('2025-09-07'), 'Feriado local', $user))
        ->toThrow(DuplicateDayException::class, 'El 07/09/2025 ya está cargado');
});

it('la misma carrera al deshacer un borrado también avisa (A4)', function (): void {
    $user = userWithRole(Role::Supervision);
    $branch = $user->branches->first();
    $record = DailyRecord::factory()->for($branch)->create(['date' => '2025-09-10', 'created_by' => $user->id]);
    app(DeleteDailyRecord::class)->handle($record, $user);
    $activity = Activity::query()->where('event', 'deleted')->latest('id')->firstOrFail();

    insertDuplicateAfterCheck($branch->id, '2025-09-10', $user);

    expect(fn () => app(UndoDeleteDailyRecord::class)->handle((int) $activity->id, $user))
        ->toThrow(DuplicateDayException::class);
});

it('deshacer un borrado no restaura días de una sede ajena (M12)', function (): void {
    $supervisorA = userWithRole(Role::Supervision);
    $branchA = $supervisorA->branches->first();
    $branchB = Branch::factory()->create(['name' => 'Zona Sur', 'code' => 'sur']);
    $supervisorB = userWithRole(Role::Supervision, $branchB);

    $record = DailyRecord::factory()->for($branchB)->create(['date' => '2025-09-10', 'created_by' => $supervisorB->id]);
    app(DeleteDailyRecord::class)->handle($record, $supervisorB);
    $activity = Activity::query()->where('event', 'deleted')->latest('id')->firstOrFail();

    // El de la sede A no tiene nada que hacer con un día de la sede B (RN-23).
    expect(fn () => app(UndoDeleteDailyRecord::class)->handle((int) $activity->id, $supervisorA))
        ->toThrow(InvalidRecordException::class, 'No puedes restaurar días de esa sede');
    expect(DailyRecord::query()->where('branch_id', $branchB->id)->where('date', '2025-09-10')->exists())->toBeFalse();

    // Quien lo borró sí lo restaura, y vuelve a SU sede.
    $restored = app(UndoDeleteDailyRecord::class)->handle((int) $activity->id, $supervisorB);
    expect($restored->branch_id)->toBe($branchB->id);
    expect($branchA->id)->not->toBe($branchB->id);
});

it('quien no borró el día ni puede borrar no puede deshacerlo (M12)', function (): void {
    $supervisor = userWithRole(Role::Supervision);
    $branch = $supervisor->branches->first();
    $operador = userWithRole(Role::Operador, $branch);

    $record = DailyRecord::factory()->for($branch)->create(['date' => '2025-09-10', 'created_by' => $supervisor->id]);
    app(DeleteDailyRecord::class)->handle($record, $supervisor);
    $activity = Activity::query()->where('event', 'deleted')->latest('id')->firstOrFail();

    expect(fn () => app(UndoDeleteDailyRecord::class)->handle((int) $activity->id, $operador))
        ->toThrow(InvalidRecordException::class, 'Solo quien borró el día puede deshacerlo');
});

it('un día cerrado se puede registrar aunque esa fecha no tenga tasa BCV: vale la escrita (M14)', function (): void {
    $user = userWithRole(Role::Operador);
    $branchId = $user->branches->first()->id;

    // Sin ninguna tasa publicada, la acción pide que se escriba en vez de reventar.
    expect(fn () => app(RegisterClosedDay::class)->handle($branchId, CarbonImmutable::parse('2025-09-07'), 'Feriado local', $user))
        ->toThrow(InvalidRecordException::class, 'Escribe la tasa para registrar el día cerrado.');

    $closed = app(RegisterClosedDay::class)->handle($branchId, CarbonImmutable::parse('2025-09-07'), 'Feriado local', $user, BigDecimal::of('150.25'));

    expect($closed->status)->toBe(DayStatus::Closed)
        ->and((string) $closed->exchange_rate)->toBe('150.2500')
        ->and($closed->exchange_rate_source)->toBe(RateSource::Manual)
        ->and($closed->shifts)->toBe(0);
});

it('editar un día cerrado no lo vuelve normal y no admite marcarlo atípico (M11)', function (): void {
    $user = userWithRole(Role::Supervision);
    $branchId = $user->branches->first()->id;
    ExchangeRate::query()->create(['date' => '2025-09-05', 'rate' => '152.82', 'source' => RateSource::Bcv]);
    $closed = app(RegisterClosedDay::class)->handle($branchId, CarbonImmutable::parse('2025-09-07'), 'Feriado local', $user);

    // Aunque llegue como normal, el día cerrado sigue cerrado.
    $saved = app(UpdateDailyRecord::class)->handle(
        $closed,
        robustInput($branchId, '2025-09-07', ['salesBs' => BigDecimal::zero(), 'transactions' => 0, 'units' => 0, 'shifts' => 0, 'inventoryUnits' => null, 'inventoryValueUsd' => null, 'notes' => 'Feriado nacional decretado']),
        $user,
        null,
    );
    expect($saved->status)->toBe(DayStatus::Closed)
        ->and($saved->notes)->toBe('Feriado nacional decretado');

    expect(fn () => app(UpdateDailyRecord::class)->handle(
        $saved->fresh(),
        robustInput($branchId, '2025-09-07', ['status' => DayStatus::Atypical, 'notes' => 'Corte de luz toda la mañana']),
        $user,
        null,
    ))->toThrow(InvalidRecordException::class, 'Un día cerrado no se puede marcar como atípico');
});

it('rechaza números fuera del rango de las columnas en vez de dejar reventar la base (A3)', function (): void {
    $user = userWithRole(Role::Operador);
    $branchId = $user->branches->first()->id;

    try {
        app(RegisterDailyRecord::class)->handle(robustInput($branchId, '2025-09-01', [
            'salesBs' => BigDecimal::of('1000000000000'),
            'rate' => BigDecimal::of('100000000'),
            'transactions' => 4294967296,
            'inventoryUnits' => 4294967296,
            'inventoryValueUsd' => BigDecimal::of('1000000000000'),
        ]), $user);
        $this->fail('Debió lanzar');
    } catch (InvalidRecordException $e) {
        expect($e->getMessage())->toContain('La venta es demasiado grande')
            ->toContain('La tasa es demasiado grande')
            ->toContain('Transacciones o unidades son demasiado grandes')
            ->toContain('Las unidades en inventario son demasiado grandes')
            ->toContain('La valuación del inventario es demasiado grande');
    }

    expect(DailyRecord::query()->count())->toBe(0);
});
