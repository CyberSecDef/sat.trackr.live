import { LitElement, html, css } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { ref, createRef, type Ref } from 'lit/directives/ref.js';
import type * as Cesium from 'cesium';
import type { SatGlobe } from './globe/Globe';
import type { Clock } from './time/Clock';
import { hasWebGL } from './util/webgl';
import { getSharedObserver } from './observer/Observer';
import { readEmbeddedReplayContext, type ReplayContext } from './replay/replayContext';
import { parseShareParams } from './share/shareUrl';
import './ui/DetailPanel';
import './ui/Timeline';
import './ui/NoWebGL';

@customElement('sat-app')
export class SatApp extends LitElement {
  @property({ type: String, attribute: 'cesium-ion-token' })
  cesiumIonToken = '';

  @property({ type: String, attribute: 'selected-norad' })
  selectedNorad: string | null = null;

  /** When set to "conjunction", the SPA enters replay mode using the
   *  JSON blob in #sat-replay-context. See docs/phase6.md. */
  @property({ type: String, attribute: 'replay-mode' })
  replayMode: string | null = null;

  /** NORAD of the currently-selected satellite, or null. */
  @state() private selected: number | null = null;

  /** Phase 6 chunk 1 — populated when the route is /conjunction/p/s and the
   *  shell embedded a valid context blob. Drives ConjunctionScene activation. */
  @state() private replayContext: ReplayContext | null = null;

  /** Clock published by <sat-globe> once it's initialized. */
  @state() private clock: Clock | null = null;

  /** Set in connectedCallback. Drives the WebGL fallback branch in render(). */
  @state() private webglSupported = true;

  private globeRef: Ref<SatGlobe> = createRef();

  static styles = css`
    :host {
      display: block;
      position: absolute;
      inset: 0;
      overflow: hidden;
    }
    .layout {
      display: grid;
      grid-template-rows: auto 1fr auto;
      height: 100%;
      width: 100%;
    }
    .globe-area {
      position: relative;
      overflow: hidden;
    }
  `;

  connectedCallback(): void {
    super.connectedCallback();
    this.webglSupported = hasWebGL();
    if (this.selectedNorad !== null && /^\d+$/.test(this.selectedNorad)) {
      this.selected = parseInt(this.selectedNorad, 10);
    }
    this.addEventListener('select', this.handleSelect as EventListener);
    this.addEventListener('search-select', this.handleSearchSelect as EventListener);
    this.addEventListener('panel-close', this.handlePanelClose as EventListener);
    this.addEventListener('clock-ready', this.handleClockReady as EventListener);
    this.addEventListener('ribbon-orbits-change', this.handleRibbonOrbits as EventListener);

    // Phase 5 chunk 6 — deep-link restore from ?sat & lat & lon & alt & t.
    // Observer is set immediately; sat selection waits for the globe (which
    // mounts in updated()) and clock waits for the `clock-ready` event.
    this.applyShareParamsFromUrl();

    // Phase 6 chunk 1 — conjunction replay mode. Reads the JSON blob the
    // SpaShellController embedded; if absent or unparseable, the SPA boots
    // in normal mode (the `<sat-app>` element keeps `replay-mode` set
    // but `replayContext` stays null, so ConjunctionScene won't activate).
    if (this.replayMode === 'conjunction') {
      this.replayContext = readEmbeddedReplayContext();
    }
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    this.removeEventListener('select', this.handleSelect as EventListener);
    this.removeEventListener('search-select', this.handleSearchSelect as EventListener);
    this.removeEventListener('panel-close', this.handlePanelClose as EventListener);
    this.removeEventListener('clock-ready', this.handleClockReady as EventListener);
    this.removeEventListener('ribbon-orbits-change', this.handleRibbonOrbits as EventListener);
  }

