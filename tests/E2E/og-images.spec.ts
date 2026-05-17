import { test, expect } from '@playwright/test';

/**
 * Phase 5 chunk 4C — OG image surface specs.
 *
 *   1. The card endpoints return 1200×630 PNGs with the long-cache header.
 *   2. The /text pages declare the right og:image URL.
 */
test.describe('OG cards', () => {
  test('satellite + events PNG endpoints return cached 1200x630 images', async ({ request }) => {
    for (const path of ['/og/satellite/25544.png', '/og/events.png']) {
      const r = await request.get(path);
      expect(r.status(), `${path} should be 200`).toBe(200);
      expect(r.headers()['content-type']).toContain('image/png');
      expect(r.headers()['cache-control']).toContain('max-age=21600');
      const body = await r.body();
      expect(body.byteLength).toBeGreaterThan(1000);
      // PNG signature: 89 50 4E 47 0D 0A 1A 0A
      expect(body[0]).toBe(0x89);
      expect(body[1]).toBe(0x50);
      expect(body[2]).toBe(0x4e);
      expect(body[3]).toBe(0x47);
    }
  });

  test('unknown OG type returns 404', async ({ request }) => {
    const r = await request.get('/og/bogus/123.png');
    expect(r.status()).toBe(404);
  });

  test('/text/satellite/25544 declares the satellite OG image', async ({ page }) => {
    const res = await page.goto('/text/satellite/25544');
    expect(res?.status() ?? 0).toBeLessThan(400);
    const og = await page.locator('meta[property="og:image"]').getAttribute('content');
    expect(og).toBe('/og/satellite/25544.png');
    const tw = await page.locator('meta[name="twitter:image"]').getAttribute('content');
    expect(tw).toBe('/og/satellite/25544.png');
    const card = await page.locator('meta[name="twitter:card"]').getAttribute('content');
    expect(card).toBe('summary_large_image');
  });

  test('/text/conjunctions inherits the default events OG image', async ({ page }) => {
    const res = await page.goto('/text/conjunctions');
    expect(res?.status() ?? 0).toBeLessThan(400);
    const og = await page.locator('meta[property="og:image"]').getAttribute('content');
    expect(og).toBe('/og/events.png');
  });
});
