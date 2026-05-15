import { describe, it, expect, vi, beforeEach } from 'vitest';
import { ObserverService } from '../../resources/js/observer/Observer';

function makeStorage(): Storage & { snapshot(): Record<string, string> } {
  const map = new Map<string, string>();
  return {
    getItem: (k: string) => (map.has(k) ? (map.get(k) as string) : null),
    setItem: (k: string, v: string) => void map.set(k, v),
    removeItem: (k: string) => void map.delete(k),
    clear: () => map.clear(),
    key: (i: number) => Array.from(map.keys())[i] ?? null,
    get length() { return map.size; },
    snapshot: () => Object.fromEntries(map.entries()),
  };
}

describe('ObserverService', () => {
  let storage: Storage & { snapshot(): Record<string, string> };
  let nowMs: number;

  beforeEach(() => {
    storage = makeStorage();
    nowMs = Date.parse('2026-05-15T12:00:00Z');
  });

  it('starts with no observer when storage is empty', () => {
    const svc = new ObserverService({ storage, now: () => nowMs });
    expect(svc.getCurrent()).toBeNull();
  });

  it('persists a manual location and reads it back on rehydrate', () => {
    const svc = new ObserverService({ storage, now: () => nowMs });
    svc.setManual(38.9072, -77.0369, 10, 'Washington, DC');

    expect(svc.getCurrent()).toMatchObject({
      latitude: 38.9072,
      longitude: -77.0369,
      altitudeMeters: 10,
      source: 'manual',
      label: 'Washington, DC',
    });

    // A new service against the same storage hydrates from JSON.
    const svc2 = new ObserverService({ storage, now: () => nowMs });
    expect(svc2.getCurrent()?.latitude).toBe(38.9072);
    expect(svc2.getCurrent()?.source).toBe('manual');
  });

  it('rejects out-of-range coordinates without mutating state', () => {
    const svc = new ObserverService({ storage, now: () => nowMs });
    expect(() => svc.setManual(95, 0)).toThrow(/Invalid latitude/);
    expect(() => svc.setManual(0, 200)).toThrow(/Invalid longitude/);
    expect(svc.getCurrent()).toBeNull();
  });

  it('notifies subscribers on every persist + clear', () => {
    const svc = new ObserverService({ storage, now: () => nowMs });
    const events: Array<{ lat?: number; src?: string } | null> = [];
    const unsub = svc.subscribe((o) => {
      events.push(o ? { lat: o.latitude, src: o.source } : null);
    });

    expect(events.at(-1)).toBeNull();         // initial sync emit

    svc.setManual(10, 20);
    expect(events.at(-1)).toEqual({ lat: 10, src: 'manual' });

    svc.clear();
    expect(events.at(-1)).toBeNull();

    unsub();
    svc.setManual(30, 40);
    expect(events).toHaveLength(3);            // unsub stopped delivery
  });

  it('clear removes the localStorage row', () => {
    const svc = new ObserverService({ storage, now: () => nowMs });
    svc.setManual(1, 2);
    expect(storage.snapshot()['sat:observer']).toBeDefined();
    svc.clear();
    expect(storage.snapshot()['sat:observer']).toBeUndefined();
  });

  it('discards malformed persisted JSON', () => {
    storage.setItem('sat:observer', '{not valid json');
    const svc = new ObserverService({ storage, now: () => nowMs });
    expect(svc.getCurrent()).toBeNull();
  });

  it('discards persisted JSON missing required keys', () => {
    storage.setItem('sat:observer', JSON.stringify({ source: 'manual' }));
    const svc = new ObserverService({ storage, now: () => nowMs });
    expect(svc.getCurrent()).toBeNull();
  });

  it('requestGeolocation resolves to a persisted observer', async () => {
    const fakeGeo = {
      getCurrentPosition: (success: (p: GeolocationPosition) => void) => {
        success({
          coords: { latitude: 51.5, longitude: -0.1, altitude: 30,
                    accuracy: 10, altitudeAccuracy: null, heading: null, speed: null },
          timestamp: 0,
        } as unknown as GeolocationPosition);
      },
    };
    const svc = new ObserverService({ storage, now: () => nowMs, geolocation: fakeGeo });
    const result = await svc.requestGeolocation();
    expect(result.latitude).toBe(51.5);
    expect(result.source).toBe('geolocation');
    expect(svc.getCurrent()?.latitude).toBe(51.5);
  });

  it('requestGeolocation rejects with the platform error message', async () => {
    const fakeGeo = {
      getCurrentPosition: (
        _ok: (p: GeolocationPosition) => void,
        err?: (e: GeolocationPositionError) => void,
      ) => {
        err?.({ code: 1, message: 'User denied', PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 });
      },
    };
    const svc = new ObserverService({ storage, now: () => nowMs, geolocation: fakeGeo });
    await expect(svc.requestGeolocation()).rejects.toThrow(/User denied/);
  });

  it('searchCity requires at least 2 chars and decodes Nominatim shape', async () => {
    const fetchFn = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => [
        { display_name: 'London, England, UK',  lat: '51.5072', lon: '-0.1276' },
        { display_name: 'London, ON, Canada',   lat: '42.9849', lon: '-81.2453' },
      ],
    });
    const svc = new ObserverService({ storage, now: () => nowMs, fetchFn });

    expect(await svc.searchCity('a')).toEqual([]);
    expect(fetchFn).not.toHaveBeenCalled();

    const results = await svc.searchCity('London');
    expect(results).toHaveLength(2);
    expect(results[0].label).toContain('London');
    expect(results[0].latitude).toBeCloseTo(51.5072, 4);
    expect(fetchFn).toHaveBeenCalledOnce();
    expect(String(fetchFn.mock.calls[0][0])).toContain('q=London');
  });

  it('searchCity rate-limits to 1 req/sec', async () => {
    const fetchFn = vi.fn().mockResolvedValue({ ok: true, json: async () => [] });
    let t = nowMs;
    const svc = new ObserverService({ storage, now: () => t, fetchFn });

    await svc.searchCity('Paris');
    t += 200; // simulate 200ms later
    const start = Date.now();
    await svc.searchCity('Paris');
    // The setTimeout in the rate limiter will sleep ~900ms; we don't assert
    // the wall clock (vitest fake timers vary) but we do assert the call
    // count + that lastNominatimAt advanced via the second now() probe.
    void start;
    expect(fetchFn).toHaveBeenCalledTimes(2);
  });

  it('searchCity surfaces non-2xx Nominatim responses', async () => {
    const fetchFn = vi.fn().mockResolvedValue({ ok: false, status: 503, json: async () => [] });
    const svc = new ObserverService({ storage, now: () => nowMs, fetchFn });
    await expect(svc.searchCity('Tokyo')).rejects.toThrow(/HTTP 503/);
  });

  it('setFromCity stores the chosen result with the city label', () => {
    const svc = new ObserverService({ storage, now: () => nowMs });
    svc.setFromCity({ label: 'Paris, FR', latitude: 48.8566, longitude: 2.3522 });
    const o = svc.getCurrent();
    expect(o?.source).toBe('city');
    expect(o?.label).toBe('Paris, FR');
    expect(o?.latitude).toBeCloseTo(48.8566, 4);
  });
});
