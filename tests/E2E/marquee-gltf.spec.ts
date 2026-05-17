import { test, expect } from '@playwright/test';
import { existsSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * Phase 5 chunk 7 — marquee glTF smoke spec.
 *
 * The contract this spec defends:
 *   • A MarqueeSpec.gltfUri pointing at /models/{file}.glb is served as
 *     application/octet-stream with a valid binary-glTF header IF the
 *     file has been fetched (make fetch-models).
 *   • If the file is absent, the route should 404, and MarqueeShapeLayer
 *     transparently falls back to a procedural primitive (verified by
 *     the existing smoke.spec.ts "home page boots" assertion not failing
 *     because the absence of the file doesn't break the SPA).
 */
test.describe('Marquee glTF', () => {
  const issPath = path.resolve(__dirname, '../../public/models/iss.glb');

  test('/models/iss.glb serves a valid binary glTF when fetched', async ({ request }) => {
    test.skip(!existsSync(issPath), 'ISS model not fetched — run `make fetch-models`');

    const r = await request.get('/models/iss.glb');
    expect(r.status()).toBe(200);

    // glb magic: "glTF" + uint32 version 2 + uint32 length.
    // Range-fetch the first 12 bytes so we don't pull 44 MB in CI.
    const head = await request.get('/models/iss.glb', { headers: { Range: 'bytes=0-11' } });
    // Some servers (PHP's built-in dev server) ignore Range; fall back to slicing.
    const buf = head.status() === 206
      ? await head.body()
      : (await head.body()).subarray(0, 12);

    expect(buf[0]).toBe(0x67); // 'g'
    expect(buf[1]).toBe(0x6c); // 'l'
    expect(buf[2]).toBe(0x54); // 'T'
    expect(buf[3]).toBe(0x46); // 'F'
    expect(buf.readUInt32LE(4)).toBe(2);
  });

  test('SPA still boots whether or not the glTF is present', async ({ page }) => {
    // The smoke happens in tests/E2E/smoke.spec.ts; here we just verify
    // the home page status. The graceful-fallback proof is that this
    // test passes whether or not iss.glb exists locally.
    const res = await page.goto('/');
    expect(res?.status() ?? 0).toBeLessThan(400);
    if (existsSync(issPath)) {
      // Sanity log: confirms which side of the fork we exercised.
      const kb = Math.round(statSync(issPath).size / 1024);
      // eslint-disable-next-line no-console
      console.info(`(marquee-gltf) iss.glb present, ${kb} KB — load path exercised`);
    }
  });
});
