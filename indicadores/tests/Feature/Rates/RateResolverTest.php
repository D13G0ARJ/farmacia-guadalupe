<?php

declare(strict_types=1);

use App\Domain\Rates\RateResolver;
use App\Domain\Shared\Formatter;
use App\Enums\RateSource;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('devuelve la tasa publicada del día con su origen', function (): void {
    ExchangeRate::query()->create(['date' => '2025-09-05', 'rate' => '152.82', 'source' => RateSource::Bcv]);

    $r = app(RateResolver::class)->forDate(CarbonImmutable::parse('2025-09-05'));

    expect($r)->not->toBeNull()
        ->and((string) $r->rate)->toBe('152.8200')
        ->and($r->source)->toBe(RateSource::Bcv)
        ->and($r->isCarried())->toBeFalse()
        ->and($r->label(new Formatter))->toBe('BCV 05/09');
});

it('el fin de semana usa la tasa del lunes, que el BCV publica el viernes en la tarde (RN-07)', function (): void {
    ExchangeRate::query()->create(['date' => '2025-09-05', 'rate' => '152.82', 'source' => RateSource::Bcv]);
    ExchangeRate::query()->create(['date' => '2025-09-08', 'rate' => '154.01', 'source' => RateSource::Bcv]);

    $saturday = app(RateResolver::class)->forDate(CarbonImmutable::parse('2025-09-06'));
    $sunday = app(RateResolver::class)->forDate(CarbonImmutable::parse('2025-09-07'));

    expect((string) $saturday?->rate)->toBe('154.0100')
        ->and($saturday?->source)->toBe(RateSource::Carried)
        ->and($saturday?->sourceDate->toDateString())->toBe('2025-09-08')
        ->and($saturday?->label(new Formatter))->toBe('Arrastrada del lun 08/09')
        ->and((string) $sunday?->rate)->toBe('154.0100')
        ->and($sunday?->isStale())->toBeFalse();
});

it('si el lunes es feriado bancario, sábado, domingo y lunes usan la tasa del martes (RN-07)', function (): void {
    ExchangeRate::query()->create(['date' => '2025-10-10', 'rate' => '190.00', 'source' => RateSource::Bcv]); // viernes
    ExchangeRate::query()->create(['date' => '2025-10-14', 'rate' => '192.50', 'source' => RateSource::Bcv]); // martes (lunes 13 feriado)

    foreach (['2025-10-11', '2025-10-12', '2025-10-13'] as $day) {
        $r = app(RateResolver::class)->forDate(CarbonImmutable::parse($day));
        expect((string) $r?->rate)->toBe('192.5000')
            ->and($r?->sourceDate->toDateString())->toBe('2025-10-14');
    }
});

it('si la siguiente publicación aún no llegó, se usa la última anterior (sábado antes de la consulta del viernes)', function (): void {
    ExchangeRate::query()->create(['date' => '2025-09-05', 'rate' => '152.82', 'source' => RateSource::Bcv]);

    $saturday = app(RateResolver::class)->forDate(CarbonImmutable::parse('2025-09-06'));

    expect((string) $saturday?->rate)->toBe('152.8200')
        ->and($saturday?->source)->toBe(RateSource::Carried)
        ->and($saturday?->label(new Formatter))->toBe('Arrastrada del vie 05/09');
});

it('un arrastre de más de una semana se marca como viejo, con la fecha completa y la antigüedad (§9.3)', function (): void {
    ExchangeRate::query()->create(['date' => '2025-09-30', 'rate' => '177.61', 'source' => RateSource::Manual]);

    $fresh = app(RateResolver::class)->forDate(CarbonImmutable::parse('2025-10-05'));
    $stale = app(RateResolver::class)->forDate(CarbonImmutable::parse('2026-09-01'));

    expect($fresh?->isStale())->toBeFalse()
        ->and($fresh?->ageDays())->toBe(5)
        ->and($fresh?->label(new Formatter))->toBe('Arrastrada del mar 30/09')
        ->and($stale?->isStale())->toBeTrue()
        ->and($stale?->ageLabel())->toBe('hace 11 meses')
        ->and($stale?->label(new Formatter))->toBe('Arrastrada del 30/09/2025 (hace 11 meses)')
        ->and(app(RateResolver::class)->forDate(CarbonImmutable::parse('2025-10-21'))?->ageLabel())->toBe('hace 3 semanas')
        ->and(app(RateResolver::class)->forDate(CarbonImmutable::parse('2027-10-21'))?->ageLabel())->toBe('hace 2 años');
});

it('una tasa publicada mucho después no sirve para un día anterior: solo se mira una semana adelante', function (): void {
    ExchangeRate::query()->create(['date' => '2025-09-08', 'rate' => '154.01', 'source' => RateSource::Bcv]);

    expect(app(RateResolver::class)->forDate(CarbonImmutable::parse('2025-09-06'))?->sourceDate->toDateString())->toBe('2025-09-08')
        ->and(app(RateResolver::class)->forDate(CarbonImmutable::parse('2025-08-20')))->toBeNull();
});
