import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
export default defineConfig({
  cacheDir: `.local/runtime/vite-e1-08-${process.env.VITE_DEV_PORT || 5238}`,
  plugins: [react()],
  resolve: { alias: { '@': fileURLToPath(new URL('../../../resources/js', import.meta.url)) } },
  server: {
    host: '127.0.0.1',
    port: Number(process.env.VITE_DEV_PORT || 5238),
    strictPort: true,
    watch: process.env.CI ? null : {
      ignored: ['**/.local/**', '**/vendor/**', '**/test-results/**', '**/playwright-report/**'],
    },
  },
});
