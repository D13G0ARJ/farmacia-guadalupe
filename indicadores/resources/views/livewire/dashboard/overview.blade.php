<div class="space-y-8">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div data-tour="dash-title">
            <h1 class="text-title text-brand-800">{{ $view->period->label() }}</h1>
            <p class="text-ink-600">{{ $branch?->name ?? 'Todas las sedes' }}</p>
        </div>
        @unless ($dashboard->isEmpty())
            {{-- Reporte PDF (UC-14): el navegador envía primero las gráficas en pantalla; si no puede, el PDF sale sin ellas --}}
            <div x-data="{ busy: false }" class="flex flex-wrap items-center gap-2 print:hidden">
                <x-btn variant="secondary" icon="download" data-tour="dash-pdf" data-images-url="{{ route('exports.charts', ['period' => $view->period->key()]) }}" data-pdf-url="{{ route('exports.pdf', ['period' => $view->period->key()]) }}"
                       x-on:click="busy = true; window.downloadReport($el.dataset.imagesUrl, $el.dataset.pdfUrl).finally(() => setTimeout(() => busy = false, 3000))" x-bind:disabled="busy">
                    <span x-show="! busy">Descargar PDF</span>
                    <span x-cloak x-show="busy">Generando…</span>
                </x-btn>
                <x-btn variant="secondary" icon="printer" class="print:hidden" data-tour="dash-print" x-on:click="window.print()">Imprimir</x-btn>
            </div>
        @endunless
    </div>

    @if ($dashboard->isEmpty())
        <div class="rounded-card border border-line bg-surface px-6 py-14 text-center" data-tour="dash-empty">
            <x-lucide name="cross" class="mx-auto h-12 w-12 text-brand-100" />
            <p class="mt-4 text-sub text-ink-900">Aún no hay días cargados en {{ strtolower($view->period->label()) }}.</p>
            @if ($canCreate && $view->firstMissingDate())
                <div class="mt-5"><x-btn :href="route('records.create', ['date' => $view->firstMissingDate()->toDateString()])" icon="plus">Cargar el primer día</x-btn></div>
            @endif
        </div>
    @else
        @php
            $summary = $dashboard->summary();
            $salesUsd = \App\Domain\Indicators\Indicator::SalesUsd;
            $sold = $formatter->money($summary->sumsAll['salesUsd'], \App\Enums\Currency::Usd, 0);
            $goal = $canSeeGoals ? $dashboard->salesGoal() : null;
            $weekdaysPlural = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábados', 'domingos'];
        @endphp

        {{-- Héroe del mes (§13.5): estado frente a la meta y proyección; G9 a ancho completo --}}
        <div class="rounded-hero border border-brand-100 bg-brand-100 p-6" data-tour="dash-hero">
            @if ($goal?->hasGoal() && $goal->actual !== null)
                @if ($goal->isComplete())
                    <p class="text-hero text-brand-800">{{ $view->period->label() }} cerró en <span class="tnum">{{ $formatter->pct($goal->pctOfTarget(), 0, false) }}</span> de la meta</p>
                    <p class="mt-1 text-sub text-ink-600">
                        {{ $sold }} vendidos frente a una meta de {{ $salesUsd->format($formatter, $goal->target) }}.
                        @if ($goal->gap !== null && $goal->gap->isPositive())
                            Faltaron {{ $salesUsd->format($formatter, $goal->gap) }}.
                        @else
                            Superó la meta por {{ $salesUsd->format($formatter, $goal->gap?->abs()) }}.
                        @endif
                    </p>
                @else
                    <p class="text-hero text-brand-800">{{ $view->period->label() }} va al <span class="tnum">{{ $formatter->pct($goal->pctOfTarget(), 0, false) }}</span> de la meta</p>
                    <p class="mt-1 text-sub text-ink-600">
                        Al ritmo actual cierra en <span class="tnum">{{ $formatter->pct($goal->pctProjected(), 0, false) }}</span>:
                        @if ($goal->gap !== null && $goal->gap->isPositive())
                            faltan {{ $salesUsd->format($formatter, $goal->gap) }}.
                        @else
                            supera la meta por {{ $salesUsd->format($formatter, $goal->gap?->abs()) }}.
                        @endif
                        @if ($goal->remainingStrongDays > 0 && $goal->strongestDay !== null)
                            {{ $goal->remainingStrongDays === 1 ? 'Queda 1' : 'Quedan '.$goal->remainingStrongDays }} {{ $weekdaysPlural[$goal->strongestDay - 1] }}, {{ $goal->remainingStrongDays === 1 ? 'tu día más fuerte' : 'tus días más fuertes' }}.
                        @endif
                    </p>
                    <p class="mt-3 text-label text-accent-600">
                        {{ $sold }} vendidos en {{ $summary->days }} {{ $summary->days === 1 ? 'día' : 'días' }} · esperado a la fecha {{ $salesUsd->format($formatter, $goal->expected) }} ({{ $formatter->pct($goal->pctOfExpected(), 0, false) }} de lo esperado) · meta {{ $salesUsd->format($formatter, $goal->target) }}
                        @if ($view->missingDates !== []) · faltan {{ count($view->missingDates) }} por cargar @endif
                    </p>
                @endif
            @else
                <p class="text-hero tnum text-brand-800">{{ $sold }}</p>
                <p class="mt-1 text-sub text-ink-600">
                    {{ $view->period->label() }} lleva {{ $sold }} vendidos en {{ $summary->days }} {{ $summary->days === 1 ? 'día' : 'días' }}
                    @if ($view->missingDates !== []) · faltan {{ count($view->missingDates) }} por cargar @endif
                </p>
                @if ($canManageGoals)
                    <p class="mt-3 flex flex-wrap items-center gap-3 text-label text-accent-600">Define una meta para ver la proyección de cierre. <x-btn variant="secondary" :href="route('goals')" wire:navigate icon="target" data-tour="dash-goal-link">Definir meta</x-btn></p>
                @elseif ($canSeeGoals)
                    <p class="mt-3 text-label text-accent-600">Sin meta definida para este mes.</p>
                @endif
            @endif

            @if (isset($specs['g9']))
                <div class="mt-4 rounded-card bg-surface/70">
                    <x-chart-panel :spec="$specs['g9']" wire:key="dashboard-chart-g9" />
                </div>
            @endif
        </div>

        {{-- KPI primarios con variación, sparkline y barra de meta (§13.5, §7.2, §8.3) --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-tour="dash-kpis">
            @foreach ($primary as $ind)
                <x-kpi :label="$ind->label()" :explanation="$ind->explanation()" :hint="$hints[$ind->value] ?? null"
                       :value="$ind->format($formatter, $summary->value($ind), $ind === \App\Domain\Indicators\Indicator::SalesBs ? 0 : null)"
                       :secondary="match ($ind) {
                           \App\Domain\Indicators\Indicator::SalesUsd => $formatter->money($summary->value(\App\Domain\Indicators\Indicator::SalesBs), \App\Enums\Currency::Bs, 0),
                           \App\Domain\Indicators\Indicator::SalesBs => $formatter->money($summary->value(\App\Domain\Indicators\Indicator::SalesUsd), \App\Enums\Currency::Usd, 0),
                           \App\Domain\Indicators\Indicator::AvgTicketUsd => $formatter->money($summary->value(\App\Domain\Indicators\Indicator::AvgTicketBs), \App\Enums\Currency::Bs, 0),
                           \App\Domain\Indicators\Indicator::AvgTicketBs => $formatter->money($summary->value(\App\Domain\Indicators\Indicator::AvgTicketUsd), \App\Enums\Currency::Usd, 1),
                           default => null,
                       }"
                       :delta="$dashboard->vsPrevious[$ind->value] ?? null"
                       :year-delta="$dashboard->vsYear[$ind->value] ?? null"
                       :sparkline="$dashboard->sparklines[$ind->value] ?? []"
                       :goal="$canSeeGoals ? $dashboard->goals->for($ind->value) : null"
                       :goal-href="$canManageGoals && $ind->supportsGoal() ? route('goals') : null" />
            @endforeach
        </div>

        <details class="group" data-tour="dash-more">
            <summary class="cursor-pointer list-none text-body text-brand-700 hover:underline">Ver 4 indicadores más</summary>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($secondary as $ind)
                    <x-kpi :label="$ind->label()" :explanation="$ind->explanation()" :hint="$hints[$ind->value] ?? null"
                           :value="$ind->format($formatter, $summary->value($ind), $ind === \App\Domain\Indicators\Indicator::SalesBs ? 0 : null)"
                           :secondary="$ind === \App\Domain\Indicators\Indicator::InventoryValueUsd && $summary->daysWithInventory > 0 ? 'promedio de '.$summary->daysWithInventory.' conteos' : null"
                           :delta="$dashboard->vsPrevious[$ind->value] ?? null"
                           :year-delta="$dashboard->vsYear[$ind->value] ?? null"
                           :sparkline="$dashboard->sparklines[$ind->value] ?? []"
                           :goal="$canSeeGoals ? $dashboard->goals->for($ind->value) : null"
                           :goal-href="$canManageGoals && $ind->supportsGoal() ? route('goals') : null" />
                @endforeach
            </div>
        </details>

        {{-- Venta diaria (G2) y mapa de calor (G8), sin marco, separadas por hairline (§13.4, §13.7) --}}
        <section class="grid rounded-card border border-line bg-surface lg:grid-cols-2 [&>section+section]:border-t [&>section+section]:border-line lg:[&>section+section]:border-l lg:[&>section+section]:border-t-0" aria-label="Gráficas del mes" data-tour="dash-charts">
            @foreach (\App\Queries\DashboardQuery::CHARTS as $id)
                @if (isset($specs[$id]))
                    <x-chart-panel :spec="$specs[$id]" wire:key="dashboard-chart-{{ $id }}" />
                @endif
            @endforeach
        </section>

        {{-- Avisos del mes (§13.7 punto 5) --}}
        @if ($dashboard->notices !== [])
            @php
                $noticeTones = [
                    'success' => 'text-success-600',
                    'warning' => 'text-warning-600',
                    'danger' => 'text-danger-600',
                    'neutral' => 'text-ink-600',
                ];
            @endphp
            <section class="rounded-card border border-line bg-surface" aria-labelledby="avisos-title" data-tour="dash-notices">
                <h2 id="avisos-title" class="border-b border-line px-5 py-3 text-sub font-semibold text-ink-900">Avisos del mes</h2>
                <ul class="divide-y divide-line">
                    @foreach ($dashboard->notices as $notice)
                        <li class="flex flex-wrap items-center gap-3 px-5 py-3">
                            <x-lucide :name="$notice['icon']" class="h-5 w-5 shrink-0 {{ $noticeTones[$notice['tone']] ?? $noticeTones['neutral'] }}" />
                            <p class="min-w-0 flex-1 text-body text-ink-900">{{ $notice['text'] }}</p>
                            @if ($notice['href'] && $notice['action'])
                                <a href="{{ $notice['href'] }}" wire:navigate class="text-body font-medium text-brand-700 hover:underline">{{ $notice['action'] }}</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <p class="text-label text-ink-400" data-tour="dash-rate-line">Tasa BCV del mes: {{ $formatter->number($summary->rateFirst, 2) }} → {{ $formatter->number($summary->rateLast, 2) }} ({{ $formatter->pct($summary->rateVariationPct) }}). <a href="{{ route('month', ['period' => $view->period->key()]) }}" wire:navigate class="text-brand-700 hover:underline">Ver el cuadro completo</a></p>
    @endif
</div>
