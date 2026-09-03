<?php

declare(strict_types=1);

use App\Domain\Charts\ChartGoalContext;
use App\Domain\Charts\ChartSpecBuilder;
use App\Domain\Goals\GoalProjector;
use App\Domain\Indicators\DailyRecordData;
use App\Domain\Indicators\Indicator;
use App\Domain\Indicators\IndicatorCalculator;
use App\Domain\Indicators\WeekdayPattern;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Tests\Support\SeptemberFixture;

function septemberRows(): array
{
    return (new IndicatorCalculator)->daily(SeptemberFixture::records());
}

function builder(): ChartSpecBuilder
{
    return new ChartSpecBuilder(new Formatter);
}

it('G2: barras de venta en dólares sobre los 30 días, con el atípico marcado y tooltips en es-VE', function (): void {
    $spec = builder()->build('g2', septemberRows(), Period::of('2025-09'));

    expect($spec->empty)->toBeFalse()
        ->and($spec->title)->toBe('Venta en dólares por día')
        ->and($spec->subtitle)->toBe('Septiembre 2025 · Sin meta definida')
        ->and($spec->slug)->toBe('g2-2025-09')
        ->and($spec->option['xAxis']['data'])->toHaveCount(30)
        ->and($spec->option['xAxis']['data'][0])->toBe('lun 1')
        ->and($spec->option['series'])->toHaveCount(1)
        ->and(round($spec->option['series'][0]['data'][0]))->toBe(614.0)
        ->and(round($spec->option['series'][0]['data'][15]))->toBe(178.0)
        ->and($spec->option['series'][0]['markPoint']['data'][0]['coord'][0])->toBe(15)
        ->and($spec->meta['trigger'])->toBe('axis')
        ->and($spec->meta['tooltips'][0])->toBe('lun 01/09 · $ 614')
        ->and($spec->meta['tooltips'][15])->toContain('mar 16/09', 'Atípico')
        ->and($spec->meta['axes'])->toBe([['format' => 'money', 'currency' => 'USD', 'precision' => 0]])
        ->and($spec->table['head'])->toBe(['Día', 'Venta en dólares'])
        ->and($spec->table['rows'])->toHaveCount(30)
        ->and($spec->table['rows'][0])->toBe(['lun 01/09', '$ 614']);
});

it('G3: dos series en dos ejes con leyenda y tooltip combinado', function (): void {
    $spec = builder()->build('g3', septemberRows(), Period::of('2025-09'));

    expect($spec->option['series'])->toHaveCount(2)
        ->and($spec->option['series'][1]['type'])->toBe('line')
        ->and($spec->option['series'][1]['yAxisIndex'])->toBe(1)
        ->and($spec->option['yAxis'])->toHaveCount(2)
        ->and($spec->option['legend']['data'])->toBe(['Transacciones', 'Unidades vendidas'])
        ->and($spec->meta['axes'])->toHaveCount(2)
        ->and($spec->meta['tooltips'][0])->toBe('lun 01/09 · 119 transacciones · 300 unidades');
});

it('G7: los sábados y el día sin conteo quedan como huecos, no como ceros', function (): void {
    $spec = builder()->build('g7', septemberRows(), Period::of('2025-09'));
    $gaps = array_filter($spec->option['series'][0]['data'], fn ($v) => $v === null);

    expect($gaps)->toHaveCount(5)
        ->and($spec->option['series'][1]['connectNulls'])->toBeFalse()
        ->and($spec->subtitle)->toContain('sábados');
});

it('G8: mapa de calor sobre el calendario del mes con una celda por día cargado', function (): void {
    $spec = builder()->build('g8', septemberRows(), Period::of('2025-09'));

    expect($spec->option['calendar']['range'])->toBe('2025-09')
        ->and($spec->option['calendar']['orient'])->toBe('vertical')
        ->and($spec->option['series'][0]['type'])->toBe('heatmap')
        ->and($spec->option['series'][0]['data'])->toHaveCount(30)
        ->and($spec->option['series'][0]['data'][0]['value'][0])->toBe('2025-09-01')
        ->and($spec->option['visualMap']['min'])->toBeLessThanOrEqual(178.0)
        ->and($spec->option['visualMap']['max'])->toBeGreaterThanOrEqual(854.0)
        ->and($spec->meta['trigger'])->toBe('item')
        ->and($spec->meta['cellLabel'])->toBe('day')
        ->and($spec->meta['tooltips'][0])->toBe('lun 01/09 · $ 614 · 119 transacciones')
        ->and($spec->meta['tooltips'][15])->toContain('Atípico');
});

