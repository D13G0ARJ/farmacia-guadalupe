/**
 * Formato es-VE en el navegador (§6.6, RN-20). Debe coincidir con App\Domain\Shared\Formatter:
 * "Bs 91.154,02" · "$ 614" · "+19,7 %" · "3.853". Cubierto por PreviewParityTest.
 */
const locale = 'es-VE';

const hasIntl = typeof Intl !== 'undefined' && typeof Intl.NumberFormat === 'function';

function toNumber(value) {
    if (value === null || value === undefined || value === '') return null;
    const n = typeof value === 'number' ? value : Number(String(value).replace(',', '.'));
    return Number.isFinite(n) ? n : null;
}

function fallback(n, precision) {
    const fixed = Math.abs(n).toFixed(precision);
    const [int, dec] = fixed.split('.');
    const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return (n < 0 ? '-' : '') + grouped + (dec ? ',' + dec : '');
}

export const fmt = {
    /** "3.853" · "1,96" · "—" */
    num(value, precision = 0) {
        const n = toNumber(value);
        if (n === null) return '—';
        if (!hasIntl) return fallback(n, precision);
        return new Intl.NumberFormat(locale, {
            minimumFractionDigits: precision,
            maximumFractionDigits: precision,
        }).format(n).replace(/ /g, '.');
    },

    /** money(91154.02, 'BS') → "Bs 91.154,02"; money(614, 'USD', 0) → "$ 614" */
    money(value, currency = 'USD', precision = 2) {
        const n = toNumber(value);
        if (n === null) return '—';
        const symbol = currency === 'BS' ? 'Bs' : currency === 'USD' ? '$' : '';
        const number = fmt.num(n, precision);
        return symbol ? `${symbol} ${number}` : number;
    },

    /** pct(0.1965) → "+19,7 %" (recibe fracción) */
    pct(fraction, precision = 1, signed = true) {
        const n = toNumber(fraction);
        if (n === null) return '—';
        const v = n * 100;
        const sign = signed && v > 0 ? '+' : '';
        return `${sign}${fmt.num(v, precision)} %`;
    },

    /**
     * Lee un input con separadores es-VE y devuelve un número o null. Espejo de Formatter::parseNumber:
     * "91.154,02" → 91154.02 · "91.154" → 91154 (puntos cada tres dígitos = miles) · "148.44" → 148.44
     */
    parse(input) {
        if (input === null || input === undefined) return null;
        const s = String(input).trim().replace(/ /g, '');
        if (s === '') return null;
        let normalized;
        if (s.includes(',')) {
            normalized = s.replace(/\./g, '').replace(',', '.');
        } else if (/^-?\d{1,3}(\.\d{3})+$/.test(s)) {
            normalized = s.replace(/\./g, '');
        } else {
            normalized = s;
        }
        if (!/^-?\d+(\.\d+)?$/.test(normalized)) return null;
        const n = Number(normalized);
        return Number.isFinite(n) ? n : null;
    },
};

export default fmt;
