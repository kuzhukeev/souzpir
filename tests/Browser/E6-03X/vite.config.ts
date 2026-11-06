import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath } from 'node:url';
export default defineConfig({cacheDir:'.local/runtime/vite-e603x',plugins:[react(),tailwindcss()],resolve:{alias:{'@':fileURLToPath(new URL('../../../resources/js',import.meta.url))}},server:{host:'127.0.0.1',port:0,strictPort:false,watch:{ignored:['**/.local/**']}}});
