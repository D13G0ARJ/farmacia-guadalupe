@props(['label', 'for', 'help' => null, 'reference' => null, 'error' => null, 'warning' => null])
{{-- Campo del formulario diario (§13.5): etiqueta con unidad, referencia "Ayer", advertencia en línea y error duro. --}}
<div {{ $attributes->merge(['class' => 'space-y-1.5']) }}>
    <label for="{{ $for }}" class="block text-label font-medium text-ink-600">{{ $label }}</label>
    {{ $slot }}
    @if ($error)
        <p class="text-label text-danger-600" role="alert">{{ $error }}</p>
    @elseif ($warning)
        <p class="flex items-start gap-1 text-label text-warning-600" role="status"><x-lucide name="warning" class="mt-0.5 h-3.5 w-3.5 shrink-0" />{{ $warning }}</p>
    @elseif ($reference)
        <p class="text-label text-ink-400">Ayer: <span class="tnum">{{ $reference }}</span></p>
    @elseif ($help)
        <p class="text-label text-ink-400">{{ $help }}</p>
    @endif
</div>
