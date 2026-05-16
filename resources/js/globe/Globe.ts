import { LitElement, html, css } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { ref, createRef, type Ref } from 'lit/directives/ref.js';
import * as Cesium from 'cesium';
import { twoline2satrec } from 'satellite.js';
import { getGroupTles, ApiError } from '../api/client';
import { Clock } from '../time/Clock';
import { createImageryProvider } from './imagery';
import { AuroraOverlayLayer } from './AuroraOverlayLayer';
import { GroundStationLayer } from './GroundStationLayer';
import { LightPollutionLayer } from './LightPollutionLayer';
import { MarqueeShapeLayer } from './MarqueeShapeLayer';
import { OrbitRibbonLayer } from './OrbitRibbonLayer';
import { PointPrimitiveLayer } from './PointPrimitiveLayer';
import { SelectionController } from './SelectionController';
import { getSharedOverlays, type OverlayState } from '../overlays/OverlayService';

/** The CelesTrak group we render at chunk 5; chunk 6+ may parameterize this. */
const DEFAULT_GROUP = 'active';

/**
 * Plain class wrapping Cesium.Viewer + the satellite-rendering pipeline.
 * Lit-element-agnostic — the imperative Cesium API doesn't benefit from
 * Lit's reactive render cycle.
 */
export class Globe {
  private viewer?: Cesium.Viewer;
  public layer?: PointPrimitiveLayer;
  public ribbons?: OrbitRibbonLayer;
  public marquee?: MarqueeShapeLayer;
  public stations?: GroundStationLayer;
  public lightPollution?: LightPollutionLayer;
  public aurora?: AuroraOverlayLayer;
  public selection?: SelectionController;
  public clock?: Clock;
  private ribbonTickUnsub: (() => void) | null = null;
  private marqueeTickUnsub: (() => void) | null = null;
  private overlayUnsub: (() => void) | null = null;
  private selectedNorad: number | null = null;
  private overlayState: OverlayState = {
    ribbons: true, marquee: true, stations: false, lightPollution: false, aurora: false,
  };

