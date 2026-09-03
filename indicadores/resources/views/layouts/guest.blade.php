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
    {{-- Acceso (UC-01, §13.4): la marca solo aquí y en el PDF; la cruz azul y el nombre en el wordmark. --}}
    <body class="h-full bg-brand-50 font-sans text-body text-ink-900 antialiased">
        <div class="flex min-h-full flex-col items-center justify-center px-4 py-10">
            <a href="/" class="flex items-center gap-3 text-brand-800" aria-label="Farmacia Guadalupe, indicadores">
                <span class="flex h-12 w-12 items-center justify-center rounded-hero bg-brand-600 text-white"><x-lucide name="cross" class="h-7 w-7" /></span>
                <span class="leading-tight">
                    <span class="block text-title">Farmacia Guadalupe</span>
                    <span class="block text-label text-ink-600">Indicadores del mes</span>
                </span>
            </a>

            <main class="mt-8 w-full max-w-md rounded-hero border border-line bg-surface p-6 sm:p-8">
                {{ $slot }}
            </main>

            <p class="mt-6 text-label text-ink-400">Acceso solo para el personal autorizado.</p>
        </div>
    </body>
</html>
