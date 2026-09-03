<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('Confirma tu contraseña')] class extends Component
{
    public string $password = '';

    /** Pide la contraseña antes de una acción sensible. */
    public function confirmPassword(): void
    {
        $this->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->validate([
            'email' => Auth::user()->email,
            'password' => $this->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        session(['auth.password_confirmed_at' => time()]);

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <h1 class="text-sub font-semibold text-ink-900">Confirma tu contraseña</h1>
    <p class="mt-1 text-label text-ink-600">Es una zona sensible: confirma tu contraseña antes de continuar.</p>

    <form wire:submit="confirmPassword" class="mt-6 space-y-5">
        <x-field label="Contraseña" for="password" :error="$errors->first('password')">
            <x-input id="password" type="password" wire:model="password" name="password" required autocomplete="current-password" :invalid="$errors->has('password')" />
        </x-field>

        <x-btn type="submit" class="w-full">Confirmar</x-btn>
    </form>
</div>
