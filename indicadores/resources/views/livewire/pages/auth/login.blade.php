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
    <h1 class="text-title text-ink-900">Entrar</h1>
    <p class="mt-1.5 text-body text-ink-600">Con tu correo y contraseña.</p>

    @if (session('status'))
        <p class="mt-5 flex items-start gap-2 rounded-card border border-success-100 bg-success-100/60 px-3 py-2.5 text-label text-success-600" role="status">
            <x-lucide name="info" class="mt-0.5 h-3.5 w-3.5 shrink-0" />{{ session('status') }}
        </p>
    @endif

    <form wire:submit="login" class="mt-7 space-y-5">
        <x-field label="Correo" for="email" :error="$errors->first('form.email')">
            <x-input id="email" type="email" wire:model="form.email" name="email" required autofocus autocomplete="username" inputmode="email" placeholder="nombre@correo.com" :invalid="$errors->has('form.email')" />
        </x-field>

        <x-field label="Contraseña" for="password" :error="$errors->first('form.password')">
            <div class="relative" x-data="{ ver: false }">
                <x-input id="password" type="password" x-bind:type="ver ? 'text' : 'password'" class="pr-12" wire:model="form.password" name="password" required autocomplete="current-password" :invalid="$errors->has('form.password')" />
                <button type="button" x-on:click="ver = ! ver" x-bind:aria-label="ver ? 'Ocultar la contraseña' : 'Mostrar la contraseña'" aria-label="Mostrar la contraseña"
                        class="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-control text-ink-400 transition-colors duration-150 hover:text-ink-600">
                    <x-lucide name="eye" class="h-[18px] w-[18px]" x-show="! ver" />
                    <x-lucide name="eye-off" class="h-[18px] w-[18px]" x-show="ver" x-cloak />
                </button>
            </div>
        </x-field>

        <div class="flex flex-wrap items-center justify-between gap-3 pt-0.5">
            <label for="remember" class="flex cursor-pointer select-none items-center gap-2 text-body text-ink-600">
                <input wire:model="form.remember" id="remember" type="checkbox" name="remember" class="h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500">
                Recordarme
            </label>
            @if (Route::has('password.request'))
                <a class="rounded-control text-label text-brand-700 hover:text-brand-800 hover:underline" href="{{ route('password.request') }}" wire:navigate>¿Olvidaste tu contraseña?</a>
            @endif
        </div>

        <x-btn type="submit" class="w-full" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login">Entrar</span>
            <span wire:loading wire:target="login" class="flex items-center gap-2">
                <x-lucide name="refresh" class="h-4 w-4 animate-spin" />Entrando…
            </span>
        </x-btn>
    </form>
</div>
