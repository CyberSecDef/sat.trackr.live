/**
 * Phase 5 chunk 6 — deep-link sharing primitives.
 *
 * Spec-locked query-string contract (see docs/phase5.md § II row 7):
 *   ?sat={norad}&lat={deg}&lon={deg}&alt={m}&t={ISO8601}
 *
 * All keys optional. Unknown / malformed values are silently dropped
 * so a bad share URL still loads something usable instead of a blank
 * page or a thrown error.
 *
 * Kept as pure functions so they're trivial to unit-test with Vitest
 * (no DOM, no Cesium).
 */

export interface ShareState {
  sat?: number;
  lat?: number;
  lon?: number;
  altMeters?: number;
  /** ISO-8601 UTC timestamp; the SPA snaps the clock to this on connect. */
  t?: string;
}

/** Parse a URL's query string into a {@link ShareState}. Silently drops malformed values. */
export function parseShareParams(input: URLSearchParams | string | URL): ShareState {
  const params =
    typeof input === 'string'
      ? new URLSearchParams(input.startsWith('?') ? input.slice(1) : input)
      : input instanceof URL
        ? input.searchParams
        : input;

  const out: ShareState = {};

  const satRaw = params.get('sat');
  if (satRaw !== null && /^\d+$/.test(satRaw)) {
    const sat = parseInt(satRaw, 10);
    if (sat > 0 && sat <= 9_999_999) out.sat = sat;
  }

  const lat = numFromParam(params, 'lat', -90, 90);
  const lon = numFromParam(params, 'lon', -180, 180);
  // Only emit observer fields if BOTH lat AND lon are valid — a half-set
  // observer is worse than no observer at all (places the user at the
  // equator or the prime meridian by accident).
  if (lat !== undefined && lon !== undefined) {
    out.lat = lat;
    out.lon = lon;
    const alt = numFromParam(params, 'alt', -500, 10_000_000);
    if (alt !== undefined) out.altMeters = alt;
  }

  const t = params.get('t');
  if (t !== null) {
    const parsed = Date.parse(t);
    if (!Number.isNaN(parsed)) out.t = new Date(parsed).toISOString();
  }

  return out;
}

/**
 * Build a query string from a {@link ShareState}. Always omits empty
 * keys so the shortest meaningful URL gets shared.
 */
export function buildShareParams(state: ShareState): string {
  const p = new URLSearchParams();
  if (state.sat !== undefined && Number.isFinite(state.sat) && state.sat > 0) {
    p.set('sat', String(state.sat | 0));
  }
  if (state.lat !== undefined && state.lon !== undefined && Number.isFinite(state.lat) && Number.isFinite(state.lon)) {
    p.set('lat', formatCoord(state.lat));
    p.set('lon', formatCoord(state.lon));
    if (state.altMeters !== undefined && Number.isFinite(state.altMeters) && state.altMeters !== 0) {
      p.set('alt', String(Math.round(state.altMeters)));
    }
  }
  if (state.t !== undefined) {
    p.set('t', state.t);
  }
  return p.toString();
}

/** Convenience: build an absolute URL combining the current origin/pathname with a fresh querystring. */
export function buildShareUrl(origin: string, pathname: string, state: ShareState): string {
  const qs = buildShareParams(state);
  const path = pathname === '' ? '/' : pathname;
  return qs === '' ? `${origin}${path}` : `${origin}${path}?${qs}`;
}

function numFromParam(params: URLSearchParams, key: string, min: number, max: number): number | undefined {
  const raw = params.get(key);
  if (raw === null || raw === '') return undefined;
  const n = Number(raw);
  if (!Number.isFinite(n) || n < min || n > max) return undefined;
  return n;
}

/** 4 decimal places ≈ 11 m on Earth — fine for sharing observer location, keeps URLs short. */
function formatCoord(n: number): string {
  return (Math.round(n * 1e4) / 1e4).toString();
}