  private handleClockReady = (e: CustomEvent<{ clock: Clock }>): void => {
    this.clock = e.detail.clock;
    // Globe is ready now — drain any deferred share-URL actions.
    if (this.pendingClockMs !== null) {
      e.detail.clock.setTimeMs(this.pendingClockMs);
      this.pendingClockMs = null;
    }
    if (this.pendingFlyToNorad !== null) {
      this.applySelection(this.pendingFlyToNorad, /* fly */ true);
      this.pendingFlyToNorad = null;
    }
    // Phase 6 chunk 1 — if the route was /conjunction/p/s and the shell
    // embedded a valid context blob, enter replay mode now that the
    // globe is initialized. Errors during scene activation are logged
    // and the SPA stays in normal mode — better than a blank page.
    if (this.replayContext !== null) {
      const globe = this.globeRef.value?.globe;
      void globe?.enterConjunctionReplay(this.replayContext).catch((err: unknown) => {
        console.warn('[sat-app] conjunction replay activation failed', err);
        this.replayContext = null;
      });
    }
  };

  /** Pending share-URL state held until the globe's Clock dispatches `clock-ready`. */
  private pendingClockMs: number | null = null;
  private pendingFlyToNorad: number | null = null;

  private applyShareParamsFromUrl(): void {
    const params = parseShareParams(window.location.search);

    if (params.sat !== undefined && this.selected === null) {
      this.selected = params.sat;
      // Defer the camera fly + highlight until the globe is initialized.
      this.pendingFlyToNorad = params.sat;
    }

    if (params.lat !== undefined && params.lon !== undefined) {
      // Don't clobber an observer the user has already chosen (and which
      // localStorage will have restored before we run). Share-URL state
      // only wins when no observer is set yet.
      const obs = getSharedObserver();
      if (obs.getCurrent() === null) {
        obs.setManual(params.lat, params.lon, params.altMeters ?? 0, 'Shared location');
      }
    }

    if (params.t !== undefined) {
      const ms = Date.parse(params.t);
      if (!Number.isNaN(ms)) this.pendingClockMs = ms;
    }
  }

  /** Click-on-globe → just set selection (no camera fly). */
  private handleSelect = (e: CustomEvent<{ norad: number | null }>): void => {
    this.applySelection(e.detail.norad, /* fly */ false);
  };

  /** Search picker → set selection AND fly the camera to it. */
  private handleSearchSelect = (e: CustomEvent<{ norad: number; name: string }>): void => {
    this.applySelection(e.detail.norad, /* fly */ true);
  };

  private handlePanelClose = (): void => {
    this.applySelection(null, false);
  };

  private handleRibbonOrbits = (e: CustomEvent<{ orbits: number }>): void => {
    this.globeRef.value?.globe.ribbons?.setOrbits(e.detail.orbits);
  };

  private applySelection(norad: number | null, fly: boolean): void {
    this.selected = norad;
    const globe = this.globeRef.value?.globe;
    globe?.layer?.setHighlight(norad);
    globe?.setRibbonTarget(norad);
    globe?.setMarqueeTarget(norad);
    if (fly && norad !== null) {
      globe?.flyToSatellite(norad);
    }
  }

  /** For <sat-detail-panel> — looks up live ECEF from the layer. */
  private getCurrentPosition = (norad: number): Cesium.Cartesian3 | null => {
    return this.globeRef.value?.globe.layer?.getPosition(norad) ?? null;
  };

  render() {
    if (!this.webglSupported) {
      // Per req_spec §24: WebGL unavailable → graceful fallback notice
      // pointing at the server-rendered text-only catalog at /text.
      return html`
        <div class="layout">
          <sat-top-bar></sat-top-bar>
          <sat-no-webgl></sat-no-webgl>
        </div>
      `;
    }
    return html`
      <div class="layout">
        <sat-top-bar></sat-top-bar>
        <div class="globe-area">
          <sat-globe
            cesium-ion-token=${this.cesiumIonToken}
            ${ref(this.globeRef)}
          ></sat-globe>
          <sat-detail-panel
            .norad=${this.selected}
            .getCurrentPosition=${this.getCurrentPosition}
          ></sat-detail-panel>
        </div>
        <sat-timeline .clock=${this.clock}></sat-timeline>
        <sat-station-tooltip></sat-station-tooltip>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-app': SatApp;
  }
}
