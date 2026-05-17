import { test, expect } from '@playwright/test';

/**
 * Phase 6 chunk 1 — conjunction-replay route smoke.
 *
 * Chunk 1 covers SSR + scene scaffold (routing, clock, chase camera).
 * HUD + replay controls + dim-everything-else land in chunk 2; this
 * spec defends just the route contract.
 */
test.describe('Conjunction-replay route', () => {
  test('/conjunction/{p}/{s} returns the SPA shell with replay context embedded for a real pair', async ({ page, request }) => {
    // Find a real conjunction pair from the live API instead of hard-coding
    // NORADs — the catalog shifts every 8h cron and a hard-coded pair would
    // bit-rot. Take the first upcoming conjunction.
    const api = await request.get('/api/v1/conjunctions/upcoming?limit=1');
    expect(api.status()).toBe(200);
    const list = await api.json();
    const row = list.data[0];
    test.skip(row === undefined, 'No upcoming conjunctions in the DB to exercise');
    // ConjunctionListController nests the two satellites: { primary: {norad_id, name, ...}, secondary: {...} }
    const primary = row.primary?.norad_id ?? row.primary;
    const secondary = row.secondary?.norad_id ?? row.secondary;
    expect(typeof primary).toBe('number');
    expect(typeof secondary).toBe('number');

    await page.goto(`/conjunction/${primary}/${secondary}`);

    // The embedded JSON script must be present + parseable.
    const blob = await page.locator('#sat-replay-context').textContent();
    expect(blob).not.toBeNull();
    const ctx = JSON.parse(blob ?? '{}');
    expect(typeof ctx.primary).toBe('number');
    expect(typeof ctx.secondary).toBe('number');
    expect(typeof ctx.tca).toBe('string');
    // Pair is order-insensitive, so the row may have come back with the
    // arguments swapped. Both NORADs must appear.
    expect([ctx.primary, ctx.secondary].sort()).toEqual([primary, secondary].sort());

    // replay-mode attribute lights up on the host element.
    const attr = await page.locator('sat-app').getAttribute('replay-mode');
    expect(attr).toBe('conjunction');
  });

  test('/conjunction/99999/88888 (unknown pair) renders the shell without replay context', async ({ page }) => {
    const res = await page.goto('/conjunction/99999/88888');
    expect(res?.status() ?? 0).toBeLessThan(400);
    const blobLocator = page.locator('#sat-replay-context');
    await expect(blobLocator).toHaveCount(0);
    const attr = await page.locator('sat-app').getAttribute('replay-mode');
    expect(attr).toBeNull();
  });
});
