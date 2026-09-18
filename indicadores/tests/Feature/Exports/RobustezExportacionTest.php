<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('sin el permiso de exportar no se descarga nada, ni escribiendo la dirección a mano (A7)', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $branch = mainBranch();
    $operator = userWithRole(Role::Operador, $branch);
    $supervisor = userWithRole(Role::Supervision, $branch);

    // El operador ve los días (records.view) pero no puede llevárselos
    $this->actingAs($operator)->get(route('exports.month', ['period' => '2025-09']))->assertForbidden();
    $this->actingAs($operator)->get(route('exports.pdf', ['period' => '2025-09']))->assertForbidden();
    $this->actingAs($operator)->get(route('exports.annual', ['year' => 2025]))->assertForbidden();
    $this->actingAs($operator)->postJson(route('exports.charts', ['period' => '2025-09']), ['images' => []])->assertForbidden();

    $this->actingAs($supervisor)->get(route('exports.month', ['period' => '2025-09']))->assertOk();
    $this->actingAs($supervisor)->get(route('exports.pdf', ['period' => '2025-09']))->assertOk();
    $this->actingAs($supervisor)->get(route('exports.annual', ['year' => 2025]))->assertOk();
});

it('los botones de Excel y PDF no aparecen para quien no puede exportar (A7)', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $branch = mainBranch();
    $operator = userWithRole(Role::Operador, $branch);
    $supervisor = userWithRole(Role::Supervision, $branch);
    $session = ['context.period' => '2025-09'];

    $this->actingAs($operator)->withSession($session)->get(route('month'))->assertOk()->assertDontSee('data-tour="month-export"', false);
    $this->actingAs($supervisor)->withSession($session)->get(route('month'))->assertOk()->assertSee('data-tour="month-export"', false);

    // El panel (al operador lo manda a Mes): alguien que ve los días pero no puede exportar no ve el PDF
    $withoutExport = userWithRole(Role::Supervision, $branch);
    $withoutExport->removeRole(Role::Supervision->value);
    $withoutExport->givePermissionTo(['records.view', 'goals.view']);

    $this->actingAs($withoutExport)->withSession($session)->get(route('dashboard'))->assertOk()->assertDontSee('data-tour="dash-pdf"', false);
    $this->actingAs($supervisor)->withSession($session)->get(route('dashboard'))->assertOk()->assertSee('data-tour="dash-pdf"', false);
});

it('un mes que no existe da 404 en vez de saltar al año siguiente (B19)', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    foreach (['2025-13', '2025-00', '2026-13', '2025-99'] as $invalid) {
        $this->actingAs($admin)->get(route('month', ['period' => $invalid]))->assertNotFound();
        $this->actingAs($admin)->get(route('exports.month', ['period' => $invalid]))->assertNotFound();
        $this->actingAs($admin)->get(route('exports.pdf', ['period' => $invalid]))->assertNotFound();
    }

    $this->actingAs($admin)->get(route('month', ['period' => '2025-12']))->assertOk();
});
