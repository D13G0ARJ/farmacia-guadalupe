<?php

declare(strict_types=1);

use App\Domain\Goals\GoalStatus;
use App\Domain\Shared\Period;
use App\Livewire\Goals\GoalsManager;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\Goal;
use App\Models\User;
use App\Queries\GoalProgressQuery;
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

function metasAdmin(): User
{
    test()->seed([DatabaseSeeder::class, DemoSeeder::class, DemoHistorySeeder::class, DemoGoalsSeeder::class]);
    app(PeriodContext::class)->set(Period::of('2025-09'));

    return User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
}

it('avisa antes de descartar la cuadrícula escrita sin guardar (M20)', function (): void {
    $admin = metasAdmin();

    $component = Livewire::actingAs($admin)->withQueryParams(['view' => 'year'])->test(GoalsManager::class)
        ->assertDontSeeHtml('wire:confirm')
        ->assertDontSee('Hay metas sin guardar.');

    expect($component->instance()->dirty())->toBeFalse();

    $component->set('grid.sales_usd.2025-10', '21.000')
        ->assertSeeHtml('wire:confirm')
        ->assertSee('Hay metas sin guardar.');

    // Guardar deja la cuadrícula limpia otra vez
    $component->call('saveYear')
        ->assertDontSeeHtml('wire:confirm')
        ->assertDontSee('Hay metas sin guardar.');

    // Y volver a escribir lo mismo que ya estaba guardado no cuenta como cambio
    $component->set('grid.sales_usd.2025-10', '21.000')->assertDontSeeHtml('wire:confirm');
});

it('el porcentaje de crecimiento tiene límites y no crea metas imposibles (M21)', function (): void {
    $admin = metasAdmin();

    $component = Livewire::actingAs($admin)->withQueryParams(['view' => 'year'])->test(GoalsManager::class)
        ->set('growth', '5000')
        ->call('increaseAll')
        ->assertHasErrors(['growth'])
        ->assertSet('grid.sales_usd.2025-09', '20.000');

    expect($component->instance()->getErrorBag()->first('growth'))->toBe('El porcentaje va de -100 a 1000.');

    $component->set('growth', '-200')->call('increaseAll')->assertHasErrors(['growth']);

    // Una meta enorme en la cuadrícula tampoco se multiplica hasta desbordar la columna
    $component->set('growth', '100')
        ->set('grid.sales_usd.2025-10', '9.000.000.000')
        ->call('increaseAll')
        ->assertHasErrors(['growth'])
        ->assertSet('grid.sales_usd.2025-10', '9.000.000.000');

    expect($component->instance()->getErrorBag()->first('growth'))->toBe('La meta es demasiado grande.');

    $component->set('growth', '10')->set('grid.sales_usd.2025-10', '')->call('increaseAll')
        ->assertHasNoErrors()
        ->assertSet('grid.sales_usd.2025-09', '22.000');
});

it('una meta que no cabe en la columna se explica al guardar el año (M21)', function (): void {
    $admin = metasAdmin();

    Livewire::actingAs($admin)->withQueryParams(['view' => 'year'])->test(GoalsManager::class)
        ->set('grid.sales_usd.2025-11', '99.999.999.999')
        ->call('saveYear')
        ->assertHasErrors(['grid.sales_usd.2025-11'])
        ->assertSee('La meta es demasiado grande.');

    expect(Goal::query()->where('period', '2025-11-01')->exists())->toBeFalse();
});

it('un indicador o un mes inventados desde el cliente se ignoran (B25)', function (): void {
    $admin = metasAdmin();

    $component = Livewire::actingAs($admin)->withQueryParams(['view' => 'year'])->test(GoalsManager::class)
        ->set('grid.inventado.2025-09', '1.000')
        ->set('grid.sales_usd.no-es-un-mes', '1.000')
        ->call('saveYear')
        ->assertHasNoErrors();

    expect(Goal::query()->where('indicator', 'inventado')->exists())->toBeFalse()
        ->and(Goal::query()->count())->toBe(8);

    // La vista del mes, igual: la clave del campo llega del cliente
    $component->set('view', 'month')->set('targets.inventado', '5.000')->assertHasNoErrors();
    expect(Goal::query()->count())->toBe(8);
});

it('con todos los días atípicos no hay base para proyectar: queda pendiente, no fuera de meta (M26)', function (): void {
    metasAdmin();
    $branch = Branch::query()->firstOrFail();

    DailyRecord::query()->where('date', '>', '2025-09-20')->delete();
    DailyRecord::query()->where('date', '>=', '2025-09-01')->update(['status' => 'atypical']);

    $sales = app(GoalProgressQuery::class)->run($branch->id, Period::of('2025-09'))->for('sales_usd');

    expect($sales?->daysRemaining)->toBeGreaterThan(0)
        ->and($sales?->status)->toBe(GoalStatus::Pending)
        ->and((string) $sales?->projection)->toBe((string) $sales?->actual);
});
