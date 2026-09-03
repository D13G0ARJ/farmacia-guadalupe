<?php

declare(strict_types=1);

use App\Domain\Rates\ExchangeRateProvider;
use App\Domain\Rates\Providers\BcvProvider;
use App\Domain\Rates\Providers\NullProvider;
use Illuminate\Support\Facades\Http;

it('lee el USD oficial de la API primaria', function (): void {
    Http::fake([
        've.dolarapi.com/*' => Http::response(['fuente' => 'oficial', 'promedio' => 148.44, 'fechaActualizacion' => '2025-09-01T12:00:00Z']),
    ]);

    $quote = app(ExchangeRateProvider::class)->fetch();

    expect($quote)->not->toBeNull()
        ->and((string) $quote->rate)->toBe('148.44')
        ->and($quote->origin)->toBe('api');
    Http::assertSentCount(1);
});

it('cae a la página del BCV cuando la API falla, y entiende la coma decimal', function (): void {
    Http::fake([
        've.dolarapi.com/*' => Http::response('', 503),
        'www.bcv.org.ve/*' => Http::response('<div id="dolar"><div class="col-sm-6 col-xs-6 centrado"><strong> 148,44000000 </strong></div></div>'),
    ]);

    $quote = app(ExchangeRateProvider::class)->fetch();

    expect((string) $quote?->rate)->toBe('148.44000000')
        ->and($quote?->origin)->toBe('bcv.org.ve');
});

it('nunca lanza: si ambas fuentes fallan devuelve null', function (): void {
    Http::fake([
        've.dolarapi.com/*' => Http::response('', 500),
        'www.bcv.org.ve/*' => Http::response('<html>sin bloque</html>'),
    ]);

    expect(app(ExchangeRateProvider::class)->fetch())->toBeNull();
});

it('ignora respuestas sin número', function (): void {
    Http::fake([
        've.dolarapi.com/*' => Http::response(['promedio' => 'n/a']),
        'www.bcv.org.ve/*' => Http::response('', 404),
    ]);

    expect(app(ExchangeRateProvider::class)->fetch())->toBeNull();
});

it('con RATES_PROVIDER=null todo es manual', function (): void {
    config()->set('indicadores.rates.provider', null);

    expect(app(ExchangeRateProvider::class))->toBeInstanceOf(NullProvider::class)
        ->and(app(ExchangeRateProvider::class)->fetch())->toBeNull();
    config()->set('indicadores.rates.provider', 'bcv');
    expect(app(ExchangeRateProvider::class))->toBeInstanceOf(BcvProvider::class);
});
