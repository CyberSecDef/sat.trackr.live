import * as Cesium from 'cesium';

/**
 * Phase 3 chunk 5: VIIRS night-lights overlay.
 *
 * Adds a `Cesium.SingleTileImageryProvider` layer on top of the base
 * imagery, sourced from NASA's 2012 VIIRS Earth-at-Night composite
 * (3600×1800 JPG, ~800KB).  `dayAlpha = 0` and `nightAlpha = 0.85`
 * make the layer invisible on the day side and clearly visible on
 * the night side, so the user sees city lights only where they'd
 * actually be illuminated — co-rotating naturally with the
 * terminator that chunk 1 enabled.
 *
 * The asset is lazy-loaded: the imagery layer is only added on the
 * first toggle-on, so users who never enable the overlay never
 * download the JPG.
 */
export class LightPollutionLayer {
  private layer: Cesium.ImageryLayer | null = null;
  private loadedOnce = false;
  private destroyed = false;

  constructor(
    private readonly viewer: Cesium.Viewer,
    private readonly textureUrl = '/textures/earth-at-night.jpg',
  ) {}

  /**
   * Show / hide the overlay.  First call to setVisible(true) lazily
   * adds the imagery layer to the viewer (and triggers the JPG
   * download).  Subsequent toggles are cheap show/hide flips.
   */
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

  /** True iff the JPG has been requested at least once. */
  hasLoaded(): boolean {
    return this.loadedOnce;
  }

  destroy(): void {
    this.destroyed = true;
    if (this.layer !== null && !this.viewer.isDestroyed()) {
      this.viewer.imageryLayers.remove(this.layer, /* destroy */ true);
    }
    this.layer = null;
  }

  private buildLayer(): Cesium.ImageryLayer {
    this.loadedOnce = true;
    const provider = new Cesium.SingleTileImageryProvider({
      url: this.textureUrl,
      rectangle: Cesium.Rectangle.MAX_VALUE,                  // full globe equirectangular
      credit: new Cesium.Credit(
        '© NASA Earth Observatory / VIIRS — '
        + '<a href="https://earthobservatory.nasa.gov/features/NightLights" target="_blank" rel="noopener">Earth at Night 2012</a>',
        true,
      ),
    });
    const layer = this.viewer.imageryLayers.addImageryProvider(provider);
    layer.dayAlpha = 0.0;        // invisible on the lit side
    layer.nightAlpha = 0.85;     // visible on the dark side, slightly less than full
    layer.brightness = 1.4;      // city lights are dim — punch them up a bit
    return layer;
  }
}
