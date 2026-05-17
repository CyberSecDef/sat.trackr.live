import * as Cesium from 'cesium';
import type { SatRec } from 'satellite.js';
import { computeGroundTrack, periodMinutes, type GroundTrackPoint } from '../passes/computeGroundTrack';

/**
 * Phase 3 chunk 2B: orbit-ribbon layer.
 *
 * Renders the ground-track of the currently-selected satellite as a
 * fading polyline on the globe.  Past = dim, future = bright.  Built
 * from {@link computeGroundTrack} which propagates the satrec over
 * `pastOrbits + futureOrbits` revolutions.
 *
 * The gradient is approximated by splitting the track into N short
 * segments (default 24) and giving each its own alpha — Cesium's
 * `PolylineCollection` doesn't support per-vertex colors out of the
 * box, but per-polyline materials are cheap.
 *
 * Re-renders only when the time delta since the last render exceeds
 * `recomputeFractionOfPeriod` of the satellite's period (default 1/30
 * — for ISS that's ~3 real minutes between rebuilds at 1× speed).
 */
export class OrbitRibbonLayer {
  private readonly collection: Cesium.PolylineCollection;
  private satrec: SatRec | null = null;
  private centerTimeMs = 0;
  private lastRenderTimeMs = 0;
  private orbits: number = DEFAULT_ORBITS;

  /**
   * Phase 6 chunk 2 — secondary ribbon slot.  Conjunction-replay uses
   * this to render the second satellite's ground track alongside the
   * primary.  Tinted distinctly (orange-ish) so the two arcs are
   * tellable apart on the globe.
   */
  private satrecSecondary: SatRec | null = null;
  private secondaryPolylines: Cesium.Polyline[] = [];

  constructor(private readonly scene: Cesium.Scene) {
    this.collection = scene.primitives.add(new Cesium.PolylineCollection());
  }

  /**
   * Show the ribbon for the given satellite.  Replaces any previously-
   * rendered ribbon.  Caller passes the satrec (built from raw TLE
   * lines via `twoline2satrec`) and the current scrubbed time.
   */
  show(satrec: SatRec, centerTimeMs: number): void {
    this.satrec = satrec;
    this.centerTimeMs = centerTimeMs;
    this.render();
  }

  /** Drop the rendered ribbon and forget the active satrec. */
  hide(): void {
    this.satrec = null;
    this.collection.removeAll();
    this.secondaryPolylines = [];
    this.satrecSecondary = null;
  }

  /**
   * Phase 6 chunk 2 — show a *second* ribbon for a different satellite.
   * Tinted in the accent-orange family so it reads as distinct from the
   * primary white track.  Disposed via {@link hideSecondary} or the
   * full {@link hide}.
   */
  showSecondary(satrec: SatRec, centerTimeMs: number): void {
    this.satrecSecondary = satrec;
    this.centerTimeMs = centerTimeMs;
    this.renderSecondary();
  }

  /** Drop only the secondary ribbon; primary ribbon left untouched. */
  hideSecondary(): void {
    if (this.satrecSecondary === null) return;
    this.satrecSecondary = null;
    for (const p of this.secondaryPolylines) {
      this.collection.remove(p);
    }
    this.secondaryPolylines = [];
  }

  /** Bump the ribbon length (in orbits) and re-render if active. */
  setOrbits(orbits: number): void {
    if (orbits <= 0 || orbits === this.orbits) return;
    this.orbits = orbits;
    if (this.satrec !== null) {
      this.render();
    }
  }

  getOrbits(): number {
    return this.orbits;
  }

  /**
   * Called from the host on every clock tick.  Cheap when no ribbon
   * is active; otherwise re-renders only when the scrubbed time has
   * moved by more than ~1/30 of the satellite's period.
   */
  update(currentTimeMs: number): void {
    const periodMs = this.satrec !== null ? periodMinutes(this.satrec) * 60_000 : 0;
    const delta = Math.abs(currentTimeMs - this.lastRenderTimeMs);
    const shouldRerender = this.satrec !== null && delta >= periodMs / 30;
    if (shouldRerender) {
      this.centerTimeMs = currentTimeMs;
      this.render();
    } else if (this.satrec === null && this.satrecSecondary === null) {
      return;
    }
    // The secondary ribbon shares the same re-render cadence as the
    // primary's; if there's no primary, we still need to keep the
    // secondary fresh during a conjunction replay.
    if (this.satrecSecondary !== null && (shouldRerender || this.satrec === null)) {
      if (this.satrec === null) {
        const secondaryPeriodMs = periodMinutes(this.satrecSecondary) * 60_000;
        if (Math.abs(currentTimeMs - this.lastRenderTimeMs) < secondaryPeriodMs / 30) return;
        this.centerTimeMs = currentTimeMs;
        this.lastRenderTimeMs = currentTimeMs;
      }
      this.renderSecondary();
    }
  }

