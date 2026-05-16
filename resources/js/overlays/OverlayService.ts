/**
 * Phase 3 chunk 4B: overlay-toggle service.
 *
 * The `§ overlays` topbar menu (chunk 4B) flips four globe overlays
 * on and off; settings persist to localStorage as `sat:overlays`.
 * The state shape is intentionally narrow — each overlay is a plain
 * boolean keyed by a stable string slug.
 *
 *   ribbons         — chunk-2 orbit ribbon for the selected satellite
 *   marquee         — chunk-3 3D shapes for marquee satellites
 *   stations        — chunk-4 ground-station overlay
 *   lightPollution  — chunk-5 VIIRS overlay (lazy-loaded raster)
 *
 * Layer code subscribes via {@link OverlayService.subscribe} and
 * shows/hides itself when the relevant flag changes.  Like
 * ObserverService, the constructor accepts injectable storage so
 * vitest can swap a fake.
 */
export type OverlayKey = 'ribbons' | 'marquee' | 'stations' | 'lightPollution' | 'aurora';

export type OverlayState = Record<OverlayKey, boolean>;

const STORAGE_KEY = 'sat:overlays';

const DEFAULT_STATE: OverlayState = {
  ribbons: true,
  marquee: true,
  stations: false,
  lightPollution: false,
  aurora: false,
};

interface StorageLike {
  getItem(key: string): string | null;
  setItem(key: string, value: string): void;
  removeItem(key: string): void;
}

export class OverlayService {
  private state: OverlayState;
  private listeners = new Set<(s: OverlayState) => void>();

  constructor(private readonly storage: StorageLike = globalThis.localStorage) {
    this.state = this.loadFromStorage();
  }

  /** Returns a read-only copy of the current state. */
  current(): OverlayState {
    return { ...this.state };
  }

  isEnabled(key: OverlayKey): boolean {
    return this.state[key];
  }

  setEnabled(key: OverlayKey, enabled: boolean): void {
    if (this.state[key] === enabled) return;
    this.state = { ...this.state, [key]: enabled };
    this.persist();
    this.notify();
  }

  /** Subscribe to changes.  Sync-emits the current state on subscribe. */
  subscribe(listener: (state: OverlayState) => void): () => void {
    this.listeners.add(listener);
    listener(this.current());
    return () => this.listeners.delete(listener);
  }

  // ─── Internals ─────────────────────────────────────────────────────────

  private loadFromStorage(): OverlayState {
    try {
      const raw = this.storage.getItem(STORAGE_KEY);
      if (raw === null) return { ...DEFAULT_STATE };
      const parsed = JSON.parse(raw) as Partial<OverlayState>;
      if (typeof parsed !== 'object' || parsed === null) return { ...DEFAULT_STATE };
      // Merge defaults so we silently absorb a future overlay being added.
      return {
        ribbons:        typeof parsed.ribbons        === 'boolean' ? parsed.ribbons        : DEFAULT_STATE.ribbons,
        marquee:        typeof parsed.marquee        === 'boolean' ? parsed.marquee        : DEFAULT_STATE.marquee,
        stations:       typeof parsed.stations       === 'boolean' ? parsed.stations       : DEFAULT_STATE.stations,
        lightPollution: typeof parsed.lightPollution === 'boolean' ? parsed.lightPollution : DEFAULT_STATE.lightPollution,
        aurora:         typeof parsed.aurora         === 'boolean' ? parsed.aurora         : DEFAULT_STATE.aurora,
      };
    } catch {
      return { ...DEFAULT_STATE };
    }
  }

  private persist(): void {
    this.storage.setItem(STORAGE_KEY, JSON.stringify(this.state));
  }

  private notify(): void {
    const snapshot = this.current();
    for (const listener of this.listeners) {
      listener(snapshot);
    }
  }
}

// Singleton accessor — UI + Globe share one instance; tests build their own.
let _shared: OverlayService | null = null;

export function getSharedOverlays(): OverlayService {
  if (_shared === null) {
    _shared = new OverlayService();
  }
  return _shared;
}

/** Test-only: reset between specs. */
export function __resetSharedOverlaysForTests(): void {
  _shared = null;
}