  async init(
    container: HTMLElement,
    opts: {
      cesiumIonToken: string;
      onSelect: (norad: number | null) => void;
      onStationSelect?: (pick: { stationId: string; name: string }, screenPos: { x: number; y: number }) => void;
      onStatus: (status: string) => void;
      onClockReady?: (clock: Clock) => void;
    },
  ): Promise<void> {
    const provider = await createImageryProvider(opts.cesiumIonToken);

    const viewer = new Cesium.Viewer(container, {
      animation: false,
      baseLayerPicker: false,
      fullscreenButton: false,
      geocoder: false,
      homeButton: false,
      navigationHelpButton: false,
      sceneModePicker: false,
      selectionIndicator: false,
      timeline: false,
      infoBox: false,
      baseLayer: new Cesium.ImageryLayer(provider, {}),
    });
    this.viewer = viewer;

    viewer.scene.globe.enableLighting = true;
    if (viewer.scene.skyAtmosphere) {
      viewer.scene.skyAtmosphere.show = true;
    }
    viewer.scene.fog.enabled = true;
    viewer.scene.backgroundColor = Cesium.Color.fromCssColorString('#0a0e27');

    // Phase 3 chunk 1: BSC5 starfield + explicit sun/moon.  The
    // skybox cubemap is generated at build time (see bin/build-skybox.php
    // and the README "Phase 3 — Showcase visuals" entry); each face is a
    // 1024² PNG of stars at their J2000 positions, magnitude-graded.
    // Cesium loads the textures into a SkyBox primitive that sits in
    // the inertial frame, so the stars track Earth's rotation correctly
    // as the user scrubs time.
    const skyboxBase = '/textures/skybox';
    viewer.scene.skyBox = new Cesium.SkyBox({
      sources: {
        positiveX: `${skyboxBase}/px.png`,
        negativeX: `${skyboxBase}/nx.png`,
        positiveY: `${skyboxBase}/py.png`,
        negativeY: `${skyboxBase}/ny.png`,
        positiveZ: `${skyboxBase}/pz.png`,
        negativeZ: `${skyboxBase}/nz.png`,
      },
    });
    if (viewer.scene.sun) {
      viewer.scene.sun.show = true;
    }
    if (viewer.scene.moon) {
      viewer.scene.moon.show = true;
    }

    this.clock = new Clock(viewer.clock);
    this.layer = new PointPrimitiveLayer(viewer.scene, this.clock);
    this.layer.onStatusChange = opts.onStatus;
    this.ribbons = new OrbitRibbonLayer(viewer.scene);
    this.marquee = new MarqueeShapeLayer(viewer.scene);
    this.stations = new GroundStationLayer(viewer.scene);
    this.lightPollution = new LightPollutionLayer(viewer);
    this.aurora = new AuroraOverlayLayer(viewer);
    this.selection = new SelectionController(viewer, {
      onSelect: opts.onSelect,
      onStationSelect: opts.onStationSelect
        ? (pick, pos) => opts.onStationSelect?.(pick, pos)
        : undefined,
    });
    opts.onClockReady?.(this.clock);

    // Phase 3 chunk 4B: subscribe to OverlayService so toggles in the
    // §overlays topbar menu reach every layer.  Sync-emits the current
    // state on subscribe, so the initial visibility is set immediately.
    this.overlayUnsub = getSharedOverlays().subscribe((state) => {
      this.overlayState = state;
      this.stations?.setVisible(state.stations);
      this.lightPollution?.setVisible(state.lightPollution);
      this.aurora?.setVisible(state.aurora);
      // Ribbon + marquee are gated by selection AND the overlay flag;
      // re-apply selection so a flip to "off" tears them down.
      this.setRibbonTarget(this.selectedNorad);
      this.refreshMarquee();
    });

    // Phase 3 chunk 2B: refresh the active orbit ribbon as the user
    // scrubs time.  OrbitRibbonLayer.update() throttles to ~once per
    // 1/30 of the satellite's period, so the cost is bounded.
    this.ribbonTickUnsub = this.clock.onTick((timeMs) => {
      if (this.overlayState.ribbons) this.ribbons?.update(timeMs);
    });

    // Phase 3 chunk 3B: track the selected satellite's marquee shape
    // every clock tick (the worker repaints positions ~4Hz; we read
    // back the cached ECEF and reposition the primitive).  No-op
    // when nothing is selected or when the camera is far from it.
    this.marqueeTickUnsub = this.clock.onTick(() => this.refreshMarquee());

    opts.onStatus('Loading satellite catalog…');
    try {
      const bundle = await getGroupTles(DEFAULT_GROUP);
      opts.onStatus(`Parsing ${bundle.count.toLocaleString()} TLEs…`);
      this.layer.load(bundle.tles);
      this.layer.startPropagation();
    } catch (err) {
      const msg = err instanceof ApiError ? `API error ${err.status}` : String(err);
      opts.onStatus(`Failed to load satellites: ${msg}`);
      console.error('Globe failed to load satellites:', err);
    }
  }

  /**
   * Animate the camera to a position above the satellite, looking
   * straight down. Uses the satellite's most recent ECEF position
   * (whichever the worker last reported); does not continuously track.
   */
  flyToSatellite(norad: number, durationSeconds = 1.5): void {
    if (!this.viewer || !this.layer) return;
    const pos = this.layer.getPosition(norad);
    if (pos === null) return; // worker hasn't reported a position yet

    const carto = Cesium.Cartographic.fromCartesian(pos);
    const lat = Cesium.Math.toDegrees(carto.latitude);
    const lon = Cesium.Math.toDegrees(carto.longitude);
    const camHeight = carto.height + 3_000_000; // 3000km above sat

    this.viewer.camera.flyTo({
      destination: Cesium.Cartesian3.fromDegrees(lon, lat, camHeight),
      orientation: { heading: 0, pitch: -Math.PI / 2, roll: 0 },
      duration: durationSeconds,
    });
  }

