<?php

declare(strict_types=1);

use App\Domain\Goals\GoalProjector;
use App\Domain\Goals\GoalStatus;
use App\Domain\Indicators\DailyRecordData;
use App\Domain\Indicators\Indicator;
use App\Domain\Indicators\IndicatorCalculator;
use App\Domain\Indicators\WeekdayPattern;
use App\Domain\Shared\Period;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Tests\Support\SeptemberFixture;

function projector(): GoalProjector
{
    return new GoalProjector(new IndicatorCalculator);
}

/** Días uniformes de $100 (tasa 100, venta Bs 10.000) del 1 al `$days` de septiembre 2025. */
function uniformRows(int $days, int $transactions = 20): array
{
    $rows = [];
    for ($d = 1; $d <= $days; $d++) {
        $rows[] = DailyRecordData::fromArray(['date' => sprintf('2025-09-%02d', $d), 'sales_bs' => '10000', 'exchange_rate' => '100', 'transactions' => $transactions, 'units' => 40, 'inventory_units' => null, 'inventory_value_usd' => null, 'shifts' => 3]);
    }

    return (new IndicatorCalculator)->daily($rows);
}

it('al día 20 con 68 % de avance y ritmo estable la proyección lineal da ≈ 102 % (UC-12)', function (): void {
    $target = BigDecimal::of('2000')->dividedBy('0.68', 4, RoundingMode::HalfUp); // 20 días × $100 = 68 % de la meta

    $p = projector()->project(Indicator::SalesUsd, $target, uniformRows(20), Period::of('2025-09'), WeekdayPattern::flat(), CarbonImmutable::parse('2025-09-20'));

    expect($p->method)->toBe('linear')
        ->and((string) $p->actual)->toBe('2000.0000')
        ->and((string) $p->pctOfTarget()?->toScale(2, RoundingMode::HalfUp))->toBe('0.68')
        ->and((string) $p->projection?->toScale(0, RoundingMode::HalfUp))->toBe('3000')
        ->and((string) $p->pctProjected()?->toScale(2, RoundingMode::HalfUp))->toBe('1.02')
        ->and($p->status)->toBe(GoalStatus::OnTrack)
        ->and($p->daysElapsed)->toBe(20)
        ->and($p->daysRemaining)->toBe(10)
        ->and((string) $p->expected?->toScale(2, RoundingMode::HalfUp))->toBe((string) $target->multipliedBy('0.666667')->toScale(2, RoundingMode::HalfUp)) // 20/30 del mes
        ->and($p->gap?->isNegative())->toBeTrue();
});

it('los días atípicos cuentan en el acumulado pero no marcan el ritmo', function (): void {
    $rows = uniformRows(10);
    $rows[4] = (new IndicatorCalculator)->daily([DailyRecordData::fromArray(['date' => '2025-09-05', 'sales_bs' => '1000', 'exchange_rate' => '100', 'transactions' => 2, 'units' => 4, 'inventory_units' => null, 'inventory_value_usd' => null, 'shifts' => 3, 'status' => 'atypical'])])[0];

    $p = projector()->project(Indicator::SalesUsd, BigDecimal::of('3000'), $rows, Period::of('2025-09'), WeekdayPattern::flat(), CarbonImmutable::parse('2025-09-10'));

    // actual = 9 × 100 + 10 = 910; ritmo = 900 / 9 días normales = 100/día; quedan 20 días → 2.910
    expect((string) $p->actual)->toBe('910.0000')
        ->and((string) $p->projection?->toScale(0, RoundingMode::HalfUp))->toBe('2910')
        ->and($p->status)->toBe(GoalStatus::AtRisk); // 97 %
});

