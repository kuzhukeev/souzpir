import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

// Standalone fixture uses the same built CSS/fonts as the application, without
// starting the Tailwind development compiler during each browser test.
export default defineConfig({
  plugins: [react()],
  publicDir: 'public',
  resolve: { alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) } },
  build: {
    outDir: '.local/runtime/layout-fixture',
    emptyOutDir: false,
    rollupOptions: { input: 'tests/Browser/Layout/fixture.html' },
  },
});
