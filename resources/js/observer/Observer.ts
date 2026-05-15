/**
 * Phase 2 chunk 5: observer-location service.
 *
 * The observer is the user's position on Earth — needed once we start
 * computing satellite passes (chunk 6) and "above horizon now?" hints
 * for the detail panel.  Persisted to localStorage as JSON under
 * `sat:observer` so it survives reloads.
 *
 * Three input modes are supported:
 *   - `geolocation` — navigator.geolocation.getCurrentPosition
 *   - `city`        — Nominatim free-text search (rate-limited 1 req/s)
 *   - `manual`      — explicit lat/lon/alt typed by the user
 *
 * The class is a small singleton (`Observer.shared`) but accepts
 * dependencies (storage, fetch, geolocation API) in its constructor
 * so unit tests can swap in fakes.
 */

export type ObserverSource = 'geolocation' | 'city' | 'manual';

export interface Observer {
  latitude: number;
  longitude: number;
  altitudeMeters: number;
  source: ObserverSource;
  label?: string;
  setAt: string; // ISO datetime
}

export interface CitySearchResult {
  label: string;
  latitude: number;
  longitude: number;
}

const STORAGE_KEY = 'sat:observer';
const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
const NOMINATIM_MIN_INTERVAL_MS = 1100; // Nominatim's etiquette: max 1 req/s

interface StorageLike {
  getItem(key: string): string | null;
  setItem(key: string, value: string): void;
  removeItem(key: string): void;
}

interface GeolocationLike {
  getCurrentPosition(
    success: (position: GeolocationPosition) => void,
    error?: (err: GeolocationPositionError) => void,
    options?: PositionOptions,
  ): void;
}

type FetchLike = typeof fetch;
type Listener = (observer: Observer | null) => void;

export interface ObserverDeps {
  storage?: StorageLike;
  geolocation?: GeolocationLike | null;
  fetchFn?: FetchLike;
  now?: () => number;
}

export class ObserverService {
  private current: Observer | null = null;
  private listeners = new Set<Listener>();
  private lastNominatimAt = 0;

  private readonly storage: StorageLike;
  private readonly geolocation: GeolocationLike | null;
  private readonly fetchFn: FetchLike;
  private readonly now: () => number;

  constructor(deps: ObserverDeps = {}) {
    this.storage     = deps.storage     ?? globalThis.localStorage;
    this.geolocation = deps.geolocation ?? (globalThis.navigator?.geolocation ?? null);
    this.fetchFn     = deps.fetchFn     ?? globalThis.fetch.bind(globalThis);
    this.now         = deps.now         ?? Date.now;
    this.current     = this.loadFromStorage();
  }

  /** The most-recently-set observer, or null if none. */
  getCurrent(): Observer | null {
    return this.current;
  }

  /** Persist a manually-entered location. Validates and notifies subscribers. */
  setManual(latitude: number, longitude: number, altitudeMeters = 0, label?: string): Observer {
    const obs = this.build(latitude, longitude, altitudeMeters, 'manual', label);
    this.persist(obs);
    return obs;
  }

  /** Persist a Nominatim city result. */
  setFromCity(result: CitySearchResult, altitudeMeters = 0): Observer {
    const obs = this.build(result.latitude, result.longitude, altitudeMeters, 'city', result.label);
    this.persist(obs);
    return obs;
  }

  /** Wraps `navigator.geolocation.getCurrentPosition` in a Promise. */
  requestGeolocation(timeoutMs = 10_000): Promise<Observer> {
    if (!this.geolocation) {
      return Promise.reject(new Error('Geolocation is not available in this browser'));
    }
    return new Promise<Observer>((resolve, reject) => {
      this.geolocation!.getCurrentPosition(
        (pos) => {
          const obs = this.build(
            pos.coords.latitude,
            pos.coords.longitude,
            pos.coords.altitude ?? 0,
            'geolocation',
          );
          this.persist(obs);
          resolve(obs);
        },
        (err) => reject(new Error(err.message || 'Geolocation request failed')),
        { enableHighAccuracy: false, maximumAge: 60_000, timeout: timeoutMs },
      );
    });
  }

