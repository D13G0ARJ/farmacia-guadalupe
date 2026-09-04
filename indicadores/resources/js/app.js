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

/**
 * Reporte PDF (§11.2): manda las gráficas en pantalla como PNG y luego pide el PDF.
 * Si algo falla al enviar, el PDF se descarga igual sin gráficas: nunca bloquea.
 */
window.downloadReport = async (imagesUrl, pdfUrl) => {
    const images = {};
    for (const [id, handle] of Object.entries(window.__charts || {})) {
        try {
            images[id] = await handle.toPng();
        } catch {
            // Una gráfica que no se pueda exportar no impide el reporte.
        }
    }
    try {
        await fetch(imagesUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
            },
            body: JSON.stringify({ images }),
        });
    } catch {
        // Sin conexión o bloqueado: se sigue con la descarga.
    }
    window.location.href = pdfUrl;
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('dailyPreview', dailyPreview);
    window.Alpine.data('chartPanel', chartPanel);
    window.Alpine.data('goalGrid', goalGrid);
});

// Los toasts emitidos desde PHP con $this->dispatch('toast', ...) llegan como evento de navegador
// `toast` en window (Livewire 3): el layout los escucha con x-on:toast.window. No hace falta puente.
