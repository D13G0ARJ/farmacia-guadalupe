<?php

declare(strict_types=1);

use App\Domain\Indicators\Indicator;
use App\Domain\Shared\Period;
use App\Enums\Role;
use App\Livewire\Annual\AnnualComparison;
use App\Livewire\Shared\ContextBar;
use App\Models\Branch;
use App\Models\User;
use App\Queries\AnnualComparisonQuery;
use App\Support\CurrencyContext;
use App\Support\PeriodContext;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoHistorySeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('arma el año con los meses cargados, el agregado ponderado y las variaciones (UC-13)', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class, DemoHistorySeeder::class]);
    $branch = Branch::query()->firstOrFail();

    $annual = app(AnnualComparisonQuery::class)->run($branch->id, 2025);

    $august = $annual->months[8];
    $september = $annual->months[9];
    expect($annual->monthsWithData())->toBe(2)
        ->and($annual->hasData(7))->toBeFalse()
        ->and((string) $annual->value(Indicator::SalesBs, 9))->toBe('3012770.86')
        ->and($annual->value(Indicator::SalesBs, 7))->toBeNull()
        ->and((string) $annual->yearValue(Indicator::SalesBs))->toBe((string) $august->sumsAll['salesBs']->plus($september->sumsAll['salesBs']))
        ->and($annual->yearValue(Indicator::Transactions)?->toInt())->toBe($august->sumsAll['transactions'] + $september->sumsAll['transactions'])
        // El ticket del año es ponderado: Σbs / Σtrn, no el promedio de los dos tickets mensuales
        ->and((string) $annual->yearValue(Indicator::AvgTicketBs)?->toScale(2, RoundingMode::HalfUp))
        ->toBe((string) $august->sumsAll['salesBs']->plus($september->sumsAll['salesBs'])->dividedBy($august->sumsAll['transactions'] + $september->sumsAll['transactions'], 2, RoundingMode::HalfUp))
        ->and($annual->vsPreviousMonth(Indicator::SalesUsd, 9))->not->toBeNull()
        ->and($annual->vsPreviousMonth(Indicator::SalesUsd, 8))->toBeNull()
        ->and($annual->vsLastYear(Indicator::SalesUsd, 9))->toBeNull()
        ->and($annual->years)->toBe([2025]);
});

it('la pantalla Año muestra la tabla del Excel con doce meses, la columna del año y navegación', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class, DemoHistorySeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
    app(PeriodContext::class)->set(Period::of('2025-09'));

    $component = Livewire::actingAs($admin)->test(AnnualComparison::class)
        ->assertSet('year', 2025)
        ->assertSee('Año 2025')
        ->assertSee('2 meses con datos')
        ->assertSee('Venta en bolívares')
        ->assertSee('Bs 3.012.771')
        ->assertSee('$ 18.611')
        ->assertSee('Exportar a Excel')
        ->assertSeeHtml('title="vs mes anterior:');

    $component->call('previousYear')->assertSet('year', 2024)->assertSee('Aún no hay meses cargados en 2024');
    $component->call('nextYear')->assertSet('year', 2025);

    Livewire::actingAs($admin)->test(ContextBar::class)->set('currency', CurrencyContext::USD);
    Livewire::actingAs($admin)->test(AnnualComparison::class)->assertDontSee('Venta en bolívares')->assertSee('Venta en dólares');
});

it('la ruta responde con sede y el operador también la ve', function (): void {
    $branch = mainBranch();

    $this->actingAs(userWithRole(Role::Operador, $branch))->get(route('annual'))->assertOk()->assertSee('Año');
    $this->actingAs(User::factory()->create())->get(route('annual'))->assertForbidden();
});
