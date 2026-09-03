<?php

declare(strict_types=1);

use App\Actions\Records\DeleteDailyRecord;
use App\Actions\Records\MarkDayAtypical;
use App\Actions\Records\RegisterClosedDay;
use App\Actions\Records\RegisterDailyRecord;
use App\Actions\Records\UpdateDailyRecord;
use App\Domain\Records\DailyRecordInput;
use App\Domain\Records\Exceptions\DuplicateDayException;
use App\Domain\Records\Exceptions\InvalidRecordException;
use App\Domain\Records\Exceptions\PeriodClosedException;
use App\Domain\Records\Exceptions\RateUnavailableException;
use App\Domain\Records\Exceptions\StaleRecordException;
use App\Enums\DayStatus;
use App\Enums\PeriodAction;
use App\Enums\RateSource;
use App\Enums\Role;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\PeriodEvent;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2025-09-16 10:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function inputFor(int $branchId, string $date, array $overrides = []): DailyRecordInput
{
    $base = [
        'branchId' => $branchId, 'date' => CarbonImmutable::parse($date), 'salesBs' => BigDecimal::of('91154.02'),
        'rate' => null, 'transactions' => 119, 'units' => 300, 'inventoryUnits' => 9029,
        'inventoryValueUsd' => BigDecimal::of('21848.73'), 'shifts' => 4,
    ];

    return new DailyRecordInput(...array_merge($base, $overrides));
}

it('registra un día resolviendo la tasa publicada y congelando el snapshot (RN-06)', function (): void {
    $user = userWithRole(Role::Operador);
    ExchangeRate::query()->create(['date' => '2025-09-01', 'rate' => '148.44', 'source' => RateSource::Bcv]);

    $record = app(RegisterDailyRecord::class)->handle(inputFor($user->branches->first()->id, '2025-09-01'), $user);

    expect($record->exists)->toBeTrue()
        ->and((string) $record->exchange_rate)->toBe('148.4400')
        ->and($record->exchange_rate_source)->toBe(RateSource::Bcv)
        ->and($record->created_by)->toBe($user->id)
        ->and($record->date->dayOfWeekIso)->toBe(1)
        ->and(Activity::query()->where('subject_type', DailyRecord::class)->where('subject_id', $record->id)->count())->toBe(1);
});

it('en un sábado usa la tasa arrastrada del viernes (RN-07)', function (): void {
    $user = userWithRole(Role::Operador);
    ExchangeRate::query()->create(['date' => '2025-09-05', 'rate' => '152.82', 'source' => RateSource::Bcv]);

    $record = app(RegisterDailyRecord::class)->handle(inputFor($user->branches->first()->id, '2025-09-06', ['inventoryUnits' => null, 'inventoryValueUsd' => null]), $user);

    expect($record->exchange_rate_source)->toBe(RateSource::Carried)
        ->and((string) $record->exchange_rate)->toBe('152.8200')
        ->and($record->inventory_units)->toBeNull();
});

it('si el usuario escribe una tasa distinta, la guarda como manual para esa fecha (§9.3)', function (): void {
    $user = userWithRole(Role::Operador);

    $record = app(RegisterDailyRecord::class)->handle(inputFor($user->branches->first()->id, '2025-09-01', ['rate' => BigDecimal::of('148.44')]), $user);

    $published = ExchangeRate::query()->where('date', '2025-09-01')->firstOrFail();
    expect($record->exchange_rate_source)->toBe(RateSource::Manual)
        ->and($published->source)->toBe(RateSource::Manual)
        ->and($published->set_by)->toBe($user->id);
});

it('sin tasa publicada ni arrastre exige tasa manual (UC-02 A4)', function (): void {
    $user = userWithRole(Role::Operador);

    app(RegisterDailyRecord::class)->handle(inputFor($user->branches->first()->id, '2025-09-01'), $user);
})->throws(RateUnavailableException::class);

it('rechaza fechas futuras y valores negativos con todos los problemas juntos (RN-15)', function (): void {
    $user = userWithRole(Role::Operador);

    try {
        app(RegisterDailyRecord::class)->handle(inputFor($user->branches->first()->id, '2025-09-17', ['salesBs' => BigDecimal::of('-1'), 'shifts' => 9]), $user);
        $this->fail('Debió lanzar');
    } catch (InvalidRecordException $e) {
        expect($e->getMessage())->toContain('no ha ocurrido')->toContain('negativa')->toContain('jornadas');
    }
});

it('no permite duplicar la fecha, con un mensaje claro (RN-01, UC-02 A1)', function (): void {
    $user = userWithRole(Role::Operador);
    $branchId = $user->branches->first()->id;
    ExchangeRate::query()->create(['date' => '2025-09-01', 'rate' => '148.44', 'source' => RateSource::Bcv]);
    app(RegisterDailyRecord::class)->handle(inputFor($branchId, '2025-09-01'), $user);

    expect(fn () => app(RegisterDailyRecord::class)->handle(inputFor($branchId, '2025-09-01'), $user))
        ->toThrow(DuplicateDayException::class, 'El 01/09/2025 ya está cargado');
    expect(fn () => app(RegisterClosedDay::class)->handle($branchId, CarbonImmutable::parse('2025-09-01'), 'Feriado', $user))
        ->toThrow(DuplicateDayException::class);
});

