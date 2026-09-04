<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed_in_spanish_with_the_identity(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertSeeVolt('profile.update-profile-information-form')
            ->assertSeeVolt('profile.update-password-form')
            ->assertSee('Tu perfil')
            ->assertSee('Tu contraseña')
            ->assertSee($user->email)
            ->assertDontSee('Delete Account');
    }

    public function test_name_can_be_updated_but_email_stays_managed_by_administration(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Volt::test('profile.update-profile-information-form')
            ->set('name', 'Ana Operadora')
            ->call('updateProfileInformation')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $user->refresh();

        $this->assertSame('Ana Operadora', $user->name);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_name_is_validated_with_a_plain_message(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('profile.update-profile-information-form')
            ->set('name', 'A')
            ->call('updateProfileInformation')
            ->assertHasErrors(['name'])
            ->assertSee('El nombre es muy corto.');
    }
}
