import { defineConfig } from 'vite';
import cesium from 'vite-plugin-cesium';
import { resolve } from 'node:path';

export default defineConfig({
  // Base URL for built assets — Apache serves them from /build/
  base: '/build/',

  // We don't use Vite's publicDir feature; PHP's public/ is the web root,
  // not Vite's static asset folder.
  publicDir: false,

  plugins: [
    cesium(),
  ],

  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    manifest: true,
    sourcemap: true,
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'resources/js/main.ts'),
      },
    },
  },

  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    cors: true,
    origin: process.env.VITE_DEV_ORIGIN ?? 'http://localhost:5173',
  },
});
