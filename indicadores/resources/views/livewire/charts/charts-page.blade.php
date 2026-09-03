<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-title text-brand-800">Gráficas</h1>
            <p class="text-ink-600">{{ $periodLabel }} · {{ $branch?->name ?? 'Todas las sedes' }}</p>
        </div>
    </div>

    {{-- Pestañas por familia (§13.7). Las familias futuras se ven, pero no se pueden abrir. --}}
    <div class="flex gap-1 overflow-x-auto border-b border-line" role="tablist" aria-label="Familias de gráficas">
        @foreach ($tabs as $key => $family)
            <button type="button" role="tab" id="tab-{{ $key }}" wire:click="$set('tab', '{{ $key }}')"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}" aria-controls="panel-{{ $key }}"
                    class="-mb-px whitespace-nowrap border-b-2 px-4 py-2.5 text-body font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 {{ $tab === $key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-600 hover:text-ink-900' }}">
                {{ $family['label'] }}
            </button>
        @endforeach
        @foreach ($soon as $key => $label)
            <span class="-mb-px whitespace-nowrap border-b-2 border-transparent px-4 py-2.5 text-body text-ink-400" aria-disabled="true" title="Próximamente">{{ $label }}</span>
        @endforeach
    </div>

    <div id="panel-{{ $tab }}" role="tabpanel" aria-labelledby="tab-{{ $tab }}" wire:loading.class="opacity-60" wire:target="tab"
         class="grid rounded-card border border-line bg-surface transition-opacity lg:grid-cols-2 [&>section+section]:border-t [&>section+section]:border-line lg:[&>section:nth-child(2n)]:border-l lg:[&>section:nth-child(n+3)]:border-t lg:[&>section:nth-child(2)]:border-t-0">
        @foreach ($specs as $id => $spec)
            <x-chart-panel :spec="$spec" wire:key="chart-{{ $tab }}-{{ $id }}" />
        @endforeach
    </div>
</div>
