import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath } from 'node:url';
export default defineConfig({ plugins: [react(), tailwindcss()], resolve: { alias: { '@': fileURLToPath(new URL('../../../resources/js', import.meta.url)) } }, cacheDir: '.local/runtime/vite-e8-15d', server: { host: '127.0.0.1', port: 0, strictPort: false } });

