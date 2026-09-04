/**
 * Vista previa de los derivados en el formulario diario (RN-26, §13.5) y borrador por fecha en el
 * navegador (§13.8). Mismas fórmulas que App\Domain\Indicators\DailyMetrics; el servidor recalcula al guardar.
 */
import { fmt } from './charts/formatters';

const DRAFT_FIELDS = ['sales', 'transactions', 'units', 'shifts', 'inventoryUnits', 'inventoryValue', 'notes'];

const storage = {
    get(key) {
        try {
            const raw = window.localStorage.getItem(key);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    },
    set(key, value) {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
        } catch {
            // Sin almacenamiento (modo privado o lleno): el formulario sigue funcionando sin borrador.
        }
    },
    remove(key) {
        try {
            window.localStorage.removeItem(key);
        } catch {
            // Igual que arriba.
        }
    },
};

export default function dailyPreview($wire, options = {}) {
    return {
        // Enlazado a las propiedades Livewire: lo que escribe el usuario y lo que corrige el servidor
        // (tasa arrastrada, cambio de fecha) llegan aquí sin reiniciar el estado en cada morph.
        sales: $wire.entangle('form.sales_bs'),
        rate: $wire.entangle('form.rate'),
        transactions: $wire.entangle('form.transactions'),
        units: $wire.entangle('form.units'),
        shifts: $wire.entangle('form.shifts'),
        inventoryUnits: $wire.entangle('form.inventory_units'),
        inventoryValue: $wire.entangle('form.inventory_value_usd'),
        notes: $wire.entangle('form.notes'),

        draftRestored: false,
        draftSavedAt: null,
        draftTimer: null,

        init() {
            if (options.clear) storage.remove(options.clear);
            if (! options.edit) this.restoreDraft();
            DRAFT_FIELDS.forEach((field) => this.$watch(field, () => this.scheduleDraft()));
        },

        get draftKey() {
            return `draft:${options.branch}:${$wire.form?.date ?? ''}`;
        },

        /** Al abrir un día nuevo sin nada escrito, vuelve lo que quedó en este navegador. */
        restoreDraft() {
            const draft = storage.get(this.draftKey);
            if (! draft || this.sales !== '' || this.transactions !== '' || this.units !== '') return;
            DRAFT_FIELDS.forEach((field) => {
                if (typeof draft[field] === 'string' && draft[field] !== '') this[field] = draft[field];
            });
            this.draftRestored = true;
            this.draftSavedAt = draft.at ? new Date(draft.at).toLocaleString('es-VE', { dateStyle: 'short', timeStyle: 'short' }) : null;
        },

        scheduleDraft() {
            clearTimeout(this.draftTimer);
            this.draftTimer = setTimeout(() => this.saveDraft(), 400);
        },

        saveDraft() {
            if (options.edit) return;
            const values = Object.fromEntries(DRAFT_FIELDS.map((field) => [field, String(this[field] ?? '')]));
            const empty = DRAFT_FIELDS.every((field) => field === 'shifts' || values[field] === '');
            if (empty) {
                storage.remove(this.draftKey);
                return;
            }
            storage.set(this.draftKey, { ...values, at: Date.now() });
        },

        discardDraft() {
            storage.remove(this.draftKey);
            DRAFT_FIELDS.forEach((field) => {
                if (field !== 'shifts') this[field] = '';
            });
            this.draftRestored = false;
        },

        get salesUsd() {
            const s = fmt.parse(this.sales);
            const r = fmt.parse(this.rate);
            return s === null || r === null || r <= 0 ? null : s / r;
        },
        get ticketBs() {
            const s = fmt.parse(this.sales);
            const t = fmt.parse(this.transactions);
            return s === null || t === null || t <= 0 ? null : s / t;
        },
        get ticketUsd() {
            const t = fmt.parse(this.transactions);
            return this.salesUsd === null || t === null || t <= 0 ? null : this.salesUsd / t;
        },
        get unitsPerTransaction() {
            const u = fmt.parse(this.units);
            const t = fmt.parse(this.transactions);
            return u === null || t === null || t <= 0 ? null : u / t;
        },
        get transactionsPerShift() {
            const t = fmt.parse(this.transactions);
            const j = fmt.parse(this.shifts);
            return t === null || j === null || j <= 0 ? null : t / j;
        },

        money(value, currency, precision) {
            return value === null ? '—' : fmt.money(value, currency, precision);
        },
        num(value, precision) {
            return value === null ? '—' : fmt.num(value, precision);
        },

        /** Al perder el foco, muestra el número con separadores es-VE y avisa a Livewire. */
        format(event, key, precision) {
            const n = fmt.parse(event.target.value);
            if (n === null) return;
            const pretty = fmt.num(n, precision);
            event.target.value = pretty;
            this[key] = pretty;
            event.target.dispatchEvent(new Event('input', { bubbles: true }));
        },
    };
}
