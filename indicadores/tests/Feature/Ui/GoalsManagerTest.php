<?php

declare(strict_types=1);

use App\Domain\Shared\Period;
use App\Enums\Role;
use App\Livewire\Goals\GoalsManager;
use App\Models\Goal;
use App\Models\User;
use App\Support\PeriodContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoGoalsSeeder;
use Database\Seeders\DemoHistorySeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-09-22 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function goalsAdmin(bool $withGoals = true): User
{
    $seeders = [DatabaseSeeder::class, DemoSeeder::class, DemoHistorySeeder::class];
    if ($withGoals) {
        $seeders[] = DemoGoalsSeeder::class;
    }
    test()->seed($seeders);
    app(PeriodContext::class)->set(Period::of('2025-09'));

    return User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
}

it('abre en "Este mes" con la meta, la sugerencia, el actual, lo esperado, la proyección y el estado (UC-11/12)', function (): void {
    $admin = goalsAdmin();

    Livewire::actingAs($admin)->test(GoalsManager::class)
        ->assertSet('view', 'month')
        ->assertSet('targets.sales_usd', '20.000')
        ->assertSet('targets.avg_ticket_usd', '5,0')
        ->assertSet('targets.sales_bs', '')
        ->assertSee('Venta en dólares')
        ->assertSee('Esperado hoy')
        ->assertSee('Proyección')
        ->assertSee('En riesgo')
        ->assertSeeHtml('placeholder="Sugerida:')
        ->assertSee('Copiar de agosto 2025')
        ->assertSee('proyección por patrón semanal');
});

it('editar una meta en línea la guarda al perder el foco; vacía la borra; inválida avisa', function (): void {
    $admin = goalsAdmin();

    $component = Livewire::actingAs($admin)->test(GoalsManager::class)
        ->set('targets.sales_usd', '22.500')
        ->assertHasNoErrors()
        ->assertDispatched('toast')
        ->assertSet('targets.sales_usd', '22.500');

    expect((string) Goal::query()->where('indicator', 'sales_usd')->where('period', '2025-09-01')->firstOrFail()->target)->toBe('22500.0000');

    $component->set('targets.transactions', '')->assertHasNoErrors();
    expect(Goal::query()->where('indicator', 'transactions')->where('period', '2025-09-01')->exists())->toBeFalse();

    $component->set('targets.units', 'muchas')->assertHasErrors(['targets.units']);
    expect((string) Goal::query()->where('indicator', 'units')->where('period', '2025-09-01')->firstOrFail()->target)->toBe('8000.0000');
});

it('lo editado en la vista del mes se ve al pasar a la vista Año', function (): void {
    $admin = goalsAdmin();

    Livewire::actingAs($admin)->test(GoalsManager::class)
        ->set('targets.sales_usd', '23.000')
        ->set('view', 'year')
        ->assertSet('grid.sales_usd.2025-09', '23.000')
        ->set('grid.sales_usd.2025-09', '24.000')
        ->call('saveYear')
        ->set('view', 'month')
        ->assertSet('targets.sales_usd', '24.000');
});

it('"Copiar de agosto" trae las metas del mes anterior', function (): void {
    $admin = goalsAdmin();
    Goal::query()->where('period', '2025-09-01')->delete();

    Livewire::actingAs($admin)->test(GoalsManager::class)
        ->assertSet('targets.sales_usd', '')
        ->call('copyPreviousMonth')
        ->assertDispatched('toast')
        ->assertSet('targets.sales_usd', '18.500')
        ->assertSet('targets.transactions', '3.800');

    expect(Goal::query()->where('period', '2025-09-01')->count())->toBe(2);
});

it('la vista Año muestra la cuadrícula y guarda en lote solo lo que cambió', function (): void {
    $admin = goalsAdmin();

    $component = Livewire::actingAs($admin)->withQueryParams(['view' => 'year'])->test(GoalsManager::class)
        ->assertSet('view', 'year')
        ->assertSet('year', 2025)
        ->assertSet('grid.sales_usd.2025-09', '20.000')
        ->assertSet('grid.sales_usd.2025-08', '18.500')
        ->assertSet('grid.sales_usd.2025-10', '')
        ->assertSee('Guardar metas')
        ->set('grid.sales_usd.2025-10', '21.000')
        ->set('grid.sales_usd.2025-11', '21.500')
        ->set('grid.transactions.2025-08', '')
        ->call('saveYear')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect((string) Goal::query()->where('indicator', 'sales_usd')->where('period', '2025-10-01')->firstOrFail()->target)->toBe('21000.0000')
        ->and(Goal::query()->where('indicator', 'sales_usd')->where('period', '2025-11-01')->exists())->toBeTrue()
        ->and(Goal::query()->where('indicator', 'transactions')->where('period', '2025-08-01')->exists())->toBeFalse()
        ->and(Goal::query()->count())->toBe(9);

    $component->call('previousYear')->assertSet('year', 2024)->assertSet('grid.sales_usd.2024-09', '');
});

it('"+X % a todo el año" y "Copiar año anterior" preparan la cuadrícula sin guardar', function (): void {
    $admin = goalsAdmin();

    $component = Livewire::actingAs($admin)->withQueryParams(['view' => 'year'])->test(GoalsManager::class)
        ->set('growth', '10')
        ->call('increaseAll')
        ->assertSet('grid.sales_usd.2025-09', '22.000')
        ->assertSet('grid.avg_ticket_usd.2025-09', '5,5')
        ->assertSet('grid.sales_usd.2025-10', '');

    expect((string) Goal::query()->where('indicator', 'sales_usd')->where('period', '2025-09-01')->firstOrFail()->target)->toBe('20000.0000');

    $component->call('nextYear')->assertSet('year', 2026)
        ->call('copyPreviousYear')
        ->assertSet('grid.sales_usd.2026-09', '20.000')
        ->assertSet('grid.sales_usd.2026-08', '18.500');

    expect(Goal::query()->where('period', '>=', '2026-01-01')->exists())->toBeFalse();

    $component->set('growth', 'x')->call('increaseAll')->assertHasErrors(['growth']);
});

it('supervisión ve las metas pero no puede cambiarlas; el operador no entra', function (): void {
    goalsAdmin();
    $supervisor = userWithRole(Role::Supervision);
    app(PeriodContext::class)->set(Period::of('2025-09'));

    Livewire::actingAs($supervisor)->test(GoalsManager::class)
        ->assertSee('Solo dirección puede cambiar las metas')
        ->assertDontSee('Copiar de agosto');

    Livewire::actingAs($supervisor)->test(GoalsManager::class)->set('targets.sales_usd', '1')->assertForbidden();
    expect((string) Goal::query()->where('indicator', 'sales_usd')->where('period', '2025-09-01')->firstOrFail()->target)->toBe('20000.0000');

    $this->actingAs(userWithRole(Role::Operador))->get(route('goals'))->assertForbidden();
    $this->actingAs($supervisor)->get(route('goals'))->assertOk();
});
