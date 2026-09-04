<?php

declare(strict_types=1);

namespace App\Domain\Charts;

use App\Domain\Indicators\DailyMetrics;
use App\Domain\Indicators\Indicator;
use App\Domain\Indicators\Unit;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Enums\Currency;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Construye la opción de ECharts de cada gráfica del catálogo (§14) a partir de las filas del mes.
 * Puro: sin base de datos ni sesión. Todo texto visible sale ya formateado en es-VE (§6.6).
 * Con datos de varias sedes por fecha (consolidado) toma la primera fila; el consolidado por
 * gráfica llega con la Ampliación B.
 */
final class ChartSpecBuilder
{
    public const IDS = ['g1', 'g2', 'g3', 'g4', 'g5', 'g6', 'g7', 'g8', 'g9', 'g10'];

    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    private const ACCENT = '#6C4FD8';

    /** Colores de resources/js/charts/theme.js (§13.1). El morado queda reservado para las metas. */
    private const BRAND = '#1D6FE5';

    private const TEAL = '#0FA3A3';

    private const DANGER = '#D23F3F';

    private const SURFACE = '#FFFFFF';

    private const LINE = '#DDE4EC';

    private const PANEL = '#EEF3F9';

    private const INK400 = '#8A98A3';

    private const INK900 = '#14232F';

    /** Rampa del mapa de calor: de brand-50 a brand-800 con pasos intermedios para que el grueso de los días quede en medios tonos. */
    private const HEAT = ['#F2F7FE', '#D5E5FB', '#A9C9F6', '#7FB0F3', '#3D8AF0', '#1D6FE5', '#0F3F8F'];

    public function __construct(private readonly Formatter $formatter) {}

