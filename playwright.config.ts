import { defineConfig, devices } from '@playwright/test';

/**
 * Phase 3 chunk 6C: Playwright smoke-test runner.
 *
 * Boots the existing PHP dev server (`make dev` style) and runs
 * Chromium against http://localhost:8000.  Visual-diff baselines
 * for the chunk-1..5 overlays are deferred to a Phase 4 follow-up
 * — chunk 6 ships the Playwright tooling + a smoke suite that
 * catches "the page failed to render at all".
 */
export default defineConfig({
  testDir: './tests/E2E',
  testMatch: /.*\.spec\.ts$/,
  fullyParallel: false,                    // single-server, single-browser
  retries: 0,
  reporter: [['list']],

  use: {
    baseURL: 'http://localhost:8000',
    trace: 'retain-on-failure',
    headless: true,
    viewport: { width: 1280, height: 800 },
  },

  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],

  webServer: {
    // Spin up the same PHP dev server `make serve` uses.  Reuse
    // an existing one when running locally so the developer doesn't
    // get a port collision.
    command: 'php -S 0.0.0.0:8000 -t public/ public/index.php',
    url: 'http://localhost:8000',
    reuseExistingServer: true,
    timeout: 30_000,
  },
});
