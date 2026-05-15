import { describe, it, expect } from 'vitest';
import data from '../../resources/data/ground_stations.json';

interface Station {
  id: string;
  name: string;
  network: string;
  operator: string;
  country: string;
  latitude_deg: number;
  longitude_deg: number;
  altitude_m: number;
}

const stations = data as Station[];

describe('ground_stations.json', () => {
  it('contains a sane number of stations', () => {
    expect(stations.length).toBeGreaterThanOrEqual(35);
    expect(stations.length).toBeLessThan(80);
  });

  it('every station has a unique id', () => {
    const ids = new Set(stations.map((s) => s.id));
    expect(ids.size).toBe(stations.length);
  });

  it('every station has lat/lon within Earth bounds', () => {
    for (const s of stations) {
      expect(s.latitude_deg).toBeGreaterThanOrEqual(-90);
      expect(s.latitude_deg).toBeLessThanOrEqual(90);
      expect(s.longitude_deg).toBeGreaterThanOrEqual(-180);
      expect(s.longitude_deg).toBeLessThanOrEqual(180);
    }
  });

  it('covers the locked-in network roster', () => {
    const networks = new Set(stations.map((s) => s.network));
    for (const expected of ['NEN', 'DSN', 'ESTRACK', 'JAXA', 'ISRO', 'KSAT', 'AWS']) {
      expect(networks).toContain(expected);
    }
  });

  it('covers both hemispheres meaningfully', () => {
    const north = stations.filter((s) => s.latitude_deg > 0).length;
    const south = stations.filter((s) => s.latitude_deg < 0).length;
    expect(north).toBeGreaterThan(15);
    expect(south).toBeGreaterThan(5);
  });

  it('has every required field on every station', () => {
    for (const s of stations) {
      expect(typeof s.id).toBe('string');
      expect(typeof s.name).toBe('string');
      expect(typeof s.network).toBe('string');
      expect(typeof s.operator).toBe('string');
      expect(typeof s.country).toBe('string');
      expect(typeof s.altitude_m).toBe('number');
      expect(s.country.length).toBe(2);             // ISO-3166 alpha-2
    }
  });
});
