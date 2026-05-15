import { describe, it, expect } from 'vitest';
import { twoline2satrec } from 'satellite.js';
import { computeGroundTrack, periodMinutes } from '../../resources/js/passes/computeGroundTrack';

// ISS TLE used throughout Phase 2 chunk 6 tests (epoch 2026-05-14).
const ISS_LINE_1 = '1 25544U 98067A   26134.20272636  .00027410  00000-0  47815-3 0  9994';
const ISS_LINE_2 = '2 25544  51.6358 207.8530 0001859  44.6849 315.4254 15.50907195502493';

const T0 = Date.parse('2026-05-15T00:00:00Z');

describe('periodMinutes', () => {
  it('returns ~93 min for ISS', () => {
    const satrec = twoline2satrec(ISS_LINE_1, ISS_LINE_2);
    expect(periodMinutes(satrec)).toBeGreaterThan(91);
    expect(periodMinutes(satrec)).toBeLessThan(94);
  });
});

describe('computeGroundTrack', () => {
  it('returns 360 samples by default for ±1 orbit', () => {
    const satrec = twoline2satrec(ISS_LINE_1, ISS_LINE_2);
    const points = computeGroundTrack({ satrec, centerTimeMs: T0 });
    expect(points.length).toBeGreaterThanOrEqual(355);
    expect(points.length).toBeLessThanOrEqual(361);
  });

  it('respects past/future orbit counts independently', () => {
    const satrec = twoline2satrec(ISS_LINE_1, ISS_LINE_2);
    const oneEach   = computeGroundTrack({ satrec, centerTimeMs: T0, pastOrbits: 1, futureOrbits: 1 });
    const futureOnly = computeGroundTrack({ satrec, centerTimeMs: T0, pastOrbits: 0, futureOrbits: 2 });
    expect(futureOnly.length).toBeGreaterThanOrEqual(oneEach.length - 5);
    // pastOrbits=0 means everything is at or after centerTimeMs
    for (const p of futureOnly) {
      expect(p.timeMs).toBeGreaterThanOrEqual(T0 - 1);
    }
  });

  it('produces lat/lon within Earth bounds for ISS', () => {
    const satrec = twoline2satrec(ISS_LINE_1, ISS_LINE_2);
    const points = computeGroundTrack({ satrec, centerTimeMs: T0 });
    for (const p of points) {
      expect(p.latitudeDeg).toBeGreaterThanOrEqual(-90);
      expect(p.latitudeDeg).toBeLessThanOrEqual(90);
      // ISS inclination is 51.6°, so |lat| should never exceed ~52°
      expect(Math.abs(p.latitudeDeg)).toBeLessThan(53);
      expect(p.longitudeDeg).toBeGreaterThanOrEqual(-180);
      expect(p.longitudeDeg).toBeLessThanOrEqual(180);
      // ISS altitude is ~415-425 km
      expect(p.altitudeMeters).toBeGreaterThan(380_000);
      expect(p.altitudeMeters).toBeLessThan(450_000);
    }
  });

  it('samples are time-ordered and span the requested window', () => {
    const satrec = twoline2satrec(ISS_LINE_1, ISS_LINE_2);
    const points = computeGroundTrack({ satrec, centerTimeMs: T0, pastOrbits: 1, futureOrbits: 1 });
    expect(points[0].timeMs).toBeLessThan(T0);
    expect(points[points.length - 1].timeMs).toBeGreaterThan(T0);
    for (let i = 1; i < points.length; i++) {
      expect(points[i].timeMs).toBeGreaterThan(points[i - 1].timeMs);
    }
  });

  it('produces a sinusoidal ground-track pattern (lat goes through both signs)', () => {
    const satrec = twoline2satrec(ISS_LINE_1, ISS_LINE_2);
    const points = computeGroundTrack({ satrec, centerTimeMs: T0 });
    const positiveLat = points.filter((p) => p.latitudeDeg > 30);
    const negativeLat = points.filter((p) => p.latitudeDeg < -30);
    // ISS at 51.6° inclination crosses both hemispheres each orbit.
    expect(positiveLat.length).toBeGreaterThan(20);
    expect(negativeLat.length).toBeGreaterThan(20);
  });

  it('respects samplesPerOrbit', () => {
    const satrec = twoline2satrec(ISS_LINE_1, ISS_LINE_2);
    const sparse = computeGroundTrack({ satrec, centerTimeMs: T0, samplesPerOrbit: 30 });
    const dense  = computeGroundTrack({ satrec, centerTimeMs: T0, samplesPerOrbit: 360 });
    expect(sparse.length).toBeLessThan(dense.length / 5);
  });
});
