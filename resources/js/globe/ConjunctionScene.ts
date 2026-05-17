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
  /** TCA-moment pulse — accent-cyan circle near the midpoint while the
   *  clock is within ±500 ms of TCA.  Created lazily on first hit. */
  private tcaPulseEntity: Cesium.Entity | null = null;
  private tcaPulseAddedToViewer = false;

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

    // Phase 6 chunk 2 — dim the catalog so the two replay sats are the
    // only thing on screen.  Alpha 0 hides them entirely; the marquee
    // shapes below render the actual replay subjects.
    this.globe.layer?.setOverallAlpha(0);

    // Both-sat ribbons (chunk 2 widened OrbitRibbonLayer with a secondary slot).
    const tNow = this.globe.clock?.getTimeMs() ?? Date.now();
    if (this.satrecA !== null) this.globe.ribbons?.show(this.satrecA, tNow);
    if (this.satrecB !== null) this.globe.ribbons?.showSecondary(this.satrecB, tNow);

    // Both-sat marquee shapes — initial positions seeded from TLE
    // propagation; subsequent ticks re-call updateMarquees.
    this.updateMarquees();

    this.frameCameraOnPair();
    this.tickUnsub = this.subscribeToTicks(() => {
      this.frameCameraOnPair();
      this.updateMarquees();
      this.globe.ribbons?.update(this.globe.clock?.getTimeMs() ?? Date.now());
      this.updateTcaPulse();
    });
  }

  dispose(): void {
    if (!this.active) return;
    this.active = false;
    this.tickUnsub?.();
    this.tickUnsub = null;
    this.globe.layer?.setOverallAlpha(1);
    this.globe.ribbons?.hide();
    this.globe.marquee?.hide();
    this.globe.marquee?.hideSecondary();
    if (this.tcaPulseAddedToViewer && this.tcaPulseEntity !== null && this.globe.viewer !== undefined) {
      this.globe.viewer.entities.remove(this.tcaPulseEntity);
    }
    this.tcaPulseEntity = null;
    this.tcaPulseAddedToViewer = false;
    this.restoreClock();
  }

  /** Re-position both marquee shapes for the current clock tick. */
  private updateMarquees(): void {
    const a = this.getEcefAtNow(this.satrecA);
    const b = this.getEcefAtNow(this.satrecB);
    if (a !== null) this.globe.marquee?.update(this.ctx.primary,   this.ctx.primaryName,   a);
    if (b !== null) this.globe.marquee?.updateSecondary(this.ctx.secondary, this.ctx.secondaryName, b);
  }

  /**
   * Phase 6 chunk 2 — live ECEF position of the primary satellite (m).
   * The HUD reads this every tick to compute live miss distance.
   * Returns null until TLEs have loaded and the first propagation succeeds.
   */
  livePrimaryEcefMeters(): [number, number, number] | null {
    return this.toTuple(this.getEcefAtNow(this.satrecA));
  }

  /** Live ECEF position of the secondary satellite (m). See {@link livePrimaryEcefMeters}. */
  liveSecondaryEcefMeters(): [number, number, number] | null {
    return this.toTuple(this.getEcefAtNow(this.satrecB));
  }

  private toTuple(c: Cesium.Cartesian3 | null): [number, number, number] | null {
    return c === null ? null : [c.x, c.y, c.z];
  }

  /**
   * Draws a brief accent-cyan ring at the midpoint between the two
   * satellites when the clock is within ±500 ms of TCA. The ring
   * fades in over the first quarter of the window and out over the
   * last, giving the marquee moment a clear visual punctuation
   * regardless of scrub direction.
   */
  private updateTcaPulse(): void {
    const viewer = this.globe.viewer;
    const clock  = this.globe.clock;
    if (viewer === undefined || clock === undefined) return;

    const offsetMs = clock.getTimeMs() - Date.parse(this.ctx.tca);
    const halfWindowMs = 500;
    const inside = Math.abs(offsetMs) <= halfWindowMs;

    if (!inside) {
      if (this.tcaPulseAddedToViewer && this.tcaPulseEntity !== null) {
        viewer.entities.remove(this.tcaPulseEntity);
        this.tcaPulseAddedToViewer = false;
      }
      return;
    }

    const a = this.getEcefAtNow(this.satrecA);
    const b = this.getEcefAtNow(this.satrecB);
    if (a === null || b === null) return;
    const midpoint = Cesium.Cartesian3.midpoint(a, b, new Cesium.Cartesian3());
    // Triangle-wave alpha: 0 at the edges, 1 at TCA.
    const norm = 1 - Math.abs(offsetMs) / halfWindowMs;
    const alpha = 0.2 + 0.7 * norm;
    const color = Cesium.Color.fromCssColorString('#00d9ff').withAlpha(alpha);

    if (this.tcaPulseEntity === null) {
      this.tcaPulseEntity = new Cesium.Entity({
        position: midpoint,
        point: {
          pixelSize: 18,
          color,
          outlineColor: color,
          outlineWidth: 2,
        },
      });
    } else {
      this.tcaPulseEntity.position = new Cesium.ConstantPositionProperty(midpoint);
      if (this.tcaPulseEntity.point !== undefined) {
        this.tcaPulseEntity.point.color = new Cesium.ConstantProperty(color);
        this.tcaPulseEntity.point.outlineColor = new Cesium.ConstantProperty(color);
      }
    }
    if (!this.tcaPulseAddedToViewer) {
      viewer.entities.add(this.tcaPulseEntity);
      this.tcaPulseAddedToViewer = true;
    }
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
