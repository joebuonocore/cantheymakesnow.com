/** @type {import('tailwindcss').Config} */

const colors = require('tailwindcss/colors')

module.exports = {    
    corePlugins: {
        reboot: false,
        preflight: false,
    },
    prefix: '.',
    content: ["./**/*.htm"],
    safelist: [
        '.col-span-2'
    ],
    theme: {
        container: {
            center: true,
            padding: '1rem',
        },
        screens: {
            'sm': '540px',
            'md': '768px',
            'lg': '1024px',
            'xl': '1242px',
        },
        colors: {
            transparent: 'transparent',
            current: 'currentColor',
            neutral: colors.gray,

            gray: colors.gray,
            blue: colors.blue,
            white: colors.white,
            green: colors.green,
            black: colors.black,
            slate: colors.slate,
            yellow: colors.yellow,
            red: colors.red,
            orange: colors.orange,
        },
        extend: {
            fontSize: {
                'xs': '0.9rem',
            },
        },
    },
    plugins: [],
}