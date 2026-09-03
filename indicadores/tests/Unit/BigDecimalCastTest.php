<?php

declare(strict_types=1);

use App\Casts\BigDecimalCast;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;

$model = new class extends Model {};

it('lee la columna como BigDecimal a la escala indicada', function () use ($model): void {
    $cast = new BigDecimalCast(scale: 2);

    $value = $cast->get($model, 'sales_bs', '91154.02', []);

    expect($value)->toBeInstanceOf(BigDecimal::class)
        ->and((string) $value)->toBe('91154.02')
        ->and((string) $cast->get($model, 'x', '148.44', []))->toBe('148.44')
        ->and($cast->get($model, 'x', null, []))->toBeNull()
        ->and($cast->get($model, 'x', '', []))->toBeNull();
});

it('escribe normalizando a la escala de la columna', function () use ($model): void {
    $cast = new BigDecimalCast(scale: 4);

    expect($cast->set($model, 'rate', '148.44', []))->toBe('148.4400')
        ->and($cast->set($model, 'rate', BigDecimal::of('177.61'), []))->toBe('177.6100')
        ->and($cast->set($model, 'rate', 150, []))->toBe('150.0000')
        ->and($cast->set($model, 'rate', null, []))->toBeNull();
});

it('rechaza float para no perder exactitud (RN-19)', function () use ($model): void {
    (new BigDecimalCast(scale: 2))->set($model, 'sales_bs', 91154.02, []);
})->throws(InvalidArgumentException::class);
