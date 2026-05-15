import { describe, it, expect } from 'vitest';
import { computePassesFromTle } from '../../resources/js/passes/computePasses';

// ISS TLE from a real CelesTrak GP fetch (epoch 2026-05-14).
const ISS_LINE_1 = '1 25544U 98067A   26134.20272636  .00027410  00000-0  47815-3 0  9994';
const ISS_LINE_2 = '2 25544  51.6358 207.8530 0001859  44.6849 315.4254 15.50907195502493';

const LONDON   = { latitude: 51.5072, longitude: -0.1276, altitudeMeters: 30 };
const SOUTHERN = { latitude: -54.8019, longitude: -68.3030, altitudeMeters: 0 };  // Ushuaia

const T0 = Date.parse('2026-05-15T00:00:00Z');

describe('computePasses', () => {
  it('produces a plausible 3-day pass list for ISS over London', () => {
    const passes = computePassesFromTle(ISS_LINE_1, ISS_LINE_2, LONDON, T0, 3);

    // ISS makes ~16 orbits/day; from London latitude (51.5°N) we typically
    // see between 4-8 visible passes per day. A 3-day window therefore
    // produces somewhere in the 8–25 range — bracket loosely so the
    // assertion isn't fragile when satellite.js bumps version.
    expect(passes.length).toBeGreaterThanOrEqual(6);
    expect(passes.length).toBeLessThanOrEqual(40);

    for (const p of passes) {
      // Basic sanity: rise < peak <= set, all ISO, max_el >= 10° (default).
      expect(Date.parse(p.rise_at)).toBeLessThan(Date.parse(p.peak_at));
      expect(Date.parse(p.peak_at)).toBeLessThanOrEqual(Date.parse(p.set_at));
      expect(p.max_elevation_deg).toBeGreaterThanOrEqual(10);
      expect(p.duration_seconds).toBeGreaterThan(0);
      expect(p.duration_seconds).toBeLessThan(20 * 60);   // ISS pass < 20 min
      // Azimuth wraps 0..360.
      for (const az of [p.rise_azimuth_deg, p.peak_azimuth_deg, p.set_azimuth_deg]) {
        expect(az).toBeGreaterThanOrEqual(0);
        expect(az).toBeLessThan(360);
      }
    }
  });

  it('respects min_elevation threshold (higher = fewer passes)', () => {
    const low  = computePassesFromTle(ISS_LINE_1, ISS_LINE_2, LONDON, T0, 3, 10);
    const high = computePassesFromTle(ISS_LINE_1, ISS_LINE_2, LONDON, T0, 3, 30);
    expect(high.length).toBeLessThanOrEqual(low.length);
    for (const p of high) {
      expect(p.max_elevation_deg).toBeGreaterThanOrEqual(30);
    }
  });

  it('returns no passes for an observer the satellite never reaches', () => {
    // ISS inclination is 51.6° — Ushuaia at 54.8° S can occasionally see
    // it, but only at low elevation. With min_elevation=70° (above what
    // any ISS pass over Ushuaia ever reaches) we expect an empty list.
    const passes = computePassesFromTle(ISS_LINE_1, ISS_LINE_2, SOUTHERN, T0, 3, 70);
    expect(passes).toEqual([]);
  });

  it('respects an explicit duration bound (1 day < 7 days)', () => {
    const oneDay   = computePassesFromTle(ISS_LINE_1, ISS_LINE_2, LONDON, T0, 1);
    const sevenDay = computePassesFromTle(ISS_LINE_1, ISS_LINE_2, LONDON, T0, 7);
    expect(oneDay.length).toBeLessThan(sevenDay.length);
    for (const p of oneDay) {
      expect(Date.parse(p.set_at)).toBeLessThanOrEqual(T0 + 86_400_000 + 60_000);
    }
  });

  it('orders passes chronologically', () => {
    const passes = computePassesFromTle(ISS_LINE_1, ISS_LINE_2, LONDON, T0, 5);
    for (let i = 1; i < passes.length; i++) {
      expect(Date.parse(passes[i].rise_at)).toBeGreaterThan(Date.parse(passes[i - 1].set_at));
    }
  });
});
