/**
 * Demostraciones de los recorridos guiados (§13.5): acciones con datos de ejemplo que el recorrido
 * ejecuta en la pantalla real para que se vea cómo se usa cada cosa. Ninguna guarda nada en el
 * servidor: escriben en campos, abren diálogos o analizan un archivo de muestra, y la acción de
 * limpieza del recorrido deja todo como estaba.
 */
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const isVisible = (el) => !! el && (el.offsetParent !== null || el.getClientRects().length > 0);

const anchor = (key) => [...document.querySelectorAll(`[data-tour="${key}"]`)].find(isVisible) ?? null;

const waitUntil = async (test, ms = 3000) => {
    const started = Date.now();
    while (Date.now() - started < ms) {
        const value = test();
        if (value) return value;
        await sleep(100);
    }
    return null;
};

/** Escribe como lo haría una persona: el campo recibe input y change, así Livewire y Alpine se enteran. */
const type = async (el, text) => {
    if (! el) return;
    el.focus();
    el.value = text;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    await sleep(150);
};

const check = async (el, checked) => {
    if (! el || el.checked === checked) return;
    el.click();
    await sleep(300);
};

const cancelOpenDialog = async () => {
    const dialog = [...document.querySelectorAll('[role="dialog"]')].find(isVisible);
    const cancel = dialog && [...dialog.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Cancelar');
    cancel?.click();
    await sleep(300);
};

const alpineData = (el) => (el && window.Alpine ? window.Alpine.$data(el) : null);

let profileName = null;

export const demos = {
    // ---- Panel ----
    'dash.more': async () => {
        const details = anchor('dash-more');
        if (details && ! details.open) details.open = true;
    },
    'dash.help': async () => {
        anchor('kpi-help')?.click();
        await sleep(200);
    },
    'dash.help.close': async () => {
        const el = anchor('kpi-help');
        if (el?.getAttribute('aria-expanded') === 'true') el.click();
    },

    // ---- Cargar día ----
    'form.sales': async () => type(document.getElementById('sales_bs'), '95480,50'),
    'form.transactions': async () => type(document.getElementById('transactions'), '131'),
    'form.units': async () => type(document.getElementById('units'), '287'),
    'form.inventory': async () => {
        await type(document.getElementById('inventory_units'), '9850');
        await type(document.getElementById('inventory_value_usd'), '22410,00');
    },
    'form.notes': async () => type(document.getElementById('notes'), 'Ejemplo del recorrido: día normal.'),
    'form.clear': async () => {
        const root = document.querySelector('[x-data^="dailyPreview"]');
        alpineData(root)?.discardDraft();
        await type(document.getElementById('notes'), '');
        await type(document.getElementById('inventory_units'), '');
        await type(document.getElementById('inventory_value_usd'), '');
    },

    // ---- Mes ----
    'month.search': async () => type(anchor('month-search')?.querySelector('input'), '16'),
    'month.search.clear': async () => type(anchor('month-search')?.querySelector('input'), ''),
    'month.exclude': async () => check(anchor('month-exclude')?.querySelector('input'), true),
    'month.exclude.off': async () => check(anchor('month-exclude')?.querySelector('input'), false),

    // ---- Gráficas ----
    'chart.data': async () => {
        const btn = anchor('chart-data');
        if (btn?.getAttribute('aria-pressed') !== 'true') btn?.click();
        await sleep(200);
    },
    'chart.data.close': async () => {
        const btn = anchor('chart-data');
        if (btn?.getAttribute('aria-pressed') === 'true') btn.click();
    },
    'charts.indicator': async () => type(anchor('charts-annual-indicator')?.querySelector('select'), 'transactions'),
    'charts.ventas': async () => {
        anchor('charts-tab-ventas')?.click();
        await sleep(400);
    },

    // ---- Metas ----
    'goals.year': async () => {
        const buttons = anchor('goals-view')?.querySelectorAll('button') ?? [];
        buttons[1]?.click();
        await waitUntil(() => anchor('goals-grid'), 4000);
    },
    'goals.type': async () => type(anchor('goals-grid')?.querySelector('input'), '20.000'),
    'goals.reset': async () => {
        await type(anchor('goals-grid')?.querySelector('input'), '');
        const buttons = anchor('goals-view')?.querySelectorAll('button') ?? [];
        buttons[0]?.click();
        await sleep(400);
    },

    // ---- Tasa BCV ----
    'rates.edit': async () => {
        anchor('rates-edit')?.click();
        const input = await waitUntil(() => anchor('rates-table')?.querySelector('input'), 4000);
        await type(input, '812,50');
    },
    'rates.cancel': async () => {
        const cancel = [...(anchor('rates-table')?.querySelectorAll('button') ?? [])].find((b) => b.textContent.trim() === 'Cancelar');
        cancel?.click();
        await sleep(300);
    },
    'rates.backfill.date': async () => type(document.getElementById('backfill-from'), '2025-01-01'),
    'dialog.cancel': cancelOpenDialog,

    // ---- Importar ----
    'import.sample': async ({ sampleUrl }) => {
        const input = document.getElementById('import-files');
        if (! input || ! sampleUrl) return;
        const response = await fetch(sampleUrl, { credentials: 'same-origin' });
        const blob = await response.blob();
        const file = new File([blob], 'cuadro-ejemplo.xlsx', { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        const transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        await waitUntil(() => document.body.textContent.includes('cuadro-ejemplo.xlsx') && ! anchor('import-analyze')?.disabled, 15000);
        await sleep(600);
        anchor('import-analyze')?.click();
        await waitUntil(() => anchor('import-review'), 40000);
        await sleep(400);
    },
    'import.expand': async () => {
        if (! anchor('import-decision')) anchor('import-toggle')?.click();
        await waitUntil(() => anchor('import-decision') || anchor('import-preview'), 4000);
    },
    'import.decide': async () => {
        const select = anchor('import-decision');
        const option = select && [...select.options].find((o) => o.value !== '');
        if (option) await type(select, option.value);
        await sleep(600);
    },
    'import.restart': async () => {
        anchor('import-restart')?.click();
        await waitUntil(() => anchor('import-dropzone'), 6000);
    },

    // ---- Administración ----
    'admin.user': async () => {
        await type(document.getElementById('user-name'), 'Ana Pérez');
        await type(document.getElementById('user-email'), 'ana.perez@farmacia.com');
        await type(document.getElementById('user-role'), 'supervision');
        await sleep(500);
    },
    'admin.branch': async () => {
        await type(document.getElementById('branch-name'), 'Sede Centro');
        await type(document.getElementById('branch-code'), 'GUA-02');
        await type(document.getElementById('branch-legal'), 'FARMACIA GUADALUPE, C.A.');
    },
    'admin.log.search': async () => type(anchor('admin-log-search')?.querySelector('input'), 'Admin'),
    'admin.log.clear': async () => type(anchor('admin-log-search')?.querySelector('input'), ''),

    // ---- Perfil ----
    'profile.name': async () => {
        const input = document.getElementById('name');
        if (input && profileName === null) profileName = input.value;
        await type(input, 'Ana Pérez');
    },
    'profile.restore': async () => {
        if (profileName !== null) await type(document.getElementById('name'), profileName);
        profileName = null;
    },
};

export async function runDemo(name, context = {}) {
    const action = demos[name];
    if (! action) return;
    try {
        await action(context);
    } catch (error) {
        // Una demostración que falle no detiene el recorrido: la explicación sigue valiendo.
        console.warn('Demostración del recorrido', name, error);
    }
}
