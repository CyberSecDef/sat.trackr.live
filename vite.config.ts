import { defineConfig } from 'vite';
import cesium from 'vite-plugin-cesium';
import { resolve } from 'node:path';

export default defineConfig({
  // base must match the URL prefix Apache serves /build/ from. Without
  // this, Vite emits absolute asset URLs like /assets/foo.js, which
  // matters for things like the propagator Worker construction:
  //   new Worker(new URL('/assets/propagator-XXX.js', ...), ...)
  // → 404, worker never loads, satellites stay invisible at the origin.
  //
  // Setting base = '/build/' makes Vite emit /build/assets/... everywhere,
  // including inside `new URL(import.meta.url)` worker resolutions.
  base: '/build/',

  // PHP's public/ is the web root, not Vite's static asset folder.
  publicDir: false,

  plugins: [
    // vite-plugin-cesium computes its file destination as
    //   path.join(outDir, path.posix.join(base, cesiumBaseUrl), 'Workers')
    // With base='/build/' and the default cesiumBaseUrl='cesium/',
    // CESIUM_BASE_URL would be '/build/cesium/' and files would land at
    // 'public/build/build/cesium/' — a double-/build/ path. Override
    // cesiumBaseUrl with '../cesium/' so the in-browser URL resolves
    // back to '/cesium/' (which we ignore — shell.php hardcodes the
    // correct '/build/cesium/' URL) but the file destination becomes
    // 'public/build/cesium/' as intended.
    cesium({ cesiumBaseUrl: '../cesium/' }),
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
