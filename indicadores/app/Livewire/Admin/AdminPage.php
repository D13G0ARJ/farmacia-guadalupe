<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Admin\SaveBranch;
use App\Actions\Admin\SaveUser;
use App\Actions\Admin\SetUserPassword;
use App\Actions\Admin\ToggleUserActive;
use App\Actions\Admin\UpdateSettings;
use App\Domain\Admin\Exceptions\AdminException;
use App\Enums\Permission;
use App\Enums\Role;
use App\Livewire\Forms\BranchForm;
use App\Livewire\Forms\SettingsForm;
use App\Livewire\Forms\UserForm;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\Goal;
use App\Models\ImportBatch;
use App\Models\PeriodEvent;
use App\Models\User;
use App\Support\ActivityDescriber;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * UC-17: usuarios, sedes, parámetros y bitácora. Solo `admin.manage`.
 */
#[Layout('layouts.app')]
#[Title('Administración')]
class AdminPage extends Component
{
    public const TABS = ['usuarios' => 'Usuarios', 'sedes' => 'Sedes', 'parametros' => 'Parámetros', 'bitacora' => 'Bitácora'];

    /** Familias de la bitácora → tipo de sujeto. */
    public const LOG_TYPES = [
        'all' => 'Todo',
        'dias' => 'Días',
        'tasas' => 'Tasas',
        'metas' => 'Metas',
        'meses' => 'Cierres de mes',
        'usuarios' => 'Usuarios',
        'sedes' => 'Sedes',
        'importaciones' => 'Importaciones',
        'parametros' => 'Parámetros',
    ];

    /** Qué puede hacer cada rol, en palabras del negocio (se muestra bajo el selector). */
    public const ROLE_HELP = [
        'operador' => 'Carga los días y corrige los recientes. No ve metas ni cierra meses.',
        'supervision' => 'Además borra días, cierra el mes, gestiona la tasa BCV y ve las metas.',
        'direccion' => 'Además define metas, reabre meses y ve todas las sedes.',
        'admin' => 'Todo lo anterior y esta pantalla: usuarios, sedes y parámetros.',
    ];

    #[Url]
    public string $tab = 'usuarios';

    public UserForm $userForm;

    public BranchForm $branchForm;

    public SettingsForm $settingsForm;

    public bool $userDialog = false;

    public bool $passwordDialog = false;

    public bool $branchDialog = false;

    public ?int $passwordUserId = null;

    public string $newPassword = '';

    /** @var array{name: string, email: string, password: string}|null credenciales recién emitidas, para entregarlas */
    public ?array $issued = null;

    #[Url(as: 'tipo')]
    public string $logType = 'all';

    #[Url(as: 'buscar')]
    public string $logSearch = '';

    public int $logLimit = 50;

    public function mount(): void
    {
        abort_unless(auth()->user()->can(Permission::AdminManage->value), 403);
        $this->normalizeTab();
        $this->settingsForm->fillFromSettings();
    }

    public function updatedTab(): void
    {
        $this->normalizeTab();
    }

    // ---- Usuarios -------------------------------------------------------------------------

    public function openUser(?int $id = null): void
    {
        $this->resetErrorBag();
        $this->userForm->reset();
        $this->issued = null;
        if ($id !== null) {
            $this->userForm->fillFromUser(User::query()->findOrFail($id));
        } else {
            $this->userForm->password = SetUserPassword::suggest();
            $main = Branch::query()->where('is_active', true)->orderBy('id')->first();
            $this->userForm->branch_ids = $main === null ? [] : [$main->id];
        }
        $this->userDialog = true;
    }

    public function suggestPassword(): void
    {
        $this->userForm->password = SetUserPassword::suggest();
    }

    public function saveUser(SaveUser $action): void
    {
        $this->authorizeAdmin();
        $this->userForm->validate();
        $user = $this->userForm->id === null ? null : User::query()->findOrFail($this->userForm->id);
        $isNew = $user === null;

        try {
            $saved = $action->handle([
                'name' => $this->userForm->name,
                'email' => $this->userForm->email,
                'role' => $this->userForm->roleEnum(),
                'branch_ids' => $this->userForm->needsBranches() ? array_map('intval', $this->userForm->branch_ids) : array_map('intval', $this->userForm->branch_ids),
                'password' => $this->userForm->password !== '' ? $this->userForm->password : null,
            ], auth()->user(), $user);
        } catch (AdminException $e) {
            $this->addError('userForm.role', $e->getMessage());

            return;
        }

        if ($isNew) {
            $this->issued = ['name' => $saved->name, 'email' => $saved->email, 'password' => $this->userForm->password];
        }
        $this->userDialog = false;
        $this->userForm->reset();
        $this->dispatch('toast', type: 'success', message: $isNew ? 'Usuario creado.' : 'Usuario actualizado.');
    }

