@props(['tone' => 'light', 'size' => 'md'])
@php
    // Wordmark: solo vive en el acceso y en el PDF (§13.4). Sentence case, sin versalitas (§13.2):
    // la jerarquía la hacen el tamaño y el peso, no las mayúsculas.
    $top = $tone === 'light' ? 'text-brand-100/70' : 'text-ink-600';
    $name = $tone === 'light' ? 'text-white' : 'text-brand-800';
    $scale = $size === 'lg' ? 'text-[27px] leading-[32px] sm:text-[32px] sm:leading-[36px]' : 'text-title';
@endphp
<span {{ $attributes->merge(['class' => 'block leading-tight']) }}>
    <span class="block text-[12px] font-medium tracking-[0.16em] {{ $top }}">Farmacia</span>
    <span class="block font-semibold tracking-[-0.015em] {{ $scale }} {{ $name }}">Guadalupe</span>
</span>
