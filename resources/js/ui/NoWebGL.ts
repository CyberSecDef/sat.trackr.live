import { LitElement, html, css } from 'lit';
import { customElement } from 'lit/decorators.js';

/**
 * <sat-no-webgl>
 *
 * Rendered by <sat-app> in place of <sat-globe>+<sat-timeline> when
 * WebGL is unavailable (older browsers, IT-restricted environments,
 * GPU disabled, headless tools). Per req_spec §24 the recovery path
 * is a server-rendered text-only catalog at /text.
 */
@customElement('sat-no-webgl')
export class SatNoWebGL extends LitElement {
  static styles = css`
    :host {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 1.4rem;
      padding: 3rem 1.5rem;
      height: 100%;
      width: 100%;
      background: var(--color-bg);
      color: var(--color-text);
      font-family: var(--font-body);
      text-align: center;
    }
    .brand {
      display: flex;
      align-items: baseline;
      gap: 0.6rem;
      font-family: var(--font-mono);
    }
    .brand__glyph {
      color: var(--color-accent);
      font-size: 1.8rem;
      line-height: 1;
    }
    .brand__name {
      font-size: 1.2rem;
      font-weight: 500;
    }
    h1 {
      margin: 0;
      font-family: var(--font-mono);
      font-size: 1.4rem;
      color: var(--color-warning);
      font-weight: 500;
    }
    p {
      max-width: 32rem;
      margin: 0;
      color: var(--color-text-muted);
      line-height: 1.55;
      font-size: 0.95rem;
    }
    .cta {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.7rem 1.4rem;
      background: var(--color-accent);
      color: var(--color-bg);
      font-family: var(--font-mono);
      font-size: 0.95rem;
      font-weight: 500;
      border-radius: 4px;
      text-decoration: none;
      letter-spacing: 0.04em;
    }
    .cta:hover {
      filter: brightness(1.1);
      text-decoration: none;
    }
    .secondary {
      color: var(--color-text-muted);
      font-family: var(--font-mono);
      font-size: 0.75rem;
    }
    .secondary a {
      color: var(--color-accent);
      text-decoration: none;
    }
    .browsers {
      color: var(--color-text-dim);
      font-size: 0.8rem;
      max-width: 32rem;
    }
  `;

  render() {
    return html`
      <div class="brand">
        <span class="brand__glyph" aria-hidden="true">⊕</span>
        <span class="brand__name">sat.trackr.live</span>
      </div>
      <h1>WebGL required for the 3D globe</h1>
      <p>
        Your browser doesn't appear to support WebGL, which the Cesium-powered globe needs to render.
        That's expected on older browsers, locked-down enterprise environments, or when GPU acceleration is disabled.
      </p>
      <a class="cta" href="/text">Open the text catalog →</a>
      <p class="browsers">
        WebGL works in current versions of Chrome, Firefox, Safari, and Edge on most desktops and phones.
        If you're on one of those, check that hardware acceleration is enabled in browser settings.
      </p>
      <p class="secondary">
        Also available: <a href="/api/v1/satellites">/api/v1/satellites</a> · <a href="/text/search">/text/search</a>
      </p>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-no-webgl': SatNoWebGL;
  }
}