it('escribir la misma tasa arrastrada no la convierte en manual', function (): void {
    $user = userWithRole(Role::Operador);
    ExchangeRate::query()->create(['date' => '2025-09-05', 'rate' => '152.82', 'source' => RateSource::Bcv]);

    $record = app(RegisterDailyRecord::class)->handle(inputFor($user->branches->first()->id, '2025-09-06', ['rate' => BigDecimal::of('152.82'), 'inventoryUnits' => null, 'inventoryValueUsd' => null]), $user);

    expect($record->exchange_rate_source)->toBe(RateSource::Carried)
        ->and(ExchangeRate::query()->count())->toBe(1);
});

it('bloquea la carga en un mes cerrado (RN-13)', function (): void {
    $user = userWithRole(Role::Operador);
    $branchId = $user->branches->first()->id;
    PeriodEvent::query()->create(['branch_id' => $branchId, 'period' => '2025-09-01', 'action' => PeriodAction::Closed, 'user_id' => $user->id]);

    app(RegisterDailyRecord::class)->handle(inputFor($branchId, '2025-09-01', ['rate' => BigDecimal::of('148.44')]), $user);
})->throws(PeriodClosedException::class, 'Septiembre 2025 está cerrado');

it('edita con bloqueo optimista: un updated_at viejo no sobrescribe (UC-03)', function (): void {
    $user = userWithRole(Role::Supervision);
    $branchId = $user->branches->first()->id;
    $record = app(RegisterDailyRecord::class)->handle(inputFor($branchId, '2025-09-01', ['rate' => BigDecimal::of('148.44')]), $user);
    $stale = $record->updated_at;

    CarbonImmutable::setTestNow('2025-09-16 10:05:00');
    app(UpdateDailyRecord::class)->handle($record, inputFor($branchId, '2025-09-01', ['transactions' => 120]), $user, $stale);

    try {
        app(UpdateDailyRecord::class)->handle($record->fresh(), inputFor($branchId, '2025-09-01', ['transactions' => 121]), $user, $stale);
        $this->fail('Debió lanzar');
    } catch (StaleRecordException $e) {
        expect($e->getMessage())->toContain($user->name);
    }

    expect($record->fresh()->transactions)->toBe(120)
        ->and(Activity::query()->where('subject_type', DailyRecord::class)->where('subject_id', $record->id)->where('event', 'updated')->count())->toBe(1);
});

it('marca un día como atípico con motivo y lo desmarca', function (): void {
    $user = userWithRole(Role::Operador);
    $record = DailyRecord::factory()->for($user->branches->first())->create(['date' => '2025-09-16', 'created_by' => $user->id]);

    $marked = app(MarkDayAtypical::class)->handle($record, true, 'Corte de electricidad toda la mañana', $user);
    expect($marked->status)->toBe(DayStatus::Atypical)->and($marked->notes)->toBe('Corte de electricidad toda la mañana');

    $unmarked = app(MarkDayAtypical::class)->handle($marked, false, null, $user);
    expect($unmarked->status)->toBe(DayStatus::Normal);

    expect(fn () => app(MarkDayAtypical::class)->handle($record, true, 'corto', $user))->toThrow(InvalidRecordException::class);
});

it('registra un día cerrado con ceros y tasa resuelta (RN-12, RN-24)', function (): void {
    $user = userWithRole(Role::Operador);
    ExchangeRate::query()->create(['date' => '2025-09-05', 'rate' => '152.82', 'source' => RateSource::Bcv]);

    $closed = app(RegisterClosedDay::class)->handle($user->branches->first()->id, CarbonImmutable::parse('2025-09-07'), 'Feriado local', $user);

    expect($closed->status)->toBe(DayStatus::Closed)
        ->and($closed->shifts)->toBe(0)
        ->and($closed->sales_bs->isZero())->toBeTrue()
        ->and((string) $closed->exchange_rate)->toBe('152.8200')
        ->and($closed->exchange_rate_source)->toBe(RateSource::Carried);
});

it('borra dejando el snapshot completo en la bitácora', function (): void {
    $user = userWithRole(Role::Supervision);
    $record = DailyRecord::factory()->for($user->branches->first())->create(['date' => '2025-09-10', 'created_by' => $user->id, 'sales_bs' => '116407.39']);

    app(DeleteDailyRecord::class)->handle($record, $user);

    $log = Activity::query()->where('subject_type', DailyRecord::class)->where('event', 'deleted')->where('subject_id', $record->id)->firstOrFail();
    expect(DailyRecord::query()->find($record->id))->toBeNull()
        ->and($log->causer_id)->toBe($user->id)
        ->and((string) $log->properties['old']['sales_bs'])->toBe('116407.39');
});
