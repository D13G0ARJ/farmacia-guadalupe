<div class="space-y-8" x-data="{ closeOpen: false, reopenOpen: false }" x-on:month-state-changed.window="closeOpen = false; reopenOpen = false">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-title text-brand-800">{{ $view->period->label() }}</h1>
            <p class="text-ink-600">{{ $branch?->name ?? 'Todas las sedes' }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 print:hidden">
            @if ($closedEvent)
                {{-- Estado = icono + texto (§13.4): "Cerrado el 05/10" --}}
                <x-badge tone="neutral" icon="lock" title="Cerrado por {{ $closedEvent->user?->name }}">Cerrado el {{ $closedEvent->created_at->format('d/m') }}</x-badge>
            @endif
            @if ($canClose)
                <x-btn variant="secondary" icon="lock" x-on:click="closeOpen = true">Cerrar {{ mb_strtolower($view->period->monthNameUpper()) }}</x-btn>
            @endif
            @if ($canReopen)
                <x-btn variant="secondary" icon="unlock" x-on:click="reopenOpen = true">Reabrir</x-btn>
            @endif
            <x-btn variant="secondary" icon="download" :href="route('exports.month', ['period' => $view->period->key()])">Exportar a Excel</x-btn>
            <x-btn variant="secondary" icon="printer" x-on:click="window.print()">Imprimir</x-btn>
        </div>
    </div>

    {{-- Modales de cierre y reapertura (UC-07): los únicos permitidos junto al borrado de día (§13.5) --}}
    @if ($canClose)
        <x-dialog show="closeOpen" id="close-month" :title="'Cerrar '.mb_strtolower($view->period->label())">
            @if ($view->missingDates !== [])
                <p class="text-ink-900">{{ count($view->missingDates) === 1 ? 'Falta 1 día por cargar' : 'Faltan '.count($view->missingDates).' días por cargar' }}: {{ implode(', ', array_map(fn ($d) => $d->day, array_slice($view->missingDates, 0, 10))) }}{{ count($view->missingDates) > 10 ? '…' : '' }}.</p>
                <label class="flex items-start gap-2">
                    <input type="checkbox" wire:model="confirmMissing" class="mt-1 h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500">
                    <span>Cerrar de todos modos, con esos días sin cargar.</span>
                </label>
            @else
                <p>Todos los días están cargados.</p>
            @endif
            <p>Cerrado el mes, nadie podrá editarlo sin reabrirlo. Quedará en el historial quién lo cerró y cuándo.</p>
            @error('close')<p class="text-label text-danger-600" role="alert">{{ $message }}</p>@enderror
            <x-slot:actions>
                <x-btn variant="ghost" x-on:click="closeOpen = false">Cancelar</x-btn>
                {{-- Con días faltantes, el botón espera la confirmación explícita (no se usan directivas dentro de atributos de componente) --}}
                <x-btn wire:click="closeMonth" wire:loading.attr="disabled" wire:target="closeMonth" x-bind:disabled="{{ $view->missingDates !== [] ? '! $wire.confirmMissing' : 'false' }}">Cerrar {{ mb_strtolower($view->period->monthNameUpper()) }}</x-btn>
            </x-slot:actions>
        </x-dialog>
    @endif
    @if ($canReopen)
        <x-dialog show="reopenOpen" id="reopen-month" :title="'Reabrir '.mb_strtolower($view->period->label())">
            <p>Mientras esté abierto, sus días se podrán volver a editar. Dirección recibirá un aviso.</p>
            <div>
                <label for="reopen-reason" class="block text-label font-medium text-ink-600">Motivo de la reapertura (obligatorio)</label>
                <textarea id="reopen-reason" wire:model="reopenReason" rows="2" maxlength="300" class="mt-1 block w-full rounded-control border-line bg-surface px-3 py-2 text-body focus:border-brand-500 focus:ring-2 focus:ring-brand-500 {{ $errors->has('reopenReason') ? 'border-danger-600' : '' }}" placeholder="Por ejemplo: se cargó mal el 15/09"></textarea>
                @error('reopenReason')<p class="mt-1 text-label text-danger-600" role="alert">{{ $message }}</p>@enderror
            </div>
            <x-slot:actions>
                <x-btn variant="ghost" x-on:click="reopenOpen = false">Cancelar</x-btn>
                <x-btn wire:click="reopenMonth" wire:loading.attr="disabled" wire:target="reopenMonth">Reabrir {{ mb_strtolower($view->period->monthNameUpper()) }}</x-btn>
            </x-slot:actions>
        </x-dialog>
    @endif

    @if ($view->loadedDays() === 0)
        {{-- Estado vacío (§13.5): una frase que dice qué falta y el botón que lo resuelve --}}
        <div class="rounded-card border border-line bg-surface px-6 py-14 text-center">
            <x-lucide name="cross" class="mx-auto h-12 w-12 text-brand-100" />
            <p class="mt-4 text-sub text-ink-900">Aún no hay días cargados en {{ strtolower($view->period->label()) }}.</p>
            @if ($canCreate && $view->firstMissingDate())
                <div class="mt-5"><x-btn :href="route('records.create', ['date' => $view->firstMissingDate()->toDateString()])" icon="plus">Cargar el primer día</x-btn></div>
            @endif
        </div>
    @else
        {{-- Calendario (UC-06) --}}
        @if ($branch !== null)
            <section class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sub font-semibold">Calendario</h2>
                    @if ($view->missingDates !== [])
                        <p class="flex flex-wrap items-center gap-2 text-ink-600">
                            <span>{{ count($view->missingDates) === 1 ? 'Falta 1 día' : 'Faltan '.count($view->missingDates).' días' }}: {{ implode(', ', array_map(fn ($d) => $d->day, array_slice($view->missingDates, 0, 8))) }}{{ count($view->missingDates) > 8 ? '…' : '' }}</span>
                            @if ($canCreate)
                                <a href="{{ route('records.create', ['date' => $view->firstMissingDate()->toDateString(), 'faltantes' => 1]) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ count($view->missingDates) === 1 ? 'Cargarlo' : 'Cargar los '.count($view->missingDates).' faltantes' }}</a>
                            @endif
                        </p>
                    @else
                        <p class="flex items-center gap-1 text-success-600"><x-lucide name="check" class="h-4 w-4" />Todos los días cargados</p>
                    @endif
                </div>
                {{-- Flechas (§13.8): mueven el foco entre los días del calendario --}}
                <div class="overflow-hidden rounded-card border border-line bg-surface"
                     x-data="{ move(step) { const links = [...$el.querySelectorAll('a[data-day]')]; const i = links.indexOf(document.activeElement); if (i < 0) return; links[Math.max(0, Math.min(links.length - 1, i + step))]?.focus() } }"
                     x-on:keydown.arrow-right.prevent="move(1)" x-on:keydown.arrow-left.prevent="move(-1)" x-on:keydown.arrow-down.prevent="move(7)" x-on:keydown.arrow-up.prevent="move(-7)">
                    <div class="grid grid-cols-7 border-b border-line bg-panel text-center text-label text-ink-600">
                        @foreach (['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'] as $d)
                            <div class="py-2">{{ $d }}</div>
                        @endforeach
                    </div>
                    @foreach ($weeks as $week)
                        <div class="grid grid-cols-7 divide-x divide-line border-b border-line last:border-b-0">
                            @foreach ($week as $date)
                                @if ($date === null)
                                    <div class="min-h-[64px] bg-brand-50/50"></div>
                                @else
                                    @php
                                        $status = $view->statusOf($date);
                                        $m = $view->byDate[$date->toDateString()] ?? null;
                                        $classes = match ($status) {
                                            'loaded' => 'bg-brand-100/70 hover:bg-brand-100',
                                            'atypical' => 'bg-accent-100 hover:bg-accent-100',
                                            'closed' => 'bg-panel text-ink-400',
                                            'missing' => 'bg-warning-100/60 border border-dashed border-warning-600/40 hover:bg-warning-100',
                                            default => 'bg-surface text-ink-400',
                                        };
                                    @endphp
                                    <a href="{{ route('records.create', ['date' => $date->toDateString()]) }}" wire:navigate
                                       @if ($status !== 'future') data-day="{{ $date->day }}" @else tabindex="-1" @endif
                                       class="block min-h-[64px] p-2 text-left transition-colors {{ $classes }} {{ $status === 'future' ? 'pointer-events-none' : '' }}"
                                       aria-label="{{ $formatter->date($date, 'long') }}: {{ match($status) { 'loaded' => 'cargado', 'atypical' => 'atípico', 'closed' => 'cerrado', 'missing' => 'falta cargar', default => 'futuro' } }}">
                                        <span class="flex items-center justify-between">
                                            <span class="text-label font-medium">{{ $date->day }}</span>
                                            @if ($status === 'atypical')<x-lucide name="warning" class="h-3.5 w-3.5 text-accent-600" />@endif
                                            @if ($status === 'closed')<x-lucide name="power" class="h-3.5 w-3.5" />@endif
                                        </span>
                                        @if ($m !== null && $m->salesUsd !== null && $status !== 'closed')
                                            <span class="mt-1 hidden text-label tnum text-ink-600 lg:block">{{ $formatter->money($m->salesUsd, \App\Enums\Currency::Usd, 0) }}</span>
                                        @endif
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @endforeach
                </div>
                <p class="flex flex-wrap gap-4 text-label text-ink-600">
                    <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-brand-100"></span>Cargado</span>
                    <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm border border-dashed border-warning-600/60 bg-warning-100"></span>Falta</span>
                    <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-accent-100"></span>Atípico</span>
                    <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-panel"></span>Cerrado</span>
                </p>
            </section>
        @endif

        {{-- Cuadro de indicadores (UC-09) --}}
        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sub font-semibold">Cuadro de indicadores</h2>
                <div class="flex flex-wrap items-center gap-4 print:hidden">
                    <label class="relative block">
                        <span class="sr-only">Buscar fecha</span>
                        <x-lucide name="search" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar fecha: 16, 16/09, mar" class="w-56 rounded-control border-line bg-surface py-1.5 pl-8 pr-3 text-label focus:border-brand-500 focus:ring-2 focus:ring-brand-500" aria-label="Buscar fecha en el cuadro">
                    </label>
                    <label class="flex items-center gap-2 text-label text-ink-600">
                        <input type="checkbox" wire:model.live="excludeAtypical" class="h-4 w-4 rounded border-line text-accent-600 focus:ring-accent-600">
                        Excluir días atípicos de los promedios
                    </label>
                </div>
            </div>
            <div class="overflow-x-auto rounded-card border border-line bg-surface">
                <table class="w-full min-w-[960px] border-collapse text-label">
                    <thead class="bg-panel text-ink-600">
                        <tr>
                            <th rowspan="2" scope="col" class="sticky left-0 z-10 bg-panel px-3 py-2 text-left font-medium" aria-sort="{{ $sort === 'date' ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                <button type="button" wire:click="sortBy('date')" class="inline-flex items-center gap-1 hover:text-ink-900">Fecha
                                    @if ($sort === 'date')<x-lucide :name="$dir === 'asc' ? 'arrow-up' : 'arrow-down'" class="h-3.5 w-3.5" />@endif
                                </button>
                            </th>
                            @foreach (collect($columns)->groupBy('group') as $group => $cols)
                                <th colspan="{{ $cols->count() }}" class="border-l border-line px-3 py-1.5 text-center font-medium">{{ $group }}</th>
                            @endforeach
                        </tr>
                        <tr>
                            @foreach ($columns as $col)
                                @php $key = $col['indicator']->value; @endphp
                                <th scope="col" class="whitespace-nowrap border-l border-line px-3 py-1.5 text-right font-medium" title="{{ $col['indicator']->explanation() }}" aria-sort="{{ $sort === $key ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                    <button type="button" wire:click="sortBy('{{ $key }}')" class="inline-flex items-center gap-1 hover:text-ink-900 {{ $sort === $key ? 'text-brand-700' : '' }}">
                                        {{ $col['indicator']->shortLabel() }}
                                        @if ($sort === $key)<x-lucide :name="$dir === 'asc' ? 'arrow-up' : 'arrow-down'" class="h-3.5 w-3.5" />@endif
                                    </button>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @if ($rows === [])
                            <tr><td colspan="{{ count($columns) + 1 }}" class="px-3 py-6 text-center text-ink-600">Ningún día coincide con "{{ $search }}".</td></tr>
                        @endif
                        @foreach ($rows as $m)
                            @php $status = $m->data->status; @endphp
                            <tr wire:key="row-{{ $m->data->date->toDateString() }}-{{ $m->data->branchId }}" class="{{ $loop->even ? 'bg-brand-50/40' : '' }} hover:bg-brand-100/40">
                                <td class="sticky left-0 z-10 whitespace-nowrap bg-inherit px-3 py-2">
                                    <a href="{{ route('records.create', ['date' => $m->data->date->toDateString()]) }}" wire:navigate class="flex items-center gap-2 font-medium text-brand-800 hover:underline">
                                        <span class="tnum">{{ $formatter->date($m->data->date, 'weekday') }}</span>
                                        @if ($status === \App\Enums\DayStatus::Atypical)<x-badge tone="accent" icon="warning">Atípico</x-badge>@endif
                                        @if ($status === \App\Enums\DayStatus::Closed)<x-badge tone="neutral" icon="power">Cerrado</x-badge>@endif
                                    </a>
                                </td>
                                @foreach ($columns as $col)
                                    @php
                                        $ind = $col['indicator'];
                                        $value = $m->value($ind);
                                        // Un día cerrado no vendió: se muestra en blanco, no como ceros.
                                        $text = $status === \App\Enums\DayStatus::Closed ? '—' : match ($ind->unit()) {
                                            \App\Domain\Indicators\Unit::Bs => $formatter->money($value, \App\Enums\Currency::Bs, $ind->precision()),
                                            \App\Domain\Indicators\Unit::Usd => $formatter->money($value, \App\Enums\Currency::Usd, $ind->precision()),
                                            default => $formatter->number($value, $ind->precision()),
                                        };
                                    @endphp
                                    <td class="whitespace-nowrap border-l border-line px-3 py-2 text-right tnum {{ $col['derived'] ? 'text-ink-600' : 'text-ink-900' }}">{{ $text }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="sticky bottom-0 border-t-2 border-line bg-surface font-semibold">
                        <tr>
                            <td class="sticky left-0 z-10 whitespace-nowrap bg-surface px-3 py-2.5">Total del mes <span class="font-normal text-ink-400">(ponderado{{ $view->summary->excludedAtypical > 0 ? ', sin '.$view->summary->excludedAtypical.' atípico' : '' }})</span></td>
                            @foreach ($columns as $col)
                                @php
                                    $ind = $col['indicator'];
                                    $value = $view->summary->value($ind);
                                    $text = match ($ind->unit()) {
                                        \App\Domain\Indicators\Unit::Bs => $formatter->money($value, \App\Enums\Currency::Bs, $ind->precision()),
                                        \App\Domain\Indicators\Unit::Usd => $formatter->money($value, \App\Enums\Currency::Usd, $ind->precision()),
                                        default => $formatter->number($value, $ind->precision()),
                                    };
                                @endphp
                                <td class="whitespace-nowrap border-l border-line px-3 py-2.5 text-right tnum">{{ $text }}</td>
                            @endforeach
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="text-label text-ink-400">
                {{ $view->summary->days }} días cargados · inventario contado {{ $view->summary->daysWithInventory }} días · tasa de {{ $formatter->number($view->summary->rateFirst, 2) }} a {{ $formatter->number($view->summary->rateLast, 2) }} ({{ $formatter->pct($view->summary->rateVariationPct) }})
            </p>
        </section>
    @endif

    {{-- Historial de cierre y reapertura (RN-13, RN-14): bitácora visible --}}
    @if ($events->isNotEmpty())
        <section class="space-y-3" aria-labelledby="history-title">
            <h2 id="history-title" class="text-sub font-semibold">Historial del mes</h2>
            <ul class="divide-y divide-line rounded-card border border-line bg-surface">
                @foreach ($events as $event)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-3" wire:key="event-{{ $event->id }}">
                        <x-lucide :name="$event->action === \App\Enums\PeriodAction::Closed ? 'lock' : 'unlock'" class="h-5 w-5 shrink-0 text-ink-600" />
                        <p class="min-w-0 flex-1 text-body text-ink-900">
                            <span class="font-medium">{{ $event->action === \App\Enums\PeriodAction::Closed ? 'Cerrado' : 'Reabierto' }}</span>
                            por {{ $event->user?->name ?? 'un usuario eliminado' }} el {{ $formatter->date($event->created_at, 'weekday_full') }} a las {{ $event->created_at->format('H:i') }}@if ($event->reason) · <span class="text-ink-600">{{ $event->reason }}</span>@endif
                        </p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
