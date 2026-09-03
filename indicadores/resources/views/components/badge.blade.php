@props(['tone' => 'neutral', 'icon' => null])
@php
    // Estado = icono + texto, nunca solo color (§13.4).
    $tones = [
        'neutral' => 'bg-panel text-ink-600 border-line',
        'brand' => 'bg-brand-100 text-brand-800 border-brand-100',
        'accent' => 'bg-accent-100 text-accent-600 border-accent-100',
        'success' => 'bg-success-100 text-success-600 border-success-100',
        'warning' => 'bg-warning-100 text-warning-600 border-warning-100',
        'danger' => 'bg-danger-100 text-danger-600 border-danger-100',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-label font-medium '.($tones[$tone] ?? $tones['neutral'])]) }}>
    @if ($icon)<x-lucide :name="$icon" class="h-3.5 w-3.5" />@endif
    {{ $slot }}
</span>
