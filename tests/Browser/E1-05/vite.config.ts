import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
export default defineConfig({
  plugins: [react()],
  publicDir: 'public',
  server: { watch: process.env.CI ? null : undefined },
  resolve: { alias: { '@': fileURLToPath(new URL('../../../resources/js', import.meta.url)) } },
  build: {
    outDir: '.local/runtime/brand-fixture',
    emptyOutDir: false,
    rollupOptions: { input: 'tests/Browser/E1-05/ui-fixture.html' },
  },
});
