<div class="flex flex-wrap items-center gap-2">
    {{-- Período: anterior · selector · siguiente (§13.3) --}}
    <div class="flex items-center rounded-control border border-line bg-surface">
        <button type="button" wire:click="previousPeriod" class="rounded-l-control p-2.5 text-ink-600 hover:bg-panel focus-visible:ring-2 focus-visible:ring-brand-500" aria-label="Mes anterior">
            <x-lucide name="chevron-left" class="h-4 w-4" />
        </button>
        <label class="sr-only" for="context-period">Período</label>
        <select id="context-period" wire:model.live="period" class="border-0 bg-transparent py-2 pl-1 pr-8 text-body font-medium text-ink-900 focus:ring-0">
            @foreach ($options as $option)
                <option value="{{ $option['key'] }}">{{ $option['label'] }}</option>
            @endforeach
        </select>
        <button type="button" wire:click="nextPeriod" class="rounded-r-control p-2.5 text-ink-600 hover:bg-panel focus-visible:ring-2 focus-visible:ring-brand-500" aria-label="Mes siguiente">
            <x-lucide name="chevron-right" class="h-4 w-4" />
        </button>
    </div>

    {{-- Sede: oculta con una sola (RN-22) --}}
    @if ($showBranches)
        <div>
            <label class="sr-only" for="context-branch">Sede</label>
            <select id="context-branch" wire:model.live="branch" class="rounded-control border-line bg-surface py-2 pl-3 pr-8 text-body text-ink-900 focus:border-brand-500 focus:ring-brand-500">
                @if ($canConsolidate)
                    <option value="all">Todas las sedes</option>
                @endif
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
    @endif

    {{-- Moneda: control segmentado --}}
    <div class="hidden items-center rounded-control border border-line bg-surface p-0.5 sm:flex" role="group" aria-label="Moneda">
        @foreach (['BS' => 'Bs', 'USD' => '$', 'BOTH' => 'Bs y $'] as $value => $label)
            <button type="button" wire:click="$set('currency', '{{ $value }}')"
                    class="rounded-[4px] px-3 py-1.5 text-label font-medium transition-colors {{ $currency === $value ? 'bg-brand-600 text-white' : 'text-ink-600 hover:bg-panel' }}"
                    @if ($currency === $value) aria-pressed="true" @endif>{{ $label }}</button>
        @endforeach
    </div>
</div>
