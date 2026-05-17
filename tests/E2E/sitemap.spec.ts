import { test, expect } from '@playwright/test';

/**
 * Phase 5 chunk 5C — sitemap + canonical + JSON-LD smoke specs.
 *
 *   1. /sitemap.xml is a valid sitemap-index referencing the chunk files.
 *   2. /sitemap-1.xml is a valid urlset with the expected schema namespace.
 *   3. /text/satellite/{norad} ships a canonical link + Thing JSON-LD.
 *   4. /text/conjunctions ships a canonical link + CollectionPage JSON-LD.
 *   5. /robots.txt advertises the sitemap.
 */
test.describe('Sitemap + canonical + JSON-LD', () => {
  test('/sitemap.xml is a sitemap-index with at least one <sitemap><loc>', async ({ request }) => {
    const r = await request.get('/sitemap.xml');
    expect(r.status()).toBe(200);
    expect(r.headers()['content-type'] ?? '').toMatch(/xml/);
    const body = await r.text();
    expect(body).toContain('<sitemapindex');
    expect(body).toContain('http://www.sitemaps.org/schemas/sitemap/0.9');
    expect(body).toMatch(/<sitemap>[\s\S]*<loc>[^<]+\/sitemap-\d+\.xml<\/loc>/);
  });

  test('/sitemap-1.xml is a valid urlset', async ({ request }) => {
    const r = await request.get('/sitemap-1.xml');
    expect(r.status()).toBe(200);
    const body = await r.text();
    expect(body).toContain('<urlset');
    expect(body).toContain('<url>');
    expect(body).toContain('<loc>');
    expect(body).toContain('<lastmod>');
  });

  test('/robots.txt advertises the sitemap', async ({ request }) => {
    const r = await request.get('/robots.txt');
    expect(r.status()).toBe(200);
    const body = await r.text();
    expect(body.toLowerCase()).toContain('sitemap:');
  });

  test('/text/satellite/25544 declares canonical + Thing JSON-LD', async ({ page }) => {
    await page.goto('/text/satellite/25544');
    const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
    expect(canonical).toMatch(/\/text\/satellite\/25544$/);

    const jsonLdRaw = await page.locator('script[type="application/ld+json"]').textContent();
    expect(jsonLdRaw).not.toBeNull();
    const ld = JSON.parse(jsonLdRaw ?? '{}');
    expect(ld['@context']).toBe('https://schema.org');
    expect(ld['@type']).toBe('Thing');
    expect(ld.identifier).toBe('NORAD:25544');
  });

  test('/text/conjunctions declares canonical + CollectionPage JSON-LD', async ({ page }) => {
    await page.goto('/text/conjunctions');
    const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
    expect(canonical).toMatch(/\/text\/conjunctions$/);

    const jsonLdRaw = await page.locator('script[type="application/ld+json"]').textContent();
    const ld = JSON.parse(jsonLdRaw ?? '{}');
    expect(ld['@type']).toBe('CollectionPage');
  });
});
