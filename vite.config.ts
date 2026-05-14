import { defineConfig } from 'vite';
import cesium from 'vite-plugin-cesium';
import { resolve } from 'node:path';

export default defineConfig({
  // We leave base at the default '/' and have ViteAssetResolver (PHP side)
  // prepend the '/build/' URL prefix when emitting <script>/<link> tags.
  // Setting Vite base to '/build/' interacts badly with vite-plugin-cesium's
  // static copy, producing doubled /build/build/cesium/ paths.

  // PHP's public/ is the web root, not Vite's static asset folder.
  publicDir: false,

  plugins: [
    // vite-plugin-cesium externalizes the `cesium` import as a global
    // (window.Cesium) and copies static assets to outDir/cesium/. We use
    // its defaults — files end up at public/build/cesium/. Because we render
    // our shell in PHP rather than letting Vite transform an index.html,
    // shell.php manually injects the Cesium.js script tag and sets
    // window.CESIUM_BASE_URL to /build/cesium/.
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
