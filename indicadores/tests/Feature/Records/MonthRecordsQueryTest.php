<?php

declare(strict_types=1);

use App\Actions\Rates\RecalculateMonthRates;
use App\Domain\Shared\Period;
use App\Enums\RateSource;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Queries\MonthRecordsQuery;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('arma el mes de septiembre con sus filas, totales ponderados y sin días faltantes', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $branch = Branch::query()->firstOrFail();

    $view = app(MonthRecordsQuery::class)->run($branch->id, Period::of('2025-09'));

    expect($view->rows)->toHaveCount(30)
        ->and($view->loadedDays())->toBe(30)
        ->and($view->missingDates)->toBe([])
        ->and($view->isClosed)->toBeFalse()
        ->and((string) $view->summary->avgTicketBs?->toScale(2, RoundingMode::HalfUp))->toBe('781.93')
        ->and($view->statusOf(CarbonImmutable::parse('2025-09-16')))->toBe('atypical')
        ->and($view->statusOf(CarbonImmutable::parse('2025-09-06')))->toBe('loaded') // sábado sin inventario NO es incompleto (UC-06)
        ->and($view->statusOf(CarbonImmutable::parse('2025-10-04')))->toBe('future');
});

it('lista los días faltantes hasta hoy y el primero de ellos', function (): void {
    CarbonImmutable::setTestNow('2025-09-20 09:00:00');
    $user = userWithRole(Role::Operador);
    $branch = $user->branches->first();
    foreach (['2025-09-01', '2025-09-02', '2025-09-04'] as $d) {
        DailyRecord::factory()->for($branch)->create(['date' => $d, 'created_by' => $user->id]);
    }

    $view = app(MonthRecordsQuery::class)->run($branch->id, Period::of('2025-09'));

    expect(array_map(fn ($d) => $d->toDateString(), $view->missingDates))->toContain('2025-09-03', '2025-09-05', '2025-09-20')
        ->and($view->missingDates)->toHaveCount(17)
        ->and($view->firstMissingDate()?->toDateString())->toBe('2025-09-03')
        ->and($view->statusOf(CarbonImmutable::parse('2025-09-03')))->toBe('missing');
});

it('el consolidado suma las sedes y no calcula faltantes', function (): void {
    $user = userWithRole(Role::Direccion);
    $a = $user->branches->first();
    $b = Branch::factory()->create();
    DailyRecord::factory()->for($a)->create(['date' => '2025-09-01', 'sales_bs' => '1000', 'exchange_rate' => '100', 'transactions' => 10, 'created_by' => $user->id]);
    DailyRecord::factory()->for($b)->create(['date' => '2025-09-01', 'sales_bs' => '3000', 'exchange_rate' => '100', 'transactions' => 10, 'created_by' => $user->id]);

    $view = app(MonthRecordsQuery::class)->run(null, Period::of('2025-09'));

    expect((string) $view->summary->sumsAll['salesBs'])->toBe('4000.00')
        ->and((string) $view->summary->avgTicketBs)->toBe('200.0000')
        ->and($view->missingDates)->toBe([]);
});

it('la cache de agregados se invalida al guardar un registro (§4.2 principio 7)', function (): void {
    $user = userWithRole(Role::Operador);
    $branch = $user->branches->first();
    $period = Period::of('2025-09');
    DailyRecord::factory()->for($branch)->create(['date' => '2025-09-01', 'sales_bs' => '1000', 'created_by' => $user->id]);

    $first = app(MonthRecordsQuery::class)->run($branch->id, $period);
    DailyRecord::factory()->for($branch)->create(['date' => '2025-09-02', 'sales_bs' => '500', 'created_by' => $user->id]);
    $second = app(MonthRecordsQuery::class)->run($branch->id, $period);

    expect((string) $first->summary->sumsAll['salesBs'])->toBe('1000.00')
        ->and((string) $second->summary->sumsAll['salesBs'])->toBe('1500.00');
});

it('la referencia "Ayer" es el último día cargado antes de la fecha', function (): void {
    $user = userWithRole(Role::Operador);
    $branch = $user->branches->first();
    DailyRecord::factory()->for($branch)->create(['date' => '2025-09-01', 'sales_bs' => '91154.02', 'created_by' => $user->id]);
    DailyRecord::factory()->for($branch)->create(['date' => '2025-09-02', 'sales_bs' => '97779.71', 'created_by' => $user->id]);

    $previous = app(MonthRecordsQuery::class)->previousLoaded($branch->id, CarbonImmutable::parse('2025-09-05'));

    expect((string) $previous?->data->salesBs)->toBe('97779.71')
        ->and(app(MonthRecordsQuery::class)->previousLoaded($branch->id, CarbonImmutable::parse('2025-09-01')))->toBeNull();
});

it('recalcular tasas del mes actualiza los snapshots que cambiaron (§9.3)', function (): void {
    $user = userWithRole(Role::Supervision);
    $branch = $user->branches->first();
    $record = DailyRecord::factory()->for($branch)->create(['date' => '2025-09-01', 'exchange_rate' => '140', 'exchange_rate_source' => RateSource::Manual, 'created_by' => $user->id]);
    ExchangeRate::query()->create(['date' => '2025-09-01', 'rate' => '148.44', 'source' => RateSource::Bcv]);

    $changed = app(RecalculateMonthRates::class)->handle($branch->id, Period::of('2025-09'), $user);

    expect($changed)->toBe(1)
        ->and((string) $record->fresh()->exchange_rate)->toBe('148.4400')
        ->and($record->fresh()->exchange_rate_source)->toBe(RateSource::Bcv)
        ->and(app(RecalculateMonthRates::class)->handle($branch->id, Period::of('2025-09'), $user))->toBe(0);
});
