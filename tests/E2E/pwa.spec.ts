import { test, expect, type Page } from '@playwright/test';

/**
 * Phase 5 chunk 2C — PWA smoke specs.
 *
 * Requires the built bundle (npm run build) — `import.meta.env.DEV`
 * gates SW registration off on localhost otherwise, which is correct
 * for `make dev` but would defeat this suite.  CI runs `make build`
 * before `make test-e2e`; the README documents the same for local runs.
 */
test.describe('PWA chunk 2', () => {
  // Both shell.php's bundled SW reg and layout.php's inline reg skip
  // localhost by default (so `make dev` doesn't get shadowed by a stale
  // cache).  Flip the opt-in flag before navigation so the SW actually
  // registers under Playwright.
  test.beforeEach(async ({ context }) => {
    await context.addInitScript(() => {
      try { localStorage.setItem('pwaEnableInDev', '1'); } catch (_) {}
    });
  });

  test('manifest.webmanifest is reachable and valid', async ({ request }) => {
    const r = await request.get('/manifest.webmanifest');
    expect(r.status()).toBe(200);
    const ct = r.headers()['content-type'] ?? '';
    expect(ct.toLowerCase()).toContain('manifest');
    const json = await r.json();
    expect(json.name).toContain('sat.trackr');
    expect(json.start_url).toBe('/');
    expect(json.display).toBe('standalone');
    expect(Array.isArray(json.icons)).toBe(true);
    const has192 = json.icons.some((i: { sizes?: string }) => i.sizes === '192x192');
    const has512 = json.icons.some((i: { sizes?: string }) => i.sizes === '512x512');
    expect(has192).toBe(true);
    expect(has512).toBe(true);
  });

  test('icon assets respond 200', async ({ request }) => {
    for (const path of [
      '/icons/icon-192.png',
      '/icons/icon-512.png',
      '/icons/icon-512-maskable.png',
      '/apple-touch-icon.png',
      '/offline.html',
    ]) {
      const r = await request.get(path);
      expect(r.status(), `${path} should serve 200`).toBe(200);
    }
  });

  test('service worker registers and activates from /text', async ({ page }) => {
    await page.goto('/text');
    const reg = await waitForActiveSW(page);
    expect(reg).toBe(true);
  });

  test('/text falls back to offline shell when network is gone', async ({ context, page }) => {
    // First visit — registers the SW and lets `activate` precache /offline.html.
    await page.goto('/text');
    expect(await waitForActiveSW(page)).toBe(true);

    // Cut the network.  Subsequent /text navigations should be intercepted
    // by the SW's networkFirstWithOfflineFallback() and resolved against the
    // precached offline shell instead of the browser's net-error page.
    await context.setOffline(true);
    await page.goto('/text/groups', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('body')).toContainText('Offline', { timeout: 5000 });
  });
});

/**
 * Polls navigator.serviceWorker.ready up to ~5 s.  Returning a Promise
 * straight to evaluate() lets Playwright propagate the actual readiness
 * instead of a race-y boolean snapshot.
 */
async function waitForActiveSW(page: Page): Promise<boolean> {
  return page.evaluate(async () => {
    if (!('serviceWorker' in navigator)) return false;
    const reg = await Promise.race([
      navigator.serviceWorker.ready,
      new Promise<null>((res) => setTimeout(() => res(null), 5000)),
    ]);
    return reg !== null && reg !== undefined && reg.active !== null;
  });
}
