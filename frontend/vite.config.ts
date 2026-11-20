import { resolve } from 'path'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  build: {
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'index.html'),
        widget: resolve(__dirname, 'widget.html'),
      },
      output: {
        manualChunks(id) {
          if (id.includes('node_modules/elkjs')) {
            return 'vendor-elk';
          }
          if (id.includes('node_modules/@xyflow')) {
            return 'vendor-xyflow';
          }
        },
      },
    },
  },
  server: {
    host: true, // Listen on all network interfaces for local network access in dev
    proxy: {
      '/api': {
        target: 'http://localhost:8100',
        changeOrigin: true,
      },
      '/storage': {
        target: 'http://localhost:8100',
        changeOrigin: true,
      },
      '/sanctum': {
        target: 'http://localhost:8100',
        changeOrigin: true,
      },
      '/health': {
        target: 'http://localhost:8100',
        changeOrigin: true,
      },
      '/app': {
        target: 'http://localhost:8080',
        ws: true,
        changeOrigin: true,
      },
    },
  },
})
