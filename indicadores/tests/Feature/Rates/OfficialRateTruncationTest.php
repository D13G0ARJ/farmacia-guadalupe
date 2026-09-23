<?php

declare(strict_types=1);

use App\Actions\Rates\FetchBcvRate;
use App\Actions\Rates\UpsertExchangeRate;
use App\Enums\RateSource;
use App\Models\ExchangeRate;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    CarbonImmutable::setTestNow('2026-09-23 09:00:00');
});
afterEach(fn () => CarbonImmutable::setTestNow());

it('la tasa automática del BCV se guarda cortando el tercer decimal, nunca redondeando (RN-27)', function (): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response(['promedio' => 853.499])]);

    $rate = app(FetchBcvRate::class)->handle(CarbonImmutable::parse('2026-09-23'));

    expect((string) $rate->rate)->toBe('853.4900')
        ->and($rate->source)->toBe(RateSource::Bcv);
});

it('una tasa escrita a mano se guarda tal cual la escribieron', function (): void {
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    $rate = app(UpsertExchangeRate::class)->handle(CarbonImmutable::parse('2026-09-22'), BigDecimal::of('852.415'), RateSource::Manual, $admin);

    expect((string) $rate->rate)->toBe('852.4150');
});

it('rates:normalize corrige las tasas BCV ya guardadas con más de dos decimales y respeta las manuales', function (): void {
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
    ExchangeRate::query()->create(['date' => '2026-09-21', 'rate' => '849.5678', 'source' => RateSource::Bcv, 'fetched_at' => now()]);
    ExchangeRate::query()->create(['date' => '2026-09-22', 'rate' => '852.4100', 'source' => RateSource::Bcv, 'fetched_at' => now()]);
    ExchangeRate::query()->create(['date' => '2026-09-23', 'rate' => '853.4990', 'source' => RateSource::Manual, 'set_by' => $admin->id]);

    $this->artisan('rates:normalize', ['--dry-run' => true])->expectsOutputToContain('Cambiarían 1 de 2')->assertSuccessful();
    expect((string) ExchangeRate::query()->where('date', '2026-09-21')->firstOrFail()->rate)->toBe('849.5678');

    $this->artisan('rates:normalize')->expectsOutputToContain('Corregidas 1 de 2')->assertSuccessful();
    expect((string) ExchangeRate::query()->where('date', '2026-09-21')->firstOrFail()->rate)->toBe('849.5600')
        ->and((string) ExchangeRate::query()->where('date', '2026-09-22')->firstOrFail()->rate)->toBe('852.4100')
        ->and((string) ExchangeRate::query()->where('date', '2026-09-23')->firstOrFail()->rate)->toBe('853.4990');
});