    /** @param  list<DailyMetrics>  $rows */
    public function build(string $id, array $rows, Period $period, ?ChartGoalContext $goal = null): ChartSpec
    {
        if (! in_array($id, self::IDS, true)) {
            throw new InvalidArgumentException("Gráfica desconocida: {$id}");
        }

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row->data->date->toDateString()] ??= $row;
        }

        [$title, $subtitle] = $this->heading($id, $period, $goal);
        $slug = $id.'-'.$period->key();

        if ($byDate === []) {
            return $this->emptySpec($id, $title, $subtitle, $slug, 'Aún no hay días cargados en '.mb_strtolower($period->label()).'.');
        }
        $cumulative = $goal?->cumulative;
        if ($id === 'g9' && $cumulative === null) {
            return $this->emptySpec($id, $title, $subtitle, $slug, 'Define una meta de venta en dólares para ver el acumulado frente a la meta.');
        }

        return match ($id) {
            'g1' => $this->cartesian($id, $title, $subtitle, $slug, $period, $byDate, [[Indicator::SalesBs, 'bar', 0]], $goal?->dailyExpected['sales_bs'] ?? null),
            'g2' => $this->cartesian($id, $title, $subtitle, $slug, $period, $byDate, [[Indicator::SalesUsd, 'bar', 0]], $goal?->dailyExpected['sales_usd'] ?? null),
            'g9' => $this->cumulative($id, $title, $subtitle, $slug, $period, $cumulative),
            'g3' => $this->cartesian($id, $title, $subtitle, $slug, $period, $byDate, [[Indicator::Transactions, 'bar', 0], [Indicator::Units, 'line', 1]]),
            'g4' => $this->cartesian($id, $title, $subtitle, $slug, $period, $byDate, [[Indicator::AvgTicketBs, 'bar', 0], [Indicator::UnitsPerTransaction, 'line', 1]]),
            'g5' => $this->cartesian($id, $title, $subtitle, $slug, $period, $byDate, [[Indicator::UnitsPerTransaction, 'line', 0], [Indicator::AvgTicketUsd, 'line', 1]]),
            'g6' => $this->cartesian($id, $title, $subtitle, $slug, $period, $byDate, [[Indicator::TransactionsPerShift, 'line', 0]]),
            'g7' => $this->cartesian($id, $title, $subtitle, $slug, $period, $byDate, [[Indicator::InventoryUnits, 'bar', 0], [Indicator::InventoryValueUsd, 'line', 1]]),
            'g10' => $this->cartesian($id, $title, $subtitle, $slug, $period, $byDate, [[Indicator::SalesUsd, 'bar', 0], [Indicator::AvgRate, 'line', 1]]),
            default => $this->heatmap($id, $title, $subtitle, $slug, $period, $byDate),
        };
    }

    /**
     * G11: comparativa interanual de un indicador, barras agrupadas por mes (§14).
     *
     * @param  array<int, BigDecimal|null>  $current  1..12 del año
     * @param  array<int, BigDecimal|null>  $previous  1..12 del año anterior
     */
    public function annualComparison(int $year, array $current, array $previous, Indicator $indicator): ChartSpec
    {
        $title = $indicator->label().' por mes';
        $subtitle = "{$year} frente a ".($year - 1);
        $slug = 'g11-'.$indicator->value.'-'.$year;

        $hasCurrent = array_filter($current, fn (?BigDecimal $v) => $v !== null) !== [];
        $hasPrevious = array_filter($previous, fn (?BigDecimal $v) => $v !== null) !== [];
        if (! $hasCurrent && ! $hasPrevious) {
            return $this->emptySpec('g11', $title, $subtitle, $slug, "Aún no hay meses cargados en {$year} ni en ".($year - 1).'.');
        }

        $format = fn (?BigDecimal $v): string => $this->format($indicator, $v);
        $dataCurrent = [];
        $dataPrevious = [];
        $tooltips = [];
        $rows = [];
        foreach (self::MONTHS as $i => $abbr) {
            $m = $i + 1;
            $dataCurrent[] = ($current[$m] ?? null)?->toFloat();
            $dataPrevious[] = ($previous[$m] ?? null)?->toFloat();
            $tooltips[] = $abbr.' · '.$year.': '.$format($current[$m] ?? null).' · '.($year - 1).': '.$format($previous[$m] ?? null);
            $rows[] = [$abbr, $format($current[$m] ?? null), $format($previous[$m] ?? null)];
        }

        $option = [
            'legend' => ['data' => [(string) $year, (string) ($year - 1)]],
            'grid' => ['left' => 8, 'right' => 8, 'top' => 40, 'bottom' => 8, 'containLabel' => true],
            'xAxis' => ['type' => 'category', 'data' => self::MONTHS],
            'yAxis' => [['type' => 'value']],
            'series' => [
                ['name' => (string) $year, 'type' => 'bar', 'data' => $dataCurrent, 'color' => self::BRAND, 'barGap' => '10%'],
                ['name' => (string) ($year - 1), 'type' => 'bar', 'data' => $dataPrevious, 'color' => self::INK400],
            ],
        ];

        return new ChartSpec('g11', $title, $subtitle, $slug, $option, ['tooltips' => $tooltips, 'axes' => [$this->axisMeta($indicator)], 'trigger' => 'axis'], ['head' => ['Mes', (string) $year, (string) ($year - 1)], 'rows' => $rows], false);
    }

    /**
     * Tasa BCV del mes (§9.4): línea con un punto por día, origen en el tooltip.
     *
     * @param  list<array{date: CarbonImmutable, rate: float|null, source: string|null}>  $points
     */
    public function rateHistory(array $points, Period $period): ChartSpec
    {
        $title = 'Tasa BCV del mes';
        $subtitle = $period->label().' · Bs por dólar';
        $slug = 'tasa-'.$period->key();

        $loaded = array_filter($points, fn (array $p) => $p['rate'] !== null);
        if ($loaded === []) {
            return new ChartSpec('rate', $title, $subtitle, $slug, [], ['tooltips' => [], 'axes' => [], 'trigger' => 'axis'], ['head' => [], 'rows' => []], true, 'Aún no hay tasas en '.mb_strtolower($period->label()).'.');
        }

        $categories = [];
        $data = [];
        $tooltips = [];
        $rows = [];
        foreach ($points as $p) {
            $categories[] = $this->formatter->weekday($p['date']).' '.$p['date']->day;
            $data[] = $p['rate'];
            $label = $this->formatter->date($p['date'], 'weekday');
            $value = $p['rate'] === null ? '—' : $this->formatter->number($p['rate'], 2);
            $origin = match ($p['source']) {
                'bcv' => 'BCV',
                'manual' => 'Manual',
                'carried' => 'Arrastrada',
                default => 'Sin tasa',
            };
            $tooltips[] = $label.' · '.$value.' Bs/$ · '.$origin;
            if ($p['rate'] !== null) {
                $rows[] = [$label, $value, $origin];
            }
        }

        $option = [
            'legend' => ['show' => false],
            'grid' => ['left' => 8, 'right' => 8, 'top' => 16, 'bottom' => 8, 'containLabel' => true],
            'xAxis' => ['type' => 'category', 'data' => $categories, 'axisLabel' => ['interval' => 'auto']],
            'yAxis' => [['type' => 'value', 'scale' => true]],
            'series' => [[
                'name' => 'Tasa BCV',
                'type' => 'line',
                'data' => $data,
                'color' => self::BRAND,
                'symbol' => 'circle',
                'symbolSize' => 5,
                'connectNulls' => true,
                'areaStyle' => ['color' => 'rgba(29,111,229,0.08)'],
            ]],
        ];

        return new ChartSpec('rate', $title, $subtitle, $slug, $option, ['tooltips' => $tooltips, 'axes' => [['format' => 'num', 'precision' => 2]], 'trigger' => 'axis'], ['head' => ['Día', 'Tasa', 'Origen'], 'rows' => $rows], false);
    }

    /** @return array{0: string, 1: string} título y subtítulo */
    private function heading(string $id, Period $period, ?ChartGoalContext $goal): array
    {
        $title = match ($id) {
            'g1' => 'Venta en bolívares por día',
            'g2' => 'Venta en dólares por día',
            'g3' => 'Transacciones y unidades',
            'g4' => 'Ticket promedio en Bs y unidades por compra',
            'g5' => 'Unidades por compra y ticket en dólares',
            'g6' => 'Transacciones por jornada',
            'g7' => 'Inventario: unidades y valuación',
            'g8' => 'Mapa de calor semanal',
            'g10' => 'Tasa BCV frente a la venta en dólares',
            default => 'Acumulado frente a la meta',
        };
        $suffix = match ($id) {
            'g2' => isset($goal?->dailyExpected['sales_usd']) ? ' · Meta diaria en morado' : ' · Sin meta definida',
            'g7' => ' · Los sábados no se cuenta',
            'g8' => ' · Venta en dólares por día de la semana',
            'g10' => ' · Venta en barras, tasa en línea',
            'g9' => ' · Venta en dólares'.(($goal?->cumulative['method'] ?? null) === 'weekday' ? ' · proyección por patrón semanal' : (($goal?->cumulative['method'] ?? null) === 'linear' ? ' · proyección lineal' : '')),
            default => '',
        };

        return [$title, $period->label().$suffix];
    }

    private function emptySpec(string $id, string $title, string $subtitle, string $slug, string $text): ChartSpec
    {
        return new ChartSpec(
            id: $id,
            title: $title,
            subtitle: $subtitle,
            slug: $slug,
            option: [],
            meta: ['tooltips' => [], 'axes' => [], 'trigger' => 'axis'],
            table: ['head' => [], 'rows' => []],
            empty: true,
            emptyText: $text,
        );
    }

    /**
     * G9: acumulado real (área), esperado a la fecha (línea morada) y proyección (línea punteada),
     * con la meta como línea horizontal (§14).
     *
     * @param  array{dates: list<string>, actual: list<float|null>, expected: list<float|null>, projected: list<float|null>, cutoffIndex: int, target: float, method: string}  $cumulative
     */
    private function cumulative(string $id, string $title, string $subtitle, string $slug, Period $period, array $cumulative): ChartSpec
    {
        $categories = [];
        $tooltips = [];
        $rows = [];
        $usd = Indicator::SalesUsd;

        foreach ($period->dates() as $i => $date) {
            $categories[] = $this->formatter->weekday($date).' '.$date->day;
            $dayLabel = $this->formatter->date($date, 'weekday');
            $actual = $cumulative['actual'][$i] ?? null;
            $expected = $cumulative['expected'][$i] ?? null;
            $projected = $cumulative['projected'][$i] ?? null;

            $parts = [$dayLabel];
            if ($actual !== null) {
                $parts[] = 'acumulado '.$usd->format($this->formatter, (string) $actual);
            }
            if ($expected !== null) {
                $parts[] = 'esperado '.$usd->format($this->formatter, (string) $expected);
            }
            if ($projected !== null && $i > $cumulative['cutoffIndex']) {
                $parts[] = 'proyección '.$usd->format($this->formatter, (string) $projected);
            }
            $tooltips[] = implode(' · ', $parts);
            $rows[] = [
                $dayLabel,
                $actual === null ? '—' : $usd->format($this->formatter, (string) $actual),
                $expected === null ? '—' : $usd->format($this->formatter, (string) $expected),
                $projected === null || $i <= $cumulative['cutoffIndex'] ? '—' : $usd->format($this->formatter, (string) $projected),
            ];
        }

        $targetLabel = 'Meta '.$usd->format($this->formatter, (string) $cumulative['target']);
        $cutoff = $cumulative['cutoffIndex'];
        // La marca "Hoy" solo tiene sentido con el mes en curso; en un mes completo se solaparía con la meta.
        $expectedAtCutoff = $cutoff < count($cumulative['dates']) - 1 ? ($cumulative['expected'][$cutoff] ?? null) : null;

        $option = [
            'legend' => ['data' => ['Acumulado', 'Esperado', 'Proyección']],
            'grid' => ['left' => 8, 'right' => 8, 'top' => 40, 'bottom' => 8, 'containLabel' => true],
            'xAxis' => ['type' => 'category', 'data' => $categories, 'axisLabel' => ['interval' => 'auto'], 'boundaryGap' => false],
            'yAxis' => [['type' => 'value', 'max' => max($cumulative['target'], ...array_filter([...$cumulative['actual'], ...$cumulative['projected']], fn ($v) => $v !== null) ?: [0]) * 1.05]],
            'series' => [
                [
                    'name' => 'Acumulado',
                    'type' => 'line',
                    'data' => $cumulative['actual'],
                    'color' => self::BRAND,
                    'symbol' => 'none',
                    'lineStyle' => ['width' => 2.5],
                    'areaStyle' => ['color' => self::BRAND, 'opacity' => 0.12],
                    'connectNulls' => false,
                    'markLine' => [
                        'symbol' => 'none',
                        'lineStyle' => ['color' => self::ACCENT, 'type' => 'dashed', 'width' => 1.5],
                        'label' => ['formatter' => $targetLabel, 'position' => 'insideEndTop', 'color' => self::ACCENT],
                        'data' => [['yAxis' => $cumulative['target']]],
                    ],
                    'markPoint' => $expectedAtCutoff === null ? null : [
                        'symbol' => 'circle',
                        'symbolSize' => 10,
                        'itemStyle' => ['color' => self::SURFACE, 'borderColor' => self::ACCENT, 'borderWidth' => 2],
                        'label' => ['show' => true, 'formatter' => 'Hoy', 'position' => 'top', 'color' => self::ACCENT, 'fontSize' => 11],
                        'data' => [['coord' => [$cutoff, $expectedAtCutoff]]],
                    ],
                ],
                [
                    'name' => 'Esperado',
                    'type' => 'line',
                    'data' => $cumulative['expected'],
                    'color' => self::ACCENT,
                    'symbol' => 'none',
                    'lineStyle' => ['width' => 1.5, 'type' => 'dashed'],
                ],
                [
                    'name' => 'Proyección',
                    'type' => 'line',
                    'data' => $cumulative['projected'],
                    'color' => self::INK400,
                    'symbol' => 'none',
                    'lineStyle' => ['width' => 2, 'type' => 'dotted'],
                    'connectNulls' => false,
                ],
            ],
        ];
        if ($option['series'][0]['markPoint'] === null) {
            unset($option['series'][0]['markPoint']);
        }

        return new ChartSpec(
            id: $id,
            title: $title,
            subtitle: $subtitle,
            slug: $slug,
            option: $option,
            meta: ['tooltips' => $tooltips, 'axes' => [['format' => 'money', 'currency' => 'USD', 'precision' => 0]], 'trigger' => 'axis'],
            table: ['head' => ['Día', 'Acumulado', 'Esperado', 'Proyección'], 'rows' => $rows],
            empty: false,
        );
    }

    /**
     * Gráfica cartesiana de barras/líneas sobre el eje de fechas del mes (G1–G7).
     *
     * @param  array<string, DailyMetrics>  $byDate
     * @param  list<array{0: Indicator, 1: string, 2: int}>  $series  indicador, tipo, índice de eje Y
     * @param  list<float|null>|null  $dailyGoal  meta diaria esperada para la primera serie (G1/G2)
     */
    private function cartesian(string $id, string $title, string $subtitle, string $slug, Period $period, array $byDate, array $series, ?array $dailyGoal = null): ChartSpec
    {
        $categories = [];
        $tooltips = [];
        $rows = [];
        $closedAreas = [];
        $atypicalPoints = [];
        $data = array_fill(0, count($series), []);

        foreach ($period->dates() as $i => $date) {
            $metrics = $byDate[$date->toDateString()] ?? null;
            $dayLabel = $this->formatter->date($date, 'weekday');
            $categories[] = $this->formatter->weekday($date).' '.$date->day;
            $row = [$dayLabel];
            $parts = [$dayLabel];

            foreach ($series as $s => [$indicator]) {
                $value = $metrics === null || $metrics->data->isClosed() ? null : $metrics->value($indicator);
                $data[$s][] = $value?->toFloat();
                $row[] = $metrics === null ? '—' : $this->format($indicator, $value);
                if ($metrics !== null && ! $metrics->data->isClosed()) {
                    $parts[] = $this->describe($indicator, $value);
                }
            }

            if ($metrics === null) {
                $parts[] = 'Sin dato';
            } elseif ($metrics->data->isClosed()) {
                $parts[] = 'Cerrado'.($metrics->data->notes !== null ? ': '.$metrics->data->notes : '');
                $closedAreas[] = [['xAxis' => $i], ['xAxis' => $i]];
            } elseif ($metrics->data->isAtypical()) {
                $parts[] = 'Atípico'.($metrics->data->notes !== null ? ': '.$metrics->data->notes : '');
                if ($data[0][$i] !== null) {
                    $atypicalPoints[] = ['coord' => [$i, $data[0][$i]]];
                }
            }

            $tooltips[] = implode(' · ', $parts);
            if ($metrics !== null) {
                $rows[] = $row;
            }
        }

        $seriesOption = [];
        $labels = [];
        $usesSecondAxis = false;
        foreach ($series as $s => [$indicator, $type, $axis]) {
            $labels[] = $indicator->label();
            $usesSecondAxis = $usesSecondAxis || $axis === 1;
            $entry = [
                'name' => $indicator->label(),
                'type' => $type,
                'data' => $data[$s],
                'yAxisIndex' => $axis,
                'color' => $s === 0 ? self::BRAND : self::TEAL,
            ];
            if ($type === 'line') {
                $entry += ['symbol' => 'circle', 'symbolSize' => $id === 'g6' ? 6 : 4, 'connectNulls' => false];
            }
            if ($s === 0 && $atypicalPoints !== []) {
                $entry['markPoint'] = [
                    'symbol' => 'circle',
                    'symbolSize' => 12,
                    'itemStyle' => ['color' => self::SURFACE, 'borderColor' => self::DANGER, 'borderWidth' => 2],
                    'label' => ['show' => false],
                    'data' => $atypicalPoints,
                ];
            }
            if ($s === 0 && $closedAreas !== []) {
                $entry['markArea'] = ['silent' => true, 'itemStyle' => ['color' => self::PANEL], 'data' => $closedAreas];
            }
            $seriesOption[] = $entry;
        }

        // Meta diaria esperada (§14 G2): línea morada discontinua, sin símbolos; entra en la leyenda.
        if ($dailyGoal !== null) {
            $labels[] = 'Meta diaria';
            $seriesOption[] = [
                'name' => 'Meta diaria',
                'type' => 'line',
                'data' => $dailyGoal,
                'yAxisIndex' => 0,
                'color' => self::ACCENT,
                'symbol' => 'none',
                'lineStyle' => ['type' => 'dashed', 'width' => 1.5],
                'connectNulls' => true,
            ];
            foreach ($tooltips as $i => $text) {
                if (($dailyGoal[$i] ?? null) !== null) {
                    $tooltips[$i] = $text.' · meta '.$series[0][0]->format($this->formatter, (string) $dailyGoal[$i]);
                }
            }
        }

        $yAxis = [['type' => 'value']];
        $axesMeta = [$this->axisMeta($series[0][0])];
        if ($usesSecondAxis) {
            $yAxis[] = ['type' => 'value', 'position' => 'right', 'splitLine' => ['show' => false]];
            $axesMeta[] = $this->axisMeta($series[1][0]);
        }

        $option = [
            'legend' => count($labels) > 1 ? ['data' => $labels] : ['show' => false],
            'grid' => ['left' => 8, 'right' => 8, 'top' => count($labels) > 1 ? 40 : 16, 'bottom' => 8, 'containLabel' => true],
            'xAxis' => ['type' => 'category', 'data' => $categories, 'axisLabel' => ['interval' => 'auto']],
            'yAxis' => $yAxis,
            'series' => $seriesOption,
        ];

        return new ChartSpec(
            id: $id,
            title: $title,
            subtitle: $subtitle,
            slug: $slug,
            option: $option,
            meta: ['tooltips' => $tooltips, 'axes' => $axesMeta, 'trigger' => 'axis'],
            table: ['head' => ['Día', ...array_map(fn (array $s) => $s[0]->label(), $series)], 'rows' => $rows],
            empty: false,
        );
    }

    /**
     * G8: mapa de calor del mes sobre un calendario (filas = semanas, columnas = lun…dom).
     *
     * @param  array<string, DailyMetrics>  $byDate
     */
    private function heatmap(string $id, string $title, string $subtitle, string $slug, Period $period, array $byDate): ChartSpec
    {
        $values = [];
        foreach ($period->dates() as $date) {
            $metrics = $byDate[$date->toDateString()] ?? null;
            if ($metrics === null || $metrics->data->isClosed() || $metrics->salesUsd === null) {
                continue;
            }
            $values[$date->toDateString()] = $metrics;
        }

        $floats = array_map(fn (DailyMetrics $m) => (float) $m->salesUsd?->toFloat(), $values);
        $min = $floats === [] ? 0.0 : floor(min($floats));
        $max = $floats === [] ? 1.0 : ceil(max($floats));
        if ($max <= $min) {
            $max = $min + 1;
        }
        $darkFrom = $min + ($max - $min) * 0.55;

        $data = [];
        $tooltips = [];
        $rows = [];
        foreach ($values as $key => $metrics) {
            $usd = (float) $metrics->salesUsd?->toFloat();
            $data[] = [
                'value' => [$key, round($usd, 2)],
                'label' => ['color' => $usd >= $darkFrom ? self::SURFACE : self::INK900],
            ];
            $dayLabel = $this->formatter->date($metrics->data->date, 'weekday');
            $parts = [$dayLabel, $this->describe(Indicator::SalesUsd, $metrics->salesUsd), $this->describe(Indicator::Transactions, BigDecimal::of($metrics->data->transactions))];
            if ($metrics->data->isAtypical()) {
                $parts[] = 'Atípico'.($metrics->data->notes !== null ? ': '.$metrics->data->notes : '');
            }
            $tooltips[] = implode(' · ', $parts);
            $rows[] = [$dayLabel, $this->format(Indicator::SalesUsd, $metrics->salesUsd), $this->format(Indicator::Transactions, BigDecimal::of($metrics->data->transactions))];
        }

        $option = [
            'calendar' => [
                'range' => $period->key(),
                'orient' => 'vertical',
                'left' => 40,
                'right' => 8,
                'top' => 28,
                'bottom' => 48,
                'cellSize' => ['auto', 'auto'],
                'dayLabel' => ['firstDay' => 1, 'nameMap' => ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'], 'color' => self::INK400, 'margin' => 8],
                'monthLabel' => ['show' => false],
                'yearLabel' => ['show' => false],
                'itemStyle' => ['color' => self::SURFACE, 'borderColor' => self::LINE, 'borderWidth' => 1],
                'splitLine' => ['show' => false],
            ],
            'visualMap' => [
                'min' => $min,
                'max' => $max,
                'calculable' => false,
                'orient' => 'horizontal',
                'left' => 'center',
                'bottom' => 0,
                'itemWidth' => 10,
                'itemHeight' => 140,
                'inRange' => ['color' => self::HEAT],
                'text' => ['Más venta', 'Menos'],
            ],
            'series' => [[
                'type' => 'heatmap',
                'coordinateSystem' => 'calendar',
                'data' => $data,
                'label' => ['show' => true, 'fontSize' => 11],
                'emphasis' => ['itemStyle' => ['borderColor' => self::INK900, 'borderWidth' => 1]],
            ]],
        ];

        return new ChartSpec(
            id: $id,
            title: $title,
            subtitle: $subtitle,
            slug: $slug,
            option: $option,
            meta: [
                'tooltips' => $tooltips,
                'axes' => [],
                'trigger' => 'item',
                'cellLabel' => 'day',
                'visualMap' => ['format' => 'money', 'currency' => 'USD', 'precision' => 0],
            ],
            table: ['head' => ['Día', Indicator::SalesUsd->label(), Indicator::Transactions->label()], 'rows' => $rows],
            empty: false,
        );
    }

    /** @return array{format: string, currency?: string, precision: int} */
    private function axisMeta(Indicator $indicator): array
    {
        return match ($indicator->unit()) {
            Unit::Bs => ['format' => 'money', 'currency' => 'BS', 'precision' => 0],
            Unit::Usd => ['format' => 'money', 'currency' => 'USD', 'precision' => $indicator->precision()],
            default => ['format' => 'num', 'precision' => $indicator->precision()],
        };
    }

    /** Valor formateado como en la tabla del mes (§2.2). */
    private function format(Indicator $indicator, ?BigDecimal $value): string
    {
        return match ($indicator->unit()) {
            Unit::Bs => $this->formatter->money($value, Currency::Bs, $indicator->precision()),
            Unit::Usd => $this->formatter->money($value, Currency::Usd, $indicator->precision()),
            default => $this->formatter->number($value, $indicator->precision()),
        };
    }

    /** Fragmento del tooltip: "$ 854", "161 transacciones", "ticket Bs 5.300" (§14). */
    private function describe(Indicator $indicator, ?BigDecimal $value): string
    {
        $text = $this->format($indicator, $value);

        return match ($indicator) {
            Indicator::SalesBs, Indicator::SalesUsd => $text,
            Indicator::Transactions => $text.' transacciones',
            Indicator::Units => $text.' unidades',
            Indicator::Shifts => $text.' jornadas',
            Indicator::AvgTicketBs, Indicator::AvgTicketUsd => 'ticket '.$text,
            Indicator::UnitsPerTransaction => $text.' und. por compra',
            Indicator::TransactionsPerShift => $text.' trn. por jornada',
            Indicator::InventoryUnits => $text.' und. en inventario',
            Indicator::InventoryValueUsd => 'valuación '.$text,
            Indicator::SalesPerShiftUsd => $text.' por jornada',
            Indicator::AvgRate => 'tasa '.$text,
            Indicator::RateVariationPct => 'variación '.$text,
        };
    }
}
