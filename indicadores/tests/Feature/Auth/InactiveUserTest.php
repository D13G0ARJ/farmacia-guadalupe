<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

it('un usuario desactivado no puede entrar aunque su contraseña sea correcta', function (): void {
    $user = User::factory()->create(['is_active' => false]);

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'password')
        ->call('login')
        ->assertHasErrors(['form.email'])
        ->assertSee('Tu acceso está desactivado. Habla con el administrador.');

    $this->assertGuest();
});

it('un usuario desactivado con sesión abierta la pierde en la siguiente petición y ve por qué', function (): void {
    $user = userWithRole(Role::Operador);

    $this->actingAs($user)->get(route('month'))->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($user)->get(route('month'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Tu acceso está desactivado. Habla con el administrador.');

    $this->assertGuest();
});
