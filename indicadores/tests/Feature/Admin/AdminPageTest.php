<?php

declare(strict_types=1);

use App\Actions\Admin\SetUserPassword;
use App\Actions\Admin\ToggleUserActive;
use App\Actions\Periods\CloseMonth;
use App\Domain\Shared\Period;
use App\Enums\Role;
use App\Livewire\Admin\AdminPage;
use App\Models\Branch;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('solo el administrador entra; el menú lo muestra únicamente a él', function (): void {
    $branch = mainBranch();
    $admin = userWithRole(Role::Admin, $branch);
    $director = userWithRole(Role::Direccion, $branch);

    $this->actingAs($admin)->get(route('admin'))->assertOk()->assertSee('Administración')->assertSee('Nuevo usuario');
    $this->actingAs($director)->get(route('admin'))->assertForbidden();
    $this->actingAs($director)->get(route('dashboard'))->assertOk()->assertDontSee('Administración');
});

it('crea un usuario desde el diálogo, muestra las credenciales una sola vez y el usuario puede entrar', function (): void {
    $branch = mainBranch();
    $admin = userWithRole(Role::Admin, $branch);

    $component = Livewire::actingAs($admin)->test(AdminPage::class)
        ->call('openUser')
        ->assertSet('userDialog', true)
        ->assertSet('userForm.branch_ids', [$branch->id]);

    expect(strlen($component->get('userForm.password')))->toBe(10);

    $component->set('userForm.name', 'A')->set('userForm.email', 'no-es-correo')->call('saveUser')
        ->assertHasErrors(['userForm.name', 'userForm.email'])
        ->assertSee('El nombre es muy corto.')
        ->assertSee('Ese correo no parece válido.');

    $component->set('userForm.name', 'Ana Operadora')
        ->set('userForm.email', 'ana@guadalupe.local')
        ->set('userForm.role', 'operador')
        ->set('userForm.password', 'ClaveTemporal9')
        ->call('saveUser')
        ->assertHasNoErrors()
        ->assertSet('userDialog', false)
        ->assertDispatched('toast')
        ->assertSee('Entrégale estos datos a Ana Operadora')
        ->assertSee('ClaveTemporal9')
        ->assertSee('ana@guadalupe.local');

    $user = User::query()->where('email', 'ana@guadalupe.local')->firstOrFail();
    expect($user->hasRole('operador'))->toBeTrue()
        ->and(Hash::check('ClaveTemporal9', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();

    $component->call('dismissIssued')->assertDontSee('ClaveTemporal9');

    $this->post('/logout');
    $this->post('/login', ['email' => 'ana@guadalupe.local', 'password' => 'ClaveTemporal9']);
});

it('un operador necesita sede; dirección no', function (): void {
    $branch = mainBranch();
    $admin = userWithRole(Role::Admin, $branch);

    $component = Livewire::actingAs($admin)->test(AdminPage::class)->call('openUser')
        ->set('userForm.name', 'Pedro Pérez')->set('userForm.email', 'pedro@guadalupe.local')->set('userForm.password', 'ClaveTemporal9')
        ->set('userForm.role', 'operador')->set('userForm.branch_ids', [])
        ->call('saveUser')
        ->assertHasErrors(['userForm.branch_ids'])
        ->assertSee('Elige al menos una sede para este rol.');

    $component->set('userForm.role', 'direccion')->call('saveUser')->assertHasNoErrors();
    expect(User::query()->where('email', 'pedro@guadalupe.local')->firstOrFail()->canSeeAllBranches())->toBeTrue();
});

it('edita, desactiva y reactiva un usuario, y cambia su contraseña con la sugerida', function (): void {
    $branch = mainBranch();
    $admin = userWithRole(Role::Admin, $branch);
    $user = userWithRole(Role::Operador, $branch);

    $component = Livewire::actingAs($admin)->test(AdminPage::class)
        ->call('openUser', $user->id)
        ->assertSet('userForm.name', $user->name)
        ->assertSet('userForm.role', 'operador')
        ->set('userForm.role', 'supervision')
        ->call('saveUser')
        ->assertHasNoErrors()
        ->assertSee('Supervisión');

    $component->call('toggleUser', $user->id)->assertDispatched('toast')->assertSee('Desactivado');
    expect($user->refresh()->is_active)->toBeFalse();
    $component->call('toggleUser', $user->id)->assertSee('Activo');
    expect($user->refresh()->is_active)->toBeTrue();

    $component->call('openPassword', $user->id)->assertSet('passwordDialog', true);
    $suggested = $component->get('newPassword');
    $component->call('savePassword')->assertHasNoErrors()->assertSet('passwordDialog', false)->assertSee($suggested);
    expect(Hash::check($suggested, $user->refresh()->password))->toBeTrue();

    $component->call('toggleUser', $admin->id)->assertDispatched('toast'); // a sí mismo: aviso, no cambio
    expect($admin->refresh()->is_active)->toBeTrue();
});

it('edita la sede: días de inventario, jornadas y umbral propio', function (): void {
    $branch = mainBranch();
    $admin = userWithRole(Role::Admin, $branch);

    $component = Livewire::actingAs($admin)->test(AdminPage::class)->set('tab', 'sedes')
        ->assertSee('Sede Principal')
        ->call('openBranch', $branch->id)
        ->assertSet('branchForm.default_shifts', 3)
        ->set('branchForm.inventory_days', [1, 2, 3, 4, 5])
        ->set('branchForm.default_shifts', 4)
        ->set('branchForm.sales_deviation_pct', '40')
        ->call('saveBranch')
        ->assertHasNoErrors()
        ->assertSet('branchDialog', false)
        ->assertSee('lun, mar, mié, jue, vie')
        ->assertSee('40 %');

    $branch->refresh();
    expect($branch->inventory_days)->toBe([1, 2, 3, 4, 5])
        ->and($branch->default_shifts)->toBe(4)
        ->and($branch->countsInventoryOn(CarbonImmutable::parse('2025-09-07')))->toBeFalse();

    $component->call('openBranch')->set('branchForm.name', 'Zona Sur')->set('branchForm.code', 'GUA-02')->set('branchForm.legal_name', 'FARMACIA GUADALUPE, C.A.')
        ->call('saveBranch')->assertHasNoErrors();
    expect(Branch::query()->count())->toBe(2);
});

it('guarda los parámetros con validación cruzada y explicación', function (): void {
    $branch = mainBranch();
    $admin = userWithRole(Role::Admin, $branch);

    $component = Livewire::actingAs($admin)->test(AdminPage::class)->set('tab', 'parametros')
        ->assertSee('Desvío de la venta (%)')
        ->assertSet('settingsForm.operator_edit_window_days', 7)
        ->set('settingsForm.goal_on_track_pct', 80)
        ->set('settingsForm.goal_at_risk_pct', 90)
        ->call('saveSettings')
        ->assertHasErrors(['settingsForm.goal_on_track_pct'])
        ->assertSee('debe ser mayor que el de "en riesgo"');

    $component->set('settingsForm.goal_on_track_pct', 100)->set('settingsForm.operator_edit_window_days', 14)
        ->call('saveSettings')->assertHasNoErrors()->assertDispatched('toast');

    expect(Setting::get('operator_edit_window_days'))->toBe(14);
});

it('la bitácora cuenta en palabras del negocio quién hizo qué, con filtros', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
    $branch = Branch::query()->firstOrFail();
    $this->actingAs($admin);
    app(CloseMonth::class)->handle($branch, Period::of('2025-09'), $admin);
    $operator = userWithRole(Role::Operador, $branch);
    app(SetUserPassword::class)->handle($operator, 'ClaveTemporal9', $admin);
    app(ToggleUserActive::class)->handle($operator, false, $admin);
    Setting::put('operator_edit_window_days', 9);

    $component = Livewire::actingAs($admin)->test(AdminPage::class)->set('tab', 'bitacora')
        ->assertSee('Administrador')
        ->assertSee('cerró el mes septiembre 2025')
        ->assertSee('creó el día 30/09/2025')
        ->assertSee('cambió la contraseña de '.$operator->name)
        ->assertSee('desactivó el acceso de '.$operator->name)
        ->assertDontSee('de a '.$operator->name);

    $component->set('logType', 'meses')->assertSee('cerró el mes')->assertDontSee('creó el día');
    $component->set('logType', 'all')->set('logSearch', 'nadie')->assertSee('Nada en la bitácora con ese filtro.');
});
