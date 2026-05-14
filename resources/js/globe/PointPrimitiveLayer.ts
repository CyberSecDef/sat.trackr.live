import * as Cesium from 'cesium';
import type { ObjectType, TleRecord } from '../api/types';
import type { Clock } from '../time/Clock';
import type { LoadedReply, PositionsReply } from '../workers/propagator';

const PROPAGATE_INTERVAL_MS = 250; // ~4 Hz; LEO sats move <800m per tick
/** A clock-time delta this large bypasses the throttle (e.g. a slider scrub). */
const BIG_JUMP_MS = 5000;

const COLORS: Record<ObjectType, Cesium.Color> = {
  PAYLOAD:     Cesium.Color.fromCssColorString('#00d9ff'),  // cyan
  ROCKET_BODY: Cesium.Color.fromCssColorString('#ffb700'),  // amber
  DEBRIS:      Cesium.Color.fromCssColorString('#ff3860'),  // red
  TBA:         Cesium.Color.fromCssColorString('#888888'),  // gray
  UNKNOWN:     Cesium.Color.fromCssColorString('#00d9ff'),  // cyan (most are payloads)
};

const PIXEL_SIZES: Record<ObjectType, number> = {
  PAYLOAD: 3,
  ROCKET_BODY: 2.5,
  DEBRIS: 2,
  TBA: 2,
  UNKNOWN: 3,
};

const HIGHLIGHT_COLOR = Cesium.Color.WHITE;
const HIGHLIGHT_PIXEL_SIZE = 9;

/**
 * Owns a Cesium PointPrimitiveCollection and a propagator Web Worker.
 * Pulls bulk TLEs from the API, hands them to the worker, then
 * polls the worker at PROPAGATE_INTERVAL_MS and updates each
 * primitive's position with the returned ECEF coordinates.
 */
export class PointPrimitiveLayer {
  private collection: Cesium.PointPrimitiveCollection;
  private worker: Worker;
  /** norad_id → primitive index */
  private indexByNorad = new Map<number, number>();
  /** norad_id → object_type (used to restore color when un-highlighting) */
  private typeByNorad = new Map<number, string>();
  /** norad_id → most recent ECEF position (meters) */
  private positionByNorad = new Map<number, Cesium.Cartesian3>();
  private highlighted: number | null = null;
  private unsubscribeTick: (() => void) | null = null;
  private lastPropagatedClockMs = 0;
  private lastPropagateRealMs = 0;
  private destroyed = false;

  /** Number of satellites currently rendered. */
  public count = 0;
  public onStatusChange: ((status: string) => void) | null = null;

  constructor(
    private readonly scene: Cesium.Scene,
    private readonly clock: Clock,
  ) {
    this.collection = scene.primitives.add(new Cesium.PointPrimitiveCollection());
    this.worker = new Worker(
      new URL('../workers/propagator.ts', import.meta.url),
      { type: 'module' }
    );
    this.worker.onmessage = (e: MessageEvent<LoadedReply | PositionsReply>) =>
      this.onWorkerMessage(e.data);
  }

  /**
   * Replace the rendered set with `records`. Spawns one PointPrimitive
   * per record at the origin (worker will fix positions on the next tick).
   */
  load(records: TleRecord[]): void {
    if (this.destroyed) return;
    this.collection.removeAll();
    this.indexByNorad.clear();
    this.typeByNorad.clear();
    this.positionByNorad.clear();
    this.highlighted = null;

    for (let i = 0; i < records.length; i++) {
      const r = records[i];
      const type = r.object_type ?? 'UNKNOWN';
      this.collection.add({
        position: Cesium.Cartesian3.ZERO, // worker will overwrite on first propagate
        color: COLORS[type] ?? COLORS.UNKNOWN,
        pixelSize: PIXEL_SIZES[type] ?? PIXEL_SIZES.UNKNOWN,
        outlineColor: Cesium.Color.BLACK.withAlpha(0.4),
        outlineWidth: 0,
        id: r.norad_id, // available via scene.pick().id
      });
      this.indexByNorad.set(r.norad_id, i);
      this.typeByNorad.set(r.norad_id, type);
    }
    this.count = records.length;

    this.worker.postMessage({ type: 'load', tles: records });
  }

