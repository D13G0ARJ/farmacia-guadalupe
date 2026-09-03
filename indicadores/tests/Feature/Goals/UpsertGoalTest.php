<?php

declare(strict_types=1);

use App\Actions\Goals\SuggestGoal;
use App\Actions\Goals\UpsertGoal;
use App\Domain\Goals\Exceptions\InvalidGoalException;
use App\Domain\Indicators\DailyRecordData;
use App\Domain\Indicators\Indicator;
use App\Domain\Indicators\IndicatorCalculator;
use App\Domain\Shared\Period;
use App\Enums\Currency;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\Goal;
use App\Models\Setting;
use Brick\Math\RoundingMode;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoHistorySeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('crea, actualiza y borra la meta de (sede, indicador, mes) con bitácora (UC-11, RN-17)', function (): void {
    $user = userWithRole(Role::Direccion);
    $branch = $user->branches->first();
    $action = app(UpsertGoal::class);
    $period = Period::of('2025-09');

    $goal = $action->handle($branch->id, Indicator::SalesUsd, $period, '20.000', $user);
    expect($goal)->not->toBeNull()
        ->and((string) $goal?->target)->toBe('20000.0000')
        ->and($goal?->currency)->toBe(Currency::Usd)
        ->and($goal?->period->toDateString())->toBe('2025-09-01');

    $updated = $action->handle($branch->id, Indicator::SalesUsd, $period, '21500,50', $user);
    expect($updated?->id)->toBe($goal?->id)
        ->and((string) $updated?->target)->toBe('21500.5000')
        ->and(Goal::query()->count())->toBe(1)
        ->and(Activity::query()->where('subject_type', Goal::class)->count())->toBe(2);

    expect($action->handle($branch->id, Indicator::SalesUsd, $period, '', $user))->toBeNull()
        ->and(Goal::query()->count())->toBe(0);
});

it('rechaza metas no positivas, texto o indicadores sin meta', function (): void {
    $user = userWithRole(Role::Direccion);
    $branch = $user->branches->first();
    $action = app(UpsertGoal::class);

    expect(fn () => $action->handle($branch->id, Indicator::SalesUsd, Period::of('2025-09'), '0', $user))->toThrow(InvalidGoalException::class)
        ->and(fn () => $action->handle($branch->id, Indicator::SalesUsd, Period::of('2025-09'), 'abc', $user))->toThrow(InvalidGoalException::class)
        ->and(fn () => $action->handle($branch->id, Indicator::AvgRate, Period::of('2025-09'), '150', $user))->toThrow(InvalidGoalException::class)
        ->and(Goal::query()->count())->toBe(0);
});

it('la meta consolidada (sin sede) convive con la de la sede', function (): void {
    $user = userWithRole(Role::Direccion);
    $branch = $user->branches->first();
    $action = app(UpsertGoal::class);

    $action->handle($branch->id, Indicator::Transactions, Period::of('2025-09'), '4000', $user);
    $action->handle(null, Indicator::Transactions, Period::of('2025-09'), '9000', $user);

    expect(Goal::query()->count())->toBe(2)
        ->and(Goal::query()->whereNull('branch_id')->first()?->isConsolidated())->toBeTrue();
});

it('sugiere la meta con el promedio de los últimos meses con datos más el crecimiento configurado (§8.1)', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class, DemoHistorySeeder::class]);
    $branch = Branch::query()->firstOrFail();
    $suggest = app(SuggestGoal::class);

    $october = $suggest->for($branch->id, Indicator::SalesUsd, Period::of('2025-10'));
    $september = $suggest->for($branch->id, Indicator::SalesUsd, Period::of('2025-09')); // solo agosto como histórico
    $nothing = $suggest->for($branch->id, Indicator::SalesUsd, Period::of('2025-08'));

    $calculator = new IndicatorCalculator;
    $usd = fn (string $from, string $to) => $calculator->summarize(DailyRecord::query()->whereBetween('date', [$from, $to])->orderBy('date')->get()->map(fn ($r) => DailyRecordData::fromModel($r))->all())->sumsAll['salesUsd'];
    $expected = $usd('2025-08-01', '2025-08-31')->plus($usd('2025-09-01', '2025-09-30'))->dividedBy(2, 4, RoundingMode::HalfUp)->multipliedBy('1.05')->toScale(0, RoundingMode::HalfUp);

    expect((string) $october)->toBe((string) $expected)
        ->and($september)->not->toBeNull()
        ->and($nothing)->toBeNull();

    Setting::put('goal_growth_pct', 10);
    expect((string) $suggest->for($branch->id, Indicator::SalesUsd, Period::of('2025-10')))
        ->toBe((string) $usd('2025-08-01', '2025-08-31')->plus($usd('2025-09-01', '2025-09-30'))->dividedBy(2, 4, RoundingMode::HalfUp)->multipliedBy('1.10')->toScale(0, RoundingMode::HalfUp));
});
