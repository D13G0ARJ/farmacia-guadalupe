<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\HelpContent;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('tiene ayuda propia para cada pantalla, siete términos de glosario y los atajos', function (): void {
    foreach (['dashboard', 'records.create', 'month', 'charts', 'goals', 'annual', 'rates', 'imports', 'admin', 'profile'] as $route) {
        $help = HelpContent::for($route);
        expect($help['title'])->not->toBe('Ayuda')->and($help['items'])->not->toBeEmpty();
    }

    expect(HelpContent::for('nada')['title'])->toBe('Ayuda')
        ->and(HelpContent::glossary())->toHaveCount(7)
        ->and(array_column(HelpContent::glossary(), 'term'))->toBe(['Día', 'Mes', 'Meta', 'Tasa BCV', 'Transacciones', 'Unidades', 'Jornadas'])
        ->and(array_column(HelpContent::shortcuts(), 'keys'))->toContain('Alt + N', '?')
        ->and(HelpContent::formulas())->toHaveCount(12);
});

it('cada pantalla incluye el panel de ayuda con su título, las fórmulas y el glosario', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    $this->actingAs($admin)->withSession(['context.period' => '2025-09'])->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Ayuda · Panel')
        ->assertSee('¿Cómo se calcula cada indicador?')
        ->assertSee('Suma de la venta de cada día convertida a dólares')
        ->assertSee('Glosario')
        ->assertSee('Atajos de teclado')
        ->assertSee('data-shortcut-new', false)
        ->assertSee('¿Cómo se calcula venta en dólares?')
        ->assertSee('Último día (mar 30/09)');

    $this->actingAs($admin)->get(route('month'))->assertOk()->assertSee('Ayuda · Mes');
    $this->actingAs($admin)->get(route('rates'))->assertOk()->assertSee('Ayuda · Tasa BCV');
});
