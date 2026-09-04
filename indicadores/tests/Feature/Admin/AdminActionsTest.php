<?php

declare(strict_types=1);

use App\Actions\Admin\SaveBranch;
use App\Actions\Admin\SaveUser;
use App\Actions\Admin\SetUserPassword;
use App\Actions\Admin\ToggleUserActive;
use App\Actions\Admin\UpdateSettings;
use App\Domain\Admin\Exceptions\AdminException;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('crea un usuario verificado con su rol y sus sedes y lo deja en la bitácora (UC-17)', function (): void {
    $branch = mainBranch();
    $admin = userWithRole(Role::Admin, $branch);

    $user = app(SaveUser::class)->handle([
        'name' => '  Ana Operadora ',
        'email' => 'Ana@Guadalupe.local',
        'role' => Role::Operador,
        'branch_ids' => [$branch->id],
        'password' => 'clave-inicial-1',
    ], $admin);

    expect($user->name)->toBe('Ana Operadora')
        ->and($user->email)->toBe('ana@guadalupe.local')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and($user->hasRole('operador'))->toBeTrue()
        ->and($user->branches()->pluck('branches.id')->all())->toBe([$branch->id])
        ->and(Hash::check('clave-inicial-1', $user->password))->toBeTrue()
        ->and(Activity::query()->where('subject_type', User::class)->where('event', 'created')->exists())->toBeTrue();
});

it('al editar conserva la contraseña si no se escribe otra y cambia el rol', function (): void {
    $branch = mainBranch();
    $admin = userWithRole(Role::Admin, $branch);
    $user = userWithRole(Role::Operador, $branch);
    $hash = $user->password;

    $saved = app(SaveUser::class)->handle(['name' => $user->name, 'email' => $user->email, 'role' => Role::Supervision, 'branch_ids' => [$branch->id], 'password' => null], $admin, $user);

    expect($saved->password)->toBe($hash)
        ->and($saved->hasRole('supervision'))->toBeTrue()
        ->and($saved->hasRole('operador'))->toBeFalse();
});

it('no deja quitar el rol ni desactivar al único administrador activo, ni desactivarse a uno mismo', function (): void {
    $branch = mainBranch();
    $admin = userWithRole(Role::Admin, $branch);
    $other = userWithRole(Role::Operador, $branch);

    expect(fn () => app(SaveUser::class)->handle(['name' => $admin->name, 'email' => $admin->email, 'role' => Role::Direccion, 'branch_ids' => []], $admin, $admin))
        ->toThrow(AdminException::class, 'único administrador')
        ->and(fn () => app(ToggleUserActive::class)->handle($admin, false, $admin))
        ->toThrow(AdminException::class, 'tu propio acceso')
        ->and(fn () => app(ToggleUserActive::class)->handle($admin, false, $other))
        ->toThrow(AdminException::class, 'único administrador');

    $second = userWithRole(Role::Admin, $branch);
    app(ToggleUserActive::class)->handle($admin, false, $second);
    expect($admin->refresh()->is_active)->toBeFalse()
        ->and(Activity::query()->where('event', 'deactivated')->exists())->toBeTrue();
});

it('cambia la contraseña sin dejarla en la bitácora y sugiere una legible', function (): void {
    $branch = mainBranch();
    $admin = userWithRole(Role::Admin, $branch);
    $user = userWithRole(Role::Operador, $branch);

    app(SetUserPassword::class)->handle($user, 'NuevaClave2025', $admin);

    $log = Activity::query()->where('event', 'password_reset')->firstOrFail();
    expect(Hash::check('NuevaClave2025', $user->refresh()->password))->toBeTrue()
        ->and(json_encode($log->properties))->not->toContain('NuevaClave2025')
        ->and(SetUserPassword::suggest())->toHaveLength(10)
        ->and(SetUserPassword::suggest())->not->toMatch('/[0OIl1]/');
});

it('guarda una sede normalizando código y días, y nunca deja el sistema sin sede activa', function (): void {
    $branch = mainBranch();
    $admin = userWithRole(Role::Admin, $branch);

    $saved = app(SaveBranch::class)->handle([
        'name' => 'Sede Zona Sur', 'code' => 'gua-02', 'legal_name' => 'FARMACIA GUADALUPE, C.A.',
        'default_shifts' => 2, 'inventory_days' => [7, 1, 1, 3], 'sales_deviation_pct' => '', 'is_active' => true,
    ], $admin);

    expect($saved->code)->toBe('GUA-02')
        ->and($saved->inventory_days)->toBe([1, 3, 7])
        ->and($saved->sales_deviation_pct)->toBeNull()
        ->and(Branch::query()->count())->toBe(2);

    app(SaveBranch::class)->handle([...$saved->only(['name', 'code', 'legal_name', 'default_shifts', 'inventory_days']), 'sales_deviation_pct' => '40', 'is_active' => false], $admin, $saved);

    expect(fn () => app(SaveBranch::class)->handle([...$branch->only(['name', 'code', 'legal_name', 'default_shifts', 'inventory_days']), 'sales_deviation_pct' => null, 'is_active' => false], $admin, $branch))
        ->toThrow(AdminException::class, 'al menos una sede activa');
});

it('guarda solo los parámetros que cambian y los anota con su valor anterior', function (): void {
    $branch = mainBranch();
    $admin = userWithRole(Role::Admin, $branch);

    $changed = app(UpdateSettings::class)->handle(['sales_deviation_pct' => 35, 'operator_edit_window_days' => 10, 'app_name' => 'Indicadores · Farmacia Guadalupe'], $admin);

    $log = Activity::query()->where('event', 'settings_updated')->firstOrFail();
    expect($changed)->toBe(['operator_edit_window_days'])
        ->and(Setting::get('operator_edit_window_days'))->toBe(10)
        ->and($log->properties['old']['operator_edit_window_days'])->toBe(7)
        ->and($log->properties['attributes']['operator_edit_window_days'])->toBe(10);

    expect(app(UpdateSettings::class)->handle(['operator_edit_window_days' => 10], $admin))->toBe([]);
});
