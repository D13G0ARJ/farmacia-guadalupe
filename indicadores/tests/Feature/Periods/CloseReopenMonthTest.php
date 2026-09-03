<?php

declare(strict_types=1);

use App\Actions\Periods\CloseMonth;
use App\Actions\Periods\ReopenMonth;
use App\Domain\Periods\Exceptions\PeriodStateException;
use App\Domain\Shared\Period;
use App\Enums\PeriodAction;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\PeriodEvent;
use App\Models\User;
use App\Notifications\MonthReopened;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('cierra un mes completo, deja el evento y la bitácora, y bloquea la edición (UC-07, RN-13)', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $branch = Branch::query()->firstOrFail();
    $supervisor = userWithRole(Role::Supervision);

    $event = app(CloseMonth::class)->handle($branch, Period::of('2025-09'), $supervisor);

    expect($event->action)->toBe(PeriodAction::Closed)
        ->and($event->reason)->toBeNull()
        ->and(PeriodEvent::isClosed($branch->id, Period::of('2025-09')))->toBeTrue()
        ->and(Activity::query()->where('subject_type', PeriodEvent::class)->count())->toBe(1)
        ->and($supervisor->can('update', DailyRecord::query()->firstOrFail()))->toBeFalse()
        ->and($supervisor->can('delete', DailyRecord::query()->firstOrFail()))->toBeFalse();
});

it('con días faltantes exige confirmación y lo anota en el motivo', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $branch = Branch::query()->firstOrFail();
    $user = userWithRole(Role::Direccion);
    DailyRecord::query()->whereIn('date', ['2025-09-10', '2025-09-11'])->delete();

    try {
        app(CloseMonth::class)->handle($branch, Period::of('2025-09'), $user);
        $this->fail('Debía pedir confirmación');
    } catch (PeriodStateException $e) {
        expect($e->getMessage())->toContain('Faltan 2 días por cargar', '10, 11')
            ->and($e->missingDates)->toHaveCount(2);
    }

    $event = app(CloseMonth::class)->handle($branch, Period::of('2025-09'), $user, confirmMissing: true);
    expect($event->reason)->toBe('Cerrado con 2 días sin cargar.');
});

it('no cierra un mes futuro, vacío o ya cerrado', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $branch = Branch::query()->firstOrFail();
    $user = userWithRole(Role::Direccion);
    $close = app(CloseMonth::class);

    expect(fn () => $close->handle($branch, Period::of('2025-11'), $user))->toThrow(PeriodStateException::class, 'no ha terminado de ocurrir')
        ->and(fn () => $close->handle($branch, Period::of('2025-07'), $user))->toThrow(PeriodStateException::class, 'sin ningún día cargado');

    $close->handle($branch, Period::of('2025-09'), $user);
    expect(fn () => $close->handle($branch, Period::of('2025-09'), $user))->toThrow(PeriodStateException::class, 'ya está cerrado');
});

it('reabrir exige motivo, mes cerrado, y avisa a dirección por correo', function (): void {
    Notification::fake();
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $branch = Branch::query()->firstOrFail();
    $director = userWithRole(Role::Direccion);
    $otherDirector = userWithRole(Role::Direccion);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
    $reopen = app(ReopenMonth::class);

    expect(fn () => $reopen->handle($branch, Period::of('2025-09'), 'Error en el 15', $director))->toThrow(PeriodStateException::class, 'no está cerrado');

    app(CloseMonth::class)->handle($branch, Period::of('2025-09'), $director);

    expect(fn () => $reopen->handle($branch, Period::of('2025-09'), 'ok', $director))->toThrow(PeriodStateException::class, 'motivo');

    $event = $reopen->handle($branch, Period::of('2025-09'), '  Se cargó mal el 15/09  ', $director);

    expect($event->action)->toBe(PeriodAction::Reopened)
        ->and($event->reason)->toBe('Se cargó mal el 15/09')
        ->and(PeriodEvent::isClosed($branch->id, Period::of('2025-09')))->toBeFalse()
        ->and($director->can('update', DailyRecord::query()->firstOrFail()))->toBeTrue();

    Notification::assertSentTo([$otherDirector, $admin], MonthReopened::class);
    Notification::assertNotSentTo($director, MonthReopened::class);
});

it('la política: supervisión cierra pero no reabre; el operador no hace ninguna de las dos', function (): void {
    $branch = mainBranch();
    $operator = userWithRole(Role::Operador, $branch);
    $supervisor = userWithRole(Role::Supervision, $branch);
    $director = userWithRole(Role::Direccion, $branch);

    expect($operator->can('close', $branch))->toBeFalse()
        ->and($operator->can('reopen', $branch))->toBeFalse()
        ->and($supervisor->can('close', $branch))->toBeTrue()
        ->and($supervisor->can('reopen', $branch))->toBeFalse()
        ->and($director->can('close', $branch))->toBeTrue()
        ->and($director->can('reopen', $branch))->toBeTrue();
});