  /**
   * Free-text city search via Nominatim.  Rate-limited to 1 req/s per
   * Nominatim's etiquette.  Returns at most 5 results — the caller
   * picks one and feeds it to {@link setFromCity}.
   */
  async searchCity(query: string): Promise<CitySearchResult[]> {
    const q = query.trim();
    if (q.length < 2) {
      return [];
    }
    const elapsed = this.now() - this.lastNominatimAt;
    if (elapsed < NOMINATIM_MIN_INTERVAL_MS) {
      await new Promise((r) => setTimeout(r, NOMINATIM_MIN_INTERVAL_MS - elapsed));
    }
    this.lastNominatimAt = this.now();

    const url = `${NOMINATIM_URL}?format=json&limit=5&q=${encodeURIComponent(q)}`;
    const response = await this.fetchFn(url, {
      headers: { 'Accept-Language': 'en', 'User-Agent': 'sat.trackr.live/0.1 (observer search)' },
    });
    if (!response.ok) {
      throw new Error(`Nominatim returned HTTP ${response.status}`);
    }
    const raw = (await response.json()) as Array<{ display_name: string; lat: string; lon: string }>;
    return raw
      .map((row) => ({
        label: row.display_name,
        latitude: Number(row.lat),
        longitude: Number(row.lon),
      }))
      .filter((r) => Number.isFinite(r.latitude) && Number.isFinite(r.longitude));
  }

  /** Forget the stored observer and notify subscribers. */
  clear(): void {
    this.current = null;
    this.storage.removeItem(STORAGE_KEY);
    this.notify();
  }

  /** Subscribe to changes. Returns an unsubscribe function. */
  subscribe(listener: Listener): () => void {
    this.listeners.add(listener);
    listener(this.current);
    return () => this.listeners.delete(listener);
  }

  // ─── Internals ─────────────────────────────────────────────────────────

  private build(
    latitude: number,
    longitude: number,
    altitudeMeters: number,
    source: ObserverSource,
    label?: string,
  ): Observer {
    if (!Number.isFinite(latitude) || latitude < -90 || latitude > 90) {
      throw new Error(`Invalid latitude ${latitude}`);
    }
    if (!Number.isFinite(longitude) || longitude < -180 || longitude > 180) {
      throw new Error(`Invalid longitude ${longitude}`);
    }
    if (!Number.isFinite(altitudeMeters)) {
      throw new Error(`Invalid altitude ${altitudeMeters}`);
    }
    return {
      latitude,
      longitude,
      altitudeMeters,
      source,
      label,
      setAt: new Date(this.now()).toISOString(),
    };
  }

  private persist(obs: Observer): void {
    this.current = obs;
    this.storage.setItem(STORAGE_KEY, JSON.stringify(obs));
    this.notify();
  }

  private notify(): void {
    for (const listener of this.listeners) {
      listener(this.current);
    }
  }

  private loadFromStorage(): Observer | null {
    try {
      const raw = this.storage.getItem(STORAGE_KEY);
      if (raw === null) {
        return null;
      }
      const parsed = JSON.parse(raw) as Observer;
      // Sanity-check the persisted shape — discard rather than crash.
      if (
        typeof parsed !== 'object'
        || parsed === null
        || typeof parsed.latitude !== 'number'
        || typeof parsed.longitude !== 'number'
      ) {
        return null;
      }
      return parsed;
    } catch {
      return null;
    }
  }
}

// Singleton accessor — UI code uses `observer` directly, tests build
// their own ObserverService with fakes.
let _shared: ObserverService | null = null;

export function getSharedObserver(): ObserverService {
  if (_shared === null) {
    _shared = new ObserverService();
  }
  return _shared;
}

/** Test-only: reset the shared singleton between specs. */
export function __resetSharedObserverForTests(): void {
  _shared = null;
}
