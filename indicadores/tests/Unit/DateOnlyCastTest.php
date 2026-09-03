<?php

declare(strict_types=1);

use App\Casts\DateOnlyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

$model = new class extends Model {};

it('guarda siempre Y-m-d, venga de string o de Carbon', function () use ($model): void {
    $cast = new DateOnlyCast;

    expect($cast->set($model, 'date', '2025-09-01', []))->toBe('2025-09-01')
        ->and($cast->set($model, 'date', '2025-09-01 00:00:00', []))->toBe('2025-09-01')
        ->and($cast->set($model, 'date', CarbonImmutable::parse('2025-09-16 15:30'), []))->toBe('2025-09-16')
        ->and($cast->set($model, 'date', null, []))->toBeNull();
});

it('lee como CarbonImmutable a medianoche, tolerando el formato con hora de otros drivers', function () use ($model): void {
    $cast = new DateOnlyCast;

    $a = $cast->get($model, 'date', '2025-09-01', []);
    $b = $cast->get($model, 'date', '2025-09-01 00:00:00', []);

    expect($a)->toBeInstanceOf(CarbonImmutable::class)
        ->and($a->toDateString())->toBe('2025-09-01')
        ->and($a->equalTo($b))->toBeTrue()
        ->and($a->dayOfWeekIso)->toBe(1)
        ->and($cast->get($model, 'date', null, []))->toBeNull();
});

it('rechaza valores que no son fechas', function () use ($model): void {
    (new DateOnlyCast)->set($model, 'date', 'septiembre', []);
})->throws(InvalidArgumentException::class);
