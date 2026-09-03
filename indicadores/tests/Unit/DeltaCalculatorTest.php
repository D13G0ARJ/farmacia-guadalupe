<?php

declare(strict_types=1);

use App\Domain\Indicators\DeltaCalculator;
use App\Domain\Indicators\Indicator;
use App\Domain\Indicators\IndicatorCalculator;
use App\Domain\Indicators\PeriodSummary;
use Brick\Math\RoundingMode;
use Tests\Support\SeptemberFixture;

function septemberSummary(): PeriodSummary
{
    return (new IndicatorCalculator)->summarize(SeptemberFixture::records());
}

/** Copia del resumen de septiembre con algunos valores sustituidos. */
function summaryWith(array $overrides): PeriodSummary
{
    $data = septemberSummary()->toArray();
    foreach ($overrides as $key => $value) {
        if (is_array($value)) {
            $data[$key] = array_merge($data[$key], $value);
        } else {
            $data[$key] = $value;
        }
    }

    return PeriodSummary::fromArray($data);
}

it('calcula la variación relativa y su tono según si más es mejor', function (): void {
    $current = septemberSummary();
    $previous = summaryWith(['sumsAll' => ['salesUsd' => '20000']]);

    $delta = (new DeltaCalculator)->delta(Indicator::SalesUsd, $current, $previous, 'agosto');

    expect((string) $delta->variation?->toScale(4, RoundingMode::HalfUp))->toBe('-0.0695') // 18.610,68 / 20.000 − 1
        ->and($delta->direction())->toBe(-1)
        ->and($delta->tone())->toBe('danger')
        ->and($delta->against)->toBe('agosto')
        ->and($delta->comparedDays)->toBeNull()
        ->and($delta->usdVariation)->toBeNull();
});

it('acompaña los indicadores en Bs con la variación en dólares para aislar la tasa (§7.2)', function (): void {
    $current = septemberSummary();
    $previous = summaryWith(['sumsAll' => ['salesBs' => '2800000', 'salesUsd' => '20000']]);

    $delta = (new DeltaCalculator)->delta(Indicator::SalesBs, $current, $previous, 'agosto', 12);

    expect($delta->variation?->toScale(3, RoundingMode::HalfUp)->__toString())->toBe('0.076')
        ->and($delta->tone())->toBe('success')
        ->and((string) $delta->usdVariation?->toScale(4, RoundingMode::HalfUp))->toBe('-0.0695')
        ->and($delta->comparedDays)->toBe(12);
});

it('no colorea cambios imperceptibles ni la tasa', function (): void {
    $current = septemberSummary();
    $calculator = new DeltaCalculator;

    $same = $calculator->delta(Indicator::Transactions, $current, summaryWith([]), 'agosto');
    $rate = $calculator->delta(Indicator::AvgRate, $current, summaryWith(['avgRate' => '100']), 'agosto');

    expect($same->direction())->toBe(0)
        ->and($same->tone())->toBe('neutral')
        ->and($rate->direction())->toBe(1)
        ->and($rate->tone())->toBe('neutral');
});

it('no hay variación sin referencia, con referencia vacía o con base cero', function (): void {
    $current = septemberSummary();
    $calculator = new DeltaCalculator;

    expect($calculator->delta(Indicator::SalesUsd, $current, null, 'agosto')->isAvailable())->toBeFalse()
        ->and($calculator->delta(Indicator::SalesUsd, $current, PeriodSummary::empty(), 'agosto')->isAvailable())->toBeFalse()
        ->and($calculator->delta(Indicator::SalesUsd, $current, summaryWith(['sumsAll' => ['salesUsd' => '0']]), 'agosto')->isAvailable())->toBeFalse()
        ->and($calculator->delta(Indicator::SalesUsd, $current, null, 'agosto')->tone())->toBe('neutral');
});

it('compara todos los indicadores pedidos e indexa por su clave', function (): void {
    $deltas = (new DeltaCalculator)->compare(septemberSummary(), summaryWith([]), 'agosto', Indicator::primary());

    expect(array_keys($deltas))->toBe(['sales_usd', 'transactions', 'avg_ticket_usd', 'units_per_transaction'])
        ->and($deltas['sales_usd']->direction())->toBe(0);
});
