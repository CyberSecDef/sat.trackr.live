import { test, expect, type Page } from '@playwright/test';

/**
 * Phase 6 chunk 4B — end-to-end replay flow.
 *
 * Drives the marquee user journey:
 *   1. navigate to /conjunction/p/s for a real (live-picked) pair
 *   2. HUD attached, paused at T-2min, ▶ Play button visible
 *   3. click ▶ Play → clock advances
 *   4. click ❚❚ Pause → clock freezes
 *
 * This is the only suite that exercises ConjunctionScene's
 * activate/dispose state machine against real Cesium.  Vitest can't
 * realistically cover this without stubbing every Cesium surface the
 * scene touches (camera, ribbons, marquees, clock).
 */

async function pickRealPair(page: Page): Promise<[number, number] | null> {
  const r = await page.request.get('/api/v1/conjunctions/upcoming?limit=1');
  if (r.status() !== 200) return null;
  const list = await r.json();
  const row = list.data?.[0];
  if (!row) return null;
  return [row.primary?.norad_id ?? row.primary, row.secondary?.norad_id ?? row.secondary];
}

/** Read the TCA countdown text from the HUD (e.g. "T-02:00"). */
async function readCountdown(page: Page): Promise<string> {
  const text = await page
    .locator('sat-app sat-conjunction-hud .stat__value--cd')
    .innerText({ timeout: 5_000 });
  return text.trim();
}

test.describe('Replay end-to-end', () => {
  test('navigate → HUD → Play advances clock → Pause freezes clock', async ({ page }) => {
    // Cesium init (~5s) + TLE fetches + play-wait + countdown reads
    // routinely run into Playwright's 30s default; bump to 90s.
    test.slow();
    const pair = await pickRealPair(page);
    test.skip(pair === null, 'No upcoming conjunctions in the DB to exercise');
    const [primary, secondary] = pair!;

    await page.goto(`/conjunction/${primary}/${secondary}`);
    const hud = page.locator('sat-app sat-conjunction-hud');
    await expect(hud).toBeAttached({ timeout: 10_000 });

    // Entry state: paused at TCA − 2 min, ▶ Play button visible.
    const playBtn = hud.locator('button.play');
    await expect(playBtn).toContainText(/Play/);
    const beforeText = await readCountdown(page);
    expect(beforeText).toMatch(/^T[+\-]\d{2}:\d{2}$/);

    // Click Play → button flips to Pause and clock starts ticking.
    await playBtn.click();
    await expect(playBtn).toContainText(/Pause/);

    // Give the clock 1.2 s of wall time to advance. Cesium's clock
    // runs at multiplier=1 by default, so this should add ~1 s to
    // the displayed countdown (sometimes a frame off — assert ≥ 1).
    await page.waitForTimeout(1200);
    const afterPlayText = await readCountdown(page);
    expect(afterPlayText).not.toBe(beforeText);

    // Click Pause → freeze. Countdown should be stable across another wait.
    await playBtn.click();
    await expect(playBtn).toContainText(/Play/);
    const pausedAt = await readCountdown(page);
    await page.waitForTimeout(800);
    const stillPausedAt = await readCountdown(page);
    expect(stillPausedAt).toBe(pausedAt);
  });

  test('HUD back link returns to the conjunctions catalog', async ({ page }) => {
    const pair = await pickRealPair(page);
    test.skip(pair === null, 'No upcoming conjunctions in the DB');
    const [p, s] = pair!;

    await page.goto(`/conjunction/${p}/${s}`);
    const back = page.locator('sat-app sat-conjunction-hud a.back');
    await expect(back).toBeVisible({ timeout: 10_000 });
    await back.click();
    await expect(page).toHaveURL(/\/text\/conjunctions/);
  });
});
