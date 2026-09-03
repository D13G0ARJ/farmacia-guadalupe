@props(['points' => [], 'label' => 'Últimos 14 días'])
@php
    // Sparkline de 60 × 20 px en SVG generado en Blade, sin JavaScript (§13.5, §14.1).
    $values = array_values(array_filter($points, fn ($v) => $v !== null));
    $n = count($values);
    $coords = [];
    if ($n >= 2) {
        $min = min($values);
        $max = max($values);
        $span = $max - $min;
        foreach ($values as $i => $v) {
            $x = 1 + ($i / ($n - 1)) * 58;
            $y = $span > 0 ? 19 - (($v - $min) / $span) * 18 : 10;
            $coords[] = number_format($x, 2, '.', '').','.number_format($y, 2, '.', '');
        }
    }
@endphp
@if ($coords !== [])
    <svg viewBox="0 0 60 20" width="60" height="20" {{ $attributes->merge(['class' => 'shrink-0 overflow-visible text-ink-400']) }} role="img" aria-label="{{ $label }}">
        <polyline fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" points="{{ implode(' ', $coords) }}" />
        @php [$lx, $ly] = explode(',', end($coords)); @endphp
        <circle cx="{{ $lx }}" cy="{{ $ly }}" r="2" class="fill-brand-600" />
    </svg>
@endif
