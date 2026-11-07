import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';
export default defineConfig({
  cacheDir: '.local/runtime/vite-e8-16v',
  plugins: [react(), tailwindcss()],
  optimizeDeps: { noDiscovery: true, include: ['react', 'react-dom/client', '@inertiajs/react'] },
  resolve: { alias: { '@': fileURLToPath(new URL('../../../resources/js', import.meta.url)) } },
  server: { host: '127.0.0.1', port: 0, watch: null },
});
