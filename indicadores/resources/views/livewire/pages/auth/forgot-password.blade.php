<?php

use App\Models\User;
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

        $email = mb_strtolower(trim($this->email));

        // A quien tiene el acceso desactivado no se le manda enlace, y se le responde lo mismo que a
        // los demás para no delatar quién existe ni quién está activo (B21).
        $user = User::query()->where('email', $email)->first();
        if ($user !== null && ! $user->is_active) {
            $this->reset('email');
            session()->flash('status', __(Password::RESET_LINK_SENT));

            return;
        }

        try {
            $status = Password::sendResetLink(['email' => $email]);
        } catch (Throwable $e) {
            // Correo mal configurado o servidor caído: se dice qué hacer, no una pantalla de error (M27).
            report($e);
            $this->addError('email', 'No se pudo enviar el correo. Pide al administrador una contraseña temporal.');

            return;
        }

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