it('un día cerrado se sombrea y no aporta valor; un día faltante se anuncia como sin dato', function (): void {
    $rows = (new IndicatorCalculator)->daily([
        DailyRecordData::fromArray(['date' => '2025-09-01', 'sales_bs' => '1000', 'exchange_rate' => '100', 'transactions' => 10, 'units' => 20, 'inventory_units' => null, 'inventory_value_usd' => null, 'shifts' => 3]),
        DailyRecordData::fromArray(['date' => '2025-09-02', 'sales_bs' => '0', 'exchange_rate' => '100', 'transactions' => 0, 'units' => 0, 'inventory_units' => null, 'inventory_value_usd' => null, 'shifts' => 0, 'status' => 'closed', 'notes' => 'Feriado']),
    ]);

    $spec = builder()->build('g1', $rows, Period::of('2025-09'));

    expect($spec->option['series'][0]['data'][0])->toBe(1000.0)
        ->and($spec->option['series'][0]['data'][1])->toBeNull()
        ->and($spec->option['series'][0]['markArea']['data'][0][0]['xAxis'])->toBe(1)
        ->and($spec->meta['tooltips'][1])->toBe('mar 02/09 · Cerrado: Feriado')
        ->and($spec->meta['tooltips'][2])->toBe('mié 03/09 · Sin dato')
        ->and($spec->table['rows'])->toHaveCount(2);
});

it('sin filas devuelve una especificación vacía con el texto del estado vacío', function (): void {
    $spec = builder()->build('g2', [], Period::of('2025-09'));

    expect($spec->empty)->toBeTrue()
        ->and($spec->emptyText)->toBe('Aún no hay días cargados en septiembre 2025.')
        ->and($spec->toArray()['option'])->toBe([]);
});

it('G2 con meta añade la línea de meta diaria y G9 dibuja acumulado, esperado, proyección y meta', function (): void {
    $rows = septemberRows();
    $projector = new GoalProjector(new IndicatorCalculator);
    $progress = ['sales_usd' => $projector->project(Indicator::SalesUsd, BigDecimal::of('20000'), $rows, Period::of('2025-09'), WeekdayPattern::flat(), CarbonImmutable::parse('2025-09-20'))];
    $goal = ChartGoalContext::fromProgress($progress);

    $g2 = builder()->build('g2', $rows, Period::of('2025-09'), $goal);
    $g9 = builder()->build('g9', $rows, Period::of('2025-09'), $goal);
    $g9Without = builder()->build('g9', $rows, Period::of('2025-09'));

    expect($g2->option['series'])->toHaveCount(2)
        ->and($g2->option['series'][1]['name'])->toBe('Meta diaria')
        ->and($g2->option['legend']['data'])->toBe(['Venta en dólares', 'Meta diaria'])
        ->and($g2->meta['tooltips'][0])->toContain('meta $ 667')
        ->and($g2->table['head'])->toBe(['Día', 'Venta en dólares'])
        ->and($g2->subtitle)->toContain('Meta diaria')
        ->and($g9->empty)->toBeFalse()
        ->and($g9->title)->toBe('Acumulado frente a la meta')
        ->and($g9->subtitle)->toContain('proyección lineal')
        ->and(array_column($g9->option['series'], 'name'))->toBe(['Acumulado', 'Esperado', 'Proyección'])
        ->and($g9->option['series'][0]['markLine']['data'][0]['yAxis'])->toBe(20000.0)
        ->and($g9->option['series'][0]['markPoint']['data'][0]['coord'][0])->toBe(19)
        ->and($g9->option['series'][0]['data'][20])->toBeNull()
        ->and($g9->option['series'][2]['data'][18])->toBeNull()
        ->and($g9->option['series'][2]['data'][29])->not->toBeNull()
        ->and($g9->meta['tooltips'][0])->toContain('lun 01/09 · acumulado $ 614 · esperado $ 667')
        ->and($g9->meta['tooltips'][29])->toContain('proyección')
        ->and($g9->table['rows'])->toHaveCount(30)
        ->and($g9Without->empty)->toBeTrue()
        ->and($g9Without->emptyText)->toContain('Define una meta');
});

it('rechaza una gráfica fuera del catálogo', function (): void {
    builder()->build('g99', septemberRows(), Period::of('2025-09'));
})->throws(InvalidArgumentException::class);
