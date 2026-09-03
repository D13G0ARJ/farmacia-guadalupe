<?php

declare(strict_types=1);

use App\Domain\Shared\Period;
use App\Enums\Role;
use App\Livewire\Dashboard\Overview;
use App\Livewire\Shared\ContextBar;
use App\Models\User;
use App\Support\CurrencyContext;
use App\Support\PeriodContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoGoalsSeeder;
use Database\Seeders\DemoHistorySeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function demoAdmin(): User
{
    test()->seed([DatabaseSeeder::class, DemoSeeder::class, DemoHistorySeeder::class]);

    return User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
}

it('muestra los KPI con variación frente a agosto, sparklines, gráficas y avisos (UC-08)', function (): void {
    $admin = demoAdmin();
    app(PeriodContext::class)->set(Period::of('2025-09'));

    $component = Livewire::actingAs($admin)->test(Overview::class)
        ->assertSee('Septiembre 2025 lleva $ 18.611 vendidos en 30 días')
        ->assertSee('vs agosto')
        ->assertSee('En dólares:')
        ->assertSeeHtml('<polyline')
        ->assertSee('Venta en dólares por día')
        ->assertSee('Mapa de calor semanal')
        ->assertSee('Avisos del mes')
        ->assertSee('Agosto 2025 no está cerrado.')
        ->assertSee('Ver el mes')
        ->assertSeeHtml('x-data="chartPanel(\'g2\')"')
        ->assertSeeHtml('wire:ignore');

    expect(array_keys($component->get('specs')))->toBe(['g2', 'g8'])
        ->and($component->get('specs')['g8']['option']['calendar']['range'])->toBe('2025-09');
});

it('con la moneda en Bs la venta y el ticket se muestran en bolívares en grande (§13.8)', function (): void {
    $admin = demoAdmin();
    app(PeriodContext::class)->set(Period::of('2025-09'));
    Livewire::actingAs($admin)->test(ContextBar::class)->set('currency', CurrencyContext::BS);

    Livewire::actingAs($admin)->test(Overview::class)
        ->assertSeeInOrder(['Venta en bolívares', 'Bs 3.012.771', '$ 18.611', 'Transacciones', 'Ticket promedio en bolívares']);
});

it('con metas, el héroe dice cómo cerró el mes y las tarjetas llevan barra de meta (§8.3)', function (): void {
    $admin = demoAdmin();
    test()->seed(DemoGoalsSeeder::class);
    app(PeriodContext::class)->set(Period::of('2025-09'));

    $component = Livewire::actingAs($admin)->test(Overview::class)
        ->assertSee('Septiembre 2025 cerró en')
        ->assertSee('93 %')
        ->assertSee('frente a una meta de $ 20.000')
        ->assertSee('Faltaron $ 1.389')
        ->assertSee('Acumulado frente a la meta')
        ->assertSeeHtml('role="progressbar"')
        ->assertSee('Cerró en')
        ->assertSee('Meta $ 5,0 · vas al')
        ->assertSeeInOrder(['Venta en bolívares', 'Definir meta']); // sin meta en Bs: enlace para definirla

    expect(array_keys($component->get('specs')))->toBe(['g9', 'g2', 'g8']);
});

it('sin meta, dirección ve el botón "Definir meta" y el operador nada de metas', function (): void {
    $admin = demoAdmin();
    app(PeriodContext::class)->set(Period::of('2025-09'));

    Livewire::actingAs($admin)->test(Overview::class)
        ->assertSee('Define una meta para ver la proyección de cierre')
        ->assertSee('Definir meta')
        ->assertDontSee('Acumulado frente a la meta');
});

it('sin datos en el mes muestra el estado vacío y no pinta gráficas', function (): void {
    $user = userWithRole(Role::Direccion);

    Livewire::actingAs($user)->test(Overview::class)
        ->assertSee('Aún no hay días cargados en octubre 2025')
        ->assertSee('Cargar el primer día')
        ->assertDontSee('Venta en dólares por día')
        ->assertDontSee('Avisos del mes');
});
