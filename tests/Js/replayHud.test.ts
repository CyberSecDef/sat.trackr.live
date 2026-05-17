import { describe, it, expect } from 'vitest';
import {
  formatTcaCountdown,
  formatMissDistance,
  liveMissKm,
} from '../../resources/js/replay/replayContext';

describe('formatTcaCountdown', () => {
  it('formats T+00:00 exactly at TCA', () => {
    expect(formatTcaCountdown(0)).toBe('T+00:00');
  });

  it('formats negative offsets as T-MM:SS', () => {
    expect(formatTcaCountdown(-120_000)).toBe('T-02:00');
    expect(formatTcaCountdown(-65_500)).toBe('T-01:05');
  });

  it('formats positive offsets as T+MM:SS', () => {
    expect(formatTcaCountdown(75_000)).toBe('T+01:15');
    expect(formatTcaCountdown(299_999)).toBe('T+04:59');
  });

  it('handles values near zero correctly without sign flipping', () => {
    // 100 ms past TCA still reads as T+, not T-.
    expect(formatTcaCountdown(100)).toBe('T+00:00');
    expect(formatTcaCountdown(-100)).toBe('T-00:00');
  });
});

describe('formatMissDistance', () => {
  it('uses km with 2 decimals at and above 1 km', () => {
    expect(formatMissDistance(1.0)).toBe('1.00 km');
    expect(formatMissDistance(3.314)).toBe('3.31 km');
    expect(formatMissDistance(0.999)).toBe('999 m');
  });

  it('uses integer meters below 1 km', () => {
    expect(formatMissDistance(0.0)).toBe('0 m');
    expect(formatMissDistance(0.042)).toBe('42 m');
    expect(formatMissDistance(0.4999)).toBe('500 m');
  });

  it('returns em-dash for non-finite input', () => {
    expect(formatMissDistance(NaN)).toBe('—');
    expect(formatMissDistance(Infinity)).toBe('—');
  });
});

describe('liveMissKm', () => {
  it('returns null when either position is missing', () => {
    expect(liveMissKm(null, [0, 0, 0])).toBeNull();
    expect(liveMissKm([0, 0, 0], null)).toBeNull();
  });

  it('computes Euclidean distance in km from ECEF meters', () => {
    expect(liveMissKm([0, 0, 0], [3000, 4000, 0])).toBe(5);   // 3-4-5 right triangle
    expect(liveMissKm([1000, 0, 0], [1000, 0, 0])).toBe(0);
  });

  it('handles ISS-scale coordinates without loss', () => {
    // ISS at ~6 800 km altitude — typical magnitudes.
    const a: [number, number, number] = [6_800_000, 0, 0];
    const b: [number, number, number] = [6_800_000, 1000, 0];
    expect(liveMissKm(a, b)).toBeCloseTo(1, 6);
  });
});
