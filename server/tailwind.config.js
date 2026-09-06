import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        // Sin esto, cualquier clase de Tailwind referenciada solo desde JS (p. ej. las opciones
        // chosenClass/dragClass de SortableJS en el tablero Kanban) se poda del CSS final por
        // "no usada" — Tailwind solo sabe que existe si aparece literalmente en un archivo escaneado.
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            // Tipografía tipo sistema (Apple/macOS en dispositivos Apple, nativa en el resto).
            // Sin dependencia de fuentes externas: nada que cargar, nada que falle sin red.
            fontFamily: {
                sans: [
                    'ui-sans-serif',
                    '-apple-system',
                    'BlinkMacSystemFont',
                    '"SF Pro Text"',
                    '"Segoe UI"',
                    'Roboto',
                    'Helvetica',
                    'Arial',
                    'sans-serif',
                ],
            },

            // Paleta de marca: azul-petróleo (agua/gasóleo). El resto de tonos semánticos
            // (success/warning/danger) usan las escalas de Tailwind (emerald/amber/rose) tal cual.
            colors: {
                primary: {
                    50: '#eff9fb',
                    100: '#d7f0f5',
                    200: '#b3e2eb',
                    300: '#82cdda',
                    400: '#48afc4',
                    500: '#2790aa',
                    600: '#1f7489',
                    700: '#1e5d70',
                    800: '#1f4c5c',
                    900: '#1c404d',
                    950: '#0e2530',
                },
            },

            // Radios consistentes en toda la app: los mismos 4 tamaños en cualquier componente.
            borderRadius: {
                sm: '0.5rem',
                DEFAULT: '0.75rem',
                md: '0.875rem',
                lg: '1rem',
                xl: '1.25rem',
                '2xl': '1.5rem',
            },

            // Sombras suaves ("soft UI"), sin el gris duro típico de plantilla SaaS.
            boxShadow: {
                'soft-sm': '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 1px 0 rgb(15 23 42 / 0.03)',
                soft: '0 2px 8px -2px rgb(15 23 42 / 0.06), 0 1px 2px -1px rgb(15 23 42 / 0.04)',
                'soft-md': '0 8px 24px -6px rgb(15 23 42 / 0.10), 0 2px 6px -2px rgb(15 23 42 / 0.05)',
                'soft-lg': '0 16px 40px -12px rgb(15 23 42 / 0.16), 0 4px 10px -4px rgb(15 23 42 / 0.08)',
                glass: '0 8px 32px -8px rgb(15 23 42 / 0.18), inset 0 1px 0 0 rgb(255 255 255 / 0.15)',
            },
        },
    },

    plugins: [forms],
};
