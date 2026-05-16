import { test, expect } from '@playwright/test';

/**
 * Phase 4 chunk 8B — visual-regression baselines for the new HTML
 * surfaces shipped in Phase 4 chunks 2-6.
 *
 * Strategy: structural assertions instead of pixel-perfect diffs.
 * Every page on this list pulls live data (TCAs, sampled_at, Kp
 * values, launch NETs) that drifts on every cron run, which would
 * make pure-pixel screenshots flake constantly.  Instead each spec
 * verifies the page renders without errors and the expected
 * sections / table headers / nav links exist — same coverage goal
 * (page-broke-completely catches), no fixture-data plumbing.
 *
 * Real pixel baselines would land in Phase 5 once there's a
 * fixture-data layer to freeze the rendered output.
 */
test.describe('Phase 4 HTML surfaces — structural smoke', () => {
  test('/text/conjunctions renders table + § conjunctions nav', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto('/text/conjunctions');

    await expect(page.locator('h1')).toContainText('Predicted conjunctions');
    await expect(page.locator('table')).toBeAttached();
    await expect(page.locator('nav.top')).toContainText('§ conjunctions');
    expect(errors).toEqual([]);
  });

  test('/text/space-weather renders §Now grid + 24h table', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto('/text/space-weather');

    await expect(page.locator('h1')).toContainText('Space weather');
    // §Now grid renders even when there are no samples (empty-state message)
    await expect(page.locator('body')).toContainText(/Now|No samples ingested/);
    await expect(page.locator('nav.top')).toContainText('§ weather');
    expect(errors).toEqual([]);
  });

  test('/text/stats renders summary + every breakdown section', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto('/text/stats');

    await expect(page.locator('h1')).toContainText('Catalog stats');
    for (const heading of ['§ Summary', '§ By object type', '§ Top countries', '§ Launches per year']) {
      // Per-heading filter — h2.toContainText() is strict mode and would
      // refuse to disambiguate multiple matches.
      await expect(page.locator('h2', { hasText: heading })).toBeVisible();
    }
    await expect(page.locator('nav.top')).toContainText('§ stats');
    expect(errors).toEqual([]);
  });

  test('/text/events renders chronological feed + atom link', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto('/text/events');

    await expect(page.locator('h1')).toContainText('Events feed');
    await expect(page.locator('body')).toContainText('events.atom');
    await expect(page.locator('nav.top')).toContainText('§ events');
    expect(errors).toEqual([]);
  });

  test('/events.atom returns valid Atom 1.0 XML', async ({ request }) => {
    const r = await request.get('/events.atom');
    expect(r.status()).toBe(200);
    const ctype = r.headers()['content-type'] ?? '';
    expect(ctype).toContain('application/atom+xml');
    const body = await r.text();
    expect(body).toContain('<?xml version="1.0"');
    expect(body).toContain('<feed xmlns="http://www.w3.org/2005/Atom">');
    expect(body).toContain('<title>');
    expect(body).toContain('<updated>');
  });
});
