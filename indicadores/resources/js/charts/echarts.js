/**
 * ECharts importado por módulos (§14.1). Solo lo que usan las once gráficas.
 */
import * as echarts from 'echarts/core';
import { BarChart, LineChart, HeatmapChart } from 'echarts/charts';
import {
    GridComponent,
    TooltipComponent,
    LegendComponent,
    DataZoomComponent,
    MarkLineComponent,
    MarkPointComponent,
    MarkAreaComponent,
    CalendarComponent,
    VisualMapComponent,
    AriaComponent,
} from 'echarts/components';
import { SVGRenderer } from 'echarts/renderers';
import theme from './theme';
import { fmt } from './formatters';

echarts.use([
    BarChart,
    LineChart,
    HeatmapChart,
    GridComponent,
    TooltipComponent,
    LegendComponent,
    DataZoomComponent,
    MarkLineComponent,
    MarkPointComponent,
    MarkAreaComponent,
    CalendarComponent,
    VisualMapComponent,
    AriaComponent,
    SVGRenderer,
]);

echarts.registerTheme('guadalupe', theme);

const formatAxis = (axis) => (value) =>
    axis.format === 'money' ? fmt.money(value, axis.currency, axis.precision ?? 0) : fmt.num(value, axis.precision ?? 0);

/**
 * Convierte una especificación de PHP (ChartSpec) en la opción final: los textos ya vienen
 * formateados en es-VE; aquí solo se enchufan las funciones que JSON no puede transportar.
 */
function toOption(spec) {
    const option = { ...(spec.option ?? {}) };
    const meta = spec.meta ?? {};
    const tooltips = meta.tooltips ?? [];

    option.tooltip = {
        ...(option.tooltip ?? {}),
        trigger: meta.trigger ?? 'axis',
        formatter: (params) => {
            const point = Array.isArray(params) ? params[0] : params;
            return tooltips[point?.dataIndex] ?? '';
        },
    };

    const axes = Array.isArray(option.yAxis) ? option.yAxis : option.yAxis ? [option.yAxis] : [];
    (meta.axes ?? []).forEach((axis, i) => {
        if (!axes[i]) return;
        axes[i] = { ...axes[i], axisLabel: { ...(axes[i].axisLabel ?? {}), formatter: formatAxis(axis) } };
    });
    if (axes.length) option.yAxis = axes;

    if (option.visualMap && meta.visualMap) {
        option.visualMap = { ...option.visualMap, formatter: formatAxis(meta.visualMap) };
    }

    if (meta.cellLabel === 'day' && Array.isArray(option.series)) {
        option.series = option.series.map((serie) =>
            serie.type === 'heatmap'
                ? { ...serie, label: { ...(serie.label ?? {}), formatter: (p) => String(Number(String(p.value[0]).slice(8, 10))) } }
                : serie,
        );
    }

    return { aria: { enabled: true, decal: { show: false } }, ...option };
}

/**
 * Crea una instancia con el tema de marca, la reajusta al contenedor y
 * devuelve helpers para Livewire/Alpine (§14.1).
 */
export function mountChart(el, spec) {
    const chart = echarts.init(el, 'guadalupe', { renderer: 'svg' });
    chart.setOption(toOption(spec), { notMerge: true });

    const observer = new ResizeObserver(() => chart.resize());
    observer.observe(el);

    return {
        chart,
        update(next) {
            chart.setOption(toOption(next), { notMerge: true });
        },
        /** PNG a 2× para el PDF (§11.2). */
        toPng() {
            return chart.getDataURL({ type: 'png', pixelRatio: 2, backgroundColor: '#fff' });
        },
        destroy() {
            observer.disconnect();
            chart.dispose();
        },
    };
}

export default echarts;
