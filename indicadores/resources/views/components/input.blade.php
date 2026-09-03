@props(['numeric' => false, 'suffix' => null, 'invalid' => false])
@php
    $classes = 'block w-full rounded-control border bg-surface px-3 py-2.5 text-body text-ink-900 placeholder:text-ink-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500 focus:ring-offset-0 disabled:bg-panel disabled:text-ink-400 min-h-[44px] '
        .($invalid ? 'border-danger-600' : 'border-line')
        .($numeric ? ' tnum text-right' : '')
        .($suffix ? ' pr-12' : '');
@endphp
<div class="relative">
    <input {{ $attributes->merge(['class' => $classes]) }} @if ($numeric) inputmode="decimal" autocomplete="off" x-on:focus="$event.target.select()" @endif>
    @if ($suffix)
        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-label text-ink-400">{{ $suffix }}</span>
    @endif
</div>
