@props(['variant' => 'primary', 'type' => 'button', 'href' => null, 'icon' => null])
@php
    // Estados default/hover/focus/active/disabled definidos para todo control (§13.4).
    $base = 'inline-flex items-center justify-center gap-2 rounded-control px-4 py-2.5 text-body font-medium transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 min-h-[44px]';
    $variants = [
        'primary' => 'bg-brand-600 text-white hover:bg-brand-700 active:bg-brand-800',
        'secondary' => 'bg-surface text-brand-800 border border-line hover:bg-panel active:bg-brand-100',
        'ghost' => 'text-brand-700 hover:bg-brand-100 active:bg-brand-100',
        'danger' => 'bg-surface text-danger-600 border border-danger-100 hover:bg-danger-100',
    ];
    $classes = $base.' '.($variants[$variant] ?? $variants['primary']);
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-lucide :name="$icon" class="h-4 w-4" />@endif{{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-lucide :name="$icon" class="h-4 w-4" />@endif{{ $slot }}
    </button>
@endif
