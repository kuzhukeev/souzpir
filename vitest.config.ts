import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  plugins: [react()],
  resolve: { alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) } },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.{test,spec}.{ts,tsx}', 'resources/js/**/*.{test,spec}.{ts,tsx}'],
    exclude: ['tests/Browser/**'],
    restoreMocks: true,
    clearMocks: true,
  },
});
