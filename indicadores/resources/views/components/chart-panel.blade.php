@props(['spec'])
{{-- Gráfica sin marco (§13.5): título, subtítulo y lienzo sobre la superficie; acciones discretas (datos, PNG). --}}
<section x-data="chartPanel(@js($spec['id']))" class="min-w-0 p-5" aria-labelledby="chart-{{ $spec['id'] }}-title">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h3 id="chart-{{ $spec['id'] }}-title" class="text-sub font-semibold text-ink-900">{{ $spec['title'] }}</h3>
            <p class="text-label text-ink-600">{{ $spec['subtitle'] }}</p>
        </div>
        @unless ($spec['empty'])
            <div class="flex shrink-0 items-center gap-1" role="group" aria-label="Acciones de la gráfica">
                <button type="button" x-on:click="showData = ! showData" x-bind:aria-pressed="showData"
                        class="inline-flex min-h-[36px] items-center gap-1.5 rounded-control px-2.5 text-label font-medium text-ink-600 transition-colors hover:bg-panel focus-visible:ring-2 focus-visible:ring-brand-500 aria-pressed:bg-brand-100 aria-pressed:text-brand-800">
                    <x-lucide name="table" class="h-4 w-4" />Datos
                </button>
                <button type="button" x-on:click="png()" x-bind:disabled="! ready"
                        class="inline-flex min-h-[36px] items-center gap-1.5 rounded-control px-2.5 text-label font-medium text-ink-600 transition-colors hover:bg-panel focus-visible:ring-2 focus-visible:ring-brand-500 disabled:cursor-not-allowed disabled:opacity-50">
                    <x-lucide name="image" class="h-4 w-4" />PNG
                </button>
                <button type="button" x-on:click="expand()" x-bind:disabled="! ready"
                        class="inline-flex min-h-[36px] items-center gap-1.5 rounded-control px-2.5 text-label font-medium text-ink-600 transition-colors hover:bg-panel focus-visible:ring-2 focus-visible:ring-brand-500 disabled:cursor-not-allowed disabled:opacity-50">
                    <x-lucide name="maximize" class="h-4 w-4" />Ampliar
                </button>
            </div>
        @endunless
    </div>

    @unless ($spec['empty'])
        {{-- Gráfica ampliada (§13.5): pantalla completa, Esc o clic fuera cierra; solo existe en el DOM mientras está abierta --}}
        <template x-if="expanded">
        <div x-on:keydown.escape.window="collapse()" class="fixed inset-0 z-50 flex items-center justify-center p-4 md:p-8" role="dialog" aria-modal="true" aria-label="{{ $spec['title'] }} ampliada">
            <div class="absolute inset-0 bg-ink-900/50" x-on:click="collapse()" aria-hidden="true"></div>
            <div class="relative flex h-full w-full max-w-[1400px] flex-col rounded-hero border border-line bg-surface p-5 shadow-overlay" x-trap.noscroll="expanded">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sub font-semibold text-ink-900">{{ $spec['title'] }}</h3>
                        <p class="text-label text-ink-600">{{ $spec['subtitle'] }}</p>
                    </div>
                    <button type="button" x-on:click="collapse()" class="rounded-control p-2 text-ink-600 hover:bg-panel" aria-label="Cerrar"><x-lucide name="x" /></button>
                </div>
                <div x-ref="big" wire:ignore class="mt-4 min-h-0 flex-1" role="img" aria-label="{{ $spec['title'] }}, {{ $spec['subtitle'] }}"></div>
            </div>
        </div>
        </template>
    @endunless

    @if ($spec['empty'])
        <div class="mt-4 flex h-[220px] items-center justify-center rounded-card border border-dashed border-line md:h-[280px]">
            <p class="text-label text-ink-400">{{ $spec['emptyText'] }}</p>
        </div>
    @else
        <div class="relative mt-4 h-[220px] md:h-[280px]">
            <div x-show="! ready" class="absolute inset-0 animate-pulse rounded-card bg-panel motion-reduce:animate-none" aria-hidden="true"></div>
            <div x-ref="canvas" wire:ignore class="h-full w-full" role="img" aria-label="{{ $spec['title'] }}, {{ $spec['subtitle'] }}"></div>
        </div>
        <div x-cloak x-show="showData" class="mt-3 overflow-x-auto rounded-card border border-line">
            <table class="w-full border-collapse text-label">
                <thead class="bg-panel text-ink-600">
                    <tr>
                        @foreach ($spec['table']['head'] as $i => $head)
                            <th class="whitespace-nowrap px-3 py-1.5 font-medium {{ $i === 0 ? 'text-left' : 'text-right' }}">{{ $head }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($spec['table']['rows'] as $row)
                        <tr>
                            @foreach ($row as $i => $cell)
                                <td class="whitespace-nowrap px-3 py-1.5 tnum {{ $i === 0 ? 'text-left text-ink-900' : 'text-right text-ink-600' }}">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
