/**
 * Panel de gráfica (§14.1): monta ECharts bajo demanda sobre la especificación que expone el
 * componente Livewire en `$wire.specs[id]` y la actualiza cuando cambia (período, sede, moneda).
 * El lienzo lleva `wire:ignore`: Livewire no toca el SVG que dibuja ECharts.
 */
const clone = (value) => JSON.parse(JSON.stringify(value));

export default function chartPanel(id) {
    return {
        id,
        ready: false,
        showData: false,
        handle: null,

        lastJson: null,

        init() {
            const spec = this.current();
            this.lastJson = spec ? JSON.stringify(spec) : null;
            this.mount(spec);
            this.$wire.$watch('specs', (specs) => this.apply(specs?.[this.id]));
        },

        current() {
            const spec = this.$wire.specs?.[this.id];
            return spec ? clone(spec) : null;
        },

        async mount(spec) {
            if (!spec || spec.empty || !this.$refs.canvas || this.handle) return;
            const { mountChart } = await window.loadCharts();
            if (this.handle || !this.$refs.canvas?.isConnected) return;
            this.handle = mountChart(this.$refs.canvas, spec);
            this.ready = true;
        },

        apply(spec) {
            if (!spec) return;
            // Livewire notifica cualquier cambio de `specs`; solo se redibuja si esta gráfica cambió.
            const json = JSON.stringify(spec);
            if (json === this.lastJson) return;
            this.lastJson = json;
            const next = JSON.parse(json);
            if (next.empty) {
                this.unmount();
                return;
            }
            this.$nextTick(() => (this.handle ? this.handle.update(next) : this.mount(next)));
        },

        /** PNG a 2× con el nombre de la gráfica y el mes (§14). */
        png() {
            if (!this.handle) return;
            const link = document.createElement('a');
            link.href = this.handle.toPng();
            link.download = `${this.$wire.specs?.[this.id]?.slug ?? this.id}.png`;
            link.click();
        },

        unmount() {
            this.handle?.destroy();
            this.handle = null;
            this.ready = false;
        },

        destroy() {
            this.unmount();
        },
    };
}
