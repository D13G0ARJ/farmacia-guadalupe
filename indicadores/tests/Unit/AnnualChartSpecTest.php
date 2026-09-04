<?php

declare(strict_types=1);

use App\Domain\Charts\ChartSpecBuilder;
use App\Domain\Indicators\Indicator;
use App\Domain\Indicators\IndicatorCalculator;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use Brick\Math\BigDecimal;
use Tests\Support\SeptemberFixture;

it('G10 cruza la venta en dólares (barras) con la tasa (línea, eje derecho)', function (): void {
    $rows = (new IndicatorCalculator)->daily(SeptemberFixture::records());

    $spec = (new ChartSpecBuilder(new Formatter))->build('g10', $rows, Period::of('2025-09'));

    expect($spec->title)->toBe('Tasa BCV frente a la venta en dólares')
        ->and($spec->option['series'][0]['type'])->toBe('bar')
        ->and($spec->option['series'][1]['type'])->toBe('line')
        ->and($spec->option['series'][1]['yAxisIndex'])->toBe(1)
        ->and(round($spec->option['series'][1]['data'][0], 2))->toBe(148.44)
        ->and($spec->meta['axes'][1])->toBe(['format' => 'num', 'precision' => 2])
        ->and($spec->meta['tooltips'][0])->toBe('lun 01/09 · $ 614 · tasa 148,44');
});

it('G11 agrupa por mes el año actual y el anterior con huecos donde no hay datos', function (): void {
    $current = array_fill(1, 12, null);
    $previous = array_fill(1, 12, null);
    $current[8] = BigDecimal::of('17500');
    $current[9] = BigDecimal::of('18610.68');
    $previous[9] = BigDecimal::of('15000');

    $spec = (new ChartSpecBuilder(new Formatter))->annualComparison(2025, $current, $previous, Indicator::SalesUsd);

    expect($spec->id)->toBe('g11')
        ->and($spec->title)->toBe('Venta en dólares por mes')
        ->and($spec->subtitle)->toBe('2025 frente a 2024')
        ->and($spec->option['xAxis']['data'][8])->toBe('sep')
        ->and($spec->option['series'][0]['name'])->toBe('2025')
        ->and($spec->option['series'][1]['name'])->toBe('2024')
        ->and($spec->option['series'][0]['data'][8])->toBe(18610.68)
        ->and($spec->option['series'][0]['data'][0])->toBeNull()
        ->and($spec->option['series'][1]['data'][8])->toBe(15000.0)
        ->and($spec->meta['tooltips'][8])->toBe('sep · 2025: $ 18.611 · 2024: $ 15.000')
        ->and($spec->meta['tooltips'][0])->toBe('ene · 2025: — · 2024: —')
        ->and($spec->table['rows'])->toHaveCount(12)
        ->and($spec->table['head'])->toBe(['Mes', '2025', '2024']);

    $empty = (new ChartSpecBuilder(new Formatter))->annualComparison(2023, array_fill(1, 12, null), array_fill(1, 12, null), Indicator::Units);
    expect($empty->empty)->toBeTrue()->and($empty->emptyText)->toContain('2023', '2022');
});