    public function toggleUser(ToggleUserActive $action, int $id): void
    {
        $this->authorizeAdmin();
        $user = User::query()->findOrFail($id);

        try {
            $action->handle($user, ! $user->is_active, auth()->user());
        } catch (AdminException $e) {
            $this->dispatch('toast', type: 'danger', message: $e->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', message: $user->is_active ? 'Acceso activado para '.$user->name.'.' : 'Acceso desactivado para '.$user->name.'.');
    }

    public function openPassword(int $id): void
    {
        $this->resetErrorBag();
        $this->passwordUserId = $id;
        $this->newPassword = SetUserPassword::suggest();
        $this->issued = null;
        $this->passwordDialog = true;
    }

    public function savePassword(SetUserPassword $action): void
    {
        $this->authorizeAdmin();
        $this->validate(['newPassword' => ['required', 'string', 'min:8', 'max:72']], ['newPassword.min' => 'La contraseña debe tener al menos 8 caracteres.', 'newPassword.required' => 'Escribe una contraseña.']);
        $user = User::query()->findOrFail($this->passwordUserId ?? 0);

        $action->handle($user, $this->newPassword, auth()->user());

        $this->issued = ['name' => $user->name, 'email' => $user->email, 'password' => $this->newPassword];
        $this->passwordDialog = false;
        $this->newPassword = '';
        $this->dispatch('toast', type: 'success', message: 'Contraseña cambiada para '.$user->name.'.');
    }

    public function dismissIssued(): void
    {
        $this->issued = null;
    }

    // ---- Sedes ----------------------------------------------------------------------------

    public function openBranch(?int $id = null): void
    {
        $this->resetErrorBag();
        $this->branchForm->reset();
        if ($id !== null) {
            $this->branchForm->fillFromBranch(Branch::query()->findOrFail($id));
        }
        $this->branchDialog = true;
    }

    public function saveBranch(SaveBranch $action): void
    {
        $this->authorizeAdmin();
        $this->branchForm->validate();
        $branch = $this->branchForm->id === null ? null : Branch::query()->findOrFail($this->branchForm->id);

        try {
            $action->handle($this->branchForm->toData(), auth()->user(), $branch);
        } catch (AdminException $e) {
            $this->addError('branchForm.is_active', $e->getMessage());

            return;
        }

        $this->branchDialog = false;
        $this->dispatch('toast', type: 'success', message: $branch === null ? 'Sede creada.' : 'Sede actualizada.');
    }

    // ---- Parámetros -----------------------------------------------------------------------

    public function saveSettings(UpdateSettings $action): void
    {
        $this->authorizeAdmin();
        $this->settingsForm->validate();

        $changed = $action->handle($this->settingsForm->toValues(), auth()->user());
        $this->settingsForm->fillFromSettings();

        $this->dispatch('toast', type: 'success', message: $changed === [] ? 'Sin cambios en los parámetros.' : 'Parámetros guardados.');
    }

    // ---- Bitácora -------------------------------------------------------------------------

    public function loadMore(): void
    {
        $this->logLimit += 50;
    }

    public function updatedLogType(): void
    {
        $this->logLimit = 50;
        if (! array_key_exists($this->logType, self::LOG_TYPES)) {
            $this->logType = 'all';
        }
    }

    public function updatedLogSearch(): void
    {
        $this->logLimit = 50;
    }

    public function render(ActivityDescriber $describer): View
    {
        $users = User::query()->with(['roles', 'branches'])->orderBy('name')->get();
        $branches = Branch::query()->withCount('dailyRecords')->orderBy('name')->get();

        $entries = [];
        $hasMore = false;
        if ($this->tab === 'bitacora') {
            $query = Activity::query()->with(['causer', 'subject'])->latest('id');
            $this->applyLogFilters($query);
            $activities = $query->limit($this->logLimit + 1)->get();
            $hasMore = $activities->count() > $this->logLimit;
            $entries = $activities->take($this->logLimit)->map(fn (Activity $a) => ['id' => $a->id, ...$describer->describe($a)])->all();
        }

        return view('livewire.admin.admin-page', [
            'tabs' => self::TABS,
            'users' => $users,
            'branches' => $branches,
            'roles' => Role::cases(),
            'roleHelp' => self::ROLE_HELP,
            'logTypes' => self::LOG_TYPES,
            'entries' => $entries,
            'hasMore' => $hasMore,
            'me' => auth()->user(),
        ]);
    }

    /** @param  Builder<Activity>  $query */
    private function applyLogFilters($query): void
    {
        $type = match ($this->logType) {
            'dias' => DailyRecord::class,
            'tasas' => ExchangeRate::class,
            'metas' => Goal::class,
            'meses' => PeriodEvent::class,
            'usuarios' => User::class,
            'sedes' => Branch::class,
            'importaciones' => ImportBatch::class,
            default => null,
        };
        if ($type !== null) {
            $query->where('subject_type', $type);
        } elseif ($this->logType === 'parametros') {
            $query->where('event', 'settings_updated');
        }

        $search = trim($this->logSearch);
        if ($search !== '') {
            $query->whereHas('causer', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()->can(Permission::AdminManage->value), 403);
    }

    private function normalizeTab(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'usuarios';
        }
    }
}
