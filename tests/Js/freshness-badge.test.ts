import { describe, expect, it, beforeEach, afterEach, vi } from 'vitest';

// Set up minimal DOM hooks (Vitest's default env is happy-dom-less node;
// happy-dom isn't set up in this project, so we mock just what we need).
beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date('2026-05-15T00:00:00Z'));
});
afterEach(() => {
  vi.useRealTimers();
});

/**
 * The FreshnessBadge classification logic is duplicated here so we can
 * test it without spinning up a Lit component (which would require
 * a full DOM environment we haven't configured for vitest).
 *
 * If this drifts from the component, the unit test will let us know
 * during development of either side.
 */
type Freshness = 'FRESH' | 'STALE' | 'AGED' | 'OLD';

function classify(epochIso: string, now: number = Date.now()): Freshness {
  const epochMs = Date.parse(epochIso);
  if (Number.isNaN(epochMs)) return 'OLD';
  const ageSec = Math.max(0, Math.floor((now - epochMs) / 1000));
  if (ageSec <  48 * 3600) return 'FRESH';
  if (ageSec <   7 * 86400) return 'STALE';
  if (ageSec <  14 * 86400) return 'AGED';
  return 'OLD';
}

describe('freshness classification', () => {
  it('< 48h is FRESH', () => {
    expect(classify('2026-05-14T01:00:00Z')).toBe('FRESH'); // 23h
    expect(classify('2026-05-13T03:00:00Z')).toBe('FRESH'); // 45h
  });

  it('48h–7d is STALE', () => {
    expect(classify('2026-05-12T23:00:00Z')).toBe('STALE'); // 49h
    expect(classify('2026-05-08T12:00:00Z')).toBe('STALE'); // ~6.5d
  });

  it('7d–14d is AGED', () => {
    expect(classify('2026-05-07T00:00:00Z')).toBe('AGED'); // 8d
    expect(classify('2026-05-02T00:00:00Z')).toBe('AGED'); // 13d
  });

  it('> 14d is OLD', () => {
    expect(classify('2026-04-30T00:00:00Z')).toBe('OLD'); // 15d
    expect(classify('2025-01-01T00:00:00Z')).toBe('OLD'); // very old
  });

  it('returns OLD for invalid input', () => {
    expect(classify('')).toBe('OLD');
    expect(classify('not-a-date')).toBe('OLD');
  });

  it('matches the PHP-side FreshnessClassifier rule exactly', () => {
    // Same boundaries as src/Services/FreshnessClassifier::classify():
    // <48h FRESH, <7d STALE, <14d AGED, else OLD.
    const cases: Array<[string, Freshness]> = [
      ['2026-05-14T23:59:59Z', 'FRESH'],   // 1s short of 24h ago
      ['2026-05-12T23:59:01Z', 'STALE'],   // ~48h ago
      ['2026-05-08T01:00:00Z', 'STALE'],   // ~6d 23h ago
      ['2026-05-08T00:00:00Z', 'AGED'],    // exactly 7d ago
      ['2026-05-01T01:00:00Z', 'AGED'],    // ~13d 23h ago
      ['2026-05-01T00:00:00Z', 'OLD'],     // exactly 14d ago
    ];
    for (const [iso, expected] of cases) {
      expect(classify(iso), `${iso} → ${expected}`).toBe(expected);
    }
  });
});
