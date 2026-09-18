<?php

declare(strict_types=1);

use App\Actions\Periods\CloseMonth;
use App\Actions\Rates\BackfillBcvRates;
use App\Actions\Rates\RecalculateMonthRates;
use App\Domain\Periods\Exceptions\PeriodStateException;
use App\Domain\Rates\Providers\BcvHistoryProvider;
use App\Domain\Shared\Period;
use App\Enums\RateSource;
use App\Livewire\Rates\RatesPage;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\PeriodContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

/** Admin con la demo de septiembre 2025 cargada y ese mes en el contexto. */
function tasasAdmin(): User
{
    test()->seed([DatabaseSeeder::class, DemoSeeder::class]);
    app(PeriodContext::class)->set(Period::of('2025-09'));

    return User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
}

it('recalcular no toca los días de un mes cerrado: la acción se niega y la pantalla lo explica (A5)', function (): void {
    $admin = tasasAdmin();
    $branch = Branch::query()->firstOrFail();
    $period = Period::of('2025-09');

    ExchangeRate::query()->where('date', '2025-09-15')->update(['rate' => '160.0000', 'source' => 'manual']);
    $before = (string) DailyRecord::query()->where('date', '2025-09-15')->firstOrFail()->exchange_rate;

    app(CloseMonth::class)->handle($branch, $period, $admin, true);

    expect(fn () => app(RecalculateMonthRates::class)->handle($branch->id, $period, $admin))
        ->toThrow(PeriodStateException::class, 'Septiembre 2025 ya está cerrado');

    expect((string) DailyRecord::query()->where('date', '2025-09-15')->firstOrFail()->exchange_rate)->toBe($before);

    // La pantalla no ofrece el botón y, si alguien fuerza la llamada, recibe un aviso en vez de un error
    Livewire::actingAs($admin)->test(RatesPage::class)
        ->assertDontSee('data-tour="rates-recalc"', false)
        ->assertDontSee('tienen una tasa distinta')
        ->call('recalculate')
        ->assertHasNoErrors()
        ->assertDispatched('toast', fn (string $name, array $p) => $p['type'] === 'warning' && str_contains($p['message'], 'ya está cerrado'));

    expect((string) DailyRecord::query()->where('date', '2025-09-15')->firstOrFail()->exchange_rate)->toBe($before);
});

it('con el mes abierto el botón de recalcular sigue estando (A5)', function (): void {
    $admin = tasasAdmin();

    Livewire::actingAs($admin)->test(RatesPage::class)->assertSee('data-tour="rates-recalc"', false);
});

it('desde la pantalla el histórico del BCV no baja de tres años y remite al comando (M5)', function (): void {
    $admin = tasasAdmin();

    Livewire::actingAs($admin)->test(RatesPage::class)
        ->set('backfillFrom', '2010-01-01')
        ->call('backfill')
        ->assertHasErrors(['backfillFrom'])
        ->assertSee('rates:backfill');

    expect(ExchangeRate::query()->where('date', '<', '2025-01-01')->count())->toBe(0);
});

it('el histórico se guarda por tramos y no repite lo que ya existe (M5)', function (): void {
    $this->seed(DatabaseSeeder::class);
    Http::fake(['*/2_1_2a25_smc.xls' => Http::response((string) file_get_contents(__DIR__.'/../../Fixtures/bcv-2025-trimestre-1.xls')), '*' => Http::response('', 404)]);

    $result = app(BackfillBcvRates::class)->handle(CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-03-31'));
    $again = app(BackfillBcvRates::class)->handle(CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-03-31'));

    expect($result['created'])->toBeGreaterThan(50)
        ->and($again['created'])->toBe(0)
        ->and(ExchangeRate::query()->where('source', RateSource::Bcv)->count())->toBe($result['created']);
});

it('la fecha que se edita no se puede cambiar desde el navegador ni salirse del mes (B17)', function (): void {
    $admin = tasasAdmin();

    // `editingDate` está bloqueada: Livewire rechaza que el payload la escriba
    expect(fn () => Livewire::actingAs($admin)->test(RatesPage::class)->set('editingDate', '1999-01-01'))
        ->toThrow(Exception::class);

    // Y `startEdit` solo acepta días del mes que muestra la tabla
    Livewire::actingAs($admin)->test(RatesPage::class)
        ->call('startEdit', '2026-05-04')
        ->assertSet('editingDate', null)
        ->call('startEdit', 'no es una fecha')
        ->assertSet('editingDate', null)
        ->call('startEdit', '2025-09-15')
        ->assertSet('editingDate', '2025-09-15');

    expect(ExchangeRate::query()->where('date', '2026-05-04')->exists())->toBeFalse();
});

it('traer el histórico no deja archivos temporales sueltos (M7)', function (): void {
    $this->seed(DatabaseSeeder::class);
    Http::fake(['*' => Http::response((string) file_get_contents(__DIR__.'/../../Fixtures/bcv-2025-trimestre-1.xls'))]);

    $count = fn (): int => count(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'bcv*') ?: []);
    $before = $count();

    app(BcvHistoryProvider::class)->fetch(CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-06-30'));

    expect($count())->toBe($before);
});
