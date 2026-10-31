import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
export default defineConfig({ plugins: [react()], publicDir: 'public', resolve: { alias: { '@': fileURLToPath(new URL('../../../resources/js', import.meta.url)) } }, build: { outDir: '.local/runtime/layout-fixture', emptyOutDir: false, rollupOptions: { input: 'tests/Browser/E1-04/ui-fixture.html' } } });
