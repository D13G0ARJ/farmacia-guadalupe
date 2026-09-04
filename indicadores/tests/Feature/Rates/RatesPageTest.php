<?php

declare(strict_types=1);

use App\Domain\Rates\ExchangeRateProvider;
use App\Domain\Rates\RateQuote;
use App\Domain\Shared\Period;
use App\Enums\RateSource;
use App\Enums\Role;
use App\Livewire\Rates\RatesPage;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\User;
use App\Support\PeriodContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function ratesAdmin(): User
{
    test()->seed([DatabaseSeeder::class, DemoSeeder::class]);
    app(PeriodContext::class)->set(Period::of('2025-09'));

    return User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
}

it('supervisión y dirección gestionan tasas; el operador no ve la pantalla', function (): void {
    $branch = mainBranch();

    $this->actingAs(userWithRole(Role::Supervision, $branch))->get(route('rates'))->assertOk()->assertSee('Tasa BCV');
    $this->actingAs(userWithRole(Role::Operador, $branch))->get(route('rates'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Operador, $branch))->get(route('month'))->assertOk()->assertDontSee('href="'.route('rates').'"', false);
});

it('lista el mes con origen, variación y estado del proveedor (§9.4)', function (): void {
    $admin = ratesAdmin();

    $component = Livewire::actingAs($admin)->test(RatesPage::class)
        ->assertSee('Septiembre 2025 · bolívares por dólar')
        ->assertSee('Consulta automática al BCV')
        ->assertSee('lun 01/09')
        ->assertSee('148,44')
        ->assertSee('177,61')
        ->assertSee('Manual')
        ->assertSee('+0,7 %'); // 149,46 / 148,44 − 1

    expect($component->get('specs')['rate']['option']['series'][0]['data'])->toHaveCount(30)
        ->and($component->get('specs')['rate']['title'])->toBe('Tasa BCV del mes');
});

it('edita una tasa en línea: valida, guarda como manual, avisa y deja bitácora', function (): void {
    $admin = ratesAdmin();

    $component = Livewire::actingAs($admin)->test(RatesPage::class)
        ->call('startEdit', '2025-09-15')
        ->assertSet('editingDate', '2025-09-15')
        ->assertSet('editValue', '158,92')
        ->set('editValue', 'abc')
        ->call('saveRate')
        ->assertHasErrors(['editValue'])
        ->assertSee('por ejemplo 177,61');

    $component->set('editValue', '160,00')->call('saveRate')
        ->assertHasNoErrors()
        ->assertSet('editingDate', null)
        ->assertDispatched('toast')
        ->assertSee('160,00');

    $rate = ExchangeRate::query()->where('date', '2025-09-15')->firstOrFail();
    expect($rate->source)->toBe(RateSource::Manual)
        ->and((string) $rate->rate)->toBe('160.0000')
        ->and($rate->set_by)->toBe($admin->id)
        ->and(Activity::query()->where('subject_type', ExchangeRate::class)->where('subject_id', $rate->id)->exists())->toBeTrue();

    // El día cargado conserva su tasa (RN-06) y la pantalla lo avisa con el recálculo pendiente
    expect((string) DailyRecord::query()->where('date', '2025-09-15')->firstOrFail()->exchange_rate)->toBe('158.9200');
    $component->assertSee('1 día cargado tiene una tasa distinta');
});

it('"Consultar ahora" pide la cotización al proveedor y la guarda para el siguiente día hábil', function (): void {
    $admin = ratesAdmin();
    app()->instance(ExchangeRateProvider::class, new class implements ExchangeRateProvider
    {
        public function fetch(): ?RateQuote
        {
            return new RateQuote(BigDecimal::of('180.5000'), CarbonImmutable::now(), 'prueba');
        }
    });

    Livewire::actingAs($admin)->test(RatesPage::class)->call('fetchNow')->assertDispatched('toast');

    // Viernes 03/10: se guarda hoy y el lunes 06/10
    expect((string) ExchangeRate::query()->where('date', '2025-10-03')->firstOrFail()->rate)->toBe('180.5000')
        ->and((string) ExchangeRate::query()->where('date', '2025-10-06')->firstOrFail()->rate)->toBe('180.5000')
        ->and(Setting::get('rates_last_success_at'))->not->toBeNull()
        ->and(Setting::get('rates_last_error'))->toBeNull();
});

it('si el proveedor no responde lo dice sin bloquear y deja el error visible', function (): void {
    $admin = ratesAdmin();
    app()->instance(ExchangeRateProvider::class, new class implements ExchangeRateProvider
    {
        public function fetch(): ?RateQuote
        {
            return null;
        }
    });

    Livewire::actingAs($admin)->test(RatesPage::class)->call('fetchNow')
        ->assertDispatched('toast')
        ->assertSee('no devolvió una cotización válida');
});

it('recalcular el mes aplica la tabla a los días cargados con confirmación y cuenta los cambios', function (): void {
    $admin = ratesAdmin();
    $branch = Branch::query()->firstOrFail();
    ExchangeRate::query()->where('date', '2025-09-15')->update(['rate' => '160.0000', 'source' => 'manual']);
    ExchangeRate::query()->where('date', '2025-09-16')->update(['rate' => '161.0000', 'source' => 'manual']);

    $component = Livewire::actingAs($admin)->test(RatesPage::class)
        ->assertSee('2 días cargados tienen una tasa distinta')
        ->call('recalculate')
        ->assertSet('recalcDialog', false)
        ->assertDispatched('toast');

    expect((string) DailyRecord::query()->where('date', '2025-09-15')->firstOrFail()->exchange_rate)->toBe('160.0000')
        ->and((string) DailyRecord::query()->where('date', '2025-09-16')->firstOrFail()->exchange_rate)->toBe('161.0000')
        ->and(DailyRecord::query()->where('date', '2025-09-15')->firstOrFail()->exchange_rate_source)->toBe(RateSource::Manual);

    $component->assertDontSee('tienen una tasa distinta');
    unset($branch);
});
