import forms from '@tailwindcss/forms'
import defaultTheme from 'tailwindcss/defaultTheme'

/** Semantic colors are CSS variables (see src/styles/main.css) so light/dark stay in sync. */
const v = (name) => `rgb(var(--${name}) / <alpha-value>)`

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js}'],
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', ...defaultTheme.fontFamily.sans],
      },
      fontSize: {
        // 14px base text
        base: ['0.875rem', { lineHeight: '1.375rem' }],
        '2xs': ['0.6875rem', { lineHeight: '1rem' }],
      },
      colors: {
        brand: {
          DEFAULT: '#F59E0B',
          hover: '#D97706',
          50: '#FFFBEB', 100: '#FEF3C7', 200: '#FDE68A', 300: '#FCD34D', 400: '#FBBF24',
          500: '#F59E0B', 600: '#D97706', 700: '#B45309', 800: '#92400E', 900: '#78350F',
        },
        navy: { DEFAULT: '#0B1220', 950: '#070B14', 900: '#0B1220', 850: '#0F1729', 800: '#131D33', 700: '#1B2742', 600: '#25334F' },
        canvas: v('canvas'),     // page background  #F7F8FA
        surface: v('surface'),   // cards            #FFFFFF
        subtle: v('subtle'),     // hover / wells
        line: v('line'),         // borders          #E5E7EB
        ink: v('ink'),           // primary text
        muted: v('muted'),       // secondary text
        faint: v('faint'),       // tertiary text / icons
      },
      borderRadius: {
        card: '14px',
      },
      boxShadow: {
        card: '0 1px 2px 0 rgb(16 24 40 / 0.04), 0 1px 3px 0 rgb(16 24 40 / 0.05)',
        lift: '0 4px 12px -2px rgb(16 24 40 / 0.08), 0 2px 4px -2px rgb(16 24 40 / 0.05)',
        pop: '0 20px 40px -12px rgb(16 24 40 / 0.25), 0 8px 16px -8px rgb(16 24 40 / 0.12)',
        glow: '0 0 0 4px rgb(245 158 11 / 0.18)',
      },
      keyframes: {
        shimmer: { '100%': { transform: 'translateX(100%)' } },
        'fade-up': { '0%': { opacity: 0, transform: 'translateY(6px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } },
        flicker: { '0%,100%': { transform: 'scale(1) rotate(0deg)' }, '50%': { transform: 'scale(1.12) rotate(-4deg)' } },
      },
      animation: {
        shimmer: 'shimmer 1.6s infinite',
        'fade-up': 'fade-up .35s cubic-bezier(.2,.8,.2,1) both',
        flicker: 'flicker 1.4s ease-in-out infinite',
      },
    },
  },
  plugins: [forms({ strategy: 'class' })],
}
