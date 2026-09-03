<?php

declare(strict_types=1);

use App\Domain\Indicators\DailyRecordData;
use App\Domain\Indicators\Indicator;
use App\Domain\Indicators\IndicatorCalculator;
use App\Domain\Shared\Decimal;
use App\Enums\DayStatus;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Tests\Support\SeptemberFixture;

$calc = new IndicatorCalculator;

it('reproduce los valores dorados de septiembre 2025 (§2.6)', function () use ($calc): void {
    $s = $calc->summarize(SeptemberFixture::records(markAtypical: false));
    $g = SeptemberFixture::golden();

    expect($s->days)->toBe(30)
        ->and((string) $s->sumsAll['salesBs'])->toBe($g['sales_bs'])
        ->and((string) $s->sumsAll['salesUsd']->toScale(2, RoundingMode::HalfUp))->toBe($g['sales_usd'])
        ->and($s->sumsAll['transactions'])->toBe($g['transactions'])
        ->and($s->sumsAll['units'])->toBe($g['units'])
        ->and($s->sumsAll['shifts'])->toBe($g['shifts'])
        ->and((string) $s->avgTicketBs?->toScale(2, RoundingMode::HalfUp))->toBe($g['avg_ticket_bs'])
        ->and((string) $s->unitsPerTransaction?->toScale(4, RoundingMode::HalfUp))->toBe($g['units_per_transaction'])
        ->and((string) $s->avgTicketUsd?->toScale(4, RoundingMode::HalfUp))->toBe($g['avg_ticket_usd'])
        ->and((string) $s->transactionsPerShift?->toScale(2, RoundingMode::HalfUp))->toBe($g['transactions_per_shift'])
        ->and($s->daysWithInventory)->toBe($g['days_with_inventory'])
        ->and((string) $s->rateFirst)->toBe($g['rate_first'])
        ->and((string) $s->rateLast)->toBe($g['rate_last']);
});

it('la venta en divisa es la suma de las conversiones diarias, no el total entre la tasa promedio (RN-05)', function () use ($calc): void {
    $s = $calc->summarize(SeptemberFixture::records(markAtypical: false));

    $shortcut = $s->sumsAll['salesBs']->dividedBy($s->avgRateSimple, 2, RoundingMode::HalfUp);

    expect((string) $s->sumsAll['salesUsd']->toScale(2, RoundingMode::HalfUp))->toBe('18610.68')
        ->and((string) $shortcut)->toBe('18633.78') // lo que daría el atajo incorrecto
        ->and((string) $s->avgRateSimple?->toScale(4, RoundingMode::HalfUp))->toBe('161.6833'); // AVERAGE(E4:E33) del Excel
});

it('los ratios son ponderados, nunca promedio de promedios (RN-04)', function () use ($calc): void {
    $records = SeptemberFixture::records(markAtypical: false);
    $s = $calc->summarize($records);

    // Promedio de los tickets diarios (lo que hace el Excel en H34): 784,63.
    $dailyTickets = array_map(fn ($m) => $m->avgTicketBs, $calc->daily($records));
    $averageOfAverages = Decimal::average($dailyTickets, 2);

    expect((string) $averageOfAverages)->toBe('784.63')
        ->and((string) $s->avgTicketBs?->toScale(2, RoundingMode::HalfUp))->toBe('781.93')
        ->and((string) $s->transactionsPerShift?->toScale(2, RoundingMode::HalfUp))->toBe('41.43'); // Excel: 41,64
});

it('el promedio del inventario solo cuenta los días con conteo y expone el último valor', function () use ($calc): void {
    $s = $calc->summarize(SeptemberFixture::records());

    expect($s->daysWithInventory)->toBe(25)
        ->and((string) $s->inventoryAvgUnits)->toBe('9737.8')
        ->and((string) $s->inventoryAvgValueUsd)->toBe('22716.44')
        ->and($s->inventoryLastUnits)->toBe(9831)
        ->and((string) $s->inventoryLastValueUsd)->toBe('23545.31');
});

it('calcula la variación de la tasa entre el primer y el último día (+19,65 %)', function () use ($calc): void {
    $s = $calc->summarize(SeptemberFixture::records());

    expect((string) $s->rateVariationPct?->toScale(4, RoundingMode::HalfUp))->toBe('0.1965')
        ->and((string) $s->value(Indicator::RateVariationPct)?->toScale(3, RoundingMode::HalfUp))->toBe('0.197');
});

it('excluir atípicos afecta a los ratios pero no a las sumas (RN-11)', function () use ($calc): void {
    $records = SeptemberFixture::records(markAtypical: true);
    $with = $calc->summarize($records, excludeAtypical: false);
    $without = $calc->summarize($records, excludeAtypical: true);

    expect($without->excludedAtypical)->toBe(1)
        ->and((string) $without->sumsAll['salesBs'])->toBe((string) $with->sumsAll['salesBs']) // las sumas no cambian
        ->and($without->sumsFiltered['transactions'])->toBe(3853 - 32)
        ->and((string) $without->sumsFiltered['salesBs'])->toBe('2984176.49')
        ->and((string) $without->avgTicketBs?->toScale(2, RoundingMode::HalfUp))->toBe('780.99')
        ->and((string) $with->avgTicketBs?->toScale(2, RoundingMode::HalfUp))->toBe('781.93');
});

it('un día cerrado cuenta como día del período pero no entra en ratios ni divide por cero', function () use ($calc): void {
    $closed = DailyRecordData::fromArray([
        'date' => '2025-09-31', 'sales_bs' => '0', 'exchange_rate' => '177.61', 'transactions' => 0,
        'units' => 0, 'shifts' => 0, 'status' => DayStatus::Closed->value,
    ]);
    $daily = $calc->daily([$closed])[0];
    $s = $calc->summarize([...SeptemberFixture::records(), $closed]);

    expect($daily->avgTicketBs)->toBeNull()
        ->and($daily->transactionsPerShift)->toBeNull()
        ->and($daily->salesUsd?->isZero())->toBeTrue()
        ->and($s->days)->toBe(31)
        ->and($s->closedDays)->toBe(1)
        ->and($s->sumsFiltered['transactions'])->toBe(3853)
        ->and((string) $s->avgTicketBs?->toScale(2, RoundingMode::HalfUp))->toBe('781.93');
});

it('deriva cada día correctamente: el 01/09/2025 es lunes y vende $ 614 a 148,44', function () use ($calc): void {
    $first = $calc->daily(SeptemberFixture::records())[0];

    expect($first->weekday)->toBe(1)
        ->and((string) $first->salesUsd?->toScale(2, RoundingMode::HalfUp))->toBe('614.08')
        ->and((string) $first->avgTicketBs?->toScale(2, RoundingMode::HalfUp))->toBe('766.00')
        ->and((string) $first->unitsPerTransaction?->toScale(2, RoundingMode::HalfUp))->toBe('2.52')
        ->and((string) $first->transactionsPerShift?->toScale(2, RoundingMode::HalfUp))->toBe('29.75')
        ->and($first->value(Indicator::Transactions))->toEqual(BigDecimal::of(119));
});

it('un período vacío devuelve un resumen vacío sin excepciones', function () use ($calc): void {
    $s = $calc->summarize([]);

    expect($s->isEmpty())->toBeTrue()
        ->and($s->value(Indicator::SalesUsd)->isZero())->toBeTrue()
        ->and($s->value(Indicator::AvgTicketBs))->toBeNull();
});
