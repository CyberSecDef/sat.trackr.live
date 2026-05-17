import { test, expect } from '@playwright/test';

/**
 * Phase 5 chunk 6 — deep-link + Share button e2e.
 *
 * Deep-link: navigating with ?sat=25544 should select the satellite on
 * the globe (the detail panel opens with the name visible).
 *
 * Share button: clicking it should call navigator.clipboard.writeText
 * (Playwright runs without navigator.share — falls through to clipboard)
 * with a URL that carries the current state back.
 */
test.describe('Share deep-links', () => {
  test('?sat=25544 selects ISS on first load', async ({ page }) => {
    await page.goto('/?sat=25544');
    // Wait for the panel to render the NORAD line, which always contains
    // the requested ID. (The name line gets the satellite's actual name,
    // which can be "ISS (ZARYA)" / "Loading…" / etc. depending on timing.)
    const panelNoradLine = page.locator('sat-detail-panel').locator('.header__id');
    await expect(panelNoradLine).toContainText('25544', { timeout: 10_000 });
  });

  test('?sat with a bogus value gracefully loads without selection', async ({ page }) => {
    const resp = await page.goto('/?sat=abc');
    expect(resp?.status() ?? 0).toBeLessThan(400);
    // The detail panel shouldn't be open. Its `open` attribute reflects `.norad !== null`.
    const panelOpenAttr = await page.locator('sat-detail-panel').getAttribute('open');
    expect(panelOpenAttr).toBeNull();
  });

  test('Share button is present in the topbar and clickable', async ({ page }) => {
    await page.goto('/?sat=25544');
    const btn = page.locator('sat-top-bar sat-share-button').locator('button');
    await expect(btn).toBeVisible({ timeout: 10_000 });
    await expect(btn).toContainText('Share');
    // Clicking shouldn't throw; what happens after (navigator.share sheet
    // vs clipboard write vs AbortError on dismiss) is browser-specific and
    // covered by the share.test.ts unit suite for URL composition.
    await btn.click();
  });
});
