import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';

export default defineConfig({ cacheDir: '.local/runtime/vite-e604d', plugins: [react(), tailwindcss()], resolve: { alias: { '@': fileURLToPath(new URL('../../../resources/js', import.meta.url)) } }, server: { host: '127.0.0.1', port: 0, strictPort: false, watch: { ignored: ['**/.local/**'] } } });
