<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\BranchSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.login')
            ->assertSee('Farmacia Guadalupe')
            ->assertSee('Correo')
            ->assertSee('Contraseña')
            ->assertSee('Entrar')
            ->assertDontSee('Log in');
    }

    public function test_failed_login_explains_in_spanish(): void
    {
        $user = User::factory()->create();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'wrong-password')
            ->call('login')
            ->assertHasErrors(['form.email'])
            ->assertSee('El correo o la contraseña no coinciden');
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password');

        $component->call('login');

        $component
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'wrong-password');

        $component->call('login');

        $component
            ->assertHasErrors()
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_navigation_menu_can_be_rendered(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, BranchSeeder::class]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(Role::Supervision->value);
        $user->branches()->attach(Branch::query()->firstOrFail()->id);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Guadalupe')
            ->assertSee('Cargar día')
            ->assertSee($user->name);
    }

    public function test_users_without_permissions_cannot_open_the_dashboard(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->get('/dashboard')->assertForbidden();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'))->assertRedirect('/');

        $this->assertGuest();
    }
}
