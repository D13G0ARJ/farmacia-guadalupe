<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-title text-brand-800">Metas</h1>
            <p class="text-ink-600">{{ $branch?->name ?? 'Consolidado' }} · metas mensuales, en dólares las de venta y ticket</p>
        </div>
        <div class="inline-flex rounded-control border border-line bg-surface p-0.5" role="group" aria-label="Vista" data-tour="goals-view">
            @foreach (['month' => 'Este mes', 'year' => 'Año'] as $key => $label)
                <button type="button" wire:click="$set('view', '{{ $key }}')"
                        class="rounded-[4px] px-3 py-1.5 text-label font-medium transition-colors {{ $view === $key ? 'bg-brand-600 text-white' : 'text-ink-600 hover:bg-panel' }}"
                        @if ($view === $key) aria-pressed="true" @endif>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    @if ($view === 'month')
        {{-- Vista "Este mes" (UC-11/UC-12): meta editable en línea + seguimiento --}}
        <section class="space-y-3" aria-labelledby="goals-month-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="goals-month-title" class="text-sub font-semibold text-ink-900">{{ $periodObj->label() }}</h2>
                @if ($canManage)
                    <x-btn variant="secondary" wire:click="copyPreviousMonth" wire:loading.attr="disabled" data-tour="goals-copy-month">Copiar de {{ mb_strtolower($periodObj->previous()->label()) }}</x-btn>
                @endif
            </div>

            <div class="relative overflow-x-auto rounded-card border border-line bg-surface" data-tour="goals-table">
                <table class="w-full min-w-[880px] border-collapse text-body">
                    <thead class="bg-panel text-label text-ink-600">
                        <tr>
                            <th class="px-4 py-2.5 text-left font-medium">Indicador</th>
                            <th class="w-44 px-4 py-2.5 text-right font-medium">Meta</th>
                            <th class="px-4 py-2.5 text-right font-medium">Actual</th>
                            <th class="px-4 py-2.5 text-right font-medium">Esperado hoy</th>
                            <th class="px-4 py-2.5 text-right font-medium">Proyección</th>
                            <th class="px-4 py-2.5 text-left font-medium">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($indicators as $ind)
                            @php
                                $p = $tracking->for($ind->value);
                                $error = $errors->has('targets.'.$ind->value) ? $errors->first('targets.'.$ind->value) : null;
                                $inBs = $ind->unit() === \App\Domain\Indicators\Unit::Bs;
                            @endphp
                            <tr wire:key="goal-row-{{ $ind->value }}" class="{{ $loop->even ? 'bg-brand-50/40' : '' }}">
                                <td class="px-4 py-2.5">
                                    <p class="font-medium text-ink-900">{{ $ind->label() }}</p>
                                    @if ($inBs && $targets[$ind->value] !== '')
                                        <p class="mt-0.5 flex items-center gap-1 text-label text-warning-600"><x-lucide name="warning" class="h-3.5 w-3.5" />Una meta en Bs se cumple sola con la devaluación.</p>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    <label for="target-{{ $ind->value }}" class="sr-only">Meta de {{ $ind->label() }}</label>
                                    <x-input id="target-{{ $ind->value }}" numeric data-tour="goals-target" wire:model.blur="targets.{{ $ind->value }}"
                                             placeholder="{{ $suggestions[$ind->value] !== null ? 'Sugerida: '.$suggestions[$ind->value] : '—' }}"
                                             :invalid="$error !== null" :disabled="! $canManage" class="py-1.5 !min-h-[36px]" />
                                    @if ($error)
                                        <p class="mt-1 text-label text-danger-600" role="alert">{{ $error }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right tnum">{{ $ind->format($formatter, $p?->actual) }}</td>
                                <td class="px-4 py-2.5 text-right tnum text-ink-600">{{ $p?->hasGoal() ? $ind->format($formatter, $p->expected) : '—' }}</td>
                                <td class="px-4 py-2.5 text-right tnum">
                                    @if ($p?->hasGoal() && $p->projection !== null)
                                        {{ $ind->format($formatter, $p->projection) }}
                                        <span class="text-label text-ink-400">({{ $formatter->pct($p->pctProjected(), 0, false) }})</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-2.5" data-tour="goals-status">
                                    @if ($p)
                                        <x-badge :tone="$p->status->tone()" :icon="$p->status->icon()">{{ $p->status->label() }}</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="text-label text-ink-400" data-tour="goals-footnote">
                Corte: {{ $formatter->date($tracking->cutoff, 'weekday_full') }} ·
                @if ($tracking->patternReliable)
                    proyección por patrón semanal ({{ $tracking->patternWeeks }} semanas de histórico).
                @else
                    proyección lineal: histórico insuficiente ({{ $tracking->patternWeeks }} de {{ \App\Domain\Indicators\WeekdayPattern::MIN_WEEKS }} semanas).
                @endif
                Los ratios (ticket, unidades por compra) no acumulan: su estado sale del valor a la fecha.
                @unless ($canManage) Solo dirección puede cambiar las metas. @endunless
            </p>
        </section>
    @else
        {{-- Vista "Año" (UC-11): cuadrícula indicador × mes, guardado en lote --}}
        <section class="space-y-3" aria-labelledby="goals-year-title" x-data="goalGrid()">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-1" data-tour="goals-year-nav">
                    <button type="button" wire:click="previousYear" class="rounded-control p-1.5 text-ink-600 hover:bg-panel" aria-label="Año anterior"><x-lucide name="chevron-left" class="h-5 w-5" /></button>
                    <h2 id="goals-year-title" class="text-sub font-semibold text-ink-900 tnum">{{ $year }}</h2>
                    <button type="button" wire:click="nextYear" class="rounded-control p-1.5 text-ink-600 hover:bg-panel" aria-label="Año siguiente"><x-lucide name="chevron-right" class="h-5 w-5" /></button>
                </div>
                @if ($canManage)
                    <div class="flex flex-wrap items-center gap-2">
                        <x-btn variant="secondary" wire:click="copyPreviousYear" wire:loading.attr="disabled" data-tour="goals-copy-year">Copiar {{ $year - 1 }}</x-btn>
                        <div class="flex items-center gap-1" data-tour="goals-growth">
                            <label for="growth" class="sr-only">Porcentaje</label>
                            <input id="growth" type="text" inputmode="decimal" wire:model="growth" class="w-16 rounded-control border-line py-2 text-right text-body tnum focus:border-brand-500 focus:ring-brand-500" aria-describedby="growth-help">
                            <span id="growth-help" class="text-label text-ink-600">%</span>
                            <x-btn variant="secondary" wire:click="increaseAll">Aplicar a todo el año</x-btn>
                        </div>
                        <x-btn wire:click="saveYear" wire:loading.attr="disabled" wire:target="saveYear" data-tour="goals-save-year">Guardar metas</x-btn>
                    </div>
                @endif
            </div>
            @error('growth')<p class="text-label text-danger-600" role="alert">{{ $message }}</p>@enderror

            {{-- Móvil (§13.8): la cuadrícula se apila, un indicador por tarjeta con sus doce meses --}}
            <div class="space-y-3 md:hidden" data-tour="goals-grid-mobile">
                @foreach ($indicators as $ind)
                    <details class="rounded-card border border-line bg-surface" wire:key="grid-card-{{ $ind->value }}" {{ $loop->first ? 'open' : '' }}>
                        <summary class="cursor-pointer list-none px-4 py-3 font-medium text-ink-900">{{ $ind->label() }}</summary>
                        <div class="grid grid-cols-3 gap-2 border-t border-line px-3 py-3">
                            @foreach ($months as $key)
                                @php $cellError = $errors->has("grid.{$ind->value}.{$key}") ? $errors->first("grid.{$ind->value}.{$key}") : null; @endphp
                                <label class="block">
                                    <span class="text-label text-ink-600">{{ ucfirst(mb_strtolower(\App\Domain\Shared\Period::of($key)->monthNameUpper())) }}</span>
                                    <input type="text" inputmode="decimal" autocomplete="off" wire:model="grid.{{ $ind->value }}.{{ $key }}" x-on:focus="$event.target.select()"
                                           @disabled(! $canManage) title="{{ $cellError }}"
                                           class="mt-0.5 block w-full rounded-control border bg-surface px-2 py-2 text-right text-body tnum text-ink-900 focus:border-brand-500 focus:ring-2 focus:ring-brand-500 disabled:bg-panel {{ $cellError ? 'border-danger-600' : 'border-line' }}">
                                </label>
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </div>

            <div class="relative hidden overflow-x-auto rounded-card border border-line bg-surface md:block" data-tour="goals-grid">
                <table class="w-full min-w-[1180px] border-collapse text-label">
                    <thead class="bg-panel text-ink-600">
                        <tr>
                            <th class="sticky left-0 z-10 bg-panel px-3 py-2 text-left font-medium">Indicador</th>
                            @foreach ($months as $key)
                                <th class="px-2 py-2 text-right font-medium">{{ ucfirst(mb_strtolower(\App\Domain\Shared\Period::of($key)->monthNameUpper())) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($indicators as $r => $ind)
                            <tr wire:key="grid-row-{{ $ind->value }}" class="{{ $loop->even ? 'bg-brand-50/40' : '' }}">
                                <td class="sticky left-0 z-10 whitespace-nowrap bg-inherit px-3 py-1.5 font-medium text-ink-900">{{ $ind->label() }}</td>
                                @foreach ($months as $c => $key)
                                    @php $cellError = $errors->has("grid.{$ind->value}.{$key}") ? $errors->first("grid.{$ind->value}.{$key}") : null; @endphp
                                    <td class="px-1 py-1">
                                        <label for="grid-{{ $ind->value }}-{{ $key }}" class="sr-only">{{ $ind->label() }}, {{ $key }}</label>
                                        <input id="grid-{{ $ind->value }}-{{ $key }}" type="text" inputmode="decimal" autocomplete="off"
                                               wire:model="grid.{{ $ind->value }}.{{ $key }}" data-row="{{ $r }}" data-col="{{ $c }}"
                                               x-on:keydown.down.prevent="move($event, 1, 0)" x-on:keydown.up.prevent="move($event, -1, 0)"
                                               x-on:keydown.enter.prevent="move($event, 1, 0)" x-on:paste="paste($event)" x-on:focus="$event.target.select()"
                                               @disabled(! $canManage)
                                               title="{{ $cellError }}"
                                               class="block w-full min-w-[84px] rounded-control border bg-surface px-2 py-1.5 text-right text-label tnum text-ink-900 focus:border-brand-500 focus:ring-2 focus:ring-brand-500 disabled:bg-panel {{ $cellError ? 'border-danger-600' : 'border-line' }}">
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-label text-ink-400">Flechas y Enter mueven el foco; puedes pegar un bloque desde Excel. Una celda vacía borra la meta al guardar.</p>
        </section>
    @endif
</div>
