@props(['tone' => 'light', 'class' => 'h-8 w-8'])
@php
    // Cruz de Farmacia Guadalupe (§13.4): brazos en el azul de marca y el pie en el violeta del logotipo.
    // Es la única pieza que reproduce la identidad; el resto del sistema usa el icono de barra.
    $arms = $tone === 'light' ? '#FFFFFF' : '#1D6FE5';
    $foot = $tone === 'light' ? '#8E77E6' : '#6C4FD8';
@endphp
<svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" {{ $attributes->merge(['class' => $class]) }}>
    <rect x="2" y="11" width="28" height="10" rx="3" fill="{{ $arms }}" />
    <rect x="11" y="2" width="10" height="28" rx="3" fill="{{ $arms }}" />
    <path d="M11 20h10v7a3 3 0 0 1-3 3h-4a3 3 0 0 1-3-3z" fill="{{ $foot }}" />
</svg>
