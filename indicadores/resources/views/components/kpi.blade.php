@props(['label', 'value', 'secondary' => null, 'explanation' => null, 'hint' => null, 'delta' => null, 'yearDelta' => null, 'sparkline' => [], 'goal' => null, 'goalHref' => null])
@php
    // Tarjeta KPI silenciosa (§13.5): blanca, hairline, sin sombra; delta con flecha y color, sparkline a la derecha,
    // barra de meta de 4 px con la marca "esperado hoy" (§8.3).
    $formatter = app(\App\Domain\Shared\Formatter::class);
    $tones = ['success' => 'text-success-600', 'danger' => 'text-danger-600', 'neutral' => 'text-ink-600', 'warning' => 'text-warning-600'];
    $bars = ['success' => 'bg-success-600', 'danger' => 'bg-danger-600', 'warning' => 'bg-warning-600', 'neutral' => 'bg-ink-400'];
    $arrows = [1 => 'arrow-up-right', -1 => 'arrow-down-right', 0 => 'minus'];
    $hasGoal = $goal?->hasGoal() ?? false;
    $pctTarget = $hasGoal ? min(100, max(0, (float) ($goal->pctOfTarget()?->toFloat() ?? 0) * 100)) : 0;
    $expectedMark = $hasGoal && $goal->accumulates() ? min(100, max(0, (float) ($goal->expectedShare()?->toFloat() ?? 0) * 100)) : null;
@endphp
@php $popoverId = 'kpi-help-'.\Illuminate\Support\Str::slug($label); @endphp
<div {{ $attributes->merge(['class' => 'rounded-card border border-line bg-surface p-5']) }}>
    <div class="flex items-start justify-between gap-2">
        <p class="text-label text-ink-600">{{ $label }}</p>
        @if ($explanation)
            {{-- "¿Cómo se calcula?" (§13.5): popover en línea con la fórmula en palabras y el último día; nunca navega --}}
            <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                <button type="button" data-tour="kpi-help" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="{{ $popoverId }}" class="rounded-full p-0.5 text-ink-400 hover:text-brand-700 focus-visible:ring-2 focus-visible:ring-brand-500" aria-label="¿Cómo se calcula {{ mb_strtolower($label) }}?" title="¿Cómo se calcula?">
                    <x-lucide name="info" class="h-4 w-4" />
                </button>
                <div x-cloak x-show="open" x-on:click.outside="open = false" id="{{ $popoverId }}" role="note" class="absolute right-0 z-20 mt-1 w-64 rounded-card border border-line bg-surface p-3 text-left shadow-overlay">
                    <p class="text-label font-medium text-ink-900">¿Cómo se calcula?</p>
                    <p class="mt-1 text-label text-ink-600">{{ $explanation }}</p>
                    @if ($hint)
                        <p class="mt-2 text-label tnum text-ink-600">{{ $hint }}</p>
                    @endif
                </div>
            </div>
        @endif
    </div>
    <div class="mt-2 flex items-end justify-between gap-3">
        {{-- Cifras largas (venta en Bs) bajan un escalón para no partirse ni pisar la sparkline --}}
        <p class="min-w-0 whitespace-nowrap tnum text-ink-900 {{ mb_strlen($value) > 11 ? 'text-title' : 'text-kpi' }}">{{ $value }}</p>
        <x-sparkline :points="$sparkline" class="mb-1.5" />
    </div>
    @if ($secondary)
        <p class="mt-1 text-label tnum text-ink-400">{{ $secondary }}</p>
    @endif
    @if ($delta)
        @if ($delta->isAvailable())
            <p class="mt-2 flex flex-wrap items-center gap-x-1 text-label {{ $tones[$delta->tone()] }}">
                <x-lucide :name="$arrows[$delta->direction()]" class="h-3.5 w-3.5" />
                <span class="tnum font-medium">{{ $formatter->pct($delta->variation) }}</span>
                <span class="text-ink-400">vs {{ $delta->against }}{{ $delta->comparedDays ? ', primeros '.$delta->comparedDays.' días' : '' }}</span>
            </p>
            @if ($delta->usdVariation !== null)
                <p class="text-label text-ink-400">En dólares: <span class="tnum">{{ $formatter->pct($delta->usdVariation) }}</span> · el resto es tasa</p>
            @endif
        @else
            <p class="mt-2 text-label text-ink-400">Sin dato de {{ $delta->against }}</p>
        @endif
    @endif
    @if ($yearDelta?->isAvailable())
        <p class="text-label text-ink-400">vs {{ $yearDelta->against }}: <span class="tnum">{{ $formatter->pct($yearDelta->variation) }}</span></p>
    @endif

    @if ($hasGoal)
        @php $tone = $goal->status->tone(); @endphp
        <div class="mt-3">
            <div class="relative h-1 w-full rounded-full bg-panel" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($pctTarget) }}" aria-label="Avance de la meta: {{ $formatter->pct($goal->pctOfTarget(), 0, false) }}">
                <div class="h-1 rounded-full {{ $bars[$tone] }}" style="width: {{ number_format($pctTarget, 1, '.', '') }}%"></div>
                @if ($expectedMark !== null)
                    <span class="absolute -top-1 h-3 w-0.5 -translate-x-1/2 bg-ink-600" style="left: {{ number_format($expectedMark, 1, '.', '') }}%" title="Esperado a la fecha: {{ $goal->indicator->format($formatter, $goal->expected) }}"></span>
                @endif
            </div>
            <p class="mt-1.5 text-label {{ $tones[$tone] }}">
                @if ($goal->status === \App\Domain\Goals\GoalStatus::Pending)
                    Meta {{ $goal->indicator->format($formatter, $goal->target) }} · sin datos aún
                @elseif (! $goal->accumulates())
                    Meta {{ $goal->indicator->format($formatter, $goal->target) }} · vas al <span class="tnum">{{ $formatter->pct($goal->pctOfTarget(), 0, false) }}</span>
                @elseif ($goal->isComplete())
                    Cerró en <span class="tnum">{{ $formatter->pct($goal->pctOfTarget(), 0, false) }}</span> de la meta ({{ $goal->indicator->format($formatter, $goal->target) }})
                @else
                    Proyección: <span class="tnum">{{ $formatter->pct($goal->pctProjected(), 0, false) }}</span> ·
                    @if ($goal->gap !== null && $goal->gap->isPositive())
                        faltan {{ $goal->indicator->format($formatter, $goal->gap) }}
                    @else
                        supera la meta por {{ $goal->indicator->format($formatter, $goal->gap?->abs()) }}
                    @endif
                @endif
            </p>
        </div>
    @elseif ($goalHref)
        <p class="mt-3 text-label"><a href="{{ $goalHref }}" wire:navigate class="text-brand-700 hover:underline">Definir meta</a></p>
    @endif
</div>
