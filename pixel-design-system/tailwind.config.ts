/** @type {import('tailwindcss').Config} */
export default {
  content: ['./src/**/*.{ts,tsx}', './.storybook/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        'px-primary': '#2C12D9',
        'px-primary-light': '#4A35E0',
        'px-primary-dark': '#1E0C96',
        'px-primary-30': 'rgba(44, 18, 217, 0.3)',
        'px-dark': '#111111',
        'px-offblack': '#14142B',
        'px-white': '#FFFFFF',
        'px-offwhite': '#FCFCFC',
        'px-input': '#EFF0F6',
        'px-label': '#7B7FA0',
        'px-line': '#D9DBE9',
        'px-error': '#E02020',
        'px-success': '#00C853',
        'px-warning': '#FFB020',
        'nm-dark': '#121212',
        'nm-dark-s': '#1C1B1F',
        'nm-light': '#F0F2F5',
        'nm-light-s': '#E6E4EB',
      },
      fontFamily: {
        display: ['Plus Jakarta Sans', 'sans-serif'],
        body: ['Poppins', 'sans-serif'],
      },
      letterSpacing: {
        display: '1px',
        'wide-body': '0.75px',
      },
      borderRadius: {
        none: '0px',
        DEFAULT: '0px',
        sm: '0px',
        md: '0px',
        lg: '0px',
      },
      boxShadow: {
        'nm-raised-d': '2px 2px 10px #000000, -2px -2px 10px #2A2A2A',
        'nm-inset-d': 'inset 5px 5px 10px #000000, inset -5px -5px 10px #2A2A2A',
        'nm-flat-d': '2px 2px 5px #000000, -2px -2px 5px #2A2A2A',
        'nm-btn-d': '2px 2px 10px #000000, -2px -2px 10px #2A2A2A',
        'nm-raised-l': '2px 2px 10px #CDD5E0, -2px -2px 10px #FFFFFF',
        'nm-inset-l': 'inset 5px 5px 10px #CDD5E0, inset -5px -5px 10px #FFFFFF',
        'nm-flat-l': '2px 2px 5px #CDD5E0, -2px -2px 5px #FFFFFF',
        'nm-btn-l': '2px 2px 10px #CDD5E0, -2px -2px 10px #FFFFFF',
      },
      keyframes: {
        'fade-slide-up': {
          '0%': { opacity: '0', transform: 'translateY(8px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
      },
      animation: {
        'fade-slide-up': 'fade-slide-up 280ms ease-out',
      },
    },
  },
  plugins: [],
};
