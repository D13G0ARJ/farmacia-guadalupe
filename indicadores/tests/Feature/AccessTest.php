<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('no expone registro público: los usuarios los crea Administración (§15)', function (): void {
    $this->get('/register')->assertNotFound();
});

it('la raíz lleva al panel y el panel exige sesión', function (): void {
    $this->get('/')->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertRedirect('/login');
});

it('el administrador sembrado puede iniciar sesión y entrar al panel', function (): void {
    $this->seed(DatabaseSeeder::class);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    $this->actingAs($admin)->get('/dashboard')->assertOk();
});

it('las vistas no cargan fuentes desde una CDN (§13.2, self-hosted)', function (): void {
    $this->get('/login')->assertOk()->assertDontSee('fonts.bunny.net')->assertDontSee('fonts.googleapis.com');
});
