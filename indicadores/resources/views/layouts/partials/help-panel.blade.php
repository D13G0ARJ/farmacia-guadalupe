@php
    $routeName = request()->route()?->getName();
    $help = \App\Support\HelpContent::for($routeName);
    $formulas = \App\Support\HelpContent::formulas();
    $glossary = \App\Support\HelpContent::glossary();
    $shortcuts = \App\Support\HelpContent::shortcuts();
    // Recorridos guiados (§13.5): pasos de esta pantalla y del recorrido general, ya filtrados por rol.
    $tourSteps = \App\Support\GuidedTours::for($routeName, auth()->user());
    $tourCatalog = \App\Support\GuidedTours::catalog(auth()->user());
    $tourData = json_encode([
        'route' => $routeName,
        'userId' => auth()->id(),
        'steps' => $tourSteps,
        'shell' => \App\Support\GuidedTours::shellTour(auth()->user()),
        'catalog' => $tourCatalog,
        'cleanup' => \App\Support\GuidedTours::cleanup($routeName),
        'sampleUrl' => route('tours.sample'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
@endphp
{{-- Datos de los recorridos: siempre en el DOM (fuera del panel plegable) para que tour.js los lea al cargar y al navegar --}}
<script type="application/json" id="guided-tour-data">{!! $tourData !!}</script>
{{-- Panel lateral de ayuda (§13.5): la ayuda de esta pantalla, las fórmulas, el glosario y los atajos. Nunca navega. --}}
{{-- Solo existe en el DOM mientras está abierto (x-if): no duplica textos para lectores de pantalla ni para "buscar en la página" --}}
<template x-if="help">
<div class="fixed inset-0 z-40 print:hidden" role="dialog" aria-modal="true" aria-labelledby="help-title" x-on:keydown.escape.window="help = false">
    <div class="absolute inset-0 bg-ink-900/30" x-on:click="help = false" aria-hidden="true"></div>
    <aside class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-surface shadow-overlay" x-trap.noscroll="help">
        <div class="flex items-center justify-between border-b border-line px-5 py-4">
            <h2 id="help-title" class="text-sub font-semibold text-ink-900">Ayuda · {{ $help['title'] }}</h2>
            <button type="button" x-on:click="help = false" class="rounded-control p-2 text-ink-600 hover:bg-panel" aria-label="Cerrar ayuda"><x-lucide name="x" /></button>
        </div>
        <div class="flex-1 space-y-6 overflow-y-auto px-5 py-5 text-body text-ink-900">
            <section>
                <p>{{ $help['intro'] }}</p>
                <ul class="mt-3 list-disc space-y-1.5 pl-5 text-ink-600">
                    @foreach ($help['items'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            </section>

            {{-- Recorridos guiados (§13.5): el sistema se explica solo, botón por botón --}}
            <section class="rounded-card border border-brand-100 bg-brand-100/40 p-4" x-data="{ seen: window.guidedTour ? window.guidedTour.seenRoutes() : [] }" x-on:tour-seen.window="seen = window.guidedTour.seenRoutes()">
                <h3 class="font-medium text-ink-900">Recorridos guiados</h3>
                <p class="mt-1 text-label text-ink-600">Un recorrido resalta cada botón y explica para qué sirve. Avanza con "Siguiente" o las flechas; sal con Esc.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @if ($tourSteps !== [])
                        <x-btn icon="help" class="min-h-[36px] px-3 text-label" x-on:click="help = false; setTimeout(() => window.guidedTour.startScreen(), 200)">Ver el recorrido de esta pantalla</x-btn>
                    @endif
                    <x-btn variant="secondary" class="min-h-[36px] px-3 text-label" x-on:click="help = false; setTimeout(() => window.guidedTour.startShell(), 200)">Cómo moverte por el sistema</x-btn>
                </div>
                <ul class="mt-3 divide-y divide-line rounded-card border border-line bg-surface" aria-label="Todos los recorridos">
                    @foreach ($tourCatalog as $tour)
                        <li class="flex items-center gap-3 px-3 py-2">
                            <a href="{{ route($tour['route']).'?recorrido=1' }}" class="min-w-0 flex-1 text-brand-700 hover:underline" title="{{ $tour['summary'] }}">{{ $tour['label'] }}</a>
                            <span class="text-label text-ink-400" x-show="seen.includes(@js($tour['route']))">visto</span>
                        </li>
                    @endforeach
                </ul>
            </section>

            <details class="group rounded-card border border-line">
                <summary class="cursor-pointer list-none px-4 py-3 font-medium text-ink-900">¿Cómo se calcula cada indicador?</summary>
                <dl class="divide-y divide-line border-t border-line">
                    @foreach ($formulas as $f)
                        <div class="px-4 py-2.5">
                            <dt class="font-medium">{{ $f['label'] }}</dt>
                            <dd class="text-label text-ink-600">{{ $f['explanation'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </details>

            <details class="group rounded-card border border-line">
                <summary class="cursor-pointer list-none px-4 py-3 font-medium text-ink-900">Glosario</summary>
                <dl class="divide-y divide-line border-t border-line">
                    @foreach ($glossary as $g)
                        <div class="px-4 py-2.5">
                            <dt class="font-medium">{{ $g['term'] }}</dt>
                            <dd class="text-label text-ink-600">{{ $g['definition'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </details>

            <details class="group rounded-card border border-line">
                <summary class="cursor-pointer list-none px-4 py-3 font-medium text-ink-900">Atajos de teclado</summary>
                <dl class="divide-y divide-line border-t border-line">
                    @foreach ($shortcuts as $s)
                        <div class="flex items-center justify-between gap-3 px-4 py-2">
                            <dd class="text-ink-600">{{ $s['action'] }}</dd>
                            <dt><kbd class="rounded border border-line bg-panel px-1.5 py-0.5 text-label tnum text-ink-900">{{ $s['keys'] }}</kbd></dt>
                        </div>
                    @endforeach
                </dl>
            </details>
        </div>
    </aside>
</div>
</template>
