/**
 * Recorridos guiados (§13.5) con driver.js: cada pantalla explica, botón por botón, qué hace cada cosa,
 * y lo muestra con datos de ejemplo (demos.js). Los pasos vienen de App\Support\GuidedTours (PHP, ya
 * filtrados por rol) en el JSON #guided-tour-data. Aquí se resuelven los `data-tour`, se abren pestañas o
 * diálogos cuando un paso lo pide, y se recuerda por usuario qué recorridos ya vio (localStorage).
 */
import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';
import { runDemo } from './demos';

const STORAGE = 'tours-seen';
let active = null;

const data = () => {
    const node = document.getElementById('guided-tour-data');
    if (! node) return null;
    try {
        return JSON.parse(node.textContent);
    } catch {
        return null;
    }
};

const isVisible = (el) => !! el && (el.offsetParent !== null || el.getClientRects().length > 0);

/** Primer `data-tour` visible con esa clave (el menú se repite en móvil y escritorio). */
const resolve = (key) => {
    if (! key) return null;
    return [...document.querySelectorAll(`[data-tour="${key}"]`)].find(isVisible) ?? null;
};

const waitFor = (key, ms) =>
    new Promise((done) => {
        const started = Date.now();
        const tick = () => {
            if (resolve(key) || Date.now() - started > ms) return done();
            setTimeout(tick, 80);
        };
        tick();
    });

/** Cierra un diálogo abierto por un paso: su botón "Cancelar" (todos los diálogos lo tienen primero). */
const closeDialog = (key) => {
    const el = resolve(key);
    const cancel = el && [...el.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Cancelar');
    cancel?.click();
};

const seenStore = () => {
    try {
        return JSON.parse(window.localStorage.getItem(STORAGE) ?? '{}');
    } catch {
        return {};
    }
};

const markSeen = (userId, route) => {
    try {
        const all = seenStore();
        all[userId] = { ...(all[userId] ?? {}), [route]: Date.now() };
        window.localStorage.setItem(STORAGE, JSON.stringify(all));
    } catch {
        // Sin almacenamiento: el recorrido simplemente vuelve a ofrecerse.
    }
    window.dispatchEvent(new CustomEvent('tour-seen'));
};

const seenRoutes = (userId) => Object.keys(seenStore()[userId] ?? {});

/** Convierte las definiciones PHP en pasos de driver.js. */
const toSteps = (defs) =>
    defs.map((def) => {
        const step = {
            popover: {
                title: def.title,
                description: def.description,
                side: def.side ?? 'bottom',
                align: def.align ?? 'start',
            },
            data: def,
        };
        if (def.element) step.element = () => resolve(def.element) ?? undefined;
        return step;
    });

function start(defs, info, key) {
    if (! defs?.length) return null;
    active?.destroy();
    const { userId, route, sampleUrl } = info;
    const cleanup = key ? [] : (info.cleanup ?? []);
    const context = { sampleUrl };
    let cleaned = false;

    /** Antes de abandonar el paso actual: deshacer su demostración y cerrar lo que abrió. */
    const leave = async (current) => {
        if (current?.undo) await runDemo(current.undo, context);
        if (current?.close) closeDialog(current.element);
    };

    /** Antes de entrar a un paso: pulsar lo que haga falta, correr su demostración y esperar el elemento. */
    const enter = async (next, withDemo) => {
        if (! next) return;
        if (next.click) resolve(next.click)?.click();
        if (withDemo && next.demo) await runDemo(next.demo, context);
        if (next.click || next.demo) await waitFor(next.element, next.wait ?? 1200);
    };

    const finish = async () => {
        if (cleaned) return;
        cleaned = true;
        for (const name of cleanup) await runDemo(name, context);
    };

    const tour = driver({
        animate: true,
        overlayOpacity: 0.55,
        stagePadding: 6,
        stageRadius: 8,
        smoothScroll: true,
        allowKeyboardControl: true,
        skipMissingElement: true,
        popoverClass: 'tour-guadalupe',
        showProgress: true,
        progressText: '{{current}} de {{total}}',
        nextBtnText: 'Siguiente',
        prevBtnText: 'Anterior',
        doneBtnText: 'Listo',
        steps: toSteps(defs),
        onNextClick: async () => {
            const index = tour.getActiveIndex() ?? 0;
            await leave(defs[index]);
            await enter(defs[index + 1], true);
            tour.moveNext();
        },
        onPrevClick: async () => {
            const index = tour.getActiveIndex() ?? 0;
            await leave(defs[index]);
            await enter(defs[index - 1], false);
            tour.movePrevious();
        },
        onDestroyStarted: async () => {
            const current = defs[tour.getActiveIndex() ?? 0];
            // Se marca como visto antes de la animación de cierre: una recarga inmediata no lo pierde.
            markSeen(userId, key ?? route);
            tour.destroy();
            await leave(current);
            await finish();
        },
        onDestroyed: () => {
            active = null;
        },
    });

    active = tour;
    (async () => {
        await enter(defs[0], true);
        tour.drive();
    })();

    return tour;
}

const shouldAutoStart = (info) => {
    if (window.innerWidth < 768) return false;
    if (new URLSearchParams(window.location.search).get('recorrido') === '1') return true;
    return ! seenRoutes(info.userId).includes(info.route);
};

function boot() {
    const info = data();
    if (! info) return;
    if (info.steps?.length && shouldAutoStart(info)) {
        setTimeout(() => {
            if (! active && data()?.route === info.route) start(info.steps, info);
        }, 700);
    }
}

window.guidedTour = {
    /** Recorrido de la pantalla actual, con sus demostraciones. */
    startScreen() {
        const info = data();
        return info ? start(info.steps, info) : null;
    },
    /** Recorrido general: menú y barra superior. */
    startShell() {
        const info = data();
        return info ? start(info.shell, info, 'shell') : null;
    },
    seenRoutes() {
        const info = data();
        return info ? seenRoutes(info.userId) : [];
    },
    stop() {
        active?.destroy();
    },
};

document.addEventListener('DOMContentLoaded', boot);
document.addEventListener('livewire:navigated', boot);
document.addEventListener('livewire:navigate', () => active?.destroy());