  destroy(): void {
    if (!this.scene.isDestroyed()) {
      this.scene.primitives.remove(this.collection);
    }
  }

  // ─── Internals ─────────────────────────────────────────────────────────

  private render(): void {
    if (this.satrec === null) {
      return;
    }
    this.collection.removeAll();

    const points = computeGroundTrack({
      satrec: this.satrec,
      centerTimeMs: this.centerTimeMs,
      pastOrbits: this.orbits,
      futureOrbits: this.orbits,
      samplesPerOrbit: 180,
    });
    if (points.length < 2) return;

    const periodMs = periodMinutes(this.satrec) * 60_000;
    const segmentCount = Math.min(SEGMENT_COUNT, Math.floor(points.length / 2));
    const samplesPerSegment = Math.ceil(points.length / segmentCount);

    for (let s = 0; s < segmentCount; s++) {
      const from = s * samplesPerSegment;
      const to   = Math.min((s + 1) * samplesPerSegment + 1, points.length);
      if (to - from < 2) continue;

      const slice = points.slice(from, to);
      const positions = sliceToPositions(slice);
      if (positions.length < 2) continue;

      const segmentCenterTimeMs = (slice[0].timeMs + slice[slice.length - 1].timeMs) / 2;
      const color = colorForSegment(segmentCenterTimeMs - this.centerTimeMs, periodMs * this.orbits);

      this.collection.add({
        positions,
        width: 2,
        material: Cesium.Material.fromType('Color', { color }),
      });
    }
    this.lastRenderTimeMs = this.centerTimeMs;
  }

  private renderSecondary(): void {
    if (this.satrecSecondary === null) return;
    for (const p of this.secondaryPolylines) {
      this.collection.remove(p);
    }
    this.secondaryPolylines = [];

    const points = computeGroundTrack({
      satrec: this.satrecSecondary,
      centerTimeMs: this.centerTimeMs,
      pastOrbits: this.orbits,
      futureOrbits: this.orbits,
      samplesPerOrbit: 180,
    });
    if (points.length < 2) return;

    const periodMs = periodMinutes(this.satrecSecondary) * 60_000;
    const segmentCount = Math.min(SEGMENT_COUNT, Math.floor(points.length / 2));
    const samplesPerSegment = Math.ceil(points.length / segmentCount);

    for (let s = 0; s < segmentCount; s++) {
      const from = s * samplesPerSegment;
      const to   = Math.min((s + 1) * samplesPerSegment + 1, points.length);
      if (to - from < 2) continue;
      const slice = points.slice(from, to);
      const positions = sliceToPositions(slice);
      if (positions.length < 2) continue;

      const segmentCenterTimeMs = (slice[0].timeMs + slice[slice.length - 1].timeMs) / 2;
      const color = colorForSegment(segmentCenterTimeMs - this.centerTimeMs, periodMs * this.orbits, SECONDARY_HUE);

      const polyline = this.collection.add({
        positions,
        width: 2,
        material: Cesium.Material.fromType('Color', { color }),
      });
      this.secondaryPolylines.push(polyline);
    }
  }
}

const DEFAULT_ORBITS = 1;
const SEGMENT_COUNT = 24;

/** Map a slice of ground-track points to Cesium ECEF Cartesians. */
function sliceToPositions(slice: GroundTrackPoint[]): Cesium.Cartesian3[] {
  const out: Cesium.Cartesian3[] = [];
  for (const p of slice) {
    if (Number.isFinite(p.longitudeDeg) && Number.isFinite(p.latitudeDeg)) {
      out.push(Cesium.Cartesian3.fromDegrees(p.longitudeDeg, p.latitudeDeg, 0));
    }
  }
  return out;
}

/** Default ribbon hue: white (primary track). */
const PRIMARY_HUE = '#ffffff';
/** Phase 6 chunk 2: secondary ribbon hue — accent orange so the two tracks distinguish on the globe. */
const SECONDARY_HUE = '#ffb700';

/**
 * Color a segment by its time-distance from "now". Past is slightly
 * dimmer than future (per the phase3.md spec — "past = dim, future =
 * bright").  `hue` lets the conjunction-replay scene tint its secondary
 * ribbon a different color from the primary white track.
 */
function colorForSegment(deltaMs: number, halfWindowMs: number, hue: string = PRIMARY_HUE): Cesium.Color {
  const rel = halfWindowMs > 0 ? deltaMs / halfWindowMs : 0;
  const absRel = Math.min(1, Math.abs(rel));
  // Linear falloff with a floor so the edges don't disappear entirely.
  let alpha = 0.85 - 0.7 * absRel;
  if (rel < 0) {
    alpha *= 0.55; // past dimmer
  }
  alpha = Math.max(0.05, Math.min(1, alpha));
  return Cesium.Color.fromCssColorString(hue).withAlpha(alpha);
}
