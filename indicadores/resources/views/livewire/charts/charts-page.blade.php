<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-title text-brand-800">Gráficas</h1>
            <p class="text-ink-600" data-tour="charts-subtitle">{{ $periodLabel }} · {{ $branch?->name ?? 'Todas las sedes' }}</p>
        </div>
    </div>

    {{-- Pestañas por familia (§13.7) --}}
    <div class="flex gap-1 overflow-x-auto border-b border-line" role="tablist" aria-label="Familias de gráficas">
        @foreach ($tabs as $key => $family)
            <button type="button" role="tab" id="tab-{{ $key }}" data-tour="charts-tab-{{ $key }}" wire:click="$set('tab', '{{ $key }}')"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}" aria-controls="panel-{{ $key }}"
                    class="-mb-px whitespace-nowrap border-b-2 px-4 py-2.5 text-body font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 {{ $tab === $key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-600 hover:text-ink-900' }}">
                {{ $family['label'] }}
            </button>
        @endforeach
    </div>

    @if ($tab === 'anio')
        {{-- G11: el indicador se elige aquí; el año es el del período de la barra de contexto --}}
        <x-field label="Indicador" for="annual-indicator" class="max-w-xs" data-tour="charts-annual-indicator" help="Cada barra es un mes; el año anterior en gris.">
            <select id="annual-indicator" wire:model.live="annualIndicator" class="block w-full rounded-control border-line bg-surface px-3 py-2.5 text-body focus:border-brand-500 focus:ring-2 focus:ring-brand-500">
                @foreach ($annualIndicators as $ind)
                    <option value="{{ $ind->value }}">{{ $ind->label() }}</option>
                @endforeach
            </select>
        </x-field>
    @endif

    {{-- Una sola gráfica en la pestaña (Inventario, Tasa, Año): ocupa todo el ancho --}}
    @php $columns = count($specs) === 1 ? '' : 'lg:grid-cols-2 lg:[&>section:nth-child(2n)]:border-l lg:[&>section:nth-child(n+3)]:border-t lg:[&>section:nth-child(2)]:border-t-0'; @endphp
    <div id="panel-{{ $tab }}" role="tabpanel" aria-labelledby="tab-{{ $tab }}" wire:loading.class="opacity-60" wire:target="tab"
         class="grid rounded-card border border-line bg-surface transition-opacity [&>section+section]:border-t [&>section+section]:border-line {{ $columns }}">
        @foreach ($specs as $id => $spec)
            <x-chart-panel :spec="$spec" wire:key="chart-{{ $tab }}-{{ $id }}" />
        @endforeach
    </div>
</div>
