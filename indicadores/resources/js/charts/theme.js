/**
 * Tema ECharts "guadalupe" (§13.1, §14.1): azul = datos, morado = metas.
 * Los valores duplican los tokens de Tailwind; si cambia la marca, cambian aquí y en app.css.
 */
export const tokens = {
    brand600: '#1D6FE5',
    brand800: '#0F3F8F',
    brand100: '#E4EEFC',
    brand50: '#F2F7FE',
    accent600: '#6C4FD8',
    teal: '#0FA3A3',
    warning600: '#D98A0B',
    danger600: '#D23F3F',
    success600: '#1E8E5A',
    ink900: '#14232F',
    ink600: '#4B5B67',
    ink400: '#8A98A3',
    line: '#DDE4EC',
    surface: '#FFFFFF',
};

export const seriesPalette = [tokens.brand600, tokens.accent600, tokens.teal, tokens.warning600, tokens.ink400];

export const fontFamily = "'IBM Plex Sans', system-ui, -apple-system, 'Segoe UI', sans-serif";

export const theme = {
    color: seriesPalette,
    backgroundColor: 'transparent',
    textStyle: { fontFamily, color: tokens.ink600, fontSize: 12 },
    animationDuration: 200,
    animationDurationUpdate: 200,
    animationEasing: 'cubicOut',

    grid: { left: 8, right: 8, top: 24, bottom: 8, containLabel: true },

    categoryAxis: {
        axisLine: { show: true, lineStyle: { color: tokens.line } },
        axisTick: { show: false },
        axisLabel: { color: tokens.ink400, fontFamily, fontSize: 11, margin: 10 },
        splitLine: { show: false },
    },
    valueAxis: {
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: tokens.ink400, fontFamily, fontSize: 11 },
        splitLine: { show: true, lineStyle: { color: tokens.line, type: [4, 4] } },
    },

    legend: {
        top: 0,
        left: 0,
        icon: 'roundRect',
        itemWidth: 10,
        itemHeight: 10,
        itemGap: 16,
        textStyle: { color: tokens.ink600, fontFamily, fontSize: 12 },
    },

    tooltip: {
        trigger: 'axis',
        axisPointer: { type: 'line', lineStyle: { color: tokens.ink400, width: 1 } },
        backgroundColor: tokens.surface,
        borderColor: tokens.line,
        borderWidth: 1,
        padding: [8, 12],
        textStyle: { color: tokens.ink900, fontFamily, fontSize: 12 },
        extraCssText: 'box-shadow: 0 8px 24px rgba(20,35,47,.12); border-radius: 8px;',
    },

    bar: { barMaxWidth: 28, itemStyle: { borderRadius: [3, 3, 0, 0] } },
    line: { smooth: false, symbol: 'circle', symbolSize: 5, lineStyle: { width: 2 }, connectNulls: false },

    // Marca de meta (§13.1): siempre en morado.
    markLine: { lineStyle: { color: tokens.accent600, type: 'dashed', width: 1.5 }, symbol: 'none', label: { color: tokens.accent600, fontFamily } },

    visualMap: {
        inRange: { color: [tokens.brand50, tokens.brand100, tokens.brand600, tokens.brand800] },
        textStyle: { color: tokens.ink600, fontFamily },
    },
};

export default theme;
