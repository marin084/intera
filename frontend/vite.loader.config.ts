import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  build: {
    emptyOutDir: false,
    outDir: 'dist/widget',
    lib: {
      entry: resolve(__dirname, 'src/widget/loader.ts'),
      name: 'ConexaLoader',
      formats: ['iife'],
      fileName: () => 'conexa.js',
    },
  },
});
