import { LitElement, html, css } from 'lit';
import { customElement, property } from 'lit/decorators.js';
import { ref, createRef, type Ref } from 'lit/directives/ref.js';
import * as Cesium from 'cesium';
import { createImageryProvider } from './imagery';

/**
 * Plain class wrapping Cesium.Viewer. Lit-element-agnostic so the imperative
 * Cesium API isn't fighting Lit's reactive render cycle.
 */
class Globe {
  private viewer?: Cesium.Viewer;

  async init(container: HTMLElement, opts: { cesiumIonToken: string }): Promise<void> {
    const provider = await createImageryProvider(opts.cesiumIonToken);

    this.viewer = new Cesium.Viewer(container, {
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

    this.viewer.scene.globe.enableLighting = true;
    this.viewer.scene.skyAtmosphere.show = true;
    this.viewer.scene.fog.enabled = true;
    this.viewer.scene.backgroundColor = Cesium.Color.fromCssColorString('#0a0e27');
  }

  destroy(): void {
    this.viewer?.destroy();
    this.viewer = undefined;
  }
}

@customElement('sat-globe')
export class SatGlobe extends LitElement {
  @property({ type: String, attribute: 'cesium-ion-token' })
  cesiumIonToken = '';

  private globe = new Globe();
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
    /* Hide Cesium's own credit container — we render our own above */
    .container :global(.cesium-widget-credits) {
      display: none !important;
    }
  `;

  async firstUpdated(): Promise<void> {
    const container = this.containerRef.value;
    if (container) {
      try {
        await this.globe.init(container, { cesiumIonToken: this.cesiumIonToken });
      } catch (err) {
        console.error('Failed to initialize Cesium globe:', err);
      }
    }
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    this.globe.destroy();
  }

  render() {
    return html`
      <div class="container" ${ref(this.containerRef)}></div>
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
