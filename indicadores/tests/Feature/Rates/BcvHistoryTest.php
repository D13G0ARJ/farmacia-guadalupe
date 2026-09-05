<?php

declare(strict_types=1);

use App\Actions\Rates\BackfillBcvRates;
use App\Domain\Rates\BcvHistoryParser;
use App\Domain\Rates\Providers\BcvHistoryProvider;
use App\Enums\RateSource;
use App\Livewire\Rates\RatesPage;
use App\Models\ExchangeRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const BCV_Q1_2025 = __DIR__.'/../../Fixtures/bcv-2025-trimestre-1.xls';

beforeEach(fn () => CarbonImmutable::setTestNow('2025-04-10 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('lee el libro trimestral real del BCV: una tasa por fecha de vigencia con la venta en Bs por dólar', function (): void {
    $rates = (new BcvHistoryParser)->parse(BCV_Q1_2025);

    expect($rates)->toHaveCount(58)
        ->and(array_key_first($rates))->toBe('2025-01-03') // la hoja del 02/01 rige el 03/01
        ->and(array_key_last($rates))->toBe('2025-04-01') // la del 31/03 rige el 01/04
        ->and((string) $rates['2025-01-03'])->toBe('52.5723')
        ->and((string) $rates['2025-04-01'])->toBe('69.776')
        ->and(array_key_exists('2025-01-04', $rates))->toBeFalse(); // sábado: el BCV no publica
});

it('arma los nombres de los trimestres que cubren el rango', function (): void {
    $provider = app(BcvHistoryProvider::class);

    expect($provider->quarters(CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-01-31')))->toBe(['2_1_2a25_smc.xls'])
        ->and($provider->quarters(CarbonImmutable::parse('2025-02-15'), CarbonImmutable::parse('2026-09-04')))
        ->toBe(['2_1_2a25_smc.xls', '2_1_2b25_smc.xls', '2_1_2c25_smc.xls', '2_1_2d25_smc.xls', '2_1_2a26_smc.xls', '2_1_2b26_smc.xls', '2_1_2c26_smc.xls']);
});

it('completa solo los días sin tasa, respeta las manuales y salta trimestres que no existen', function (): void {
    $this->seed(DatabaseSeeder::class);
    Http::fake([
        '*/2_1_2a25_smc.xls' => Http::response(file_get_contents(BCV_Q1_2025)),
        '*/2_1_2b25_smc.xls' => Http::response('', 404),
    ]);
    ExchangeRate::query()->create(['date' => '2025-01-03', 'rate' => '99.00', 'source' => RateSource::Manual]);

    $result = app(BackfillBcvRates::class)->handle(CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-04-10'));

    expect($result['found'])->toBe(58)
        ->and($result['created'])->toBe(57)
        ->and($result['existing'])->toBe(1)
        ->and($result['files'])->toBe(['2_1_2a25_smc.xls'])
        ->and($result['failed'])->toBe(['2_1_2b25_smc.xls'])
        ->and((string) ExchangeRate::query()->where('date', '2025-01-03')->firstOrFail()->rate)->toBe('99.0000') // manual intacta
        ->and(ExchangeRate::query()->where('date', '2025-04-01')->firstOrFail()->source)->toBe(RateSource::Bcv)
        ->and(ExchangeRate::query()->where('date', '2025-04-01')->firstOrFail()->fetched_at)->not->toBeNull()
        ->and(ExchangeRate::query()->where('source', RateSource::Bcv)->count())->toBe(57);

    // Un rango parcial solo trae ese tramo (marzo tiene 18 publicaciones: Carnaval sin BCV), y repetirlo no duplica nada
    $again = app(BackfillBcvRates::class)->handle(CarbonImmutable::parse('2025-03-01'), CarbonImmutable::parse('2025-03-31'));
    expect($again['found'])->toBe(18)->and($again['created'])->toBe(0)->and($again['existing'])->toBe(18);
});

it('rates:backfill informa lo que trajo y rechaza fechas inválidas', function (): void {
    $this->seed(DatabaseSeeder::class);
    Http::fake(['*/2_1_2a25_smc.xls' => Http::response(file_get_contents(BCV_Q1_2025))]);

    $this->artisan('rates:backfill', ['--from' => '2025-02-01', '--to' => '2025-02-28'])
        ->expectsOutputToContain('Tasas nuevas: 20')
        ->expectsOutputToContain('Rango encontrado: 2025-02-03 a 2025-02-28')
        ->assertSuccessful();
    expect(ExchangeRate::query()->count())->toBe(20);

    $this->artisan('rates:backfill', ['--from' => 'nada'])->assertFailed();
    $this->artisan('rates:backfill', ['--from' => '2025-03-01', '--to' => '2025-02-01'])->assertFailed();
});

it('desde Tasa BCV, quien gestiona tasas trae el histórico con un aviso claro', function (): void {
    $this->seed(DatabaseSeeder::class);
    Http::fake(['*/2_1_2a25_smc.xls' => Http::response(file_get_contents(BCV_Q1_2025)), '*' => Http::response('', 404)]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    Livewire::actingAs($admin)->test(RatesPage::class)
        ->assertSee('Traer histórico del BCV')
        ->set('backfillFrom', '2030-01-01')
        ->call('backfill')
        ->assertHasErrors(['backfillFrom'])
        ->set('backfillFrom', '2025-03-01')
        ->call('backfill')
        ->assertHasNoErrors()
        ->assertSet('backfillDialog', false)
        ->assertDispatched('toast', fn (string $name, array $params) => $params['type'] === 'success' && str_contains($params['message'], '19 tasas nuevas del BCV (05/03/2025 a 01/04/2025)'));

    expect(ExchangeRate::query()->count())->toBe(19);
});
