@php
    // Borrador por fecha en el navegador (§13.8): clave por sede y fecha; el día recién guardado limpia el suyo.
    $savedDate = session()->pull('saved_date'); $draftClear = $savedDate ? 'draft:'.$branchId.':'.$savedDate : '';
@endphp
<div class="space-y-6"
     x-data="dailyPreview($wire, { branch: {{ $branchId }}, edit: {{ $isEdit ? 'true' : 'false' }}, clear: '{{ $draftClear }}' })">

    {{-- Título: la fecha con su día de la semana, derivado (RN-02) --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div data-tour="form-heading">
            <p class="text-label text-ink-600">{{ $isEdit ? 'Editar día' : 'Cargar día' }}</p>
            <h1 class="text-title text-brand-800">{{ ucfirst($weekdayLabel) }}</h1>
        </div>
        <a href="{{ route('month', ['period' => $period->key()]) }}" wire:navigate data-tour="form-see-month" class="text-body text-brand-700 hover:underline">Ver el mes</a>
    </div>

    {{-- Carga en secuencia de los días atrasados (§13.8): posición, anterior y siguiente --}}
    @if ($sequenceInfo !== null)
        <div class="flex flex-wrap items-center gap-3 rounded-card border border-brand-100 bg-brand-100/60 px-4 py-3" role="status" data-tour="form-sequence">
            <x-lucide name="list" class="h-5 w-5 text-brand-700" />
            <p class="flex-1 text-ink-900">
                @if ($sequenceInfo['total'] === 0)
                    No quedan días por cargar en {{ mb_strtolower($period->label()) }}.
                @elseif ($sequenceInfo['position'] !== null)
                    <span class="tnum font-medium">Faltante {{ $sequenceInfo['position'] }} de {{ $sequenceInfo['total'] }}</span> en {{ mb_strtolower($period->label()) }}.
                @else
                    {{ $sequenceInfo['total'] === 1 ? 'Queda 1 día por cargar' : 'Quedan '.$sequenceInfo['total'].' días por cargar' }} en {{ mb_strtolower($period->label()) }}.
                @endif
            </p>
            <div class="flex items-center gap-1">
                @if ($sequenceInfo['previous'])
                    <x-btn variant="ghost" :href="route('records.create', ['date' => $sequenceInfo['previous'], 'faltantes' => 1])" icon="chevron-left" class="min-h-[36px] px-2.5 text-label">Anterior</x-btn>
                @endif
                @if ($sequenceInfo['next'])
                    <x-btn variant="ghost" :href="route('records.create', ['date' => $sequenceInfo['next'], 'faltantes' => 1])" class="min-h-[36px] px-2.5 text-label">Siguiente <x-lucide name="chevron-right" class="h-4 w-4" /></x-btn>
                @endif
                <x-btn variant="ghost" :href="route('records.create', ['date' => $form->date])" class="min-h-[36px] px-2.5 text-label">Salir</x-btn>
            </div>
        </div>
    @endif

    {{-- Borrador recuperado (§13.8): lo escrito antes de cerrar la pestaña o perder la sesión --}}
    <div x-cloak x-show="draftRestored" class="flex flex-wrap items-center gap-3 rounded-card border border-line bg-panel px-4 py-3" role="status" data-tour="form-draft">
        <x-lucide name="history" class="h-5 w-5 text-ink-600" />
        <p class="flex-1 text-ink-900">Recuperamos lo que escribiste para este día<span x-text="draftSavedAt ? ' (' + draftSavedAt + ')' : ''"></span>. Revísalo antes de guardar.</p>
        <x-btn variant="ghost" class="min-h-[36px] px-2.5 text-label" x-on:click="discardDraft()">Descartar</x-btn>
    </div>

    @if ($periodClosed)
        <div class="flex items-start gap-3 rounded-card border border-line bg-panel p-4" role="status" data-tour="form-locked">
            <x-lucide name="lock" class="mt-0.5 h-5 w-5 text-ink-600" />
            <div>
                <p class="font-medium">{{ $period->label() }} está cerrado{{ $closedSince ? ' desde el '.$closedSince : '' }}.</p>
                <p class="text-ink-600">Nadie puede editarlo sin reabrirlo. <a href="{{ route('month', ['period' => $period->key()]) }}" wire:navigate class="text-brand-700 hover:underline">{{ auth()->user()->can('reopen', \App\Models\Branch::query()->findOrFail($branchId)) ? 'Reabrir desde el mes' : 'Pedir reapertura a dirección' }}</a></p>
            </div>
        </div>
    @elseif ($readOnly)
        <div class="flex items-start gap-3 rounded-card border border-line bg-panel p-4" role="status" data-tour="form-locked">
            <x-lucide name="info" class="mt-0.5 h-5 w-5 text-ink-600" />
            <div>
                <p class="font-medium">Este día es de solo lectura.</p>
                <p class="text-ink-600">{{ $readOnlyReason }}</p>
            </div>
        </div>
    @endif

    @if ($error)
        <div class="flex items-start gap-3 rounded-card border border-danger-100 bg-danger-100/60 p-4 text-danger-600" role="alert">
            <x-lucide name="warning" class="mt-0.5 h-5 w-5 shrink-0" />
            <p>{{ $error }}</p>
        </div>
    @endif

    {{-- Inerte (mes cerrado o solo lectura) se ve atenuado: el estado no puede depender solo del aviso --}}
    <form wire:submit="save" class="grid gap-6 pb-16 lg:grid-cols-[minmax(0,1fr)_320px] lg:pb-0 [&[inert]]:opacity-60" @if ($periodClosed || $readOnly) inert @endif>
        <div class="space-y-8">
            {{-- Fecha --}}
            <section class="space-y-4">
                <x-field label="Fecha" for="date" :error="$errors->first('form.date')" class="max-w-xs" data-tour="form-date">
                    <x-input id="date" type="date" wire:model.live="form.date" max="{{ now()->toDateString() }}" :invalid="$errors->has('form.date')" />
                </x-field>
            </section>

            {{-- Grupo 1: Ventas del día --}}
            <section class="space-y-4">
                <h2 class="text-sub font-semibold text-ink-900">Ventas del día</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-field label="Venta del día (Bs)" for="sales_bs" data-tour="form-sales" :error="$errors->first('form.sales_bs')" :warning="collect($warnings)->firstWhere('field', 'sales_bs')['message'] ?? null" :reference="$reference['sales_bs'] ?? null">
                        <x-input id="sales_bs" numeric suffix="Bs" wire:model.live.debounce.500ms="form.sales_bs" x-on:input="sales = $event.target.value" x-on:blur="format($event, 'sales', 2)" placeholder="0,00" :invalid="$errors->has('form.sales_bs')" autofocus />
                    </x-field>
                    <x-field label="Tasa BCV (Bs por $)" for="rate" data-tour="form-rate" :error="$errors->first('form.rate')" :warning="collect($warnings)->firstWhere('field', 'rate')['message'] ?? null" :reference="$reference['rate'] ?? null">
                        <div class="space-y-1.5">
                            <x-input id="rate" numeric wire:model.live.debounce.500ms="form.rate" x-on:input="rate = $event.target.value" x-on:blur="format($event, 'rate', 2)" placeholder="0,00" :invalid="$errors->has('form.rate')" />
                            @if ($rateLabel)
                                <x-badge :tone="$rateTone" :icon="$rateTone === 'danger' ? 'warning' : null" title="El BCV no publica fines de semana; se usa la última tasa publicada">{{ $rateLabel }}</x-badge>
                            @endif
                        </div>
                    </x-field>
                </div>
            </section>

            {{-- Grupo 2: Operación --}}
            <section class="space-y-4">
                <h2 class="text-sub font-semibold text-ink-900">Operación</h2>
                <div class="grid gap-5 sm:grid-cols-3">
                    <x-field label="Transacciones" for="transactions" data-tour="form-transactions" :error="$errors->first('form.transactions')" :warning="collect($warnings)->firstWhere('field', 'transactions')['message'] ?? null" :reference="$reference['transactions'] ?? null">
                        <x-input id="transactions" numeric inputmode="numeric" wire:model.live.debounce.500ms="form.transactions" x-on:input="transactions = $event.target.value" placeholder="0" :invalid="$errors->has('form.transactions')" />
                    </x-field>
                    <x-field label="Unidades vendidas" for="units" data-tour="form-units" :error="$errors->first('form.units')" :warning="collect($warnings)->firstWhere('field', 'units')['message'] ?? null" :reference="$reference['units'] ?? null">
                        <x-input id="units" numeric inputmode="numeric" wire:model.live.debounce.500ms="form.units" x-on:input="units = $event.target.value" placeholder="0" :invalid="$errors->has('form.units')" />
                    </x-field>
                    <x-field label="Jornadas (turnos)" for="shifts" data-tour="form-shifts" :error="$errors->first('form.shifts')" :reference="$reference['shifts'] ?? null">
                        <x-input id="shifts" numeric inputmode="numeric" wire:model.live.debounce.500ms="form.shifts" x-on:input="shifts = $event.target.value" :invalid="$errors->has('form.shifts')" />
                    </x-field>
                </div>
            </section>

            {{-- Grupo 3: Inventario, plegado en días sin conteo (RN-09) --}}
            <section class="space-y-4" data-tour="form-inventory">
                <div class="flex items-center justify-between">
                    <h2 class="text-sub font-semibold text-ink-900">Inventario</h2>
                    @unless ($inventoryDay)
                        <button type="button" wire:click="toggleInventory" class="text-label text-brand-700 hover:underline">
                            {{ $showInventory ? 'Ocultar' : 'Hoy no toca conteo · registrar de todos modos' }}
                        </button>
                    @endunless
                </div>
                @if ($showInventory)
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-field label="Unidades en inventario" for="inventory_units" :error="$errors->first('form.inventory_units')" :warning="collect($warnings)->firstWhere('field', 'inventory_units')['message'] ?? null" :reference="$reference['inventory_units'] ?? null">
                            <x-input id="inventory_units" numeric inputmode="numeric" wire:model.live.debounce.500ms="form.inventory_units" x-on:input="inventoryUnits = $event.target.value" placeholder="0" :invalid="$errors->has('form.inventory_units')" />
                        </x-field>
                        <x-field label="Valuación del inventario ($)" for="inventory_value_usd" :error="$errors->first('form.inventory_value_usd')" :reference="$reference['inventory_value_usd'] ?? null">
                            <x-input id="inventory_value_usd" numeric suffix="$" wire:model.live.debounce.500ms="form.inventory_value_usd" x-on:input="inventoryValue = $event.target.value" placeholder="0,00" :invalid="$errors->has('form.inventory_value_usd')" />
                        </x-field>
                    </div>
                @else
                    <p class="text-label text-ink-400">Los {{ strtolower(\Carbon\CarbonImmutable::parse($form->date)->locale('es')->dayName) }} no se cuenta inventario en esta sede.</p>
                @endif
            </section>

            {{-- Observación y día atípico (UC-04) --}}
            <section class="space-y-4">
                <x-field label="Observación del día" for="notes" data-tour="form-notes" :error="$errors->first('form.notes')" help="Opcional. Obligatoria si marcas el día como atípico.">
                    <textarea id="notes" wire:model.live.debounce.500ms="form.notes" x-on:input="notes = $event.target.value" rows="2" maxlength="500" class="block w-full rounded-control border-line bg-surface px-3 py-2.5 text-body focus:border-brand-500 focus:ring-2 focus:ring-brand-500" placeholder="Por ejemplo: corte de luz de 10 a 12, media jornada"></textarea>
                </x-field>
                <label class="flex items-start gap-3" data-tour="form-atypical">
                    <input type="checkbox" wire:model.live="form.atypical" class="mt-1 h-5 w-5 rounded border-line text-accent-600 focus:ring-accent-600">
                    <span>
                        <span class="font-medium">Día atípico</span>
                        <span class="block text-label text-ink-600">No se usará en la proyección de metas ni en los promedios, pero sí cuenta en los totales.</span>
                    </span>
                </label>
            </section>

            {{-- Franja de advertencias sin revisar (§13.5): sin modal --}}
            @if ($warnings !== [] && ! $acknowledged)
                <div class="flex flex-wrap items-center gap-3 rounded-card border border-warning-100 bg-warning-100/60 p-4" role="status" x-init="$el.focus()" tabindex="-1" data-tour="form-warnings">
                    <x-lucide name="warning" class="h-5 w-5 text-warning-600" />
                    <p class="flex-1 text-ink-900">{{ count($warnings) === 1 ? '1 advertencia sin revisar' : count($warnings).' advertencias sin revisar' }}</p>
                    <x-btn variant="secondary" x-on:click="document.getElementById('{{ $warnings[0]['field'] }}')?.focus()">Revisar</x-btn>
                    <x-btn variant="secondary" wire:click="saveAnyway" wire:loading.attr="disabled">Guardar de todos modos</x-btn>
                </div>
            @endif

            {{-- Acciones --}}
            <div class="flex flex-wrap items-center gap-3 border-t border-line pt-6">
                <x-btn type="submit" wire:loading.attr="disabled" wire:target="save" data-tour="form-save">
                    <span wire:loading.remove wire:target="save">{{ $isEdit ? 'Guardar cambios' : 'Guardar día' }}</span>
                    <span wire:loading wire:target="save">Guardando…</span>
                </x-btn>
                @if ($isEdit && $lastEdit)
                    <p class="basis-full text-label text-ink-400 sm:ml-auto sm:basis-auto" data-tour="form-last-edit">Última edición: {{ $lastEdit }}</p>
                @endif
                @if ($isEdit && $canDelete && ! $periodClosed)
                    {{-- UC-03: borrar día con confirmación (modal permitido, §13.5) y "Deshacer" en el aviso --}}
                    <div x-data="{ deleteOpen: false }">
                        <x-btn variant="ghost" icon="trash" class="text-danger-600 hover:bg-danger-100" data-tour="form-delete" x-on:click="deleteOpen = true">Borrar día</x-btn>
                        <x-dialog show="deleteOpen" id="delete-day" data-tour="dialog-delete-day" title="Borrar este día">
                            <p>Se borrará {{ $weekdayLabel }} con todos sus datos. Tendrás unos segundos para deshacerlo desde el aviso.</p>
                            <x-slot:actions>
                                <x-btn variant="ghost" x-on:click="deleteOpen = false">Cancelar</x-btn>
                                <x-btn variant="danger" wire:click="deleteDay" wire:loading.attr="disabled" wire:target="deleteDay">Borrar día</x-btn>
                            </x-slot:actions>
                        </x-dialog>
                    </div>
                @endif
                @unless ($isEdit)
                    <div x-data="{ open: false, reason: '' }" class="relative" data-tour="form-closed-day">
                        <x-btn variant="ghost" icon="power" x-on:click="open = ! open">Registrar como día cerrado</x-btn>
                        <div x-cloak x-show="open" x-on:click.outside="open = false" class="absolute left-0 z-10 mt-2 w-80 rounded-card border border-line bg-surface p-4 shadow-overlay">
                            <p class="mb-2 font-medium">¿Ese día no operó?</p>
                            <label for="closed_reason" class="text-label text-ink-600">Motivo</label>
                            <input id="closed_reason" type="text" x-model="reason" class="mt-1 block w-full rounded-control border-line text-body focus:border-brand-500 focus:ring-brand-500" placeholder="Feriado, inventario general…">
                            <div class="mt-3 flex justify-end gap-2">
                                <x-btn variant="ghost" x-on:click="open = false">Cancelar</x-btn>
                                <x-btn variant="secondary" x-on:click="$wire.registerClosed(reason); open = false" x-bind:disabled="reason.trim().length < 5">Registrar cerrado</x-btn>
                            </div>
                        </div>
                    </div>
                @endunless
            </div>
        </div>

        {{-- Panel "Se calculará" (RN-26): en el navegador; el servidor recalcula al guardar --}}
        <aside class="lg:sticky lg:top-20 self-start rounded-card border border-line bg-surface p-5" aria-live="polite" data-tour="form-preview">
            <p class="text-label text-ink-600">Se calculará</p>
            <dl class="mt-3 divide-y divide-line">
                <div class="flex items-baseline justify-between py-2.5"><dt class="text-ink-600">Venta en dólares</dt><dd class="text-sub tnum font-semibold" x-text="money(salesUsd, 'USD', 2)"></dd></div>
                <div class="flex items-baseline justify-between py-2.5"><dt class="text-ink-600">Ticket promedio</dt><dd class="tnum font-medium" x-text="money(ticketBs, 'BS', 0)"></dd></div>
                <div class="flex items-baseline justify-between py-2.5"><dt class="text-ink-600">Ticket en dólares</dt><dd class="tnum font-medium" x-text="money(ticketUsd, 'USD', 1)"></dd></div>
                <div class="flex items-baseline justify-between py-2.5"><dt class="text-ink-600">Unidades por compra</dt><dd class="tnum font-medium" x-text="num(unitsPerTransaction, 1)"></dd></div>
                <div class="flex items-baseline justify-between py-2.5"><dt class="text-ink-600">Transacciones por jornada</dt><dd class="tnum font-medium" x-text="num(transactionsPerShift, 0)"></dd></div>
            </dl>
            @if ($reference !== [])
                <p class="mt-4 text-label text-ink-400">{{ $reference['label'] }}</p>
            @endif
        </aside>

        {{-- Móvil y tableta: barra fija al pie con los derivados clave y Guardar (§13.3), sobre la navegación inferior --}}
        <div class="fixed inset-x-0 bottom-[56px] z-20 flex items-center gap-4 border-t border-line bg-surface/95 px-4 py-2 backdrop-blur md:bottom-0 lg:hidden" data-tour="form-mobile-bar">
            <dl class="flex min-w-0 flex-1 gap-5">
                <div class="min-w-0"><dt class="truncate text-label text-ink-600">Venta $</dt><dd class="tnum font-semibold whitespace-nowrap" x-text="money(salesUsd, 'USD', 2)"></dd></div>
                <div class="min-w-0"><dt class="truncate text-label text-ink-600">Ticket Bs</dt><dd class="tnum font-semibold whitespace-nowrap" x-text="money(ticketBs, 'BS', 0)"></dd></div>
                <div class="min-w-0"><dt class="truncate text-label text-ink-600">Und./compra</dt><dd class="tnum font-semibold whitespace-nowrap" x-text="num(unitsPerTransaction, 1)"></dd></div>
            </dl>
            <x-btn type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $isEdit ? 'Guardar' : 'Guardar día' }}</span>
                <span wire:loading wire:target="save">Guardando…</span>
            </x-btn>
        </div>
    </form>
</div>
