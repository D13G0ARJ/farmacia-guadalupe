/**
 * Cuadrícula de metas indicador × mes (UC-11): navegación con flechas/Enter y pegado desde Excel
 * (bloques separados por tabulador y salto de línea) a partir de la celda enfocada.
 */
export default function goalGrid() {
    const cell = (root, row, col) => root.querySelector(`[data-row="${row}"][data-col="${col}"]`);

    return {
        move(event, dRow, dCol) {
            const from = event.target;
            const next = cell(this.$root, Number(from.dataset.row) + dRow, Number(from.dataset.col) + dCol);
            if (!next) return;
            next.focus();
            next.select?.();
        },

        paste(event) {
            const text = event.clipboardData?.getData('text') ?? '';
            if (!text.includes('\t') && !text.includes('\n')) return;
            event.preventDefault();
            const row0 = Number(event.target.dataset.row);
            const col0 = Number(event.target.dataset.col);
            text.replace(/\r/g, '')
                .split('\n')
                .forEach((line, i) => {
                    if (line.trim() === '') return;
                    line.split('\t').forEach((value, j) => {
                        const target = cell(this.$root, row0 + i, col0 + j);
                        if (!target) return;
                        target.value = value.trim();
                        target.dispatchEvent(new Event('input', { bubbles: true }));
                    });
                });
        },
    };
}
