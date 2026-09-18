<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div data-tour="annual-title">
            <h1 class="text-title text-brand-800">Año {{ $annual->year }}</h1>
            <p class="text-ink-600">{{ $branch?->name ?? 'Todas las sedes' }} · {{ $annual->monthsWithData() === 0 ? 'sin meses cargados' : ($annual->monthsWithData() === 1 ? '1 mes con datos' : $annual->monthsWithData().' meses con datos') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center rounded-control border border-line bg-surface" role="group" aria-label="Año" data-tour="annual-year-nav">
                <button type="button" wire:click="previousYear" class="p-2 text-ink-600 hover:bg-panel" aria-label="Año anterior"><x-lucide name="chevron-left" class="h-4 w-4" /></button>
                <select wire:model.live="year" class="border-0 bg-transparent py-1.5 pl-2 pr-8 text-body font-medium text-ink-900 focus:ring-0" aria-label="Elegir año">
                    @foreach ($annual->years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
                </select>
                <button type="button" wire:click="nextYear" class="p-2 text-ink-600 hover:bg-panel" aria-label="Año siguiente"><x-lucide name="chevron-right" class="h-4 w-4" /></button>
            </div>
            @if ($canExport && ! $annual->isEmpty())
                <x-btn variant="secondary" icon="download" data-tour="annual-export" :href="route('exports.annual', ['year' => $annual->year])">Exportar a Excel</x-btn>
            @endif
        </div>
    </div>

    @if ($annual->isEmpty())
        <div class="rounded-card border border-line bg-surface px-6 py-14 text-center" data-tour="annual-empty">
            <x-lucide name="cross" class="mx-auto h-12 w-12 text-brand-100" />
            <p class="mt-4 text-sub text-ink-900">Aún no hay meses cargados en {{ $annual->year }}.</p>
            <p class="mt-1 text-body text-ink-600">Carga los días del mes o importa los cuadros anteriores para ver el año completo.</p>
        </div>
    @else
        {{-- Tabla anual (§2.4): la del Excel, con ratios ponderados y variaciones al pasar el cursor --}}
        <div class="overflow-x-auto rounded-card border border-line bg-surface" data-tour="annual-table">
            <table class="w-full min-w-[1080px] border-collapse text-label">
                <thead class="bg-panel text-ink-600">
                    <tr>
                        <th class="sticky left-0 z-10 bg-panel px-3 py-2 text-left font-medium">Indicador</th>
                        @foreach ($months as $i => $m)
                            @php $n = $i + 1; $future = \Carbon\CarbonImmutable::create($annual->year, $n, 1)->gt($today); @endphp
                            <th class="whitespace-nowrap border-l border-line px-3 py-2 text-right font-medium {{ $annual->hasData($n) ? '' : 'text-ink-400' }}" title="{{ $annual->hasData($n) ? $annual->months[$n]->days.' días cargados' : ($future ? 'Aún no ocurre' : 'Sin datos') }}">{{ $m }}</th>
                        @endforeach
                        <th class="whitespace-nowrap border-l-2 border-line px-3 py-2 text-right font-semibold text-ink-900" data-tour="annual-year-column">Año</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($rows as $ind)
                        <tr wire:key="annual-{{ $ind->value }}" class="{{ $loop->even ? 'bg-brand-50/40' : '' }} hover:bg-brand-100/40">
                            <td class="sticky left-0 z-10 whitespace-nowrap bg-inherit px-3 py-2 font-medium text-ink-900" title="{{ $ind->explanation() }}">{{ $ind->label() }}</td>
                            @foreach ($months as $i => $m)
                                @php
                                    $n = $i + 1;
                                    $value = $annual->value($ind, $n);
                                    $vsPrev = $annual->vsPreviousMonth($ind, $n);
                                    $vsYear = $annual->vsLastYear($ind, $n);
                                    $text = match ($ind->unit()) {
                                        \App\Domain\Indicators\Unit::Bs => $formatter->money($value, \App\Enums\Currency::Bs, 0),
                                        \App\Domain\Indicators\Unit::Usd => $formatter->money($value, \App\Enums\Currency::Usd, $ind->precision()),
                                        default => $formatter->number($value, $ind->precision()),
                                    };
                                    $hint = $value === null ? '' : 'vs mes anterior: '.($vsPrev === null ? '—' : $formatter->pct($vsPrev)).' · vs '.($annual->year - 1).': '.($vsYear === null ? '—' : $formatter->pct($vsYear));
                                @endphp
                                <td class="whitespace-nowrap border-l border-line px-3 py-2 text-right tnum {{ $value === null ? 'bg-panel/60 text-ink-400' : 'text-ink-900' }}" @if ($hint) title="{{ $hint }}" @endif>{{ $value === null ? '—' : $text }}</td>
                            @endforeach
                            @php $yearValue = $annual->yearValue($ind); @endphp
                            <td class="whitespace-nowrap border-l-2 border-line px-3 py-2 text-right tnum font-semibold text-ink-900">{{ $yearValue === null ? '—' : match ($ind->unit()) {
                                \App\Domain\Indicators\Unit::Bs => $formatter->money($yearValue, \App\Enums\Currency::Bs, 0),
                                \App\Domain\Indicators\Unit::Usd => $formatter->money($yearValue, \App\Enums\Currency::Usd, $ind->precision()),
                                default => $formatter->number($yearValue, $ind->precision()),
                            } }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-label text-ink-400" data-tour="annual-footnote">Los meses se agregan como en el cuadro del mes: sumas para ventas, transacciones, unidades y jornadas; promedios ponderados para tickets, unidades por compra, transacciones por jornada y tasa; promedio de los días con conteo para el inventario. La columna Año pondera todos los días del año. Pasa el cursor por una celda para ver la variación frente al mes anterior y frente al año pasado.</p>
    @endif
</div>
