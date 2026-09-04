<!DOCTYPE html>
<html lang="es" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    @php
        $user = auth()->user();
        $nav = [
            ['title' => 'Día a día', 'items' => [
                ['label' => 'Panel', 'icon' => 'panel', 'route' => 'dashboard', 'active' => request()->routeIs('dashboard')],
                ['label' => 'Cargar día', 'icon' => 'plus', 'route' => 'records.create', 'active' => request()->routeIs('records.create')],
                ['label' => 'Mes', 'icon' => 'calendar', 'route' => 'month', 'active' => request()->routeIs('month')],
            ]],
            ['title' => 'Análisis', 'items' => [
                ['label' => 'Gráficas', 'icon' => 'chart', 'route' => 'charts', 'active' => request()->routeIs('charts')],
                ['label' => 'Metas', 'icon' => 'target', 'route' => 'goals', 'active' => request()->routeIs('goals'), 'hidden' => ! $user->can('goals.view')],
                ['label' => 'Año', 'icon' => 'year', 'route' => 'annual', 'active' => request()->routeIs('annual')],
            ]],
            ['title' => 'Configuración', 'items' => [
                ['label' => 'Tasa BCV', 'icon' => 'rate', 'route' => 'rates', 'active' => request()->routeIs('rates'), 'hidden' => ! $user->can('rates.manage')],
                ['label' => 'Importar', 'icon' => 'upload', 'route' => 'imports', 'active' => request()->routeIs('imports'), 'hidden' => ! $user->can('imports.run')],
                ['label' => 'Administración', 'icon' => 'settings', 'route' => 'admin', 'active' => request()->routeIs('admin'), 'hidden' => ! $user->can('admin.manage')],
            ]],
        ];
    @endphp
    <body class="h-full bg-brand-50 font-sans text-body text-ink-900 antialiased"
          x-data="{ sidebar: false, toasts: [], push(t) { if (! t || ! t.message) return; const id = Date.now() + Math.random(); this.toasts.push({ id, ...t }); setTimeout(() => this.toasts = this.toasts.filter(x => x.id !== id), t.action ? 10000 : 4000) }, dismiss(id) { this.toasts = this.toasts.filter(x => x.id !== id) } }"
          x-on:toast.window="push(Array.isArray($event.detail) ? $event.detail[0] : $event.detail)"
          @if (session('toast')) x-init="push(@js(session('toast')))" @endif>

        <div class="flex min-h-full">
            {{-- Barra lateral (§13.3): capa neutra `panel`, tres grupos, activo en azul. --}}
            <aside class="fixed inset-y-0 left-0 z-30 hidden w-60 flex-col border-r border-line bg-panel md:flex" aria-label="Navegación principal">
                <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2 px-5 py-5 text-brand-800">
                    <x-lucide name="cross" class="h-6 w-6 text-brand-600" />
                    <span class="text-sub font-semibold">Guadalupe</span>
                </a>
                <nav class="flex-1 space-y-6 px-3">
                    @foreach ($nav as $group)
                        <div>
                            <p class="mb-1 px-3 text-label text-ink-400">{{ $group['title'] }}</p>
                            <div class="space-y-0.5">
                                @foreach ($group['items'] as $item)
                                    @continue($item['hidden'] ?? false)
                                    <x-nav-item :icon="$item['icon']" :href="isset($item['route']) ? route($item['route']) : null" :active="$item['active'] ?? false" :soon="$item['soon'] ?? false">{{ $item['label'] }}</x-nav-item>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </nav>
                <div class="border-t border-line px-3 py-3">
                    <a href="{{ route('profile') }}" wire:navigate class="flex items-center gap-3 rounded-control px-3 py-2 text-body text-brand-800 hover:bg-brand-100/60">
                        <x-lucide name="user" class="h-5 w-5" />
                        <span class="truncate">{{ $user->name }}</span>
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-3 rounded-control px-3 py-2 text-body text-ink-600 hover:bg-brand-100/60">
                            <x-lucide name="logout" class="h-5 w-5" />Salir
                        </button>
                    </form>
                </div>
            </aside>

            <div class="flex min-h-full min-w-0 flex-1 flex-col md:pl-60">
                {{-- Barra superior: contexto global y la única acción primaria (§13.3). --}}
                <header class="sticky top-0 z-20 border-b border-line bg-panel/95 backdrop-blur">
                    <div class="mx-auto flex max-w-[1280px] items-center gap-3 px-4 py-3 sm:px-6">
                        <button type="button" class="md:hidden rounded-control p-2 text-brand-800 hover:bg-brand-100/60" x-on:click="sidebar = true" aria-label="Abrir menú">
                            <x-lucide name="menu" />
                        </button>
                        <livewire:shared.context-bar />
                        <div class="ml-auto hidden sm:block">
                            @can('records.create')
                                <x-btn :href="route('records.create')" icon="plus" wire:navigate>Cargar día</x-btn>
                            @endcan
                        </div>
                    </div>
                </header>

                <main class="mx-auto w-full max-w-[1280px] flex-1 px-4 py-6 pb-24 sm:px-6 md:pb-8">
                    {{ $slot }}
                </main>

                {{-- Móvil: navegación inferior y botón fijo de carga (§13.3). --}}
                <nav class="fixed inset-x-0 bottom-0 z-30 flex h-[56px] items-stretch border-t border-line bg-panel md:hidden" aria-label="Navegación">
                    <a href="{{ route('dashboard') }}" wire:navigate class="flex flex-1 flex-col items-center gap-0.5 py-2 text-label {{ request()->routeIs('dashboard') ? 'text-brand-600' : 'text-ink-600' }}"><x-lucide name="panel" />Panel</a>
                    @can('records.create')
                        <a href="{{ route('records.create') }}" wire:navigate class="flex flex-1 flex-col items-center gap-0.5 py-2 text-label {{ request()->routeIs('records.create') ? 'text-brand-600' : 'text-ink-600' }}"><x-lucide name="plus" />Cargar</a>
                    @endcan
                    <a href="{{ route('month') }}" wire:navigate class="flex flex-1 flex-col items-center gap-0.5 py-2 text-label {{ request()->routeIs('month') ? 'text-brand-600' : 'text-ink-600' }}"><x-lucide name="calendar" />Mes</a>
                    <button type="button" x-on:click="sidebar = true" class="flex flex-1 flex-col items-center gap-0.5 py-2 text-label text-ink-600"><x-lucide name="menu" />Más</button>
                </nav>
            </div>
        </div>

        {{-- Menú lateral móvil --}}
        <div x-cloak x-show="sidebar" class="fixed inset-0 z-40 md:hidden" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-ink-900/40" x-on:click="sidebar = false"></div>
            <div class="absolute inset-y-0 left-0 w-64 bg-panel p-4 shadow-overlay" x-on:keydown.escape.window="sidebar = false">
                <div class="mb-4 flex items-center justify-between">
                    <span class="flex items-center gap-2 text-sub font-semibold text-brand-800"><x-lucide name="cross" class="h-5 w-5 text-brand-600" />Guadalupe</span>
                    <button type="button" x-on:click="sidebar = false" class="rounded-control p-2 hover:bg-brand-100/60" aria-label="Cerrar menú"><x-lucide name="x" /></button>
                </div>
                <nav class="space-y-5">
                    @foreach ($nav as $group)
                        <div>
                            <p class="mb-1 px-3 text-label text-ink-400">{{ $group['title'] }}</p>
                            @foreach ($group['items'] as $item)
                                @continue($item['hidden'] ?? false)
                                <x-nav-item :icon="$item['icon']" :href="isset($item['route']) ? route($item['route']) : null" :active="$item['active'] ?? false" :soon="$item['soon'] ?? false">{{ $item['label'] }}</x-nav-item>
                            @endforeach
                        </div>
                    @endforeach
                    <div class="border-t border-line pt-3">
                        <a href="{{ route('profile') }}" wire:navigate class="flex items-center gap-3 rounded-control px-3 py-2 text-body text-brand-800"><x-lucide name="user" class="h-5 w-5" />{{ $user->name }}</a>
                        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="flex w-full items-center gap-3 rounded-control px-3 py-2 text-body text-ink-600"><x-lucide name="logout" class="h-5 w-5" />Salir</button></form>
                    </div>
                </nav>
            </div>
        </div>

        {{-- Toasts (§13.5): inferior derecha, 4 s, mismo verbo de la acción en pasado. --}}
        <div class="pointer-events-none fixed bottom-20 right-4 z-50 flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2 md:bottom-4" aria-live="polite">
            <template x-for="t in toasts" :key="t.id">
                <div class="pointer-events-auto flex items-start gap-2 rounded-card border bg-surface px-4 py-3 shadow-overlay"
                     :class="t.type === 'danger' ? 'border-danger-100' : (t.type === 'warning' ? 'border-warning-100' : 'border-success-100')">
                    <span :class="t.type === 'danger' ? 'text-danger-600' : (t.type === 'warning' ? 'text-warning-600' : 'text-success-600')">
                        <template x-if="t.type === 'danger' || t.type === 'warning'"><x-lucide name="warning" class="h-5 w-5" /></template>
                        <template x-if="t.type !== 'danger' && t.type !== 'warning'"><x-lucide name="check" class="h-5 w-5" /></template>
                    </span>
                    <p class="min-w-0 flex-1 text-body text-ink-900" x-text="t.message"></p>
                    {{-- "Deshacer" 10 s donde aplica (§13.5): dispara un evento Livewire que atiende la pantalla actual --}}
                    <template x-if="t.action">
                        <button type="button" class="shrink-0 text-body font-medium text-brand-700 hover:underline focus-visible:ring-2 focus-visible:ring-brand-500"
                                x-on:click="Livewire.dispatch(t.action.event, t.action.params ?? {}); dismiss(t.id)" x-text="t.action.label"></button>
                    </template>
                </div>
            </template>
        </div>
    </body>
</html>
