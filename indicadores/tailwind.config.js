import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            // Tokens de marca (§13.1). Azul = datos y acciones; morado = metas y proyección.
            colors: {
                brand: {
                    50: '#F2F7FE',
                    100: '#E4EEFC',
                    500: '#3D8AF0',
                    600: '#1D6FE5',
                    700: '#1558C2',
                    800: '#0F3F8F',
                    // Azul marino profundo del wordmark: solo el panel de marca del acceso (§13.4).
                    900: '#0A2C66',
                },
                accent: {
                    100: '#EDE8FB',
                    600: '#6C4FD8',
                },
                ink: {
                    400: '#8A98A3',
                    600: '#4B5B67',
                    900: '#14232F',
                },
                panel: '#EEF3F9',
                line: '#DDE4EC',
                surface: '#FFFFFF',
                success: { 100: '#E4F5EC', 600: '#1E8E5A' },
                warning: { 100: '#FCF1DE', 600: '#D98A0B' },
                danger: { 100: '#FBE6E6', 600: '#D23F3F' },
            },
            fontFamily: {
                sans: ['"IBM Plex Sans"', ...defaultTheme.fontFamily.sans],
            },
            // Escala fija de §13.2 (px): 13 etiquetas · 15 cuerpo · 17 subtítulos · 22 títulos · 32 KPI · 44 héroe
            fontSize: {
                label: ['13px', { lineHeight: '18px' }],
                body: ['15px', { lineHeight: '22px' }],
                sub: ['17px', { lineHeight: '24px' }],
                title: ['22px', { lineHeight: '28px', fontWeight: '600' }],
                kpi: ['32px', { lineHeight: '36px', fontWeight: '600' }],
                hero: ['44px', { lineHeight: '48px', fontWeight: '600' }],
            },
            borderRadius: {
                control: '6px',
                card: '8px',
                hero: '12px',
            },
            boxShadow: {
                // La única sombra del sistema: modales, menús y toasts (§13.4).
                overlay: '0 8px 24px rgba(20, 35, 47, 0.12)',
            },
        },
    },

    plugins: [
        forms,
        // Cifras tabulares en todo número (§13.2).
        ({ addUtilities }) => addUtilities({ '.tnum': { fontVariantNumeric: 'tabular-nums' } }),
    ],
};
