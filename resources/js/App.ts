import { LitElement, html, css } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';

@customElement('sat-app')
export class SatApp extends LitElement {
  @property({ type: String, attribute: 'cesium-ion-token' })
  cesiumIonToken = '';

  @property({ type: String, attribute: 'selected-norad' })
  selectedNorad: string | null = null;

  /** NORAD of the currently-selected satellite, or null. Set on click. */
  @state() private selected: number | null = null;

  static styles = css`
    :host {
      display: block;
      position: absolute;
      inset: 0;
      overflow: hidden;
    }
    .layout {
      display: grid;
      grid-template-rows: auto 1fr;
      height: 100%;
      width: 100%;
    }
    .globe-area {
      position: relative;
      overflow: hidden;
    }
    /* Chunk-5 placeholder — replaced by the real <sat-detail-panel> in chunk 6. */
    .selected-pill {
      position: absolute;
      top: 4rem;
      right: 1rem;
      padding: 0.4rem 0.8rem;
      background: var(--color-bg-overlay);
      border: 1px solid var(--color-accent);
      border-radius: 4px;
      color: var(--color-accent);
      font-family: var(--font-mono);
      font-size: 0.85rem;
      z-index: 5;
    }
  `;

  connectedCallback(): void {
    super.connectedCallback();
    if (this.selectedNorad !== null && /^\d+$/.test(this.selectedNorad)) {
      this.selected = parseInt(this.selectedNorad, 10);
    }
    this.addEventListener('select', this.handleSelect as EventListener);
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    this.removeEventListener('select', this.handleSelect as EventListener);
  }

  private handleSelect = (e: CustomEvent<{ norad: number | null }>): void => {
    this.selected = e.detail.norad;
  };

  render() {
    return html`
      <div class="layout">
        <sat-top-bar></sat-top-bar>
        <div class="globe-area">
          <sat-globe cesium-ion-token=${this.cesiumIonToken}></sat-globe>
          ${this.selected !== null
            ? html`<div class="selected-pill">§ NORAD ${this.selected}</div>`
            : null}
        </div>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-app': SatApp;
  }
}
