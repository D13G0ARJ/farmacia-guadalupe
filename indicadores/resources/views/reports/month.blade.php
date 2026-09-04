<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Indicadores {{ $view->period->label() }}</title>
    <style>
        @page { margin: 14mm 12mm 16mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #14232F; margin: 0; }
        h1 { font-size: 18pt; margin: 0; color: #0F3F8F; }
        h2 { font-size: 12pt; margin: 14pt 0 6pt; color: #14232F; }
        .muted { color: #4B5B67; }
        .faint { color: #8A98A3; }
        .band { border-bottom: 2px solid #1D6FE5; padding-bottom: 6pt; }
        .band table { width: 100%; }
        .band td { vertical-align: bottom; }
        table.kpi { width: 100%; border-collapse: separate; border-spacing: 6pt 0; margin: 8pt -6pt 0; }
        table.kpi td { width: 25%; border: 1px solid #DDE4EC; border-radius: 4pt; padding: 6pt 8pt; vertical-align: top; }
        .kpi .label { font-size: 8pt; color: #4B5B67; }
        .kpi .value { font-size: 15pt; font-weight: bold; margin-top: 2pt; }
        .kpi .delta { font-size: 8pt; margin-top: 2pt; }
        .up { color: #1E8E5A; } .down { color: #D23F3F; } .neutral { color: #4B5B67; }
        table.grid { width: 100%; border-collapse: collapse; font-size: 7.5pt; }
        table.grid th, table.grid td { border: 1px solid #DDE4EC; padding: 2.5pt 4pt; }
        table.grid th { background: #1D6FE5; color: #fff; font-weight: bold; text-align: center; }
        table.grid td.num { text-align: right; }
        table.grid tr.total td { font-weight: bold; background: #EEF3F9; }
        table.grid tr.atypical td { background: #EDE8FB; }
        table.grid tr.closed td { color: #8A98A3; }
        table.charts { width: 100%; border-collapse: separate; border-spacing: 8pt 0; margin-left: -8pt; }
        table.charts td { width: 50%; vertical-align: top; }
        table.charts img { width: 100%; height: auto; border: 1px solid #DDE4EC; }
        .chart-title { font-size: 9pt; font-weight: bold; margin: 0 0 3pt; }
        .badge { display: inline-block; padding: 1pt 5pt; border-radius: 8pt; font-size: 7pt; background: #EEF3F9; color: #4B5B67; }
        .badge.ok { background: #E4F5EC; color: #1E8E5A; } .badge.warn { background: #FCF1DE; color: #D98A0B; } .badge.bad { background: #FBE6E6; color: #D23F3F; }
        .page-break { page-break-before: always; }
        .footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: #8A98A3; text-align: center; }
    </style>
</head>
<body>
@php
    $summary = $dashboard->summary();
    $fmt = fn (\App\Domain\Indicators\Indicator $ind, ?\Brick\Math\BigDecimal $v, ?int $precision = null) => match ($ind->unit()) {
        \App\Domain\Indicators\Unit::Bs => $formatter->money($v, \App\Enums\Currency::Bs, $precision ?? ($ind === \App\Domain\Indicators\Indicator::SalesBs ? 0 : $ind->precision())),
        \App\Domain\Indicators\Unit::Usd => $formatter->money($v, \App\Enums\Currency::Usd, $precision ?? $ind->precision()),
        default => $formatter->number($v, $precision ?? $ind->precision()),
    };
    $goals = $canSeeGoals ? $dashboard->goals : null;
@endphp
<div class="footer">{{ $legalName }} · Indicadores de {{ mb_strtolower($view->period->label()) }} · generado por {{ $generatedBy }} el {{ $generatedAt->format('d/m/Y H:i') }}</div>

<div class="band">
    <table>
        <tr>
            <td>
                <h1>{{ $view->period->label() }}</h1>
                <div class="muted">{{ $legalName }} · {{ $branch?->name ?? 'Todas las sedes' }}</div>
            </td>
            <td style="text-align: right;" class="muted">
                {{ $summary->days }} {{ $summary->days === 1 ? 'día cargado' : 'días cargados' }}@if ($view->missingDates !== []) · faltan {{ count($view->missingDates) }}@endif<br>
                Tasa BCV: {{ $formatter->number($summary->rateFirst, 2) }} → {{ $formatter->number($summary->rateLast, 2) }} ({{ $formatter->pct($summary->rateVariationPct) }})
            </td>
        </tr>
    </table>
</div>

@if ($dashboard->isEmpty())
    <p style="margin-top: 20pt;">Aún no hay días cargados en {{ mb_strtolower($view->period->label()) }}.</p>
@else
    <h2>Indicadores del mes</h2>
    @foreach ([$primary, $secondary] as $group)
        <table class="kpi">
            <tr>
                @foreach ($group as $ind)
                    @php
                        $delta = $dashboard->vsPrevious[$ind->value] ?? null;
                        $goal = $goals?->for($ind->value);
                    @endphp
                    <td>
                        <div class="label">{{ $ind->label() }}</div>
                        <div class="value">{{ $fmt($ind, $summary->value($ind)) }}</div>
                        @if ($delta?->isAvailable())
                            <div class="delta {{ $delta->tone() === 'success' ? 'up' : ($delta->tone() === 'danger' ? 'down' : 'neutral') }}">{{ $delta->direction() > 0 ? '▲' : ($delta->direction() < 0 ? '▼' : '=') }} {{ $formatter->pct($delta->variation) }} <span class="faint">vs {{ $delta->against }}{{ $delta->comparedDays ? ', primeros '.$delta->comparedDays.' días' : '' }}</span></div>
                        @else
                            <div class="delta faint">Sin dato del mes anterior</div>
                        @endif
                        @if ($goal !== null && $goal->hasGoal())
                            <div class="delta muted">Meta {{ $fmt($ind, $goal->target, 0) }} · {{ $goal->pctOfTarget() === null ? '—' : $formatter->pct($goal->pctOfTarget(), 0, false) }} logrado
                                @if ($goal->pctProjected() !== null)· cierre estimado {{ $formatter->pct($goal->pctProjected(), 0, false) }}@endif</div>
                        @endif
                    </td>
                @endforeach
            </tr>
        </table>
    @endforeach

    @if ($images !== [])
        {{-- Las gráficas van en su propia página: dompdf no parte una tabla con imágenes grandes --}}
        <div class="page-break"></div>
        <h2>Gráficas</h2>
        @php
            $chartTitles = ['g9' => 'Acumulado frente a la meta', 'g2' => 'Venta en dólares por día', 'g8' => 'Mapa de calor semanal', 'g1' => 'Venta en bolívares por día', 'g3' => 'Transacciones y unidades', 'g6' => 'Transacciones por jornada', 'g7' => 'Inventario', 'g10' => 'Tasa BCV frente a la venta', 'rate' => 'Tasa BCV del mes'];
            // El acumulado (G9) es apaisado y ancho: ocupa la fila completa; el resto va de a dos.
            $wide = array_intersect_key($images, ['g9' => true]);
            $rest = array_diff_key($images, $wide);
        @endphp
        <table class="charts">
            @foreach ($wide as $id => $dataUrl)
                <tr>
                    <td colspan="2">
                        <p class="chart-title">{{ $chartTitles[$id] ?? $id }}</p>
                        <img src="{{ $dataUrl }}" alt="{{ $chartTitles[$id] ?? $id }}">
                    </td>
                </tr>
            @endforeach
            @foreach (array_chunk($rest, 2, true) as $pair)
                <tr>
                    @foreach ($pair as $id => $dataUrl)
                        <td>
                            <p class="chart-title">{{ $chartTitles[$id] ?? $id }}</p>
                            <img src="{{ $dataUrl }}" alt="{{ $chartTitles[$id] ?? $id }}">
                        </td>
                    @endforeach
                    @if (count($pair) === 1)<td></td>@endif
                </tr>
            @endforeach
        </table>
    @else
        <p class="faint" style="margin-top: 8pt;">Gráficas no disponibles en este reporte: descárgalo desde el panel con las gráficas en pantalla para incluirlas.</p>
    @endif

    <div class="page-break"></div>
    <h2>Cuadro de indicadores</h2>
    <table class="grid">
        <thead>
            <tr>
                <th>Día</th><th>Fecha</th><th>Venta Bs</th><th>Venta $</th><th>Tasa</th><th>Trans.</th><th>Unid.</th><th>Ticket Bs</th><th>Und./compra</th><th>Ticket $</th><th>Inv. und.</th><th>Inv. $</th><th>Trn./jorn.</th><th>Jorn.</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($view->rows as $m)
                @php $status = $m->data->status; @endphp
                <tr class="{{ $status === \App\Enums\DayStatus::Atypical ? 'atypical' : ($status === \App\Enums\DayStatus::Closed ? 'closed' : '') }}">
                    <td>{{ $formatter->weekday($m->data->date) }}</td>
                    <td>{{ $m->data->date->format('d/m/Y') }}@if ($status === \App\Enums\DayStatus::Atypical) *@elseif ($status === \App\Enums\DayStatus::Closed) (cerrado)@endif</td>
                    @if ($status === \App\Enums\DayStatus::Closed)
                        @for ($i = 0; $i < 12; $i++)<td class="num">—</td>@endfor
                    @else
                        <td class="num">{{ $formatter->number($m->data->salesBs, 2) }}</td>
                        <td class="num">{{ $formatter->number($m->salesUsd, 0) }}</td>
                        <td class="num">{{ $formatter->number($m->data->rate, 2) }}</td>
                        <td class="num">{{ $m->data->transactions }}</td>
                        <td class="num">{{ $m->data->units }}</td>
                        <td class="num">{{ $formatter->number($m->avgTicketBs, 0) }}</td>
                        <td class="num">{{ $formatter->number($m->unitsPerTransaction, 1) }}</td>
                        <td class="num">{{ $formatter->number($m->avgTicketUsd, 1) }}</td>
                        <td class="num">{{ $m->data->inventoryUnits === null ? '—' : $formatter->number($m->data->inventoryUnits) }}</td>
                        <td class="num">{{ $m->data->inventoryValueUsd === null ? '—' : $formatter->number($m->data->inventoryValueUsd, 0) }}</td>
                        <td class="num">{{ $formatter->number($m->transactionsPerShift, 0) }}</td>
                        <td class="num">{{ $m->data->shifts }}</td>
                    @endif
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="2">Total del mes (ponderado)</td>
                <td class="num">{{ $formatter->number($summary->sumsAll['salesBs'], 2) }}</td>
                <td class="num">{{ $formatter->number($summary->sumsAll['salesUsd'], 0) }}</td>
                <td class="num">{{ $formatter->number($summary->avgRate, 2) }}</td>
                <td class="num">{{ $summary->sumsAll['transactions'] }}</td>
                <td class="num">{{ $summary->sumsAll['units'] }}</td>
                <td class="num">{{ $formatter->number($summary->avgTicketBs, 0) }}</td>
                <td class="num">{{ $formatter->number($summary->unitsPerTransaction, 1) }}</td>
                <td class="num">{{ $formatter->number($summary->avgTicketUsd, 1) }}</td>
                <td class="num">{{ $formatter->number($summary->inventoryAvgUnits, 0) }}</td>
                <td class="num">{{ $formatter->number($summary->inventoryAvgValueUsd, 0) }}</td>
                <td class="num">{{ $formatter->number($summary->transactionsPerShift, 0) }}</td>
                <td class="num">{{ $summary->sumsAll['shifts'] }}</td>
            </tr>
        </tbody>
    </table>
    <p class="faint">* día atípico: cuenta en los totales, no en los promedios ni en la proyección. Los promedios son ponderados sobre el mes, no promedios de promedios.</p>

    @if ($goals !== null && $goals->hasAnyGoal())
        <h2>Metas del mes</h2>
        <table class="grid">
            <thead><tr><th>Indicador</th><th>Meta</th><th>Actual</th><th>Esperado a la fecha</th><th>Proyección de cierre</th><th>Estado</th></tr></thead>
            <tbody>
                @foreach ($goals->progress as $key => $progress)
                    @continue(! $progress->hasGoal())
                    @php $ind = $progress->indicator; @endphp
                    <tr>
                        <td>{{ $ind->label() }}</td>
                        <td class="num">{{ $fmt($ind, $progress->target, 0) }}</td>
                        <td class="num">{{ $fmt($ind, $progress->actual, 0) }} ({{ $progress->pctOfTarget() === null ? '—' : $formatter->pct($progress->pctOfTarget(), 0, false) }})</td>
                        <td class="num">{{ $fmt($ind, $progress->expected, 0) }}</td>
                        <td class="num">{{ $progress->projection === null ? '—' : $fmt($ind, $progress->projection, 0).' ('.$formatter->pct($progress->pctProjected(), 0, false).')' }}</td>
                        <td><span class="badge {{ $progress->status->tone() === 'success' ? 'ok' : ($progress->status->tone() === 'warning' ? 'warn' : ($progress->status->tone() === 'danger' ? 'bad' : '')) }}">{{ $progress->status->label() }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @php $notes = array_filter($view->rows, fn ($m) => $m->data->status !== \App\Enums\DayStatus::Normal && $m->data->notes); @endphp
    @if ($notes !== [])
        <h2>Observaciones</h2>
        <ul style="margin: 0; padding-left: 14pt;">
            @foreach ($notes as $m)
                <li><strong>{{ $formatter->date($m->data->date, 'weekday') }}</strong> ({{ $m->data->status === \App\Enums\DayStatus::Atypical ? 'atípico' : 'cerrado' }}): {{ $m->data->notes }}</li>
            @endforeach
        </ul>
    @endif
@endif
</body>
</html>
