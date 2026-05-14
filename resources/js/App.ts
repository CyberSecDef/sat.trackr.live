import { LitElement, html, css } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { ref, createRef, type Ref } from 'lit/directives/ref.js';
import type * as Cesium from 'cesium';
import type { SatGlobe } from './globe/Globe';
import type { Clock } from './time/Clock';
import './ui/DetailPanel';
import './ui/Timeline';

@customElement('sat-app')
export class SatApp extends LitElement {
  @property({ type: String, attribute: 'cesium-ion-token' })
  cesiumIonToken = '';

  @property({ type: String, attribute: 'selected-norad' })
  selectedNorad: string | null = null;

  /** NORAD of the currently-selected satellite, or null. */
  @state() private selected: number | null = null;

  /** Clock published by <sat-globe> once it's initialized. */
  @state() private clock: Clock | null = null;

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
    if (this.selectedNorad !== null && /^\d+$/.test(this.selectedNorad)) {
      this.selected = parseInt(this.selectedNorad, 10);
    }
    this.addEventListener('select', this.handleSelect as EventListener);
    this.addEventListener('search-select', this.handleSearchSelect as EventListener);
    this.addEventListener('panel-close', this.handlePanelClose as EventListener);
    this.addEventListener('clock-ready', this.handleClockReady as EventListener);
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    this.removeEventListener('select', this.handleSelect as EventListener);
    this.removeEventListener('search-select', this.handleSearchSelect as EventListener);
    this.removeEventListener('panel-close', this.handlePanelClose as EventListener);
    this.removeEventListener('clock-ready', this.handleClockReady as EventListener);
  }

  private handleClockReady = (e: CustomEvent<{ clock: Clock }>): void => {
    this.clock = e.detail.clock;
  };

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

  private applySelection(norad: number | null, fly: boolean): void {
    this.selected = norad;
    const globe = this.globeRef.value?.globe;
    globe?.layer?.setHighlight(norad);
    if (fly && norad !== null) {
      globe?.flyToSatellite(norad);
    }
  }

  /** For <sat-detail-panel> — looks up live ECEF from the layer. */
  private getCurrentPosition = (norad: number): Cesium.Cartesian3 | null => {
    return this.globeRef.value?.globe.layer?.getPosition(norad) ?? null;
  };

  render() {
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
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-app': SatApp;
  }
}
