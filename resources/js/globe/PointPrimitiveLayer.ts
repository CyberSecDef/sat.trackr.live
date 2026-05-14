import * as Cesium from 'cesium';
import type { ObjectType, TleRecord } from '../api/types';
import type { LoadedReply, PositionsReply } from '../workers/propagator';

const PROPAGATE_INTERVAL_MS = 250; // ~4 Hz; LEO sats move <800m per tick

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
  private propagateTimer: number | null = null;
  private destroyed = false;

  /** Number of satellites currently rendered. */
  public count = 0;
  public onStatusChange: ((status: string) => void) | null = null;

  constructor(private readonly scene: Cesium.Scene) {
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
    }
    this.count = records.length;

    this.worker.postMessage({ type: 'load', tles: records });
  }

  startPropagation(): void {
    if (this.propagateTimer !== null) return;
    // Kick once immediately so the user doesn't wait a full interval to see motion.
    this.requestPropagate();
    this.propagateTimer = window.setInterval(() => this.requestPropagate(), PROPAGATE_INTERVAL_MS);
  }

  stopPropagation(): void {
    if (this.propagateTimer !== null) {
      window.clearInterval(this.propagateTimer);
      this.propagateTimer = null;
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

  private requestPropagate(): void {
    this.worker.postMessage({ type: 'propagate', timeMs: Date.now() });
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
        const idx = this.indexByNorad.get(noradIds[i]);
        if (idx === undefined) continue;
        const p = this.collection.get(idx);
        // worker emits km; Cesium wants meters.
        p.position = new Cesium.Cartesian3(
          positions[i * 3] * 1000,
          positions[i * 3 + 1] * 1000,
          positions[i * 3 + 2] * 1000,
        );
      }
    }
  }
}
