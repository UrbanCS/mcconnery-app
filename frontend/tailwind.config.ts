import type { Config } from 'tailwindcss';

export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        paper: '#f7f5f2',
        ink: '#26312e',
        cedar: '#47665a',
        action: '#696941',
        moss: '#6f7d55',
        rosewood: '#8a5c58',
        line: '#ded8d0'
      },
      boxShadow: {
        soft: '0 10px 30px rgba(38, 49, 46, 0.08)'
      }
    }
  },
  plugins: []
} satisfies Config;
