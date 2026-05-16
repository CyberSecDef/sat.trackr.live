import * as Cesium from 'cesium';

/**
 * Phase 4 chunk 4B — NOAA OVATION aurora-forecast overlay.
 *
 * Adds a Cesium `SingleTileImageryProvider` layer fed by
 * `public/textures/aurora-latest.png`, regenerated server-side every
 * ~15 minutes by `make ingest-ovation`.  Unlike chunk-5's light
 * pollution overlay we keep both dayAlpha + nightAlpha at full
 * opacity — the aurora oval exists at all longitudes regardless of
 * what the user is observing visually.
 *
 * The layer is genuinely lazy — the PNG isn't requested until the
 * first toggle-on.  Subsequent toggles are cheap show/hide flips
 * (Cesium keeps the texture cached).
 *
 * Reload-on-toggle is intentional: each "show" appends a fresh
 * `?v=now` cache-buster so the user picks up a newer raster when
 * they re-enable the overlay after the cron has refreshed.
 */
export class AuroraOverlayLayer {
  private layer: Cesium.ImageryLayer | null = null;
  private destroyed = false;

  constructor(
    private readonly viewer: Cesium.Viewer,
    private readonly textureUrl = '/textures/aurora-latest.png',
  ) {}

  setVisible(visible: boolean): void {
    if (this.destroyed) return;
    if (visible) {
      if (this.layer === null) {
        this.layer = this.buildLayer();
      }
      this.layer.show = true;
    } else if (this.layer !== null) {
      this.layer.show = false;
    }
  }

  destroy(): void {
    this.destroyed = true;
    if (this.layer !== null && !this.viewer.isDestroyed()) {
      this.viewer.imageryLayers.remove(this.layer, /* destroy */ true);
    }
    this.layer = null;
  }

  private buildLayer(): Cesium.ImageryLayer {
    const url = `${this.textureUrl}?v=${Date.now()}`;
    const provider = new Cesium.SingleTileImageryProvider({
      url,
      rectangle: Cesium.Rectangle.MAX_VALUE,
      credit: new Cesium.Credit(
        'OVATION aurora forecast — '
        + '<a href="https://www.swpc.noaa.gov/products/aurora-30-minute-forecast" target="_blank" rel="noopener">NOAA SWPC</a>',
        true,
      ),
    });
    const layer = this.viewer.imageryLayers.addImageryProvider(provider);
    layer.alpha = 1.0;
    // dayAlpha + nightAlpha left at their 1.0 defaults — the aurora
    // oval shape is meaningful on both the lit and the dark sides
    // of the globe (it's tied to the magnetic poles, not the sun).
    return layer;
  }
}
