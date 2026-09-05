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

it('arrastra la última tasa publicada en fin de semana (RN-07, H4)', function (): void {
    ExchangeRate::query()->create(['date' => '2025-09-05', 'rate' => '152.82', 'source' => RateSource::Bcv]);

    $saturday = app(RateResolver::class)->forDate(CarbonImmutable::parse('2025-09-06'));
    $sunday = app(RateResolver::class)->forDate(CarbonImmutable::parse('2025-09-07'));

    expect((string) $saturday?->rate)->toBe('152.8200')
        ->and($saturday?->source)->toBe(RateSource::Carried)
        ->and($saturday?->sourceDate->toDateString())->toBe('2025-09-05')
        ->and($saturday?->label(new Formatter))->toBe('Arrastrada del vie 05/09')
        ->and($sunday?->isCarried())->toBeTrue();
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

it('no arrastra hacia atrás: una tasa futura no sirve para un día anterior', function (): void {
    ExchangeRate::query()->create(['date' => '2025-09-08', 'rate' => '154.01', 'source' => RateSource::Bcv]);

    expect(app(RateResolver::class)->forDate(CarbonImmutable::parse('2025-09-06')))->toBeNull();
});
