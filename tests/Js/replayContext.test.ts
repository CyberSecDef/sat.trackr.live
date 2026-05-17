import { describe, it, expect } from 'vitest';
import {
  parseReplayContext,
  replayEntryTimeMs,
  replayWindowMs,
  REPLAY_WINDOW_MS,
  REPLAY_START_OFFSET_MS,
} from '../../resources/js/replay/replayContext';

const validRaw = {
  primary: 25544,
  secondary: 44713,
  primary_name: 'ISS (ZARYA)',
  secondary_name: 'STARLINK-1007',
  tca: '2026-05-19T17:34:00Z',
  miss_km: 0.42,
  rel_speed_km_s: 12.1,
  probability: 1.2e-5,
};

describe('parseReplayContext', () => {
  it('accepts a fully-populated payload', () => {
    const ctx = parseReplayContext(validRaw);
    expect(ctx.primary).toBe(25544);
    expect(ctx.secondary).toBe(44713);
    expect(ctx.primaryName).toBe('ISS (ZARYA)');
    expect(ctx.secondaryName).toBe('STARLINK-1007');
    expect(ctx.tca).toBe('2026-05-19T17:34:00Z');
    expect(ctx.missKm).toBeCloseTo(0.42);
    expect(ctx.relSpeedKmS).toBe(12.1);
    expect(ctx.probability).toBe(1.2e-5);
  });

  it('treats absent rel_speed_km_s / probability as null', () => {
    const ctx = parseReplayContext({ ...validRaw, rel_speed_km_s: null, probability: null });
    expect(ctx.relSpeedKmS).toBeNull();
    expect(ctx.probability).toBeNull();
  });

  it('rejects malformed timestamps', () => {
    expect(() => parseReplayContext({ ...validRaw, tca: 'not-a-date' })).toThrow(/tca/);
  });

  it('rejects missing required string fields', () => {
    expect(() => parseReplayContext({ ...validRaw, primary_name: '' })).toThrow(/primary_name/);
  });

  it('rejects non-numeric NORADs', () => {
    expect(() => parseReplayContext({ ...validRaw, primary: 'whoops' })).toThrow(/primary/);
  });

  it('rejects null / non-object input', () => {
    expect(() => parseReplayContext(null)).toThrow();
    expect(() => parseReplayContext('foo')).toThrow();
  });
});

describe('replay window math', () => {
  const ctx = parseReplayContext(validRaw);
  const tcaMs = Date.parse('2026-05-19T17:34:00Z');

  it('entry time is TCA − 2 min', () => {
    expect(replayEntryTimeMs(ctx)).toBe(tcaMs + REPLAY_START_OFFSET_MS);
    expect(replayEntryTimeMs(ctx)).toBe(tcaMs - 120_000);
  });

  it('window is TCA ± 5 min', () => {
    const [startMs, endMs] = replayWindowMs(ctx);
    expect(endMs - startMs).toBe(2 * REPLAY_WINDOW_MS);
    expect(startMs).toBe(tcaMs - REPLAY_WINDOW_MS);
    expect(endMs).toBe(tcaMs + REPLAY_WINDOW_MS);
  });

  it('entry time falls inside the window', () => {
    const entry = replayEntryTimeMs(ctx);
    const [startMs, endMs] = replayWindowMs(ctx);
    expect(entry).toBeGreaterThan(startMs);
    expect(entry).toBeLessThan(endMs);
  });
});
