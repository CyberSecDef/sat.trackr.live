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
    if (this.satrec === null) return;
    const periodMs = periodMinutes(this.satrec) * 60_000;
    const delta = Math.abs(currentTimeMs - this.lastRenderTimeMs);
    if (delta < periodMs / 30) return;
    this.centerTimeMs = currentTimeMs;
    this.render();
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

/**
 * Color a segment by its time-distance from "now". Past is slightly
 * dimmer than future (per the phase3.md spec — "past = dim, future =
 * bright").
 */
function colorForSegment(deltaMs: number, halfWindowMs: number): Cesium.Color {
  const rel = halfWindowMs > 0 ? deltaMs / halfWindowMs : 0;
  const absRel = Math.min(1, Math.abs(rel));
  // Linear falloff with a floor so the edges don't disappear entirely.
  let alpha = 0.85 - 0.7 * absRel;
  if (rel < 0) {
    alpha *= 0.55; // past dimmer
  }
  alpha = Math.max(0.05, Math.min(1, alpha));
  return Cesium.Color.fromCssColorString('#ffffff').withAlpha(alpha);
}
