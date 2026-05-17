import * as Cesium from 'cesium';
import { twoline2satrec, propagate, gstime, eciToEcf } from 'satellite.js';
import type { Globe } from './Globe';
import type { ReplayContext } from '../replay/replayContext';
import { replayEntryTimeMs, replayWindowMs } from '../replay/replayContext';
import { getSatelliteTle } from '../api/client';

type Satrec = ReturnType<typeof twoline2satrec>;

/**
 * Phase 6 chunk 1 — replay scene for a conjunction.
 *
 * Chunk-1 scope (what this class does today):
 *   1. Fetches both satellites' current TLEs (existing API)
 *   2. Sets the clock to TCA − 2 min, paused, clamped to TCA ± 5 min
 *   3. Frames the chase camera on the midpoint of the two satellites
 *      with a BoundingSphere wide enough to keep both inside the
 *      frustum; re-frames every clock tick so the camera follows the
 *      pair as time advances
 *
 * Chunk-2 will add:
 *   - HUD overlay (live miss distance + countdown)
 *   - Replay timeline with bracket markers + prominent ▶ play
 *   - Both-satellite ribbons (OrbitRibbonLayer currently single-target)
 *   - Both-satellite marquee shapes
 *   - Visual TCA-moment highlight
 *   - Dim/hide the rest of the catalog (PointPrimitiveLayer needs a
 *     setOverallAlpha method first)
 *
 * Disposal restores clock state. One-shot: caller activates, holds the
 * instance, then disposes when the route changes.
 */
export class ConjunctionScene {
  private tickUnsub: (() => void) | null = null;
  private active = false;
  private satrecA: Satrec | null = null;
  private satrecB: Satrec | null = null;
  private restored: { startTime: Cesium.JulianDate; stopTime: Cesium.JulianDate; range: Cesium.ClockRange } | null = null;

  constructor(
    private readonly globe: Globe,
    public readonly ctx: ReplayContext,
  ) {}

  async activate(): Promise<void> {
    if (this.active) return;
    this.active = true;

    // Pull TLEs in parallel for both satellites; needed for the
    // main-thread propagation that drives the chase camera. Worker-side
    // propagation continues for the dot layer but isn't accessible here.
    const [tleA, tleB] = await Promise.all([
      getSatelliteTle(this.ctx.primary).catch(() => null),
      getSatelliteTle(this.ctx.secondary).catch(() => null),
    ]);
    if (tleA !== null) this.satrecA = twoline2satrec(tleA.data.line1, tleA.data.line2);
    if (tleB !== null) this.satrecB = twoline2satrec(tleB.data.line1, tleB.data.line2);

    this.configureClock();
    this.frameCameraOnPair();
    this.tickUnsub = this.subscribeToTicks(() => this.frameCameraOnPair());
  }

  dispose(): void {
    if (!this.active) return;
    this.active = false;
    this.tickUnsub?.();
    this.tickUnsub = null;
    this.restoreClock();
  }

  private configureClock(): void {
    const clock = this.globe.clock;
    if (clock === undefined) return;
    // Stash whatever the clock's prior window was so dispose() can put it back.
    this.restored = {
      startTime: clock.cesium.startTime.clone(),
      stopTime:  clock.cesium.stopTime.clone(),
      range:     clock.cesium.clockRange,
    };
    const [startMs, endMs] = replayWindowMs(this.ctx);
    clock.cesium.startTime  = Cesium.JulianDate.fromDate(new Date(startMs));
    clock.cesium.stopTime   = Cesium.JulianDate.fromDate(new Date(endMs));
    clock.cesium.clockRange = Cesium.ClockRange.CLAMPED;
    clock.setTimeMs(replayEntryTimeMs(this.ctx));
    clock.pause();
  }

  private restoreClock(): void {
    const clock = this.globe.clock;
    if (clock === undefined || this.restored === null) return;
    clock.cesium.startTime  = this.restored.startTime;
    clock.cesium.stopTime   = this.restored.stopTime;
    clock.cesium.clockRange = this.restored.range;
    this.restored = null;
  }

  /** Reframe the chase camera at the live midpoint of the two satellites. */
  private frameCameraOnPair(): void {
    const a = this.getEcefAtNow(this.satrecA);
    const b = this.getEcefAtNow(this.satrecB);
    if (a === null || b === null) return;

    const midpoint = Cesium.Cartesian3.midpoint(a, b, new Cesium.Cartesian3());
    const separation = Cesium.Cartesian3.distance(a, b);
    // Frame the BoundingSphere with a comfortable margin: ×6 keeps the
    // sats well inside the frustum even at sub-km separations. Floor at
    // 200 km so the camera doesn't dive into Earth at the closest
    // approach.
    const sphereRadius = Math.max(200_000, separation * 6);
    const sphere = new Cesium.BoundingSphere(midpoint, sphereRadius);
    const viewer = this.globe.viewer;
    if (viewer === undefined) return;
    viewer.camera.viewBoundingSphere(
      sphere,
      new Cesium.HeadingPitchRange(
        Cesium.Math.toRadians(0),
        Cesium.Math.toRadians(-30),
        sphereRadius * 2.5,
      ),
    );
  }

  /** Propagate a satrec forward to whatever Cesium's clock currently reads. */
  private getEcefAtNow(satrec: Satrec | null): Cesium.Cartesian3 | null {
    if (satrec === null) return null;
    const clock = this.globe.clock;
    if (clock === undefined) return null;
    const date = new Date(clock.getTimeMs());
    const eci = propagate(satrec, date);
    if (!eci || typeof eci.position !== 'object' || eci.position === null) return null;
    const gmst = gstime(date);
    const ecf = eciToEcf(eci.position, gmst);
    return Cesium.Cartesian3.fromArray([ecf.x * 1000, ecf.y * 1000, ecf.z * 1000]);
  }

  private subscribeToTicks(handler: () => void): () => void {
    const clock = this.globe.clock;
    if (clock === undefined) return () => undefined;
    return clock.onTick(() => handler());
  }
}
