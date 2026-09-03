<?php

declare(strict_types=1);

use App\Domain\Indicators\IndicatorCalculator;
use App\Domain\Indicators\WeekdayPattern;
use App\Domain\Shared\Decimal;
use App\Domain\Shared\Period;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Tests\Support\SeptemberFixture;

$metrics = (new IndicatorCalculator)->daily(SeptemberFixture::records());

it('con septiembre real, el miércoles es el día más fuerte y los pesos suman 1', function () use ($metrics): void {
    $pattern = WeekdayPattern::fromMetrics($metrics);

    expect($pattern->strongestDay())->toBe(3)
        ->and((string) Decimal::sum($pattern->weights)->toScale(2, RoundingMode::HalfUp))->toBe('1.00')
        ->and($pattern->weightFor(CarbonImmutable::parse('2025-09-24'))->isGreaterThan($pattern->weightFor(CarbonImmutable::parse('2025-09-06'))))->toBeTrue();
});

it('un solo mes no es histórico suficiente: la proyección debe caer al método lineal (RN-18)', function () use ($metrics): void {
    $pattern = WeekdayPattern::fromMetrics($metrics);

    expect($pattern->weeksObserved)->toBeLessThan(WeekdayPattern::MIN_WEEKS)
        ->and($pattern->isReliable())->toBeFalse()
        ->and(WeekdayPattern::fromMetrics($metrics, minWeeks: 4)->isReliable())->toBeTrue();
});

it('el día atípico no contamina el patrón', function () use ($metrics): void {
    $withAtypicalExcluded = WeekdayPattern::fromMetrics($metrics);
    $ignoringStatus = WeekdayPattern::fromMetrics((new IndicatorCalculator)->daily(SeptemberFixture::records(markAtypical: false)));

    // Al excluir el 16/09 (martes, $178), el martes pesa más que si se incluyera.
    expect($withAtypicalExcluded->weights[2]->isGreaterThan($ignoringStatus->weights[2]))->toBeTrue();
});

it('reparte el mes: la fracción esperada al día 15 y el peso restante son complementarios', function () use ($metrics): void {
    $pattern = WeekdayPattern::fromMetrics($metrics);
    $period = Period::of('2025-09');
    $cut = CarbonImmutable::parse('2025-09-15');

    $share = $pattern->shareOfPeriod($period, $cut);
    $remaining = $pattern->remainingWeight($period, $cut);
    $total = BigDecimal::zero();
    foreach ($period->dates() as $d) {
        $total = $total->plus($pattern->weightFor($d));
    }

    expect($share->isPositive())->toBeTrue()
        ->and($share->isLessThan(BigDecimal::one()))->toBeTrue()
        ->and((string) $share->plus($remaining->dividedBy($total, 6, RoundingMode::HalfUp))->toScale(2, RoundingMode::HalfUp))->toBe('1.00');
});

it('el patrón plano pesa igual todos los días', function (): void {
    $flat = WeekdayPattern::flat();

    expect($flat->isReliable())->toBeFalse()
        ->and((string) $flat->weightFor(CarbonImmutable::parse('2025-09-01'))->toScale(3, RoundingMode::HalfUp))->toBe('0.143')
        ->and((string) $flat->shareOfPeriod(Period::of('2025-09'), CarbonImmutable::parse('2025-09-15'))->toScale(2, RoundingMode::HalfUp))->toBe('0.50');
});
