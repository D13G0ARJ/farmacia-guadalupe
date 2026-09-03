<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('Verifica tu correo')] class extends Component
{
    /** Reenvía el correo de verificación. */
    public function sendVerification(): void
    {
        if (Auth::user()->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);

            return;
        }

        Auth::user()->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div>
    <h1 class="text-sub font-semibold text-ink-900">Verifica tu correo</h1>
    <p class="mt-1 text-body text-ink-600">Te enviamos un enlace a tu correo. Ábrelo para activar tu acceso. Si no llegó, te enviamos otro.</p>

    @if (session('status') == 'verification-link-sent')
        <p class="mt-4 rounded-card border border-success-100 bg-success-100/60 px-3 py-2 text-label text-success-600" role="status">Enlace enviado de nuevo al correo de tu cuenta.</p>
    @endif

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <x-btn wire:click="sendVerification">Reenviar enlace</x-btn>
        <button type="button" wire:click="logout" class="text-label text-ink-600 hover:underline">Salir</button>
    </div>
</div>
