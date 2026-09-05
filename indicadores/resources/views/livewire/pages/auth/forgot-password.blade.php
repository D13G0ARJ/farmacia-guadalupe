<?php

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('Recuperar contraseña')] class extends Component
{
    public string $email = '';

    /** Envía el enlace de restablecimiento al correo indicado. */
    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $status = Password::sendResetLink($this->only('email'));

        if ($status != Password::RESET_LINK_SENT) {
            $this->addError('email', __($status));

            return;
        }

        $this->reset('email');

        session()->flash('status', __($status));
    }
}; ?>

<div>
    <h1 class="text-title text-ink-900">Recuperar contraseña</h1>
    <p class="mt-1.5 text-body text-ink-600">Escribe tu correo y te enviamos un enlace para elegir una contraseña nueva.</p>

    @if (session('status'))
        <p class="mt-4 rounded-card border border-success-100 bg-success-100/60 px-3 py-2 text-label text-success-600" role="status">{{ session('status') }}</p>
    @endif

    <form wire:submit="sendPasswordResetLink" class="mt-6 space-y-5">
        <x-field label="Correo" for="email" :error="$errors->first('email')">
            <x-input id="email" type="email" wire:model="email" name="email" required autofocus :invalid="$errors->has('email')" />
        </x-field>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <a class="text-label text-brand-700 hover:underline" href="{{ route('login') }}" wire:navigate>Volver a entrar</a>
            <x-btn type="submit">Enviar enlace</x-btn>
        </div>
    </form>
</div>
