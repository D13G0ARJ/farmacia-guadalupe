<?php

declare(strict_types=1);

use App\Domain\Shared\Period;
use App\Enums\Role;
use App\Livewire\Charts\ChartsPage;
use App\Livewire\Shared\ContextBar;
use App\Models\User;
use App\Support\CurrencyContext;
use App\Support\PeriodContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoHistorySeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function chartsAdmin(): User
{
    test()->seed([DatabaseSeeder::class, DemoSeeder::class]);
    app(PeriodContext::class)->set(Period::of('2025-09'));

    return User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
}

it('abre en Ventas con sus tres gráficas y cambia de pestaña con una sola petición (UC-10)', function (): void {
    $admin = chartsAdmin();

    $component = Livewire::actingAs($admin)->test(ChartsPage::class)
        ->assertSet('tab', 'ventas')
        ->assertSee('Gráficas')
        ->assertSee('Septiembre 2025 · Sede Principal')
        ->assertSee('Venta en dólares por día')
        ->assertSee('Venta en bolívares por día')
        ->assertSee('Mapa de calor semanal')
        ->assertSee('Tasa')
        ->assertSee('Año')
        ->assertDontSee('Próximamente');

    expect(array_keys($component->get('specs')))->toBe(['g2', 'g1', 'g8']);

    $component->set('tab', 'operacion')
        ->assertSee('Transacciones y unidades')
        ->assertSee('Transacciones por jornada')
        ->assertDontSee('Venta en dólares por día');

    expect(array_keys($component->get('specs')))->toBe(['g3', 'g6', 'g4', 'g5']);

    $component->set('tab', 'inventario');
    expect(array_keys($component->get('specs')))->toBe(['g7']);
});

it('las pestañas Tasa y Año traen G10 y G11, y el indicador de G11 se elige', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class, DemoHistorySeeder::class]);
    app(PeriodContext::class)->set(Period::of('2025-09'));
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    $component = Livewire::actingAs($admin)->test(ChartsPage::class)->set('tab', 'tasa')
        ->assertSee('Tasa BCV frente a la venta en dólares');
    expect(array_keys($component->get('specs')))->toBe(['g10'])
        ->and($component->get('specs')['g10']['option']['series'])->toHaveCount(2)
        ->and($component->get('specs')['g10']['meta']['tooltips'][0])->toContain('$ 614', 'tasa 148,44');

    $component->set('tab', 'anio')
        ->assertSee('Venta en dólares por mes')
        ->assertSee('2025 frente a 2024')
        ->assertSee('Indicador');
    $spec = $component->get('specs')['g11'];
    expect(array_keys($component->get('specs')))->toBe(['g11'])
        ->and($spec['option']['xAxis']['data'])->toHaveCount(12)
        ->and($spec['option']['series'][0]['data'][8])->toBeGreaterThan(18000.0) // septiembre
        ->and($spec['option']['series'][0]['data'][7])->toBeGreaterThan(18000.0) // agosto sintético
        ->and($spec['option']['series'][0]['data'][0])->toBeNull()
        ->and($spec['option']['series'][1]['data'][8])->toBeNull()
        ->and($spec['meta']['tooltips'][8])->toContain('sep · 2025: $ 18.611 · 2024: —');

    $component->set('annualIndicator', 'transactions')->assertSee('Transacciones por mes');
    expect($component->get('specs')['g11']['option']['series'][0]['data'][8])->toBe(3853.0);

    $component->set('annualIndicator', 'inexistente')->assertSet('annualIndicator', 'sales_usd');
});

it('una pestaña inválida en la URL vuelve a Ventas', function (): void {
    $admin = chartsAdmin();

    Livewire::actingAs($admin)->withQueryParams(['tab' => 'nada'])->test(ChartsPage::class)->assertSet('tab', 'ventas');
});

it('con la moneda en Bs la gráfica en bolívares va primero', function (): void {
    $admin = chartsAdmin();
    Livewire::actingAs($admin)->test(ContextBar::class)->set('currency', CurrencyContext::BS);

    $component = Livewire::actingAs($admin)->test(ChartsPage::class);

    expect(array_keys($component->get('specs')))->toBe(['g1', 'g2', 'g8']);
});

it('la ruta responde para un usuario con sede y se niega sin sede', function (): void {
    $admin = chartsAdmin();

    $this->actingAs($admin)->get(route('charts'))->assertOk()->assertSee('Gráficas');
    $this->actingAs(userWithRole(Role::Operador))->get(route('charts'))->assertOk();
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))->get(route('charts'))->assertForbidden();
});

it('un mes sin datos muestra cada gráfica con su estado vacío', function (): void {
    $user = userWithRole(Role::Supervision);

    $component = Livewire::actingAs($user)->test(ChartsPage::class)
        ->assertSee('Aún no hay días cargados en octubre 2025.');

    expect($component->get('specs')['g2']['empty'])->toBeTrue();
});
