<?php

declare(strict_types=1);

use App\Domain\Shared\Period;
use App\Enums\Role;
use App\Livewire\Charts\ChartsPage;
use App\Livewire\Shared\ContextBar;
use App\Models\User;
use App\Support\PeriodContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Mailer\Exception\TransportException;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('el panel de gráfica conserva el wire:key que le pasa la pantalla (A12)', function (): void {
    $html = Blade::render('<x-chart-panel :spec="$spec" wire:key="chart-ventas-g2" />', [
        'spec' => ['id' => 'g2', 'title' => 'Venta en dólares por día', 'subtitle' => 'Septiembre 2025', 'empty' => true, 'emptyText' => 'Aún no hay días cargados.'],
    ]);

    expect($html)->toContain('wire:key="chart-ventas-g2"')
        ->and($html)->toContain('class="min-w-0 p-5"'); // las clases del componente siguen ahí

    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    app(PeriodContext::class)->set(Period::of('2025-09'));
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    Livewire::actingAs($admin)->test(ChartsPage::class)
        ->assertSeeHtml('wire:key="chart-ventas-g2"')
        ->assertSeeHtml('wire:key="chart-ventas-g1"');
});

it('no se navega al futuro ni más allá de dos años atrás (M22)', function (): void {
    $user = userWithRole(Role::Direccion);
    app(PeriodContext::class)->set(Period::of('2025-10'));

    $component = Livewire::actingAs($user)->test(ContextBar::class)
        ->assertSet('period', '2025-10')
        ->call('nextPeriod')
        ->assertSet('period', '2025-10')
        ->assertDontSee('Noviembre 2025');

    expect(app(PeriodContext::class)->current()->key())->toBe('2025-10');

    // Desde el selector: un mes futuro o muy viejo se recorta al rango permitido
    $component->set('period', '2027-05')->assertSet('period', '2025-10');
    $component->set('period', '2000-01')->assertSet('period', '2023-10');
    $component->set('period', 'no-es-un-mes')->assertSet('period', '2023-10');

    // Y hacia atrás, las flechas paran en el mes más viejo del selector
    $component->call('previousPeriod')->assertSet('period', '2023-10');
});

it('un año o una pestaña imposibles en la URL responden 200 (M23)', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    $this->actingAs($admin)->get(route('annual').'?anio=abc')->assertOk()->assertSee('Año 2025');
    $this->actingAs($admin)->get(route('annual').'?anio[]=2024')->assertOk()->assertSee('Año 2025');
    $this->actingAs($admin)->get(route('annual').'?anio=1500')->assertOk()->assertSee('Año 2025');
    $this->actingAs($admin)->get(route('charts').'?tab[]=x&indicador[]=y')->assertOk()->assertSee('Gráficas');
    $this->actingAs($admin)->get(route('goals').'?view[]=x')->assertOk()->assertSee('Metas');
});

it('Esc solo cierra el diálogo abierto y el campo numérico no repite inputmode (B15, B26)', function (): void {
    $dialog = Blade::render('<x-dialog show="$wire.userDialog" title="Borrar el día">¿Seguro?</x-dialog>');

    expect($dialog)->toContain('x-on:keydown.escape.window="if ($wire.userDialog) { $wire.userDialog = false }"');

    // El componente pone inputmode="decimal" solo si quien lo usa no trajo el suyo
    expect(Blade::render('<x-input numeric />'))->toContain('inputmode="decimal"');
    expect(substr_count(Blade::render('<x-input numeric inputmode="numeric" />'), 'inputmode='))->toBe(1);
});

it('la moneda se puede cambiar también en el teléfono (B23)', function (): void {
    $user = userWithRole(Role::Direccion);

    $html = Livewire::actingAs($user)->test(ContextBar::class)->html();

    expect($html)->toContain('aria-label="Moneda"')
        ->and($html)->not->toContain('hidden items-center rounded-control border border-line bg-surface p-0.5 sm:flex');
});

it('cambiar la contraseña desde el perfil rota el recordarme y queda en la bitácora (B19)', function (): void {
    $user = User::factory()->create();
    $token = $user->remember_token;
    $this->actingAs($user);

    Volt::test('profile.update-password-form')
        ->set('current_password', 'password')
        ->set('password', 'ClaveNueva9')
        ->set('password_confirmation', 'ClaveNueva9')
        ->call('updatePassword')
        ->assertHasNoErrors();

    $user->refresh();
    expect(Hash::check('ClaveNueva9', $user->password))->toBeTrue()
        ->and($user->remember_token)->not->toBe($token)
        ->and(Activity::query()->where('event', 'password_changed')->where('subject_id', $user->id)->exists())->toBeTrue();
});

it('cambiar el nombre desde el perfil queda en la bitácora con el antes y el después (B19)', function (): void {
    $user = User::factory()->create(['name' => 'Nombre Viejo']);
    $this->actingAs($user);

    Volt::test('profile.update-profile-information-form')
        ->set('name', 'Nombre Nuevo')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $activity = Activity::query()->where('event', 'name_changed')->firstOrFail();
    expect($user->refresh()->name)->toBe('Nombre Nuevo')
        ->and($activity->properties['old']['name'])->toBe('Nombre Viejo')
        ->and($activity->properties['attributes']['name'])->toBe('Nombre Nuevo');

    // Guardar el mismo nombre no ensucia la bitácora
    Volt::test('profile.update-profile-information-form')->set('name', 'Nombre Nuevo')->call('updateProfileInformation');
    expect(Activity::query()->where('event', 'name_changed')->count())->toBe(1);
});

it('si el correo no sale, se dice qué hacer en vez de romper (M27)', function (): void {
    $user = User::factory()->create();

    Password::shouldReceive('sendResetLink')->once()->andThrow(new TransportException('smtp caído'));

    Volt::test('pages.auth.forgot-password')
        ->set('email', $user->email)
        ->call('sendPasswordResetLink')
        ->assertHasErrors(['email'])
        ->assertSee('No se pudo enviar el correo. Pide al administrador una contraseña temporal.');
});

it('a quien tiene el acceso desactivado no se le manda enlace, y se le responde igual que a los demás (B21)', function (): void {
    Notification::fake();
    $inactivo = User::factory()->create(['is_active' => false]);
    $activo = User::factory()->create();

    // Misma respuesta que a un usuario normal: sin error y con el campo limpio
    Volt::test('pages.auth.forgot-password')
        ->set('email', mb_strtoupper($inactivo->email))
        ->call('sendPasswordResetLink')
        ->assertHasNoErrors()
        ->assertSet('email', '');

    Notification::assertNothingSent();

    Volt::test('pages.auth.forgot-password')
        ->set('email', $activo->email)
        ->call('sendPasswordResetLink')
        ->assertHasNoErrors()
        ->assertSet('email', '');

    Notification::assertSentTo($activo, ResetPassword::class);
    Notification::assertNotSentTo($inactivo, ResetPassword::class);
});
