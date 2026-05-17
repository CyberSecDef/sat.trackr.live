/**
 * Phase 6 chunk 1 — typed accessor + validator for the replay-context
 * payload embedded by SpaShellController.
 *
 * On a /conjunction/{primary}/{secondary} route, the shell renders
 *   <script id="sat-replay-context" type="application/json">{...}</script>
 * carrying the soonest-TCA row for the pair.  The SPA reads it once at
 * boot, falls back to fetching /api/v1/conjunctions/{p}/{s} if the
 * embed is missing (e.g. someone navigates client-side), and enters
 * replay state.
 *
 * Pure helpers, no DOM beyond reading a single script tag.
 */

export interface ReplayContext {
  primary: number;
  secondary: number;
  primaryName: string;
  secondaryName: string;
  /** ISO 8601 UTC. */
  tca: string;
  /** Miss distance at TCA in kilometers (authoritative; live HUD is a separate concern). */
  missKm: number;
  /** Relative velocity at TCA in km/s; null when SOCRATES didn't ship it. */
  relSpeedKmS: number | null;
  /** Foster-method collision probability; null when SOCRATES didn't ship it. */
  probability: number | null;
}

/** Read the JSON blob from the shell, or null if the route didn't embed one. */
export function readEmbeddedReplayContext(): ReplayContext | null {
  if (typeof document === 'undefined') return null;
  const el = document.getElementById('sat-replay-context');
  if (el === null) return null;
  try {
    return parseReplayContext(JSON.parse(el.textContent ?? '{}'));
  } catch {
    return null;
  }
}

/** Validates an unknown payload into a {@link ReplayContext}; throws on malformed input. */
export function parseReplayContext(raw: unknown): ReplayContext {
  if (typeof raw !== 'object' || raw === null) {
    throw new Error('replay context must be an object');
  }
  const r = raw as Record<string, unknown>;
  const primary = numOrThrow(r.primary, 'primary');
  const secondary = numOrThrow(r.secondary, 'secondary');
  const tca = strOrThrow(r.tca, 'tca');
  if (Number.isNaN(Date.parse(tca))) {
    throw new Error(`replay context: tca is not a valid ISO date (${tca})`);
  }
  return {
    primary,
    secondary,
    primaryName:   strOrThrow(r.primary_name,   'primary_name'),
    secondaryName: strOrThrow(r.secondary_name, 'secondary_name'),
    tca,
    missKm:        numOrThrow(r.miss_km,         'miss_km'),
    relSpeedKmS:   r.rel_speed_km_s === null || r.rel_speed_km_s === undefined ? null : numOrThrow(r.rel_speed_km_s, 'rel_speed_km_s'),
    probability:   r.probability   === null || r.probability   === undefined ? null : numOrThrow(r.probability,     'probability'),
  };
}

function numOrThrow(v: unknown, name: string): number {
  if (typeof v !== 'number' || !Number.isFinite(v)) {
    throw new Error(`replay context: ${name} must be a finite number (got ${String(v)})`);
  }
  return v;
}

function strOrThrow(v: unknown, name: string): string {
  if (typeof v !== 'string' || v === '') {
    throw new Error(`replay context: ${name} must be a non-empty string`);
  }
  return v;
}

/**
 * Replay window math. Locked in docs/phase6.md § II row 4:
 *   - replay window: ±5 min around TCA
 *   - playback starts paused at T−2 min
 */
export const REPLAY_WINDOW_MS    = 5 * 60_000;
export const REPLAY_START_OFFSET_MS = -2 * 60_000;

/** Returns the absolute ms timestamp the clock should be set to on entry. */
export function replayEntryTimeMs(ctx: ReplayContext): number {
  return Date.parse(ctx.tca) + REPLAY_START_OFFSET_MS;
}

/** Returns the [startMs, endMs] full window the replay timeline scrubs across. */
export function replayWindowMs(ctx: ReplayContext): [number, number] {
  const t = Date.parse(ctx.tca);
  return [t - REPLAY_WINDOW_MS, t + REPLAY_WINDOW_MS];
}

/**
 * Format a signed offset from TCA (current clock time minus TCA, in ms)
 * as a HUD countdown — "T-02:34", "T+00:11", "T+00:00".
 *
 * Pure for unit testing; HUD calls this on every tick.
 */
export function formatTcaCountdown(offsetMs: number): string {
  const sign = offsetMs >= 0 ? '+' : '-';
  const abs = Math.abs(offsetMs);
  const totalSecs = Math.floor(abs / 1000);
  const mm = String(Math.floor(totalSecs / 60)).padStart(2, '0');
  const ss = String(totalSecs % 60).padStart(2, '0');
  return `T${sign}${mm}:${ss}`;
}

/**
 * Format a miss-distance reading for the HUD.  km when ≥ 1 km, m
 * otherwise — both with 2 significant digits past the decimal.  Stays
 * stable in width so the HUD doesn't jitter as numbers change.
 */
export function formatMissDistance(km: number): string {
  if (!Number.isFinite(km)) return '—';
  if (km >= 1) return `${km.toFixed(2)} km`;
  return `${Math.round(km * 1000)} m`;
}

/**
 * Compute live miss distance (in km) from two ECEF position arrays in
 * meters.  Returns null when either input is missing.  Pure for tests.
 */
export function liveMissKm(a: [number, number, number] | null, b: [number, number, number] | null): number | null {
  if (a === null || b === null) return null;
  const dx = a[0] - b[0];
  const dy = a[1] - b[1];
  const dz = a[2] - b[2];
  return Math.sqrt(dx * dx + dy * dy + dz * dz) / 1000;
}
