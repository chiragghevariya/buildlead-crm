import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  build: {
    // The only chunk above Vite's 500 kB default is ApexCharts (~690 kB, ~200 kB gzip). It is already
    // tree-shaken to area/bar/donut and lazy-loaded by the dashboard charts, so it never blocks first paint.
    chunkSizeWarningLimit: 750,
  },
  server: {
    host: process.env.VITE_HOST || 'localhost',
    watch: process.env.VITE_POLL ? { usePolling: true, interval: 300 } : undefined,
    port: 5190,
    strictPort: true,
    // Same-origin API in dev: /api → Laravel on :8020
    proxy: { '/api': { target: process.env.VITE_PROXY_TARGET || 'http://127.0.0.1:8020', changeOrigin: true } },
  },
})
