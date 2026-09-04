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

/** Aviso al usuario desde JavaScript, con el mismo toast del layout. */
const toast = (detail) => window.dispatchEvent(new CustomEvent('toast', { detail }));

const isTyping = (target) =>
    target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

/**
 * Atajos de teclado (§13.8): Alt+N carga el día, "?" abre la ayuda de la pantalla.
 * Enter y Esc los resuelven los formularios y los diálogos.
 */
document.addEventListener('keydown', (event) => {
    if (event.altKey && ! event.ctrlKey && ! event.metaKey && event.code === 'KeyN') {
        const url = document.body.dataset.shortcutNew;
        if (url) {
            event.preventDefault();
            window.Livewire?.navigate ? window.Livewire.navigate(url) : (window.location.href = url);
        }
        return;
    }
    if (event.key === '?' && ! event.altKey && ! event.ctrlKey && ! event.metaKey && ! isTyping(event.target)) {
        event.preventDefault();
        window.dispatchEvent(new CustomEvent('toggle-help'));
    }
});

/**
 * Sesión vencida y red caída (§13.8, §13.6): mensaje amable en lugar de la pantalla de error.
 * Lo escrito en el formulario sigue en el navegador (borrador por fecha).
 */
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 419 || status === 401) {
                preventDefault();
                toast({ type: 'warning', message: 'Tu sesión venció. Vuelve a entrar; lo que escribiste sigue guardado en este navegador.' });
                setTimeout(() => window.location.reload(), 2500);
                return;
            }
            if (status === 0 || status >= 502) {
                preventDefault();
                toast({ type: 'danger', message: 'No se pudo guardar. Tus datos siguen aquí; intenta de nuevo.' });
            }
        });
    });
});

// Los toasts emitidos desde PHP con $this->dispatch('toast', ...) llegan como evento de navegador
// `toast` en window (Livewire 3): el layout los escucha con x-on:toast.window. No hace falta puente.
