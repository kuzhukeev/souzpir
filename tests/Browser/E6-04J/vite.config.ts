import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath } from 'node:url';
export default defineConfig({ cacheDir: '.local/runtime/vite-e6-04j', plugins: [react(), tailwindcss()], optimizeDeps: { noDiscovery: true, include: ['react', 'react-dom/client', '@inertiajs/react'] }, server: { watch: null }, resolve: { alias: { '@': fileURLToPath(new URL('../../../resources/js', import.meta.url)) } } });
