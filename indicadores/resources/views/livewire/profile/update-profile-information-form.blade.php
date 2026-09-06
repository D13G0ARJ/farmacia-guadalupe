<?php

use App\Enums\Role;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';

    public function mount(): void
    {
        $this->name = Auth::user()->name;
    }

    /** Solo el nombre: el correo lo cambia Administración para no dejar a nadie fuera por una verificación pendiente. */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate(
            ['name' => ['required', 'string', 'min:3', 'max:80']],
            ['name.required' => 'Escribe tu nombre.', 'name.min' => 'El nombre es muy corto.'],
        );

        $user->fill($validated);
        $user->save();

        $this->dispatch('toast', type: 'success', message: 'Nombre guardado.');
    }
}; ?>

<div>
    <h2 id="profile-info-title" class="text-sub font-semibold text-ink-900">Tus datos</h2>
    <p class="mt-1 text-label text-ink-600">Así te ven los demás en la bitácora y en los cierres de mes.</p>

    <form wire:submit="updateProfileInformation" class="mt-5 space-y-5">
        <x-field label="Nombre" for="name" :error="$errors->first('name')" data-tour="profile-name">
            <x-input id="name" type="text" wire:model="name" required autocomplete="name" :invalid="$errors->has('name')" />
        </x-field>

        <x-field label="Correo" for="email" help="Para cambiarlo, pídeselo al administrador." data-tour="profile-email">
            <x-input id="email" type="email" :value="auth()->user()->email" disabled />
        </x-field>

        <x-field label="Rol" for="role" help="Lo asigna el administrador." data-tour="profile-role">
            <x-input id="role" type="text" :value="Role::tryFrom((string) auth()->user()->getRoleNames()->first())?->label() ?? 'Sin rol'" disabled />
        </x-field>

        <div class="flex justify-end">
            <x-btn type="submit" wire:loading.attr="disabled" wire:target="updateProfileInformation" data-tour="profile-save-name">Guardar nombre</x-btn>
        </div>
    </form>
</div>
