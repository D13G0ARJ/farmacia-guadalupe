/**
 * Vista previa de los derivados en el formulario diario (RN-26, §13.5).
 * Mismas fórmulas que App\Domain\Indicators\DailyMetrics; el servidor recalcula al guardar.
 */
import { fmt } from './charts/formatters';

export default function dailyPreview($wire) {
    return {
        // Enlazado a las propiedades Livewire: lo que escribe el usuario y lo que corrige el servidor
        // (tasa arrastrada, cambio de fecha) llegan aquí sin reiniciar el estado en cada morph.
        sales: $wire.entangle('form.sales_bs'),
        rate: $wire.entangle('form.rate'),
        transactions: $wire.entangle('form.transactions'),
        units: $wire.entangle('form.units'),
        shifts: $wire.entangle('form.shifts'),

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
