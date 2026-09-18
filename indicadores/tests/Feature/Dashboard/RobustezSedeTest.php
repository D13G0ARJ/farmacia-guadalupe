<?php

declare(strict_types=1);

use App\Domain\Shared\Period;
use App\Enums\Role;
use App\Livewire\Annual\AnnualComparison;
use App\Livewire\Charts\ChartsPage;
use App\Livewire\Dashboard\Overview;
use App\Livewire\Goals\GoalsManager;
use App\Models\Branch;
use App\Models\User;
use App\Support\CurrentBranch;
use App\Support\PeriodContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoGoalsSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * Supervisor cuya única sede quedó desactivada: no le queda ninguna sede accesible, mientras la
 * sede principal (con los datos de demostración) sigue activa.
 */
function supervisorSinSedeActiva(): User
{
    test()->seed([DatabaseSeeder::class, DemoSeeder::class, DemoGoalsSeeder::class]);
    app(PeriodContext::class)->set(Period::of('2025-09'));

    $cerrada = Branch::factory()->create(['name' => 'Sede Cerrada', 'code' => 'GUA-99', 'is_active' => false]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::Supervision->value);
    $user->branches()->attach($cerrada->id);

    return $user;
}

it('sin sede activa asignada no se ve el consolidado de las demás sedes (A10)', function (): void {
    $supervisor = supervisorSinSedeActiva();

    expect(app(CurrentBranch::class)->hasAccess($supervisor))->toBeFalse()
        ->and($supervisor->accessibleBranches())->toHaveCount(0);

    // Panel: estado vacío y ni una cifra de la otra sede
    $overview = Livewire::actingAs($supervisor)->test(Overview::class)
        ->assertSee('No tienes ninguna sede activa asignada. Pide a Administración que te asigne una.')
        ->assertDontSee('18.611')
        ->assertDontSee('Avisos del mes');
    expect($overview->get('specs'))->toBe([]);

    // Gráficas, Año y Metas, lo mismo
    $charts = Livewire::actingAs($supervisor)->test(ChartsPage::class)
        ->assertSee('No tienes ninguna sede activa asignada.')
        ->assertDontSee('Venta en dólares por día');
    expect($charts->get('specs'))->toBe([]);

    Livewire::actingAs($supervisor)->test(AnnualComparison::class)
        ->assertSee('No tienes ninguna sede activa asignada.')
        ->assertDontSee('3.012.771');

    $goals = Livewire::actingAs($supervisor)->test(GoalsManager::class)
        ->assertSee('No tienes ninguna sede activa asignada.')
        ->assertDontSee('20.000');
    expect($goals->get('targets.sales_usd'))->toBe('')
        ->and($goals->get('grid.sales_usd.2025-09'))->toBe('');
});

it('las pantallas responden 200 con el aviso, no con un error (A10)', function (): void {
    $supervisor = supervisorSinSedeActiva();

    foreach (['dashboard', 'charts', 'annual', 'goals'] as $route) {
        $this->actingAs($supervisor)->get(route($route))
            ->assertOk()
            ->assertSee('No tienes ninguna sede activa asignada. Pide a Administración que te asigne una.');
    }
});

it('quien sí tiene sede activa sigue viendo sus datos (A10 no rompe lo demás)', function (): void {
    supervisorSinSedeActiva();
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    Livewire::actingAs($admin)->test(Overview::class)
        ->assertDontSee('No tienes ninguna sede activa asignada')
        ->assertSee('18.611');
});
