<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('Entrar')] class extends Component
{
    public LoginForm $form;

    /** UC-01: autentica, regenera la sesión y lleva a la pantalla de inicio del rol. */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <h1 class="text-sub font-semibold text-ink-900">Entrar</h1>
    <p class="mt-1 text-label text-ink-600">Con tu correo y contraseña.</p>

    @if (session('status'))
        <p class="mt-4 rounded-card border border-success-100 bg-success-100/60 px-3 py-2 text-label text-success-600" role="status">{{ session('status') }}</p>
    @endif

    <form wire:submit="login" class="mt-6 space-y-5">
        <x-field label="Correo" for="email" :error="$errors->first('form.email')">
            <x-input id="email" type="email" wire:model="form.email" name="email" required autofocus autocomplete="username" :invalid="$errors->has('form.email')" />
        </x-field>

        <x-field label="Contraseña" for="password" :error="$errors->first('form.password')">
            <x-input id="password" type="password" wire:model="form.password" name="password" required autocomplete="current-password" :invalid="$errors->has('form.password')" />
        </x-field>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <label for="remember" class="flex items-center gap-2 text-body text-ink-600">
                <input wire:model="form.remember" id="remember" type="checkbox" name="remember" class="h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500">
                Recordarme
            </label>
            @if (Route::has('password.request'))
                <a class="text-label text-brand-700 hover:underline" href="{{ route('password.request') }}" wire:navigate>¿Olvidaste tu contraseña?</a>
            @endif
        </div>

        <x-btn type="submit" class="w-full" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login">Entrar</span>
            <span wire:loading wire:target="login">Entrando…</span>
        </x-btn>
    </form>
</div>
