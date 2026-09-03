<?php

declare(strict_types=1);

use App\Domain\Indicators\DailyRecordData;
use App\Domain\Indicators\IndicatorCalculator;
use App\Domain\Shared\Decimal;
use App\Domain\Shared\Period;
use App\Enums\PeriodAction;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\PeriodEvent;
use App\Models\User;
use App\Queries\DashboardQuery;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoHistorySeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

it('compara un mes completo con el anterior y anuncia que el anterior no está cerrado', function (): void {
    CarbonImmutable::setTestNow('2025-10-03 09:00:00');
    $this->seed([DatabaseSeeder::class, DemoSeeder::class, DemoHistorySeeder::class]);
    $branch = Branch::query()->firstOrFail();

    $dashboard = app(DashboardQuery::class)->run($branch->id, Period::of('2025-09'));

    $calculator = new IndicatorCalculator;
    $toData = fn (DailyRecord $r) => DailyRecordData::fromModel($r);
    $augustUsd = $calculator->summarize(DailyRecord::query()->whereBetween('date', ['2025-08-01', '2025-08-31'])->orderBy('date')->get()->map($toData)->all())->sumsAll['salesUsd'];
    $septemberUsd = $dashboard->summary()->sumsAll['salesUsd'];

    $sales = $dashboard->vsPrevious['sales_usd'];
    expect($dashboard->partialDays)->toBeNull()
        ->and($sales->isAvailable())->toBeTrue()
        ->and($sales->against)->toBe('agosto')
        ->and($sales->comparedDays)->toBeNull()
        ->and((string) $sales->variation?->toScale(4, RoundingMode::HalfUp))->toBe((string) Decimal::variation($augustUsd, $septemberUsd)?->toScale(4, RoundingMode::HalfUp))
        ->and($sales->direction())->toBe(-1) // agosto tiene 31 días: vendió algo más en total
        ->and($dashboard->vsPrevious['sales_bs']->usdVariation)->not->toBeNull()
        ->and($dashboard->vsYear['sales_usd']->isAvailable())->toBeFalse()
        ->and($dashboard->vsYear['sales_usd']->against)->toBe('septiembre 2024')
        ->and($dashboard->sparklines['sales_usd'])->toHaveCount(14)
        ->and(array_keys($dashboard->charts))->toBe(['g2', 'g8'])
        ->and($dashboard->charts['g2']['empty'])->toBeFalse()
        ->and(array_column($dashboard->notices, 'text'))->toBe(['Agosto 2025 no está cerrado.']);
});

it('con el mes en curso incompleto compara a fecha equivalente: los mismos días del mes anterior (§7.2)', function (): void {
    CarbonImmutable::setTestNow('2025-09-20 09:00:00');
    $this->seed([DatabaseSeeder::class, DemoSeeder::class, DemoHistorySeeder::class]);
    $branch = Branch::query()->firstOrFail();
    DailyRecord::query()->where('date', '>', '2025-09-20')->delete();

    $dashboard = app(DashboardQuery::class)->run($branch->id, Period::of('2025-09'));

    $calculator = new IndicatorCalculator;
    $toData = fn (DailyRecord $r) => DailyRecordData::fromModel($r);
    $septemberUsd = $calculator->summarize(DailyRecord::query()->whereBetween('date', ['2025-09-01', '2025-09-20'])->orderBy('date')->get()->map($toData)->all())->sumsAll['salesUsd'];
    $augustUsd = $calculator->summarize(DailyRecord::query()->whereBetween('date', ['2025-08-01', '2025-08-20'])->orderBy('date')->get()->map($toData)->all())->sumsAll['salesUsd'];

    $sales = $dashboard->vsPrevious['sales_usd'];
    expect($dashboard->partialDays)->toBe(20)
        ->and($sales->comparedDays)->toBe(20)
        ->and((string) $sales->variation?->toScale(4, RoundingMode::HalfUp))
        ->toBe((string) Decimal::variation($augustUsd, $septemberUsd)?->toScale(4, RoundingMode::HalfUp));
});

it('avisa de los días faltantes y de la tasa de hoy arrastrada', function (): void {
    CarbonImmutable::setTestNow('2025-09-22 09:00:00'); // lunes
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $branch = Branch::query()->firstOrFail();
    DailyRecord::query()->where('date', '>', '2025-09-18')->delete();
    ExchangeRate::query()->where('date', '2025-09-22')->delete();

    $dashboard = app(DashboardQuery::class)->run($branch->id, Period::of('2025-09'));
    $texts = array_column($dashboard->notices, 'text');

    expect($texts[0])->toBe('Faltan 4 días por cargar: 19, 20, 21, 22.')
        ->and($dashboard->notices[0]['action'])->toBe('Cargar el primero')
        ->and($dashboard->notices[0]['href'])->toBe(route('records.create', ['date' => '2025-09-19']))
        ->and($texts[1])->toContain('arrastrada del dom 21/09')
        ->and($dashboard->partialDays)->toBe(18);
});

it('un mes cerrado lo dice en los avisos y el consolidado no lleva avisos', function (): void {
    CarbonImmutable::setTestNow('2025-10-03 09:00:00');
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $branch = Branch::query()->firstOrFail();
    $admin = User::query()->firstOrFail();
    PeriodEvent::query()->create(['branch_id' => $branch->id, 'period' => '2025-09-01', 'action' => PeriodAction::Closed, 'user_id' => $admin->id]);

    $closed = app(DashboardQuery::class)->run($branch->id, Period::of('2025-09'));
    $consolidated = app(DashboardQuery::class)->run(null, Period::of('2025-09'));

    expect(array_column($closed->notices, 'text'))->toBe(['Septiembre 2025 está cerrado.'])
        ->and($consolidated->notices)->toBe([])
        ->and($consolidated->vsPrevious['sales_usd']->isAvailable())->toBeFalse()
        ->and($consolidated->charts['g8']['empty'])->toBeFalse();
});

it('un mes sin datos produce un panel vacío sin fallar', function (): void {
    CarbonImmutable::setTestNow('2025-10-03 09:00:00');
    $this->seed([DatabaseSeeder::class]);
    $branch = Branch::query()->firstOrFail();

    $dashboard = app(DashboardQuery::class)->run($branch->id, Period::of('2025-09'));

    expect($dashboard->isEmpty())->toBeTrue()
        ->and($dashboard->charts['g2']['empty'])->toBeTrue()
        ->and($dashboard->sparklines['sales_usd'])->toBe([])
        ->and($dashboard->vsPrevious['sales_usd']->isAvailable())->toBeFalse();
});
