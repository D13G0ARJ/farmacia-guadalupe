<?php

declare(strict_types=1);

use App\Domain\Indicators\DailyRecordData;
use App\Domain\Records\DailyRecordInput;
use App\Domain\Records\WarningContext;
use App\Domain\Records\WarningDetector;
use App\Domain\Shared\Formatter;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

function inputWith(array $overrides = []): DailyRecordInput
{
    $base = [
        'branchId' => 1, 'date' => CarbonImmutable::parse('2025-09-02'), 'salesBs' => BigDecimal::of('97779.71'),
        'rate' => BigDecimal::of('149.46'), 'transactions' => 138, 'units' => 257, 'inventoryUnits' => 9848,
        'inventoryValueUsd' => BigDecimal::of('22358.31'), 'shifts' => 4,
    ];

    return new DailyRecordInput(...array_merge($base, $overrides));
}

function contextWith(array $overrides = []): WarningContext
{
    $previous = DailyRecordData::fromArray([
        'date' => '2025-09-01', 'sales_bs' => '91154.02', 'exchange_rate' => '148.44',
        'transactions' => 119, 'units' => 300, 'shifts' => 4,
    ]);
    $base = [
        'previous' => $previous,
        'recentSalesBs' => [BigDecimal::of('91154.02'), BigDecimal::of('97779.71'), BigDecimal::of('102970.43')],
        'countsInventoryToday' => true, 'salesDeviationPct' => 35, 'rateDeviationPct' => 10,
    ];

    return new WarningContext(...array_merge($base, $overrides));
}

$detector = new WarningDetector(new Formatter);

it('un día normal no genera advertencias', function () use ($detector): void {
    expect($detector->detect(inputWith(), contextWith()))->toBe([]);
});

it('avisa cuando la tasa se aparta más del 10 % de la anterior, con la comparación concreta (H1 del formulario)', function () use ($detector): void {
    $warnings = $detector->detect(inputWith(['rate' => BigDecimal::of('15')]), contextWith());

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->code)->toBe('rate_deviation')
        ->and($warnings[0]->field)->toBe('rate')
        ->and($warnings[0]->message)->toBe('Es 90 % menor que ayer (148,44). Revísala.');
});

it('avisa cuando la venta se desvía más del umbral respecto al promedio reciente', function () use ($detector): void {
    $warnings = $detector->detect(inputWith(['salesBs' => BigDecimal::of('28594.37')]), contextWith());

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->code)->toBe('sales_deviation')
        ->and($warnings[0]->message)->toStartWith('Es 71 % menor que el promedio de los últimos 3 días (Bs 97.301).');
});

it('detecta unidades menores que transacciones y ventas sin transacciones', function () use ($detector): void {
    $a = $detector->detect(inputWith(['units' => 120]), contextWith());
    $b = $detector->detect(inputWith(['transactions' => 0, 'units' => 0]), contextWith());
    $c = $detector->detect(inputWith(['salesBs' => BigDecimal::zero()]), contextWith(['recentSalesBs' => []]));

    expect($a[0]->code)->toBe('units_lt_transactions')
        ->and($a[0]->message)->toBe('Hay menos unidades (120) que transacciones (138).')
        ->and(array_column($b, 'code'))->toContain('transactions_zero_with_sales')
        ->and(array_column($c, 'code'))->toContain('sales_zero_with_transactions');
});

it('avisa si toca conteo de inventario y está vacío, pero no los sábados (RN-09)', function () use ($detector): void {
    $empty = ['inventoryUnits' => null, 'inventoryValueUsd' => null];

    $weekday = $detector->detect(inputWith($empty), contextWith(['countsInventoryToday' => true]));
    $saturday = $detector->detect(inputWith($empty), contextWith(['countsInventoryToday' => false]));

    expect(array_column($weekday, 'code'))->toBe(['inventory_missing'])
        ->and($saturday)->toBe([]);
});

it('sin historial no avisa por desvío de venta ni de tasa', function () use ($detector): void {
    $ctx = contextWith(['previous' => null, 'recentSalesBs' => []]);

    expect($detector->detect(inputWith(['rate' => BigDecimal::of('15'), 'salesBs' => BigDecimal::of('5')]), $ctx))->toBe([]);
});