  /** Latest known ECEF position (meters) for the given NORAD, if any. */
  getPosition(norad: number): Cesium.Cartesian3 | null {
    return this.positionByNorad.get(norad) ?? null;
  }

  /**
   * Visually emphasize a single satellite (white + larger). Pass null to
   * clear. Restores the previous selection's original color/size.
   */
  setHighlight(norad: number | null): void {
    if (this.highlighted === norad) return;
    if (this.highlighted !== null) {
      this.applyDefaultStyle(this.highlighted);
    }
    if (norad !== null) {
      const idx = this.indexByNorad.get(norad);
      if (idx !== undefined) {
        const p = this.collection.get(idx);
        p.color = HIGHLIGHT_COLOR;
        p.pixelSize = HIGHLIGHT_PIXEL_SIZE;
      }
    }
    this.highlighted = norad;
  }

  private applyDefaultStyle(norad: number): void {
    const idx = this.indexByNorad.get(norad);
    if (idx === undefined) return;
    const type = (this.typeByNorad.get(norad) ?? 'UNKNOWN') as keyof typeof COLORS;
    const p = this.collection.get(idx);
    p.color = COLORS[type] ?? COLORS.UNKNOWN;
    p.pixelSize = PIXEL_SIZES[type] ?? PIXEL_SIZES.UNKNOWN;
  }

  startPropagation(): void {
    if (this.unsubscribeTick !== null) return;
    // Kick once immediately so the user doesn't wait a full interval to see motion.
    this.requestPropagate(this.clock.getTimeMs());
    // Subscribe to Cesium's render-loop ticks (~60Hz max). Throttle below.
    this.unsubscribeTick = this.clock.onTick((timeMs) => this.maybePropagate(timeMs));
  }

  stopPropagation(): void {
    if (this.unsubscribeTick !== null) {
      this.unsubscribeTick();
      this.unsubscribeTick = null;
    }
  }

  destroy(): void {
    this.destroyed = true;
    this.stopPropagation();
    this.worker.terminate();
    if (!this.scene.isDestroyed()) {
      this.scene.primitives.remove(this.collection);
    }
  }

  /**
   * Called on every Cesium tick (~60Hz). Throttles to PROPAGATE_INTERVAL_MS,
   * EXCEPT when the clock made a big jump (e.g. user scrubbed the slider) —
   * then propagate immediately so the visible swarm catches up to the new time.
   */
  private maybePropagate(timeMs: number): void {
    if (this.destroyed) return;
    const realNow = performance.now();
    const elapsedReal = realNow - this.lastPropagateRealMs;
    const clockDelta = Math.abs(timeMs - this.lastPropagatedClockMs);
    if (elapsedReal < PROPAGATE_INTERVAL_MS && clockDelta < BIG_JUMP_MS) {
      return;
    }
    this.requestPropagate(timeMs);
  }

  private requestPropagate(timeMs: number): void {
    this.worker.postMessage({ type: 'propagate', timeMs });
    this.lastPropagatedClockMs = timeMs;
    this.lastPropagateRealMs = performance.now();
  }

  private onWorkerMessage(data: LoadedReply | PositionsReply): void {
    if (this.destroyed) return;
    if (data.type === 'loaded') {
      this.onStatusChange?.(`Tracking ${data.parsed.toLocaleString()} satellites`);
      return;
    }
    if (data.type === 'positions') {
      const { count, positions, noradIds } = data;
      for (let i = 0; i < count; i++) {
        const norad = noradIds[i];
        const idx = this.indexByNorad.get(norad);
        if (idx === undefined) continue;
        // worker emits km; Cesium wants meters.
        const cartesian = new Cesium.Cartesian3(
          positions[i * 3] * 1000,
          positions[i * 3 + 1] * 1000,
          positions[i * 3 + 2] * 1000,
        );
        this.collection.get(idx).position = cartesian;
        this.positionByNorad.set(norad, cartesian);
      }
    }
  }
}
