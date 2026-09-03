<?php

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('Nueva contraseña')] class extends Component
{
    #[Locked]
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        $this->email = request()->string('email');
    }

    /** Restablece la contraseña del usuario del enlace. */
    public function resetPassword(): void
    {
        $this->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::reset(
            $this->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) {
                $user->forceFill([
                    'password' => Hash::make($this->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status != Password::PASSWORD_RESET) {
            $this->addError('email', __($status));

            return;
        }

        session()->flash('status', __($status));

        $this->redirectRoute('login', navigate: true);
    }
}; ?>

<div>
    <h1 class="text-sub font-semibold text-ink-900">Nueva contraseña</h1>
    <p class="mt-1 text-label text-ink-600">Elige una contraseña de al menos 8 caracteres.</p>

    <form wire:submit="resetPassword" class="mt-6 space-y-5">
        <x-field label="Correo" for="email" :error="$errors->first('email')">
            <x-input id="email" type="email" wire:model="email" name="email" required autofocus autocomplete="username" :invalid="$errors->has('email')" />
        </x-field>

        <x-field label="Contraseña nueva" for="password" :error="$errors->first('password')">
            <x-input id="password" type="password" wire:model="password" name="password" required autocomplete="new-password" :invalid="$errors->has('password')" />
        </x-field>

        <x-field label="Repite la contraseña" for="password_confirmation">
            <x-input id="password_confirmation" type="password" wire:model="password_confirmation" name="password_confirmation" required autocomplete="new-password" />
        </x-field>

        <x-btn type="submit" class="w-full">Guardar contraseña</x-btn>
    </form>
</div>
