import { test, expect } from '@playwright/test';

/**
 * Phase 5 chunk 3C — OpenAPI surface smoke specs.
 *
 *   /api/v1/openapi.json — must be valid OpenAPI 3.1, contain every
 *     Phase-1..4 endpoint, and reference the shared schemas.
 *   /api/v1/docs         — must render an HTML page that includes the
 *     Swagger UI bundle reference (or the fallback message).
 */
test.describe('OpenAPI', () => {
  test('/api/v1/openapi.json is valid 3.1 with all expected routes', async ({ request }) => {
    const r = await request.get('/api/v1/openapi.json');
    expect(r.status()).toBe(200);
    expect(r.headers()['content-type'] ?? '').toContain('application/json');

    const spec = await r.json();
    expect(spec.openapi).toBe('3.1.0');
    expect(spec.info?.title).toBe('sat.trackr.live API');
    expect(spec.info?.license?.identifier).toBe('AGPL-3.0-or-later');
    expect(Array.isArray(spec.servers)).toBe(true);

    const paths = Object.keys(spec.paths ?? {});
    expect(paths.length).toBeGreaterThanOrEqual(21);
    for (const expected of [
      '/api/v1/satellites',
      '/api/v1/satellites/{norad}',
      '/api/v1/satellites/{norad}/radio',
      '/api/v1/conjunctions/upcoming',
      '/api/v1/space-weather/now',
      '/api/v1/stats/{breakdown}',
    ]) {
      expect(paths).toContain(expected);
    }

    const schemas = Object.keys(spec.components?.schemas ?? {});
    for (const expected of ['SatelliteSummary', 'TleCurrent', 'Conjunction', 'RadioTransmitter', 'ErrorResponse']) {
      expect(schemas).toContain(expected);
    }
  });

  test('/api/v1/docs renders the Swagger UI shell', async ({ page }) => {
    await page.goto('/api/v1/docs');
    await expect(page).toHaveTitle(/API docs/);
    // The bundle reference must be present even if the CDN never loads (offline / firewalled).
    const swaggerScript = page.locator('script[src*="swagger-ui-dist"]');
    await expect(swaggerScript).toHaveCount(1);
    // The fallback link to the raw spec is always rendered (visible only if CDN fails).
    await expect(page.locator('a[href="/api/v1/openapi.json"]')).toHaveCount(2);
  });
});
