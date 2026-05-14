import { LitElement, html, css } from 'lit';
import { customElement, property } from 'lit/decorators.js';

@customElement('sat-app')
export class SatApp extends LitElement {
  @property({ type: String, attribute: 'cesium-ion-token' })
  cesiumIonToken = '';

  @property({ type: String, attribute: 'selected-norad' })
  selectedNorad: string | null = null;

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
  `;

  render() {
    return html`
      <div class="layout">
        <sat-top-bar></sat-top-bar>
        <div class="globe-area">
          <sat-globe cesium-ion-token=${this.cesiumIonToken}></sat-globe>
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
