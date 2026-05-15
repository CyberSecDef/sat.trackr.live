import { LitElement, html, css } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('sat-top-bar')
export class SatTopBar extends LitElement {
  static styles = css`
    :host {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: 0.6rem 1rem;
      background: var(--color-bg-overlay);
      border-bottom: 1px solid var(--color-border);
      backdrop-filter: blur(8px);
      z-index: 10;
    }

    .brand {
      display: flex;
      align-items: baseline;
      gap: 0.6rem;
      font-family: var(--font-mono);
      flex-shrink: 0;
    }
    .brand__glyph {
      color: var(--color-accent);
      font-size: 1.4rem;
      line-height: 1;
    }
    .brand__name {
      color: var(--color-text);
      font-size: 1rem;
      font-weight: 500;
    }
    .brand__tagline {
      color: var(--color-text-muted);
      font-family: var(--font-body);
      font-size: 0.85rem;
      font-style: italic;
    }
    .brand__tagline em {
      color: var(--color-accent);
      font-style: italic;
      font-weight: 500;
    }

    nav {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      color: var(--color-text-muted);
      font-family: var(--font-mono);
      font-size: 0.85rem;
    }
    nav .divider {
      color: var(--color-text-dim);
    }
    nav .item {
      cursor: pointer;
      color: inherit;
      text-decoration: none;
    }
    nav .item:hover {
      color: var(--color-accent);
      text-decoration: none;
    }
    nav .item--placeholder {
      opacity: 0.4;
      cursor: default;
    }
    nav .item--placeholder:hover {
      color: var(--color-text-muted);
    }

    .actions {
      display: flex;
      align-items: center;
      gap: 0.8rem;
      flex-shrink: 0;
    }

    @media (max-width: 800px) {
      .brand__tagline {
        display: none;
      }
      nav {
        display: none;
      }
    }
  `;

  render() {
    return html`
      <div class="brand">
        <span class="brand__glyph" aria-hidden="true">⊕</span>
        <span class="brand__name">sat.trackr.live</span>
        <span class="brand__tagline">Space situational awareness, <em>legible</em></span>
      </div>
      <nav aria-label="Primary">
        <a class="item" href="/text">§ catalog</a>
        <span class="divider" aria-hidden="true">·</span>
        <a class="item" href="/text/launches">§ launches</a>
        <span class="divider" aria-hidden="true">·</span>
        <a class="item" href="/text/decays">§ decays</a>
        <span class="divider" aria-hidden="true">·</span>
        <span class="item item--placeholder" title="coming in Phase 4">§ events</span>
      </nav>
      <div class="actions">
        <sat-search></sat-search>
        <sat-observer-pill></sat-observer-pill>
        <sat-overlays-menu></sat-overlays-menu>
        <sat-theme-switcher></sat-theme-switcher>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-top-bar': SatTopBar;
  }
}
