import { describe, it, expect } from 'vitest';
import {
  parseReplayContext,
  replayEntryTimeMs,
  replayWindowMs,
  REPLAY_WINDOW_MS,
  REPLAY_START_OFFSET_MS,
} from '../../resources/js/replay/replayContext';

/**
 * Phase 6 chunk 4 — edge-case coverage for the replay window math.
 *
 * Honest note on what this file does NOT cover: the ConjunctionScene
 * activate/dispose state machine is exercised end-to-end by the
 * Playwright suite (chunk 4B) instead.  Vitest runs in Node without
 * WebGL, and stubbing every Cesium surface ConjunctionScene touches
 * would inflate the spec without buying real confidence — the actual
 * failures we care about (camera framing breaks, clock window doesn't
 * restore, ribbons render once and never re-render) only manifest
 * in a real browser.
 */

const buildCtx = (tca: string) =>
  parseReplayContext({
    primary: 25544,
    secondary: 44713,
    primary_name: 'ISS',
    secondary_name: 'X',
    tca,
    miss_km: 1,
    rel_speed_km_s: 10,
    probability: 1e-4,
  });

describe('replay window math — edge cases', () => {
  it('handles fractional-second ISO timestamps', () => {
    const ctx = buildCtx('2026-05-19T17:34:05.231Z');
    const tcaMs = Date.parse('2026-05-19T17:34:05.231Z');
    expect(replayEntryTimeMs(ctx)).toBe(tcaMs - 2 * 60_000);
    const [s, e] = replayWindowMs(ctx);
    expect(s).toBe(tcaMs - REPLAY_WINDOW_MS);
    expect(e).toBe(tcaMs + REPLAY_WINDOW_MS);
  });

  it('handles ISO timestamps with explicit UTC offset', () => {
    // ISO 8601 allows +00:00 alongside Z; Date.parse normalizes both.
    const ctx = buildCtx('2026-05-19T17:34:00+00:00');
    expect(replayEntryTimeMs(ctx)).toBe(Date.parse('2026-05-19T17:34:00Z') + REPLAY_START_OFFSET_MS);
  });

  it('window radius is exactly 5 minutes; entry offset is exactly 2 minutes', () => {
    expect(REPLAY_WINDOW_MS).toBe(5 * 60_000);
    expect(REPLAY_START_OFFSET_MS).toBe(-2 * 60_000);
  });

  it('produces a window strictly containing the entry time + TCA + 5min mark', () => {
    const ctx = buildCtx('2026-05-19T17:34:00Z');
    const tcaMs = Date.parse('2026-05-19T17:34:00Z');
    const [startMs, endMs] = replayWindowMs(ctx);
    expect(startMs).toBeLessThan(replayEntryTimeMs(ctx));
    expect(replayEntryTimeMs(ctx)).toBeLessThan(tcaMs);
    expect(tcaMs).toBeLessThan(endMs);
    // The seek head should never escape the window during normal scrub.
    expect(endMs - startMs).toBe(2 * REPLAY_WINDOW_MS);
  });

  it('window math is stable across years (no Date timezone surprises)', () => {
    // Pick a TCA that spans a DST boundary — should be irrelevant since
    // all replay math is in UTC milliseconds.
    const ctx = buildCtx('2026-03-08T07:00:00Z'); // ~spring forward in US
    const tcaMs = Date.parse('2026-03-08T07:00:00Z');
    expect(replayEntryTimeMs(ctx)).toBe(tcaMs - 120_000);
  });
});
