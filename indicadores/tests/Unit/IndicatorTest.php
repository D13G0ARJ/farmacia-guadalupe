<?php

declare(strict_types=1);

use App\Domain\Indicators\Aggregation;
use App\Domain\Indicators\Indicator;
use App\Domain\Indicators\Unit;
use App\Enums\Currency;

it('todo indicador tiene etiqueta, etiqueta corta y explicación', function (): void {
    foreach (Indicator::cases() as $indicator) {
        expect($indicator->label())->not->toBe('')
            ->and($indicator->shortLabel())->not->toBe('')
            ->and($indicator->explanation())->toEndWith('.');
    }
});

it('respeta la precisión de presentación del Excel (tabla 2.2)', function (): void {
    expect(Indicator::SalesBs->precision())->toBe(2)
        ->and(Indicator::SalesUsd->precision())->toBe(0)
        ->and(Indicator::AvgTicketBs->precision())->toBe(0)
        ->and(Indicator::UnitsPerTransaction->precision())->toBe(1)
        ->and(Indicator::AvgTicketUsd->precision())->toBe(1)
        ->and(Indicator::TransactionsPerShift->precision())->toBe(0)
        ->and(Indicator::AvgRate->precision())->toBe(2);
});

it('agrega ratios de forma ponderada y sumas como sumas (RN-04)', function (): void {
    expect(Indicator::AvgTicketBs->aggregation())->toBe(Aggregation::WeightedRatio)
        ->and(Indicator::UnitsPerTransaction->aggregation())->toBe(Aggregation::WeightedRatio)
        ->and(Indicator::TransactionsPerShift->aggregation())->toBe(Aggregation::WeightedRatio)
        ->and(Indicator::SalesUsd->aggregation())->toBe(Aggregation::Sum)
        ->and(Indicator::AvgRate->aggregation())->toBe(Aggregation::WeightedAverage)
        ->and(Indicator::InventoryValueUsd->aggregation())->toBe(Aggregation::AverageWithCount);
});

it('asigna la moneda de meta según la unidad (RN-17)', function (): void {
    expect(Indicator::SalesUsd->goalCurrency())->toBe(Currency::Usd)
        ->and(Indicator::SalesBs->goalCurrency())->toBe(Currency::Bs)
        ->and(Indicator::Transactions->goalCurrency())->toBe(Currency::None)
        ->and(Indicator::InventoryValueUsd->unit())->toBe(Unit::Usd)
        ->and(Indicator::AvgRate->supportsGoal())->toBeFalse()
        ->and(Indicator::SalesUsd->supportsGoal())->toBeTrue();
});

it('no colorea los deltas de la tasa (§13.1)', function (): void {
    expect(Indicator::AvgRate->moreIsBetter())->toBeNull()
        ->and(Indicator::RateVariationPct->moreIsBetter())->toBeNull()
        ->and(Indicator::SalesUsd->moreIsBetter())->toBeTrue();
});

it('conserva el orden de la tabla anual del Excel, con 9 y 10 invertidos (§2.4)', function (): void {
    $order = Indicator::annualOrder();

    expect($order)->toHaveCount(12)
        ->and($order[8])->toBe(Indicator::InventoryValueUsd)
        ->and($order[9])->toBe(Indicator::InventoryUnits)
        ->and(Indicator::primary())->toHaveCount(4)
        ->and(Indicator::secondary())->toHaveCount(4);
});
