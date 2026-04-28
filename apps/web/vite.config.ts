import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

// Vite dev proxy: any request the SPA makes to /api/* gets forwarded to the local
// WordPress install. This avoids cross-origin cookie weirdness in dev — the SPA
// thinks it's same-origin. Production uses real CORS (DEFYN_SPA_ORIGIN allowlist
// in F3a's Cors middleware).
const WP_TARGET = process.env.VITE_WP_URL ?? 'https://defynwp.local';

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: WP_TARGET,
        changeOrigin: true,
        secure: false, // Local-by-Flywheel uses self-signed certs
        rewrite: (p) => p.replace(/^\/api/, '/wp-json'),
      },
    },
  },
});
