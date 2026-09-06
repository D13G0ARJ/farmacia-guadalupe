@props(['href' => null, 'icon', 'active' => false, 'soon' => false])
@if ($soon)
    <span class="flex items-center gap-3 rounded-control px-3 py-2 text-body text-ink-400" aria-disabled="true" title="Próximamente">
        <x-lucide :name="$icon" class="h-5 w-5" />{{ $slot }}
    </span>
@else
    <a href="{{ $href }}" wire:navigate {{ $attributes->only(['data-tour']) }}
       class="flex items-center gap-3 rounded-control px-3 py-2 text-body transition-colors {{ $active ? 'bg-brand-100 font-medium text-brand-700 border-l-2 border-brand-600 -ml-0.5' : 'text-brand-800 hover:bg-brand-100/60' }}"
       @if ($active) aria-current="page" @endif>
        <x-lucide :name="$icon" class="h-5 w-5" />{{ $slot }}
    </a>
@endif
