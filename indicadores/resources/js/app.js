// Livewire 4 registra Alpine por su cuenta. Aquí se exponen el formato es-VE (§6.6), la vista
// previa del formulario diario (RN-26) y la carga bajo demanda de ECharts (§14.1): el formulario
// no debe pagar el peso de las gráficas.
import { fmt } from './charts/formatters';
import dailyPreview from './daily-preview';
import chartPanel from './charts/panel';
import goalGrid from './goal-grid';

window.fmt = fmt;

let chartsModule = null;

/** Carga ECharts solo cuando una pantalla lo pide. Devuelve { echarts, mountChart }. */
window.loadCharts = async () => {
    chartsModule ??= await import('./charts/echarts');

    return { echarts: chartsModule.default, mountChart: chartsModule.mountChart };
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('dailyPreview', dailyPreview);
    window.Alpine.data('chartPanel', chartPanel);
    window.Alpine.data('goalGrid', goalGrid);
});

// Los toasts emitidos desde PHP con $this->dispatch('toast', ...) llegan como evento de navegador
// `toast` en window (Livewire 3): el layout los escucha con x-on:toast.window. No hace falta puente.
