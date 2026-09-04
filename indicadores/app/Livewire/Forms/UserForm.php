<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Form;

/** Alta y edición de usuarios en Administración (UC-17). */
class UserForm extends Form
{
    public ?int $id = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'operador';

    /** @var list<int> */
    public array $branch_ids = [];

    public string $password = '';

    public function fillFromUser(User $user): void
    {
        $this->id = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = (string) ($user->getRoleNames()->first() ?? Role::Operador->value);
        $this->branch_ids = $user->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
        $this->password = '';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'email' => ['required', 'string', 'email', 'max:120', Rule::unique('users', 'email')->ignore($this->id)],
            'role' => ['required', Rule::in(array_column(Role::cases(), 'value'))],
            'branch_ids' => [$this->needsBranches() ? 'required' : 'nullable', 'array'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')],
            'password' => [$this->id === null ? 'required' : 'nullable', 'string', 'min:8', 'max:72'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre.',
            'name.min' => 'El nombre es muy corto.',
            'email.required' => 'Escribe el correo.',
            'email.email' => 'Ese correo no parece válido.',
            'email.unique' => 'Ya hay un usuario con ese correo.',
            'branch_ids.required' => 'Elige al menos una sede para este rol.',
            'password.required' => 'Escribe una contraseña inicial de 8 caracteres o más.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ];
    }

    public function roleEnum(): Role
    {
        return Role::from($this->role);
    }

    /** Dirección y administración ven todas las sedes; operador y supervisión necesitan una asignada. */
    public function needsBranches(): bool
    {
        return in_array($this->role, [Role::Operador->value, Role::Supervision->value], true);
    }
}
