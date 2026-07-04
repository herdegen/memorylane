import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/filament/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
                display: ['"Playfair Display"', 'Georgia', 'serif'],
            },
            colors: {
                // Fond crème chaude de toutes les pages
                page: '#FAF7F1',
                // Accent principal chaud (ambre)
                brand: {
                    50:  '#FFFBEB',
                    100: '#FEF3C7',
                    200: '#FDE68A',
                    300: '#FCD34D',
                    400: '#FBBF24',
                    500: '#F59E0B',
                    600: '#D97706', // CTA principal
                    700: '#B45309',
                    800: '#92400E',
                    900: '#78350F',
                },
                // Neutres chauds (stone)
                surface: {
                    50:  '#FAFAF9',
                    100: '#F5F5F4',
                    200: '#E7E5E4',
                    300: '#D6D3D1',
                    400: '#A8A29E',
                    500: '#78716C',
                    600: '#57534E',
                    700: '#44403C',
                    800: '#292524',
                    900: '#1C1917',
                },
            },
            borderRadius: {
                card: '12px',
                modal: '16px',
            },
            boxShadow: {
                // Ombres teintées pierre chaude (#1C1917), jamais bleues
                'warm-sm': '0 1px 2px rgba(28, 25, 23, 0.06)',
                'warm-md': '0 4px 12px rgba(28, 25, 23, 0.08)',
                'warm-lg': '0 12px 32px rgba(28, 25, 23, 0.14)',
            },
        },
    },
    plugins: [],
};
