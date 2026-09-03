<?php

declare(strict_types=1);

use App\Domain\Shared\Period;
use Carbon\CarbonImmutable;

it('construye un mes desde "YYYY-MM" o desde una fecha', function (): void {
    $a = Period::of('2025-09');
    $b = Period::of('2025-09-17');
    $c = Period::of(CarbonImmutable::parse('2025-09-30'));

    expect($a->key())->toBe('2025-09')
        ->and($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeTrue()
        ->and($a->days())->toBe(30)
        ->and($a->start->toDateString())->toBe('2025-09-01')
        ->and($a->end()->toDateString())->toBe('2025-09-30');
});

it('etiqueta en español y nombre en mayúsculas para el Excel exportado', function (): void {
    $p = Period::of('2025-09');

    expect($p->label())->toBe('Septiembre 2025')
        ->and($p->monthNameUpper())->toBe('SEPTIEMBRE');
});

it('navega a mes anterior, siguiente y mismo mes del año anterior sin desbordar', function (): void {
    $p = Period::of('2025-03');

    expect($p->previous()->key())->toBe('2025-02')
        ->and($p->next()->key())->toBe('2025-04')
        ->and($p->sameMonthLastYear()->key())->toBe('2024-03')
        ->and(Period::of('2025-01')->previous()->key())->toBe('2024-12');
});

it('sabe si una fecha pertenece al mes y enumera sus días', function (): void {
    $p = Period::of('2025-09');

    expect($p->contains(CarbonImmutable::parse('2025-09-16')))->toBeTrue()
        ->and($p->contains(CarbonImmutable::parse('2025-10-01')))->toBeFalse()
        ->and($p->dates())->toHaveCount(30)
        ->and((string) $p)->toBe('2025-09');
});

it('rechaza períodos inválidos', function (): void {
    Period::of('septiembre');
})->throws(InvalidArgumentException::class);
