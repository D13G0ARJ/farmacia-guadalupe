<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function updatePassword(): void
    {
        try {
            $validated = $this->validate(
                [
                    'current_password' => ['required', 'string', 'current_password'],
                    'password' => ['required', 'string', Password::defaults(), 'confirmed'],
                ],
                [
                    'current_password.required' => 'Escribe tu contraseña actual.',
                    'current_password.current_password' => 'La contraseña actual no es correcta.',
                    'password.required' => 'Escribe la contraseña nueva.',
                    'password.confirmed' => 'Las dos contraseñas nuevas no coinciden.',
                ],
            );
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
        $this->dispatch('toast', type: 'success', message: 'Contraseña cambiada.');
    }
}; ?>

<div>
    <h2 id="profile-password-title" class="text-sub font-semibold text-ink-900">Tu contraseña</h2>
    <p class="mt-1 text-label text-ink-600">Al menos 8 caracteres. Si el administrador te dio una temporal, cámbiala aquí.</p>

    <form wire:submit="updatePassword" class="mt-5 space-y-5" data-tour="profile-password">
        <x-field label="Contraseña actual" for="update_password_current_password" :error="$errors->first('current_password')">
            <x-input id="update_password_current_password" type="password" wire:model="current_password" autocomplete="current-password" :invalid="$errors->has('current_password')" />
        </x-field>

        <x-field label="Contraseña nueva" for="update_password_password" :error="$errors->first('password')">
            <x-input id="update_password_password" type="password" wire:model="password" autocomplete="new-password" :invalid="$errors->has('password')" />
        </x-field>

        <x-field label="Repite la contraseña nueva" for="update_password_password_confirmation">
            <x-input id="update_password_password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password" />
        </x-field>

        <div class="flex justify-end">
            <x-btn type="submit" wire:loading.attr="disabled" wire:target="updatePassword" data-tour="profile-change-password">Cambiar contraseña</x-btn>
        </div>
    </form>
</div>
