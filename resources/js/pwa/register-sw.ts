/**
 * Phase 5 chunk 2B — service-worker registration for the SPA shell.
 *
 * Mirrored by an inline `navigator.serviceWorker.register('/sw.js')`
 * in `resources/views/text/layout.php`, so /text visitors get the
 * same offline coverage even though they never load main.ts.
 *
 * Skipped on http://localhost when DEV mode is true — service workers
 * happily cache stale dev-server output, which is exactly what you
 * don't want while iterating.  Tests can opt in by setting
 * `localStorage.pwaEnableInDev = '1'`.
 */
export function registerServiceWorker(): void {
  if (!('serviceWorker' in navigator)) return;

  const isLocalhost =
    location.hostname === 'localhost' ||
    location.hostname === '127.0.0.1' ||
    location.hostname.endsWith('.localhost');
  const devOverride =
    typeof localStorage !== 'undefined' && localStorage.getItem('pwaEnableInDev') === '1';

  if (import.meta.env.DEV && isLocalhost && !devOverride) {
    return;
  }

  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register('/sw.js', { scope: '/' })
      .catch((err) => {
        console.warn('[sw] registration failed', err);
      });
  });
}
