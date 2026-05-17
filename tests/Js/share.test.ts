import { describe, it, expect } from 'vitest';
import { parseShareParams, buildShareParams, buildShareUrl } from '../../resources/js/share/shareUrl';

describe('parseShareParams', () => {
  it('returns empty state for an empty query string', () => {
    expect(parseShareParams('')).toEqual({});
    expect(parseShareParams('?')).toEqual({});
  });

  it('accepts a valid sat NORAD', () => {
    expect(parseShareParams('sat=25544')).toEqual({ sat: 25544 });
    expect(parseShareParams('?sat=25544')).toEqual({ sat: 25544 });
  });

  it('drops malformed sat values silently', () => {
    expect(parseShareParams('sat=abc')).toEqual({});
    expect(parseShareParams('sat=-1')).toEqual({});
    expect(parseShareParams('sat=99999999')).toEqual({});
    expect(parseShareParams('sat=')).toEqual({});
  });

  it('accepts an in-range lat/lon pair and altitude', () => {
    expect(parseShareParams('lat=51.5074&lon=-0.1278')).toEqual({
      lat: 51.5074, lon: -0.1278,
    });
    expect(parseShareParams('lat=51.5074&lon=-0.1278&alt=42')).toEqual({
      lat: 51.5074, lon: -0.1278, altMeters: 42,
    });
  });

  it('refuses to emit a half-set observer (lat without lon)', () => {
    expect(parseShareParams('lat=51.5074')).toEqual({});
    expect(parseShareParams('lon=-0.1278')).toEqual({});
  });

  it('clamps lat/lon out-of-range to dropped', () => {
    expect(parseShareParams('lat=91&lon=0')).toEqual({});
    expect(parseShareParams('lat=0&lon=181')).toEqual({});
  });

  it('round-trips an ISO 8601 timestamp', () => {
    const result = parseShareParams('t=2026-05-16T12:30:00Z');
    expect(result.t).toBe('2026-05-16T12:30:00.000Z');
  });

  it('drops unparseable timestamps', () => {
    expect(parseShareParams('t=not-a-date')).toEqual({});
  });

  it('combines all four together', () => {
    const r = parseShareParams('sat=25544&lat=51.5074&lon=-0.1278&alt=35&t=2026-05-16T12:30:00Z');
    expect(r).toEqual({
      sat: 25544,
      lat: 51.5074,
      lon: -0.1278,
      altMeters: 35,
      t: '2026-05-16T12:30:00.000Z',
    });
  });
});

describe('buildShareParams', () => {
  it('emits nothing for an empty state', () => {
    expect(buildShareParams({})).toBe('');
  });

  it('emits sat alone', () => {
    expect(buildShareParams({ sat: 25544 })).toBe('sat=25544');
  });

  it('drops a 0 altitude (always meant "no altitude")', () => {
    expect(buildShareParams({ lat: 51.5, lon: -0.13, altMeters: 0 })).toBe('lat=51.5&lon=-0.13');
  });

  it('emits altitude only when both lat and lon are present', () => {
    expect(buildShareParams({ altMeters: 42 })).toBe('');
  });

  it('rounds coordinates to 4 decimal places (~11m)', () => {
    expect(buildShareParams({ lat: 51.50742123, lon: -0.12781999 })).toBe('lat=51.5074&lon=-0.1278');
  });

  it('round-trips through parseShareParams', () => {
    const state = { sat: 25544, lat: 51.5074, lon: -0.1278, altMeters: 35, t: '2026-05-16T12:30:00.000Z' };
    const round = parseShareParams(buildShareParams(state));
    expect(round).toEqual(state);
  });
});

describe('buildShareUrl', () => {
  it('combines origin + path + query', () => {
    expect(buildShareUrl('https://sat.trackr.live', '/', { sat: 25544 }))
      .toBe('https://sat.trackr.live/?sat=25544');
  });

  it('returns origin + path when there is nothing to share', () => {
    expect(buildShareUrl('https://sat.trackr.live', '/', {}))
      .toBe('https://sat.trackr.live/');
  });

  it('treats an empty pathname as "/"', () => {
    expect(buildShareUrl('https://sat.trackr.live', '', { sat: 1 }))
      .toBe('https://sat.trackr.live/?sat=1');
  });
});
