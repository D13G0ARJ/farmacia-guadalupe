<?php

declare(strict_types=1);

use App\Actions\Rates\FetchBcvRate;
use App\Actions\Rates\UpsertExchangeRate;
use App\Enums\RateSource;
use App\Enums\Role;
use App\Jobs\FetchDailyBcvRate;
use App\Models\ExchangeRate;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('guarda la cotización para la fecha de vigencia indicada', function (): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response(['promedio' => 148.44])]);

    $rate = app(FetchBcvRate::class)->handle(CarbonImmutable::parse('2025-09-01'));

    expect($rate)->not->toBeNull()
        ->and($rate->date->toDateString())->toBe('2025-09-01')
        ->and((string) $rate->rate)->toBe('148.4400')
        ->and($rate->source)->toBe(RateSource::Bcv)
        ->and($rate->fetched_at)->not->toBeNull();
});

it('no sobrescribe una tasa manual con una automática (§9.3)', function (): void {
    $user = userWithRole(Role::Supervision);
    app(UpsertExchangeRate::class)->handle(CarbonImmutable::parse('2025-09-01'), BigDecimal::of('150'), RateSource::Manual, $user);
    Http::fake(['ve.dolarapi.com/*' => Http::response(['promedio' => 148.44])]);

    app(FetchBcvRate::class)->handle(CarbonImmutable::parse('2025-09-01'));

    $stored = ExchangeRate::query()->where('date', '2025-09-01')->firstOrFail();
    expect((string) $stored->rate)->toBe('150.0000')
        ->and($stored->source)->toBe(RateSource::Manual)
        ->and($stored->set_by)->toBe($user->id);
});

it('una tasa manual sí corrige una automática', function (): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response(['promedio' => 148.44])]);
    app(FetchBcvRate::class)->handle(CarbonImmutable::parse('2025-09-01'));

    app(UpsertExchangeRate::class)->handle(CarbonImmutable::parse('2025-09-01'), BigDecimal::of('149'), RateSource::Manual, userWithRole(Role::Supervision));

    expect((string) ExchangeRate::query()->where('date', '2025-09-01')->firstOrFail()->rate)->toBe('149.0000')
        ->and(ExchangeRate::query()->count())->toBe(1);
});

it('registra una advertencia si la cotización se desvía más del umbral, pero la guarda igual (RN-16)', function (): void {
    ExchangeRate::query()->create(['date' => '2025-08-29', 'rate' => '148.00', 'source' => RateSource::Bcv]);
    Http::fake(['ve.dolarapi.com/*' => Http::response(['promedio' => 200])]);
    Log::spy();

    $rate = app(FetchBcvRate::class)->handle(CarbonImmutable::parse('2025-09-01'));

    expect((string) $rate?->rate)->toBe('200.0000');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $msg) => str_contains($msg, 'desviación'))->once();
});

it('si el proveedor no responde no guarda nada y no lanza', function (): void {
    Http::fake(['*' => Http::response('', 500)]);

    expect(app(FetchBcvRate::class)->handle(CarbonImmutable::parse('2025-09-01')))->toBeNull()
        ->and(ExchangeRate::query()->count())->toBe(0);
});

it('calcula el siguiente día hábil saltando el fin de semana', function (): void {
    expect(FetchBcvRate::nextBusinessDay(CarbonImmutable::parse('2025-09-05'))->toDateString())->toBe('2025-09-08') // viernes → lunes
        ->and(FetchBcvRate::nextBusinessDay(CarbonImmutable::parse('2025-09-03'))->toDateString())->toBe('2025-09-04')
        ->and(FetchBcvRate::nextBusinessDay(CarbonImmutable::parse('2025-09-06'))->toDateString())->toBe('2025-09-08');
});

it('el job guarda la tasa para la fecha que recibe', function (): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response(['promedio' => 154.01])]);

    (new FetchDailyBcvRate('2025-09-08'))->handle(app(FetchBcvRate::class));

    expect((string) ExchangeRate::query()->where('date', '2025-09-08')->firstOrFail()->rate)->toBe('154.0100');
});
