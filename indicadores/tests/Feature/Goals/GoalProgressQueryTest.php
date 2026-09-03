<?php

declare(strict_types=1);

use App\Domain\Goals\GoalStatus;
use App\Domain\Shared\Period;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\Goal;
use App\Queries\DashboardQuery;
use App\Queries\GoalProgressQuery;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoGoalsSeeder;
use Database\Seeders\DemoHistorySeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

it('sigue las metas de un mes completo: corte a fin de mes, patrón semanal fiable con dos meses de histórico', function (): void {
    CarbonImmutable::setTestNow('2025-10-03 09:00:00');
    $this->seed([DatabaseSeeder::class, DemoSeeder::class, DemoHistorySeeder::class, DemoGoalsSeeder::class]);
    $branch = Branch::query()->firstOrFail();

    $tracking = app(GoalProgressQuery::class)->run($branch->id, Period::of('2025-09'));
    $sales = $tracking->for('sales_usd');

    expect(Goal::query()->count())->toBe(8)
        ->and($tracking->cutoff->toDateString())->toBe('2025-09-30')
        ->and($tracking->patternReliable)->toBeTrue()
        ->and($tracking->hasAnyGoal())->toBeTrue()
        ->and(array_keys($tracking->targets))->toContain('sales_usd', 'transactions', 'avg_ticket_usd')
        ->and($sales?->method)->toBe('weekday')
        ->and((string) $sales?->target)->toBe('20000.0000')
        ->and((string) $sales?->pctOfTarget()?->toScale(2, RoundingMode::HalfUp))->toBe('0.93')
        ->and($sales?->status)->toBe(GoalStatus::AtRisk)
        ->and($sales?->isComplete())->toBeTrue()
        ->and($tracking->for('avg_ticket_usd')?->method)->toBe('ratio')
        ->and($tracking->for('sales_bs')?->status)->toBe(GoalStatus::NoGoal)
        ->and(count($tracking->progress))->toBe(9);
});

it('en el mes en curso corta en el último día cargado y proyecta lo que falta', function (): void {
    CarbonImmutable::setTestNow('2025-09-22 09:00:00');
    $this->seed([DatabaseSeeder::class, DemoSeeder::class, DemoGoalsSeeder::class]);
    $branch = Branch::query()->firstOrFail();
    DailyRecord::query()->where('date', '>', '2025-09-20')->delete();

    $tracking = app(GoalProgressQuery::class)->run($branch->id, Period::of('2025-09'));
    $sales = $tracking->for('sales_usd');

    expect($tracking->cutoff->toDateString())->toBe('2025-09-20')
        ->and($tracking->patternReliable)->toBeFalse() // 3 semanas de histórico
        ->and($sales?->method)->toBe('linear')
        ->and($sales?->daysRemaining)->toBe(10)
        ->and($sales?->projection?->isGreaterThan($sales->actual))->toBeTrue()
        ->and($sales?->series['cutoffIndex'])->toBe(19);
});

it('el panel incorpora el seguimiento y la gráfica G9 cuando hay meta de venta en dólares', function (): void {
    CarbonImmutable::setTestNow('2025-10-03 09:00:00');
    $this->seed([DatabaseSeeder::class, DemoSeeder::class, DemoGoalsSeeder::class]);
    $branch = Branch::query()->firstOrFail();

    $dashboard = app(DashboardQuery::class)->run($branch->id, Period::of('2025-09'));

    expect(array_keys($dashboard->charts))->toBe(['g9', 'g2', 'g8'])
        ->and($dashboard->charts['g9']['empty'])->toBeFalse()
        ->and($dashboard->charts['g9']['option']['series'][0]['markLine']['data'][0]['yAxis'])->toBe(20000.0)
        ->and($dashboard->charts['g2']['option']['series'])->toHaveCount(2)
        ->and($dashboard->charts['g2']['subtitle'])->toContain('Meta diaria')
        ->and($dashboard->salesGoal()?->hasGoal())->toBeTrue();

    Goal::query()->delete();
    $without = app(DashboardQuery::class)->run($branch->id, Period::of('2025-09'));
    expect(array_keys($without->charts))->toBe(['g2', 'g8'])
        ->and($without->charts['g2']['subtitle'])->toContain('Sin meta definida');
});
