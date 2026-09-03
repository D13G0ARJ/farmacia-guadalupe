<?php

declare(strict_types=1);

use App\Domain\Indicators\PeriodSummary;
use App\Domain\Shared\Period;
use App\Support\PeriodSummaryCache;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Cache;

function summaryFixture(): PeriodSummary
{
    $sums = ['salesBs' => BigDecimal::of('3012770.86'), 'salesUsd' => BigDecimal::of('18611.0467'), 'transactions' => 3853, 'units' => 7543, 'shifts' => 93];

    return new PeriodSummary(30, 25, 0, 0, $sums, $sums, BigDecimal::of('781.9260'), BigDecimal::of('4.8303'), BigDecimal::of('1.9577'), BigDecimal::of('41.4301'), null, BigDecimal::of('161.8816'), null, BigDecimal::of('9540.2'), BigDecimal::of('22716.3612'), 9831, BigDecimal::of('23545.10'), BigDecimal::of('148.44'), BigDecimal::of('177.61'), BigDecimal::of('19.65'));
}

it('sobrevive a un store que serializa (los stores reales no deserializan objetos)', function (): void {
    config()->set('cache.stores.array.serialize', true);
    Cache::forgetDriver('array');
    $cache = new PeriodSummaryCache(Cache::store('array'));
    $period = Period::of('2025-09');
    $calls = 0;

    $first = $cache->remember(1, $period, false, function () use (&$calls): PeriodSummary {
        $calls++;

        return summaryFixture();
    });
    $second = $cache->remember(1, $period, false, function () use (&$calls): PeriodSummary {
        $calls++;

        return summaryFixture();
    });

    expect($calls)->toBe(1)
        ->and($second)->toBeInstanceOf(PeriodSummary::class)
        ->and($second->toArray())->toBe($first->toArray())
        ->and((string) $second->sumsAll['salesUsd'])->toBe('18611.0467')
        ->and($second->inventoryLastUnits)->toBe(9831)
        ->and($second->salesPerShiftUsd)->toBeNull();
});

it('ida y vuelta del resumen vacío y con nulos', function (): void {
    $empty = PeriodSummary::empty();

    expect(PeriodSummary::fromArray($empty->toArray())->isEmpty())->toBeTrue()
        ->and(PeriodSummary::fromArray($empty->toArray())->toArray())->toBe($empty->toArray());
});

it('olvidar una sede invalida también el consolidado', function (): void {
    $cache = new PeriodSummaryCache(Cache::store('array'));
    $period = Period::of('2025-09');
    $calls = 0;
    $compute = function () use (&$calls): PeriodSummary {
        $calls++;

        return summaryFixture();
    };

    $cache->remember(1, $period, false, $compute);
    $cache->remember(null, $period, false, $compute);
    $cache->forget(1, $period);
    $cache->remember(1, $period, false, $compute);
    $cache->remember(null, $period, false, $compute);

    expect($calls)->toBe(4);
});