it('con patrón semanal fiable proyecta por pesos y cuenta los días fuertes que quedan', function (): void {
    $rows = (new IndicatorCalculator)->daily(SeptemberFixture::records());
    $pattern = WeekdayPattern::fromMetrics($rows, minWeeks: 4); // el fixture tiene 5 semanas: fiable para la prueba

    $p = projector()->project(Indicator::SalesUsd, BigDecimal::of('20000'), $rows, Period::of('2025-09'), $pattern, CarbonImmutable::parse('2025-09-20'));

    $strongest = $pattern->strongestDay();
    $remainingStrong = count(array_filter(Period::of('2025-09')->dates(), fn ($d) => $d->day > 20 && $d->dayOfWeekIso === $strongest));

    expect($p->method)->toBe('weekday')
        ->and($p->strongestDay)->toBe($strongest)
        ->and($p->remainingStrongDays)->toBe($remainingStrong)
        ->and($p->projection?->isGreaterThan($p->actual ?? BigDecimal::zero()))->toBeTrue()
        ->and($p->series['dates'])->toHaveCount(30)
        ->and($p->series['cutoffIndex'])->toBe(19)
        ->and($p->series['actual'][19])->not->toBeNull()
        ->and($p->series['actual'][20])->toBeNull()
        ->and($p->series['projected'][18])->toBeNull()
        ->and(round($p->series['projected'][19]))->toBe(round($p->series['actual'][19]))
        ->and(round($p->series['projected'][29]))->toBe(round((float) $p->projection?->toFloat()))
        ->and(round($p->series['expected'][29]))->toBe(20000.0)
        ->and(round(array_sum($p->series['daily'])))->toBe(20000.0);
});

it('los ratios no acumulan: el estado sale del valor ponderado a la fecha', function (): void {
    $p = projector()->project(Indicator::AvgTicketUsd, BigDecimal::of('5'), uniformRows(10), Period::of('2025-09'), WeekdayPattern::flat(), CarbonImmutable::parse('2025-09-10'));

    expect($p->method)->toBe('ratio')
        ->and((string) $p->actual)->toBe('5.0000') // $100 / 20 transacciones
        ->and((string) $p->projection)->toBe('5.0000')
        ->and((string) $p->expected)->toBe('5')
        ->and($p->status)->toBe(GoalStatus::OnTrack)
        ->and($p->series)->toBeNull()
        ->and($p->accumulates())->toBeFalse();
});

it('umbrales de estado: 100 % en meta, 90 % en riesgo, menos fuera de meta (§8.2)', function (): void {
    $rows = uniformRows(30); // mes completo: $3.000
    $at = fn (string $target) => projector()->project(Indicator::SalesUsd, BigDecimal::of($target), $rows, Period::of('2025-09'), WeekdayPattern::flat(), CarbonImmutable::parse('2025-10-03'))->status;

    expect($at('3000'))->toBe(GoalStatus::OnTrack)
        ->and($at('3300'))->toBe(GoalStatus::AtRisk)   // 90,9 %
        ->and($at('3400'))->toBe(GoalStatus::OffTrack); // 88 %
});

it('un mes completo no proyecta: la proyección es el real y no quedan días', function (): void {
    $p = projector()->project(Indicator::SalesUsd, BigDecimal::of('2500'), uniformRows(30), Period::of('2025-09'), WeekdayPattern::flat(), CarbonImmutable::parse('2025-10-15'));

    expect($p->isComplete())->toBeTrue()
        ->and((string) $p->projection?->toScale(4, RoundingMode::HalfUp))->toBe('3000.0000')
        ->and((string) $p->gap?->toScale(4, RoundingMode::HalfUp))->toBe('-500.0000')
        ->and($p->cutoff->toDateString())->toBe('2025-09-30');
});

it('sin meta o sin datos lo dice en vez de fallar', function (): void {
    $noGoal = projector()->project(Indicator::SalesUsd, null, uniformRows(5), Period::of('2025-09'), WeekdayPattern::flat(), CarbonImmutable::parse('2025-09-05'));
    $noData = projector()->project(Indicator::SalesUsd, BigDecimal::of('1000'), [], Period::of('2025-09'), WeekdayPattern::flat(), CarbonImmutable::parse('2025-08-31'));
    $zero = projector()->project(Indicator::SalesUsd, BigDecimal::zero(), uniformRows(5), Period::of('2025-09'), WeekdayPattern::flat(), CarbonImmutable::parse('2025-09-05'));

    expect($noGoal->status)->toBe(GoalStatus::NoGoal)
        ->and($noGoal->hasGoal())->toBeFalse()
        ->and($noGoal->expected)->toBeNull()
        ->and($noData->status)->toBe(GoalStatus::Pending)
        ->and($noData->actual)->toBeNull()
        ->and($noData->daysElapsed)->toBe(0)
        ->and($zero->status)->toBe(GoalStatus::NoGoal);
});