  /**
   * Show / hide the orbit ribbon for a NORAD.  Pulls the raw TLE
   * lines back from PointPrimitiveLayer (loaded at startup) and
   * builds a fresh satrec on the main thread for the ribbon — the
   * worker has its own satrecs but they're not transferable.
   * Pass null to clear.
   */
  setRibbonTarget(norad: number | null): void {
    if (!this.ribbons || !this.layer || !this.clock) return;
    if (norad === null || !this.overlayState.ribbons) {
      this.ribbons.hide();
      return;
    }
    const tle = this.layer.getTle(norad);
    if (tle === null) {
      this.ribbons.hide();
      return;
    }
    const satrec = twoline2satrec(tle.line1, tle.line2);
    this.ribbons.show(satrec, this.clock.getTimeMs());
  }

  /** Track the selected NORAD for the marquee model overlay. */
  setMarqueeTarget(norad: number | null): void {
    this.selectedNorad = norad;
    if (norad === null) {
      this.marquee?.hide();
      return;
    }
    this.refreshMarquee();
  }

  private refreshMarquee(): void {
    if (!this.marquee) return;
    const norad = this.selectedNorad;
    if (norad === null || !this.overlayState.marquee) {
      this.marquee.hide();
      return;
    }
    const position = this.layer?.getPosition(norad) ?? null;
    const name     = this.layer?.getName(norad) ?? null;
    this.marquee.update(norad, name, position);
  }

  /**
   * Phase 4 chunk 6D — count satellites currently above a given
   * elevation from an observer at (lat°, lon°, alt_m).  Reads the
   * worker's most-recent cached ECEF positions; cost is one vector
   * subtract + two dot products per satellite (~15k iterations, sub-
   * 50ms in practice).  Returns 0 if the point layer hasn't loaded.
   */
  countSatellitesAboveStation(latDeg: number, lonDeg: number, altMeters = 0, minElevationDeg = 5): number {
    if (!this.layer) return 0;
    const obsCart = Cesium.Cartesian3.fromDegrees(lonDeg, latDeg, altMeters);
    const enuFromWorld = Cesium.Matrix4.inverseTransformation(
      Cesium.Transforms.eastNorthUpToFixedFrame(obsCart),
      new Cesium.Matrix4(),
    );
    const minSinEl = Math.sin((minElevationDeg * Math.PI) / 180);

    let count = 0;
    const rel = new Cesium.Cartesian3();
    const enu = new Cesium.Cartesian3();
    // The layer doesn't expose its internal Map, so iterate via the
    // (norad, position) pairs accessible through the public helper.
    for (const [, position] of this.layer.iteratePositions()) {
      Cesium.Cartesian3.subtract(position, obsCart, rel);
      const range = Cesium.Cartesian3.magnitude(rel);
      if (range === 0) continue;
      Cesium.Matrix4.multiplyByPointAsVector(enuFromWorld, rel, enu);
      // sin(elevation) = up / range; up component is enu.z.
      if ((enu.z / range) >= minSinEl) {
        count++;
      }
    }
    return count;
  }

  destroy(): void {
    this.ribbonTickUnsub?.();
    this.ribbonTickUnsub = null;
    this.marqueeTickUnsub?.();
    this.marqueeTickUnsub = null;
    this.overlayUnsub?.();
    this.overlayUnsub = null;
    this.selection?.destroy();
    this.ribbons?.destroy();
    this.marquee?.destroy();
    this.stations?.destroy();
    this.lightPollution?.destroy();
    this.aurora?.destroy();
    this.layer?.destroy();
    this.viewer?.destroy();
    this.viewer = undefined;
    this.layer = undefined;
    this.ribbons = undefined;
    this.marquee = undefined;
    this.stations = undefined;
    this.lightPollution = undefined;
    this.aurora = undefined;
    this.selection = undefined;
  }
}

@customElement('sat-globe')
export class SatGlobe extends LitElement {
  @property({ type: String, attribute: 'cesium-ion-token' })
  cesiumIonToken = '';

  @state() private status = 'Initializing globe…';
  @state() private loaded = false;

  // Exposed so <sat-app> can call flyToSatellite / layer.getPosition /
  // layer.setHighlight from the host context without leaking the underlying
  // Cesium objects directly.
  public readonly globe = new Globe();
  private containerRef: Ref<HTMLDivElement> = createRef();

