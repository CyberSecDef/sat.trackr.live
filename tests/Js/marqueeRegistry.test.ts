import { describe, it, expect } from 'vitest';

vi.mock('cesium', () => ({
  Color: { fromCssColorString: (s: string) => ({ css: s }) },
}));

import { vi } from 'vitest';
import { findMarqueeSpec, MARQUEE_SPECS } from '../../resources/js/globe/marqueeRegistry';

describe('marqueeRegistry', () => {
  it('finds ISS by NORAD', () => {
    const spec = findMarqueeSpec(25544);
    expect(spec).not.toBeNull();
    expect(spec?.label).toBe('ISS (ZARYA)');
    expect(spec?.shape).toBe('panel');
  });

  it('finds Hubble by NORAD', () => {
    const spec = findMarqueeSpec(20580);
    expect(spec?.label).toBe('Hubble');
    expect(spec?.shape).toBe('cylinder');
  });

  it('returns null for an unknown NORAD without a name', () => {
    expect(findMarqueeSpec(99999)).toBeNull();
  });

  it('matches Starlink by name prefix when NORAD is unknown', () => {
    const spec = findMarqueeSpec(99999, 'STARLINK-1234');
    expect(spec?.namePrefix).toBe('STARLINK');
    expect(spec?.shape).toBe('panel');
  });

  it('does not match Starlink when name does not start with STARLINK', () => {
    expect(findMarqueeSpec(99999, 'OTHERSAT-1')).toBeNull();
  });

  it('exact NORAD match takes priority over name prefix', () => {
    // ISS (25544) named "STARLINK-FAKE" should still match ISS, not Starlink.
    const spec = findMarqueeSpec(25544, 'STARLINK-FAKE');
    expect(spec?.label).toBe('ISS (ZARYA)');
  });

  it('every spec has positive dimensions and a visual scale > 0', () => {
    for (const spec of MARQUEE_SPECS) {
      expect(spec.dimensionsMeters.x).toBeGreaterThan(0);
      expect(spec.dimensionsMeters.y).toBeGreaterThan(0);
      expect(spec.dimensionsMeters.z).toBeGreaterThan(0);
      expect(spec.visualScale).toBeGreaterThan(0);
    }
  });

  it('every spec has either norad or namePrefix', () => {
    for (const spec of MARQUEE_SPECS) {
      const hasNorad = typeof spec.norad === 'number';
      const hasPrefix = typeof spec.namePrefix === 'string';
      expect(hasNorad || hasPrefix).toBe(true);
    }
  });
});
