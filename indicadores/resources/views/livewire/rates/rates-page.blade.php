<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-title text-brand-800">Tasa BCV</h1>
            <p class="text-ink-600">{{ $periodLabel }} · bolívares por dólar</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($status['automatic'])
                <x-btn variant="secondary" icon="refresh" wire:click="fetchNow" wire:loading.attr="disabled" wire:target="fetchNow">
                    <span wire:loading.remove wire:target="fetchNow">Consultar ahora</span>
                    <span wire:loading wire:target="fetchNow">Consultando…</span>
                </x-btn>
            @endif
            @if ($status['automatic'])
                <x-btn variant="secondary" icon="download" wire:click="$set('backfillDialog', true)">Traer histórico del BCV</x-btn>
            @endif
            @if ($branch !== null)
                <x-btn variant="secondary" icon="history" wire:click="$set('recalcDialog', true)">Recalcular el mes</x-btn>
            @endif
        </div>
    </div>

    {{-- Histórico oficial (§9.2): libros trimestrales del BCV; solo crea los días sin tasa --}}
    @if ($status['automatic'])
        <x-dialog show="$wire.backfillDialog" id="backfill-dialog" title="Traer el histórico del BCV">
            <p>Se descargan los libros trimestrales oficiales del BCV y se agregan las tasas de los días que no tienen ninguna. Las tasas escritas a mano y las ya publicadas no se tocan.</p>
            <x-field label="Desde" for="backfill-from" :error="$errors->first('backfillFrom')" help="Hasta hoy. Cada trimestre tarda unos segundos en descargarse.">
                <x-input id="backfill-from" type="date" wire:model="backfillFrom" min="2010-01-01" max="{{ now()->toDateString() }}" :invalid="$errors->has('backfillFrom')" class="max-w-xs" />
            </x-field>
            <x-slot:actions>
                <x-btn variant="ghost" x-on:click="$wire.backfillDialog = false">Cancelar</x-btn>
                <x-btn wire:click="backfill" wire:loading.attr="disabled" wire:target="backfill">
                    <span wire:loading.remove wire:target="backfill">Traer tasas</span>
                    <span wire:loading wire:target="backfill">Descargando…</span>
                </x-btn>
            </x-slot:actions>
        </x-dialog>
    @endif

    {{-- Estado del proveedor (§9.4) --}}
    <div class="flex flex-wrap items-start gap-3 rounded-card border border-line bg-surface px-5 py-4" role="status">
        <x-lucide name="rate" class="mt-0.5 h-5 w-5 shrink-0 {{ $status['error'] ? 'text-warning-600' : 'text-brand-600' }}" />
        <div class="min-w-0 flex-1">
            <p class="font-medium text-ink-900">{{ $status['headline'] }}</p>
            @if ($status['detail'])<p class="text-label text-ink-600">{{ $status['detail'] }}</p>@endif
            @if ($status['error'])<p class="text-label text-warning-600">{{ $status['error'] }}</p>@endif
            <p class="mt-1 text-label text-ink-400">Los fines de semana no hay publicación: se usa la última tasa (arrastrada). Una tasa escrita a mano nunca la pisa la automática.</p>
        </div>
        <dl class="grid grid-cols-3 gap-4 text-center">
            <div><dt class="text-label text-ink-600">Publicadas</dt><dd class="text-sub tnum font-semibold text-ink-900">{{ $published }}</dd></div>
            <div><dt class="text-label text-ink-600">Manuales</dt><dd class="text-sub tnum font-semibold text-ink-900">{{ $manual }}</dd></div>
            <div><dt class="text-label text-ink-600">Del mes</dt><dd class="text-sub tnum font-semibold text-ink-900">{{ $first === null ? '—' : $formatter->number($first, 2).' → '.$formatter->number($last, 2) }}</dd></div>
        </dl>
    </div>

    @if ($pending > 0)
        <div class="flex flex-wrap items-center gap-3 rounded-card border border-warning-100 bg-warning-100/60 px-5 py-3" role="status">
            <x-lucide name="warning" class="h-5 w-5 shrink-0 text-warning-600" />
            <p class="min-w-0 flex-1 text-body text-ink-900">{{ $pending === 1 ? '1 día cargado tiene una tasa distinta a la de esta tabla.' : "{$pending} días cargados tienen una tasa distinta a la de esta tabla." }} Los días conservan la tasa con la que se cargaron hasta que decidas recalcular.</p>
            <x-btn variant="secondary" wire:click="$set('recalcDialog', true)">Recalcular el mes</x-btn>
        </div>
    @endif

    <section class="rounded-card border border-line bg-surface" aria-label="Gráfica de la tasa">
        <x-chart-panel :spec="$specs['rate']" wire:key="rate-chart-{{ $period }}" />
    </section>

    <section class="overflow-x-auto rounded-card border border-line bg-surface" aria-label="Tasas del mes">
        <table class="w-full min-w-[720px] border-collapse text-body">
            <thead class="bg-panel text-label text-ink-600">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">Día</th>
                    <th class="px-4 py-2 text-right font-medium">Tasa (Bs por $)</th>
                    <th class="px-4 py-2 text-left font-medium">Origen</th>
                    <th class="px-4 py-2 text-right font-medium">Variación</th>
                    <th class="px-4 py-2 text-left font-medium">Fijada por</th>
                    <th class="px-4 py-2 text-right font-medium"><span class="sr-only">Editar</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($rows as $row)
                    @php $key = $row['date']->toDateString(); $isEditing = $editingDate === $key; @endphp
                    <tr wire:key="rate-{{ $key }}" class="{{ $row['source'] === \App\Enums\RateSource::Carried ? 'text-ink-400' : '' }} {{ $isEditing ? 'bg-brand-50' : ($loop->even ? 'bg-brand-50/40' : '') }}">
                        <td class="whitespace-nowrap px-4 py-2 tnum {{ $row['source'] === \App\Enums\RateSource::Carried ? '' : 'text-ink-900' }}">{{ $formatter->date($row['date'], 'weekday') }}</td>
                        <td class="px-4 py-2 text-right tnum">
                            @if ($isEditing)
                                <div class="flex items-center justify-end gap-2">
                                    <input type="text" inputmode="decimal" wire:model="editValue" wire:keydown.enter="saveRate" wire:keydown.escape="cancelEdit" x-init="$el.focus(); $el.select()"
                                           class="w-32 rounded-control border-line bg-surface px-2 py-1.5 text-right text-body tnum focus:border-brand-500 focus:ring-2 focus:ring-brand-500 {{ $errors->has('editValue') ? 'border-danger-600' : '' }}" aria-label="Tasa del {{ $formatter->date($row['date'], 'short') }}">
                                </div>
                                @error('editValue')<p class="mt-1 text-right text-label text-danger-600" role="alert">{{ $message }}</p>@enderror
                            @else
                                {{ $row['rate'] === null ? '—' : $formatter->number($row['rate'], 2) }}
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @if ($row['source'] === \App\Enums\RateSource::Bcv)
                                <x-badge tone="brand">BCV</x-badge>
                            @elseif ($row['source'] === \App\Enums\RateSource::Manual)
                                <x-badge tone="warning">Manual</x-badge>
                            @elseif ($row['source'] === \App\Enums\RateSource::Carried && ($row['stale'] ?? false))
                                <x-badge tone="danger" icon="warning" title="No hubo consultas al BCV desde esa fecha: consúltala o fíjala">Arrastrada del {{ $row['carriedFrom']?->format('d/m/Y') ?? '—' }}</x-badge>
                            @elseif ($row['source'] === \App\Enums\RateSource::Carried)
                                <x-badge tone="neutral" title="El BCV no publicó ese día">Arrastrada del {{ $row['carriedFrom'] ? $formatter->date($row['carriedFrom'], 'weekday') : '—' }}</x-badge>
                            @else
                                <x-badge tone="danger" icon="warning">Sin tasa</x-badge>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right tnum {{ $row['variation'] === null ? 'text-ink-400' : ($row['variation']->isNegative() ? 'text-danger-600' : 'text-ink-600') }}">{{ $row['variation'] === null ? '—' : $formatter->pct($row['variation']) }}</td>
                        <td class="px-4 py-2 text-ink-600">{{ $row['setter'] ?? ($row['source'] === \App\Enums\RateSource::Bcv ? 'BCV (automática)' : '—') }}</td>
                        <td class="px-4 py-2 text-right">
                            @if ($isEditing)
                                <div class="flex justify-end gap-1">
                                    <x-btn variant="ghost" wire:click="cancelEdit" class="min-h-[36px] px-2.5 text-label">Cancelar</x-btn>
                                    <x-btn wire:click="saveRate" wire:loading.attr="disabled" wire:target="saveRate" class="min-h-[36px] px-3 text-label">Guardar</x-btn>
                                </div>
                            @else
                                <x-btn variant="ghost" icon="pencil" wire:click="startEdit('{{ $key }}')" class="min-h-[36px] px-2.5 text-label">{{ $row['source'] === \App\Enums\RateSource::Carried || $row['source'] === null ? 'Fijar' : 'Editar' }}</x-btn>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-ink-400">Este mes aún no ha empezado.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    @if ($branch !== null)
        <x-dialog show="$wire.recalcDialog" id="recalc-dialog" :title="'Recalcular las tasas de '.mb_strtolower($periodLabel)">
            <p>Cada día cargado guarda la tasa con la que se cargó. Al recalcular, los días de <span class="font-medium text-ink-900">{{ $branch->name }}</span> toman la tasa de esta tabla y sus ventas en dólares cambian.</p>
            <p class="text-ink-900">{{ $pending === 0 ? 'Ningún día necesita cambios.' : ($pending === 1 ? 'Cambiaría 1 día.' : "Cambiarían {$pending} días.") }} Queda en la bitácora.</p>
            <x-slot:actions>
                <x-btn variant="ghost" x-on:click="$wire.recalcDialog = false">Cancelar</x-btn>
                <x-btn wire:click="recalculate" wire:loading.attr="disabled" wire:target="recalculate" x-bind:disabled="{{ $pending === 0 ? 'true' : 'false' }}">Recalcular</x-btn>
            </x-slot:actions>
        </x-dialog>
    @endif
</div>
