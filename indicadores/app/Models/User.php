<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Permission;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_active
 */
#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Sedes asignadas (RN-23). Dirección y admin ven todas vía `branches.all`.
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withTimestamps();
    }

    public function canSeeAllBranches(): bool
    {
        // checkPermissionTo devuelve false (en vez de lanzar) si el permiso aún no existe.
        return $this->checkPermissionTo(Permission::BranchesAll->value);
    }

    /**
     * Sedes accesibles: todas si tiene `branches.all`, si no las asignadas.
     *
     * @return Collection<int, Branch>
     */
    public function accessibleBranches(): Collection
    {
        // Memo por petición: la barra de contexto y la pantalla lo consultan en el mismo ciclo.
        return once(fn (): Collection => $this->canSeeAllBranches()
            ? Branch::query()->where('is_active', true)->orderBy('name')->get()
            : $this->branches()->where('is_active', true)->orderBy('name')->get());
    }

    /** Pantalla de inicio según el rol principal (§13.8). */
    public function homeRoute(): string
    {
        $role = Role::tryFrom((string) $this->getRoleNames()->first());

        return $role?->homeRoute() ?? 'dashboard';
    }
}
