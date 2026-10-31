import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

// Reuses the application build and the same static root as the layout fixture.
export default defineConfig({
  plugins: [react()],
  publicDir: 'public',
  resolve: { alias: { '@': fileURLToPath(new URL('../../../resources/js', import.meta.url)) } },
  build: {
    outDir: '.local/runtime/layout-fixture',
    emptyOutDir: false,
    rollupOptions: { input: 'tests/Browser/E1-02/ui-fixture.html' },
  },
});
