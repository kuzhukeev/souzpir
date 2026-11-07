import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath } from 'node:url';

export default defineConfig({cacheDir: '.local/runtime/vite-e8-16q', plugins: [react(), tailwindcss()], resolve: {alias: {'@': fileURLToPath(new URL('../../../resources/js', import.meta.url))}}});
