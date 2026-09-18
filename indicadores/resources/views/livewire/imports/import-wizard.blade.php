<div class="space-y-6">
    <div>
        <h1 class="text-title text-brand-800">Importar meses anteriores</h1>
        <p class="text-ink-600">Sube los cuadros de indicadores en Excel. Nada entra sin que lo revises y confirmes.</p>
    </div>

    {{-- Pasos (§10.4) --}}
    <ol class="flex flex-wrap gap-2 text-label" aria-label="Pasos" data-tour="import-steps">
        @foreach (['Archivos', 'Revisión', 'Confirmación'] as $i => $label)
            @php $n = $i + 1; @endphp
            <li class="flex items-center gap-2 rounded-full border px-3 py-1 {{ $step === $n ? 'border-brand-600 bg-brand-100 text-brand-800' : ($step > $n ? 'border-success-100 bg-success-100 text-success-600' : 'border-line text-ink-400') }}" @if ($step === $n) aria-current="step" @endif>
                @if ($step > $n)<x-lucide name="check" class="h-3.5 w-3.5" />@else<span class="tnum">{{ $n }}</span>@endif {{ $label }}
            </li>
        @endforeach
    </ol>

    {{-- ===================== Paso 1: archivos ===================== --}}
    @if ($step === 1)
        <form wire:submit="analyze" class="space-y-5 rounded-card border border-line bg-surface p-5">
            @if ($branches->count() > 1)
                <x-field label="Sede" for="import-branch" :error="$errors->first('branchId')" help="A qué sede pertenecen estos archivos." data-tour="import-branch">
                    <select id="import-branch" wire:model="branchId" class="block w-full max-w-xs rounded-control border-line bg-surface px-3 py-2.5 text-body focus:border-brand-500 focus:ring-2 focus:ring-brand-500">
                        @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                    </select>
                </x-field>
            @endif

            <div x-data="{ dragging: false }" data-tour="import-dropzone">
                <p class="text-label font-medium text-ink-600">Archivos</p>
                <label for="import-files" x-on:dragover.prevent="dragging = true" x-on:dragleave="dragging = false" x-on:drop.prevent="dragging = false; $refs.input.files = $event.dataTransfer.files; $refs.input.dispatchEvent(new Event('change'))"
                       class="mt-1.5 flex cursor-pointer flex-col items-center justify-center gap-2 rounded-card border-2 border-dashed px-6 py-10 text-center transition-colors"
                       x-bind:class="dragging ? 'border-brand-500 bg-brand-100/60' : 'border-line bg-panel/60 hover:bg-panel'">
                    <x-lucide name="upload" class="h-8 w-8 text-brand-600" />
                    <span class="text-body text-ink-900">Arrastra aquí los archivos o haz clic para elegirlos</span>
                    <span class="text-label text-ink-600">Uno por mes, formato .xlsx, hasta 5 MB y {{ \App\Livewire\Imports\ImportWizard::MAX_FILES }} archivos por lote. Sin macros.</span>
                    <input id="import-files" x-ref="input" type="file" multiple accept=".xlsx" wire:model="files" class="sr-only">
                </label>
                <div wire:loading wire:target="files" class="mt-2 text-label text-ink-600">Subiendo…</div>
                @error('files')<p class="mt-1 text-label text-danger-600" role="alert">{{ $message }}</p>@enderror
                @foreach ($errors->get('files.*') as $messages)
                    <p class="mt-1 text-label text-danger-600" role="alert">{{ $messages[0] }}</p>
                @endforeach
            </div>

            @if ($files !== [])
                <ul class="divide-y divide-line rounded-card border border-line">
                    @foreach ($files as $i => $file)
                        <li class="flex items-center gap-3 px-4 py-2 text-body" wire:key="file-{{ $i }}">
                            <x-lucide name="table" class="h-4 w-4 text-ink-400" />
                            <span class="min-w-0 flex-1 truncate text-ink-900">{{ $file->getClientOriginalName() }}</span>
                            <span class="text-label tnum text-ink-400">{{ number_format($file->getSize() / 1024) }} KB</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="flex items-center justify-between gap-3">
                <p class="text-label text-ink-400">El nombre del archivo no importa: se lee el mes de su contenido.</p>
                <x-btn type="submit" wire:loading.attr="disabled" wire:target="analyze,files" data-tour="import-analyze">
                    <span wire:loading.remove wire:target="analyze">Analizar {{ count($files) > 1 ? count($files).' archivos' : 'archivo' }}</span>
                    <span wire:loading wire:target="analyze">Leyendo…</span>
                </x-btn>
            </div>
        </form>
    @endif

    {{-- ===================== Paso 2: revisión ===================== --}}
    @if ($step === 2)
        <div class="space-y-4">
            @foreach ($review as $item)
                @php $batch = $item['batch']; $month = $item['month']; $skipped = $skip[$batch->id] ?? false; @endphp
                <section wire:key="batch-{{ $batch->id }}" data-tour="import-review" class="rounded-card border bg-surface {{ $errors->has('batch.'.$batch->id) ? 'border-danger-600' : 'border-line' }}">
                    <div class="flex flex-wrap items-center gap-3 px-5 py-4">
                        <x-lucide name="table" class="h-5 w-5 shrink-0 text-ink-400" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-ink-900">{{ $batch->original_filename }}</p>
                            @if ($month === null)
                                <p class="text-label text-danger-600">No se pudo leer: {{ $batch->summary['error'] ?? 'formato desconocido' }}</p>
                            @else
                                <p class="text-label text-ink-600">{{ $batch->summary['period_label'] }} · {{ $batch->summary['rows'] }} filas @if ($batch->summary['legal_name'])· {{ $batch->summary['legal_name'] }}@endif</p>
                            @endif
                        </div>
                        @if ($month !== null)
                            <div class="flex flex-wrap items-center gap-1.5">
                                @foreach ($severities as $severity)
                                    @php $count = count($item['groups'][$severity->value] ?? []); @endphp
                                    @if ($count > 0)
                                        <x-badge :tone="$severity->tone()">{{ $count }} {{ mb_strtolower($severity->label()) }}</x-badge>
                                    @endif
                                @endforeach
                                @if ($item['groups'] === [])
                                    <x-badge tone="success" icon="check">Sin anomalías</x-badge>
                                @endif
                            </div>
                            <label class="flex items-center gap-2 text-label text-ink-600" data-tour="import-skip"><input type="checkbox" wire:model.live="skip.{{ $batch->id }}" class="h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500">No importar</label>
                            <x-btn variant="ghost" wire:click="toggle({{ $batch->id }})" class="min-h-[36px] px-2.5 text-label" data-tour="import-toggle">{{ $expanded === $batch->id ? 'Ocultar' : 'Revisar' }}</x-btn>
                        @endif
                    </div>

                    @error('batch.'.$batch->id)<p class="border-t border-danger-100 bg-danger-100/60 px-5 py-2 text-label text-danger-600" role="alert">{{ $message }}</p>@enderror

                    @if ($month !== null && $expanded === $batch->id && ! $skipped)
                        <div class="space-y-5 border-t border-line px-5 py-4">
                            @if ($item['pending'] > 0)
                                <p class="flex items-center gap-2 text-body text-danger-600" role="status"><x-lucide name="warning" class="h-4 w-4" />{{ $item['pending'] === 1 ? 'Falta 1 anomalía por resolver.' : 'Faltan '.$item['pending'].' anomalías por resolver.' }}</p>
                            @endif

                            @foreach ($severities as $severity)
                                @php
                                    $list = $item['groups'][$severity->value] ?? [];
                                    // Lo informativo no pide decisión: se pliega para que lo importante quede a la vista.
                                    $collapsible = $severity === \App\Enums\AnomalySeverity::Info;
                                    $groupId = 'group-'.$batch->id.'-'.$severity->value;
                                @endphp
                                @if ($list !== [])
                                    <div x-data="{ open: {{ $collapsible ? 'false' : 'true' }} }">
                                        <div class="flex items-center gap-3">
                                            <h3 class="text-label font-medium text-ink-600">{{ $severity->label() }} ({{ count($list) }})</h3>
                                            @if ($collapsible)
                                                <button type="button" class="text-label font-medium text-brand-700 hover:underline" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="{{ $groupId }}">
                                                    <span x-show="! open">Ver detalle</span><span x-cloak x-show="open">Ocultar detalle</span>
                                                </button>
                                            @endif
                                        </div>
                                        <ul id="{{ $groupId }}" x-show="open" class="mt-2 divide-y divide-line rounded-card border border-line">
                                            @foreach ($list as $anomaly)
                                                @php $id = $anomaly->id(); $options = $anomaly->type->options(); @endphp
                                                <li class="flex flex-wrap items-center gap-3 px-3 py-2" wire:key="anomaly-{{ $batch->id }}-{{ md5($id) }}">
                                                    <x-badge :tone="$severity->tone()">{{ $anomaly->type->label() }}</x-badge>
                                                    <p class="min-w-0 flex-1 text-body text-ink-900">{{ $anomaly->message }}</p>
                                                    @if ($options !== [])
                                                        <select wire:model.live="decisions.{{ $batch->id }}.{{ $id }}" data-tour="import-decision" aria-label="Decisión: {{ $anomaly->type->label() }}"
                                                                class="rounded-control border-line bg-surface px-2 py-1.5 text-label focus:border-brand-500 focus:ring-2 focus:ring-brand-500 {{ $severity === \App\Enums\AnomalySeverity::High && ! isset($decisions[$batch->id][$id]) ? 'border-danger-600' : '' }}">
                                                            @if ($severity === \App\Enums\AnomalySeverity::High)<option value="">Elige…</option>@endif
                                                            @foreach ($options as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                                                        </select>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            @endforeach

                            <div data-tour="import-preview">
                                <h3 class="text-label font-medium text-ink-600">Vista previa ({{ count($item['preview']) }} días con los derivados recalculados)</h3>
                                <div class="mt-2 overflow-x-auto rounded-card border border-line">
                                    <table class="w-full min-w-[840px] border-collapse text-label">
                                        <thead class="bg-panel text-ink-600">
                                            <tr>
                                                @foreach (['Día', 'Venta Bs', 'Tasa', 'Venta $', 'Trans.', 'Unid.', 'Ticket Bs', 'Und./compra', 'Ticket $', 'Inv. und.', 'Inv. $', 'Jorn.'] as $h)
                                                    <th class="whitespace-nowrap px-2 py-1.5 font-medium {{ $loop->first ? 'text-left' : 'text-right' }}">{{ $h }}</th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-line">
                                            @foreach ($item['preview'] as $m)
                                                <tr wire:key="preview-{{ $batch->id }}-{{ $m->data->date->toDateString() }}">
                                                    <td class="whitespace-nowrap px-2 py-1 text-ink-900">{{ $formatter->date($m->data->date, 'weekday') }}</td>
                                                    <td class="px-2 py-1 text-right tnum">{{ $formatter->number($m->data->salesBs, 2) }}</td>
                                                    <td class="px-2 py-1 text-right tnum">{{ $formatter->number($m->data->rate, 2) }}</td>
                                                    <td class="px-2 py-1 text-right tnum text-ink-600">{{ $formatter->number($m->salesUsd, 0) }}</td>
                                                    <td class="px-2 py-1 text-right tnum">{{ $m->data->transactions }}</td>
                                                    <td class="px-2 py-1 text-right tnum">{{ $m->data->units }}</td>
                                                    <td class="px-2 py-1 text-right tnum text-ink-600">{{ $formatter->number($m->avgTicketBs, 0) }}</td>
                                                    <td class="px-2 py-1 text-right tnum text-ink-600">{{ $formatter->number($m->unitsPerTransaction, 1) }}</td>
                                                    <td class="px-2 py-1 text-right tnum text-ink-600">{{ $formatter->number($m->avgTicketUsd, 1) }}</td>
                                                    <td class="px-2 py-1 text-right tnum">{{ $m->data->inventoryUnits === null ? '—' : $formatter->number($m->data->inventoryUnits) }}</td>
                                                    <td class="px-2 py-1 text-right tnum">{{ $m->data->inventoryValueUsd === null ? '—' : $formatter->number($m->data->inventoryValueUsd, 0) }}</td>
                                                    <td class="px-2 py-1 text-right tnum">{{ $m->data->shifts }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @endif
                </section>
            @endforeach

            @php
                // Textos calculados aquí: sin comillas dobles dentro de atributos de componente.
                $pendingLabel = $blocking === 1 ? 'Falta 1 anomalía por resolver' : 'Faltan '.$blocking.' anomalías por resolver';
                $confirmLabel = $blocking > 0 ? $pendingLabel : ($importable === 1 ? 'Importar 1 mes' : 'Importar '.$importable.' meses');
                $confirmDisabled = $blocking > 0 || $importable === 0;
            @endphp
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-card border border-line bg-surface px-5 py-4">
                <label class="flex items-center gap-2 text-body text-ink-900" data-tour="import-close-after"><input type="checkbox" wire:model="closeAfter" class="h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500">Cerrar los meses al importar <span class="text-label text-ink-600">(quedan de solo lectura hasta que dirección los reabra)</span></label>
                <div class="flex items-center gap-2">
                    <x-btn variant="ghost" wire:click="restart" data-tour="import-restart">Volver a empezar</x-btn>
                    <x-btn wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm" :disabled="$confirmDisabled" :title="$blocking > 0 ? $pendingLabel : null" data-tour="import-confirm">
                        <span wire:loading.remove wire:target="confirm">{{ $confirmLabel }}</span>
                        <span wire:loading wire:target="confirm">Importando…</span>
                    </x-btn>
                </div>
            </div>
        </div>
    @endif

    {{-- ===================== Paso 3: confirmación ===================== --}}
    @if ($step === 3)
        <div class="space-y-4">
            @foreach ($review as $item)
                @php $batch = $item['batch']; $r = $results[$batch->id] ?? null; @endphp
                <section wire:key="result-{{ $batch->id }}" data-tour="import-result" class="flex flex-wrap items-center gap-3 rounded-card border border-line bg-surface px-5 py-4">
                    @if ($r === null)
                        <x-lucide name="minus" class="h-5 w-5 shrink-0 text-ink-400" />
                        <p class="min-w-0 flex-1 text-body text-ink-600">{{ $batch->original_filename }}: no se importó.</p>
                    @elseif ($r['rejected'])
                        <x-lucide name="x" class="h-5 w-5 shrink-0 text-ink-400" />
                        <p class="min-w-0 flex-1 text-body text-ink-600">{{ $batch->summary['period_label'] ?? $batch->original_filename }}: omitido por tu decisión.</p>
                    @else
                        <x-lucide name="check" class="h-5 w-5 shrink-0 text-success-600" />
                        <p class="min-w-0 flex-1 text-body text-ink-900">
                            <span class="font-medium">{{ $batch->summary['period_label'] }} importado.</span>
                            {{ $r['created'] }} {{ $r['created'] === 1 ? 'día nuevo' : 'días nuevos' }}@if ($r['updated'] > 0), {{ $r['updated'] }} {{ $r['updated'] === 1 ? 'actualizado' : 'actualizados' }}@endif@if ($r['closed'] > 0), {{ $r['closed'] }} {{ $r['closed'] === 1 ? 'cerrado' : 'cerrados' }}@endif@if ($r['atypical'] > 0), {{ $r['atypical'] }} {{ $r['atypical'] === 1 ? 'atípico' : 'atípicos' }}@endif@if ($r['skipped'] > 0), {{ $r['skipped'] }} {{ $r['skipped'] === 1 ? 'fila omitida' : 'filas omitidas' }}@endif.
                        </p>
                        <x-btn variant="secondary" :href="route('month', ['period' => substr($batch->period->toDateString(), 0, 7)])" wire:navigate>Ver el mes</x-btn>
                    @endif
                </section>
            @endforeach
            <div class="flex justify-end"><x-btn variant="secondary" icon="upload" wire:click="restart" data-tour="import-more">Importar más archivos</x-btn></div>
        </div>
    @endif
</div>
