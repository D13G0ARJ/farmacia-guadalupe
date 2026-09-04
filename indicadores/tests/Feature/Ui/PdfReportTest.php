<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoGoalsSeeder;
use Database\Seeders\DemoHistorySeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

/** PNG mínimo válido (1×1) como URL de datos. */
function tinyPng(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
}

it('descarga el reporte PDF del mes con KPI, cuadro y metas, aun sin gráficas (UC-14)', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class, DemoHistorySeeder::class, DemoGoalsSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    $response = $this->actingAs($admin)->get(route('exports.pdf', ['period' => '2025-09']));

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('reporte-2025-09.pdf')
        ->and(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');
});

it('recibe las gráficas del navegador, las valida y las usa una sola vez', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    $this->actingAs($admin)->postJson(route('exports.charts', ['period' => '2025-09']), [
        'images' => ['g2' => tinyPng(), 'g8' => 'data:image/jpeg;base64,AAAA', 'malo/../x' => tinyPng(), 'g9' => 'no es una imagen'],
    ])->assertOk()->assertJson(['stored' => 1]);

    expect(cache()->get("report-charts:{$admin->id}:2025-09"))->toHaveKey('g2');

    $this->actingAs($admin)->get(route('exports.pdf', ['period' => '2025-09']))->assertOk();
    expect(cache()->get("report-charts:{$admin->id}:2025-09"))->toBeNull(); // se consumen al generar

    $this->actingAs($admin)->postJson(route('exports.charts', ['period' => '2025-09']), ['images' => 'nada'])->assertStatus(422);
    $this->actingAs($admin)->postJson(route('exports.charts', ['period' => 'nada']), ['images' => []])->assertNotFound();
});

it('exige sesión y rechaza períodos inválidos', function (): void {
    $this->get(route('exports.pdf', ['period' => '2025-09']))->assertRedirect(route('login'));

    $this->seed([DatabaseSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
    $this->actingAs($admin)->get(route('exports.pdf', ['period' => 'nada']))->assertNotFound();
    $this->actingAs($admin)->get(route('exports.pdf', ['period' => '2025-07']))->assertOk(); // mes vacío: PDF con el aviso
});