  static styles = css`
    :host {
      display: block;
      position: relative;
      width: 100%;
      height: 100%;
    }
    .container {
      position: absolute;
      inset: 0;
    }

    /* The widgets.css loaded in shell.php doesn't pierce this Lit
       component's Shadow DOM, so the Cesium viewer's internal divs
       default to a 300×150 canvas inside an unsized .cesium-widget.
       Inline the essential sizing rules so the viewer fills its
       container. */
    .container .cesium-viewer,
    .container .cesium-viewer-cesiumWidgetContainer,
    .container .cesium-widget,
    .container .cesium-widget canvas {
      width: 100%;
      height: 100%;
      display: block;
    }
    .container .cesium-widget canvas {
      touch-action: none;
    }
    .credit {
      position: absolute;
      bottom: 0.4rem;
      right: 0.6rem;
      color: var(--color-text-dim);
      font-family: var(--font-body);
      font-size: 0.7rem;
      pointer-events: auto;
      z-index: 1;
    }
    .credit a {
      color: var(--color-text-muted);
    }
    .status {
      position: absolute;
      bottom: 0.4rem;
      left: 0.6rem;
      padding: 0.25rem 0.6rem;
      background: var(--color-bg-overlay);
      border: 1px solid var(--color-border);
      border-radius: 4px;
      color: var(--color-text-muted);
      font-family: var(--font-mono);
      font-size: 0.75rem;
      pointer-events: none;
      z-index: 1;
      transition: opacity 0.4s ease;
    }
    .status.faded {
      opacity: 0.5;
    }
    /* Hide Cesium's built-in credit container — we render our own. */
    .container :global(.cesium-widget-credits) {
      display: none !important;
    }
  `;

  async firstUpdated(): Promise<void> {
    const container = this.containerRef.value;
    if (!container) return;

    await this.globe.init(container, {
      cesiumIonToken: this.cesiumIonToken,
      onSelect: (norad) => this.dispatchSelect(norad),
      onStationSelect: (pick, pos) => {
        // Phase 4 chunk 6D — bridge the SelectionController callback into
        // a window-level event the <sat-station-tooltip> listens for.
        // Compute the count synchronously against the worker's cached
        // positions so the tooltip never flickers as "Tracking 0".
        const layer = this.globe.stations;
        const catalog = layer?.constructor && (layer.constructor as { catalog?: () => Array<{
          id: string; name: string; latitude_deg: number; longitude_deg: number; altitude_m: number;
        }> }).catalog?.() || [];
        const station = catalog.find((s) => s.id === pick.stationId);
        const count = station
          ? this.globe.countSatellitesAboveStation(station.latitude_deg, station.longitude_deg, station.altitude_m, 5)
          : 0;
        window.dispatchEvent(new CustomEvent('station-pick-info', {
          detail: {
            stationId: pick.stationId,
            name:      pick.name,
            trackingCount: count,
            screenX:   pos.x,
            screenY:   pos.y,
          },
        }));
      },
      onStatus: (s) => {
        this.status = s;
        if (s.startsWith('Tracking')) {
          this.loaded = true;
          // Fade the status pill once tracking starts.
          window.setTimeout(() => this.requestUpdate(), 3000);
        }
      },
      onClockReady: (clock) => {
        this.dispatchEvent(
          new CustomEvent('clock-ready', {
            detail: { clock },
            bubbles: true,
            composed: true,
          }),
        );
      },
    });
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    this.globe.destroy();
  }

  private dispatchSelect(norad: number | null): void {
    this.dispatchEvent(
      new CustomEvent<{ norad: number | null }>('select', {
        detail: { norad },
        bubbles: true,
        composed: true,
      }),
    );
  }

  render() {
    return html`
      <div class="container" ${ref(this.containerRef)}></div>
      <div class="status ${this.loaded ? 'faded' : ''}">${this.status}</div>
      <div class="credit">
        ${this.cesiumIonToken
          ? html`<a href="https://cesium.com" target="_blank" rel="noopener">Cesium ion</a>`
          : html`©
              <a
                href="https://www.openstreetmap.org/copyright"
                target="_blank"
                rel="noopener"
                >OpenStreetMap</a
              >
              contributors`}
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-globe': SatGlobe;
  }
}
