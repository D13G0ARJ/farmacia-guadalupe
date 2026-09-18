<?php

declare(strict_types=1);

use App\Actions\Admin\SaveUser;
use App\Domain\Admin\Exceptions\AdminException;
use App\Enums\Role;
use App\Livewire\Admin\AdminPage;
use App\Models\Setting;
use App\Models\User;
use App\Support\ActivityDescriber;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('un parámetro numérico vacío o con letras da un mensaje, no un error (A11)', function (): void {
    $admin = userWithRole(Role::Admin, mainBranch());

    $component = Livewire::actingAs($admin)->test(AdminPage::class)->set('tab', 'parametros')
        ->set('settingsForm.sales_deviation_pct', '')
        ->set('settingsForm.report_email_day', '')
        ->call('saveSettings')
        ->assertHasErrors(['settingsForm.sales_deviation_pct', 'settingsForm.report_email_day'])
        ->assertSee('Este valor es obligatorio.');

    $component->set('settingsForm.sales_deviation_pct', 'abc')
        ->set('settingsForm.report_email_day', 'mañana')
        ->set('settingsForm.goal_on_track_pct', 'cien')
        ->call('saveSettings')
        ->assertHasErrors(['settingsForm.sales_deviation_pct', 'settingsForm.report_email_day', 'settingsForm.goal_on_track_pct'])
        ->assertSee('Escribe un número entero.');

    // Nada de esto llegó a guardarse
    expect(Setting::get('sales_deviation_pct'))->toBe(35);

    $component->set('settingsForm.sales_deviation_pct', '40')
        ->set('settingsForm.report_email_day', '3')
        ->set('settingsForm.goal_on_track_pct', '100')
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect(Setting::get('sales_deviation_pct'))->toBe(40)
        ->and(Setting::get('report_email_day'))->toBe(3);
});

it('las jornadas de la sede vacías o con letras avisan en vez de reventar (A11)', function (): void {
    $branch = mainBranch();
    $admin = userWithRole(Role::Admin, $branch);

    $component = Livewire::actingAs($admin)->test(AdminPage::class)->set('tab', 'sedes')
        ->call('openBranch', $branch->id)
        ->set('branchForm.default_shifts', '')
        ->call('saveBranch')
        ->assertHasErrors(['branchForm.default_shifts']);

    $component->set('branchForm.default_shifts', 'tres')
        ->call('saveBranch')
        ->assertHasErrors(['branchForm.default_shifts'])
        ->assertSee('Escribe un número entero de jornadas.');

    expect($branch->refresh()->default_shifts)->toBe(3);

    $component->set('branchForm.default_shifts', '5')->call('saveBranch')->assertHasNoErrors();
    expect($branch->refresh()->default_shifts)->toBe(5);
});

it('un correo repetido con otras mayúsculas se rechaza con su mensaje (M24)', function (): void {
    $branch = mainBranch();
    $admin = userWithRole(Role::Admin, $branch);
    $operator = userWithRole(Role::Operador, $branch);

    Livewire::actingAs($admin)->test(AdminPage::class)
        ->call('openUser')
        ->set('userForm.name', 'Otra Persona')
        ->set('userForm.email', mb_strtoupper($operator->email))
        ->set('userForm.password', 'ClaveTemporal9')
        ->call('saveUser')
        ->assertHasErrors(['userForm.email'])
        ->assertSee('Ya hay un usuario con ese correo.');

    expect(User::query()->count())->toBe(2);
});

it('si aun así choca el índice único, se explica en vez de dar un 500 (M24)', function (): void {
    $branch = mainBranch();
    $admin = userWithRole(Role::Admin, $branch);
    $operator = userWithRole(Role::Operador, $branch);

    expect(fn () => app(SaveUser::class)->handle([
        'name' => 'Otra Persona',
        'email' => $operator->email,
        'role' => Role::Operador,
        'branch_ids' => [$branch->id],
        'password' => 'ClaveTemporal9',
    ], $admin))->toThrow(AdminException::class, 'Ya hay un usuario con ese correo.');

    expect(User::query()->count())->toBe(2);
});

it('a dirección y administración no se les asigna ninguna sede (B18)', function (): void {
    $branch = mainBranch();
    $admin = userWithRole(Role::Admin, $branch);

    Livewire::actingAs($admin)->test(AdminPage::class)
        ->call('openUser')
        ->assertSet('userForm.branch_ids', [$branch->id])
        ->set('userForm.name', 'Dora Dirección')
        ->set('userForm.email', 'dora@guadalupe.local')
        ->set('userForm.role', 'direccion')
        ->set('userForm.password', 'ClaveTemporal9')
        ->call('saveUser')
        ->assertHasNoErrors();

    $dora = User::query()->where('email', 'dora@guadalupe.local')->firstOrFail();
    expect($dora->branches()->count())->toBe(0)
        ->and($dora->canSeeAllBranches())->toBeTrue();
});

it('una pestaña o un filtro imposibles en la URL no rompen Administración (M23)', function (): void {
    $admin = userWithRole(Role::Admin, mainBranch());

    $this->actingAs($admin)->get(route('admin').'?tab[]=x&tipo[]=y&buscar[]=z')->assertOk()->assertSee('Usuarios');

    Livewire::actingAs($admin)->withQueryParams(['tab' => ['x'], 'tipo' => ['y'], 'buscar' => ['z']])->test(AdminPage::class)
        ->assertSet('tab', 'usuarios')
        ->assertSet('logType', 'all')
        ->assertSet('logSearch', '');
});

it('los parámetros se leen una vez por petición y guardar invalida la memoria (M25)', function (): void {
    mainBranch();

    Setting::get('sales_deviation_pct');
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    Setting::get('sales_deviation_pct');
    Setting::get('sales_deviation_pct');
    expect($queries)->toBe(0)
        ->and(Setting::get('sales_deviation_pct'))->toBe(35);

    Setting::put('sales_deviation_pct', 42);
    expect(Setting::get('sales_deviation_pct'))->toBe(42);

    Setting::forget('sales_deviation_pct');
    expect(Setting::get('sales_deviation_pct'))->toBe(35);
});

it('la bitácora describe en palabras los cambios hechos desde el perfil', function (): void {
    $user = userWithRole(Role::Admin, mainBranch());
    activity()->performedOn($user)->causedBy($user)->event('password_changed')->log('password_changed');
    activity()->performedOn($user)->causedBy($user)->event('name_changed')->withProperties(['old' => ['name' => 'Ana'], 'attributes' => ['name' => 'Ana Pérez']])->log('name_changed');

    $describer = app(ActivityDescriber::class);
    $entries = Activity::query()->orderBy('id')->get()->map(fn ($a) => $describer->describe($a));

    expect($entries[0]['verb'])->toBe('cambió su contraseña')
        ->and($entries[0]['subject'])->toBe('')
        ->and($entries[1]['verb'])->toBe('cambió su nombre')
        ->and($entries[1]['changes'][0]['old'])->toBe('Ana')
        ->and($entries[1]['changes'][0]['new'])->toBe('Ana Pérez');
});
