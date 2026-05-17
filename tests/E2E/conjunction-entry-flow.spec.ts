import { test, expect } from '@playwright/test';

/**
 * Phase 6 chunk 3 — entry-point flow.
 *
 * The marquee user flow is:  /text/conjunctions  →  ▶ Replay link
 *                                                 →  /conjunction/p/s
 *                                                 →  HUD visible
 *
 * Plus: the Atom feed's conjunction entries link at the replay route,
 * not the JSON API, so feed readers + crawlers land on a human page.
 */
test.describe('Replay entry flow', () => {
  test('clicking ▶ Replay in /text/conjunctions lands on the replay scene with HUD', async ({ page }) => {
    await page.goto('/text/conjunctions');
    const firstLink = page.locator('a.replay-link').first();
    // Skip when no rows match the default window (cron may be sparse).
    const linkCount = await page.locator('a.replay-link').count();
    test.skip(linkCount === 0, 'No conjunctions in /text/conjunctions to click');

    const href = await firstLink.getAttribute('href');
    expect(href).toMatch(/^\/conjunction\/\d+\/\d+$/);

    await firstLink.click();
    await expect(page).toHaveURL(/\/conjunction\/\d+\/\d+/);

    const hud = page.locator('sat-app sat-conjunction-hud');
    await expect(hud).toBeAttached({ timeout: 10_000 });
    await expect(hud).toContainText('Miss');
    await expect(hud).toContainText('TCA');
  });

  test('/events.atom conjunction entries link at /conjunction/p/s', async ({ request }) => {
    const r = await request.get('/events.atom');
    expect(r.status()).toBe(200);
    const body = await r.text();
    expect(body).toContain('<feed');
    // Conjunction entries are categorized with category term="conjunction"
    // and their <link rel="alternate" href="..."> points at the replay
    // route.  Allow zero matches (sparse feeds) but assert shape when present.
    const matches = [...body.matchAll(/<link rel="alternate" href="(\/conjunction\/\d+\/\d+)"/g)];
    if (matches.length > 0) {
      for (const m of matches) {
        expect(m[1]).toMatch(/^\/conjunction\/\d+\/\d+$/);
      }
    }
    // Belt and suspenders — the OLD JSON-API URL pattern must NOT appear
    // for any conjunction entry now.
    expect(body).not.toMatch(/<link rel="alternate" href="\/api\/v1\/conjunctions\/\d+\/\d+"/);
  });
});
