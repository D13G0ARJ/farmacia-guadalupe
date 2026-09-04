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
        /**
         * PNG a 2× para la descarga (§14) y el PDF (§11.2). El lienzo es SVG, así que se
         * rasteriza en un canvas con fondo blanco; el resultado es siempre `data:image/png`.
         */
        async toPng() {
            const svg = chart.getDataURL({ type: 'svg' });
            const image = new Image();
            await new Promise((resolve, reject) => {
                image.onload = resolve;
                image.onerror = () => reject(new Error('No se pudo rasterizar la gráfica.'));
                image.src = svg;
            });
            const canvas = document.createElement('canvas');
            canvas.width = chart.getWidth() * 2;
            canvas.height = chart.getHeight() * 2;
            const context = canvas.getContext('2d');
            context.fillStyle = '#fff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.drawImage(image, 0, 0, canvas.width, canvas.height);

            return canvas.toDataURL('image/png');
        },
        destroy() {
            observer.disconnect();
            chart.dispose();
        },
    };
}

export default echarts;
