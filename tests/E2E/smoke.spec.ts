import { test, expect } from '@playwright/test';

/**
 * Phase 3 chunk 6C: smoke specs.
 *
 * These don't compare pixels — they just verify the page boots,
 * the topbar renders, and the chunk-4 overlays menu opens.  Visual
 * regression with baseline images is deferred to a Phase 4 follow-
 * up; the goal of chunk 6 is "the tooling is in place and Phase 3
 * features survive a basic load test."
 */

test.describe('SPA loads', () => {
  test('home page boots, no console errors past the WebGL probe', async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') consoleErrors.push(msg.text());
    });

    await page.goto('/');
    await expect(page).toHaveTitle(/sat\.trackr\.live/);

    // Top bar renders.  shadow DOM means we look in the custom element.
    const topbar = page.locator('sat-top-bar');
    await expect(topbar).toBeAttached();

    // The WebGL gate may legitimately log a warning if the headless
    // browser doesn't expose hardware GL — tolerate that one specific
    // error pattern; fail on anything else.
    const fatalErrors = consoleErrors.filter(
      (e) => !/webgl|cesium/i.test(e),
    );
    expect(fatalErrors, fatalErrors.join('\n')).toEqual([]);
  });

  test('overlays menu opens and lists all four toggles', async ({ page }) => {
    await page.goto('/');

    // The overlays menu lives inside <sat-top-bar>'s shadow root.
    // Reach the button via piercing the shadow DOM.
    const menuToggle = page.locator('sat-top-bar')
      .locator('sat-overlays-menu')
      .locator('button.toggle');
    await expect(menuToggle).toBeAttached();
    await menuToggle.click();

    const items = page.locator('sat-top-bar')
      .locator('sat-overlays-menu')
      .locator('div.item');
    await expect(items).toHaveCount(4);
    await expect(items.nth(0)).toContainText(/Orbit ribbon/);
    await expect(items.nth(2)).toContainText(/Ground stations/);
    await expect(items.nth(3)).toContainText(/Light pollution/);
  });

  test('text catalog at /text returns a satellite list (no JS path)', async ({ page }) => {
    await page.goto('/text');
    await expect(page.locator('h1')).toContainText(/catalog/i);
    // The chunk-8 catalog page renders a table with a satellites column.
    await expect(page.locator('table')).toBeAttached();
  });
});
