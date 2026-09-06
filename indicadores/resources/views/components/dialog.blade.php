@props(['show', 'title', 'maxWidth' => 'max-w-md'])
{{--
    Modal (§13.5): solo para cerrar/reabrir mes, borrar día y confirmar importación. Radio 12, la única sombra
    del sistema, título en sentence case, botón primario a la derecha con el mismo verbo de la acción, Esc cierra.
    `show` es una expresión Alpine (el padre lleva el estado).
--}}
<div x-cloak x-show="{{ $show }}" x-on:keydown.escape.window="{{ $show }} = false" class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="{{ $attributes->get('id', 'dialog') }}-title">
    <div class="absolute inset-0 bg-ink-900/40" x-on:click="{{ $show }} = false" aria-hidden="true"></div>
    <div class="relative w-full {{ $maxWidth }} rounded-hero border border-line bg-surface p-6 shadow-overlay" {{ $attributes->only(['data-tour']) }}
         x-show="{{ $show }}" x-transition.opacity.duration.150ms x-trap.noscroll="{{ $show }}">
        <h2 id="{{ $attributes->get('id', 'dialog') }}-title" class="text-sub font-semibold text-ink-900">{{ $title }}</h2>
        <div class="mt-3 space-y-3 text-body text-ink-600">{{ $slot }}</div>
        @isset($actions)
            <div class="mt-6 flex flex-wrap justify-end gap-2">{{ $actions }}</div>
        @endisset
    </div>
</div>
