import { LitElement, html, css, nothing } from 'lit';
import { customElement, state } from 'lit/decorators.js';
import { getSharedOverlays, type OverlayKey, type OverlayState } from '../overlays/OverlayService';

interface OverlayMeta {
  key: OverlayKey;
  glyph: string;
  label: string;
  description: string;
}

const OVERLAYS: ReadonlyArray<OverlayMeta> = [
  {
    key: 'ribbons',
    glyph: '〜',
    label: 'Orbit ribbon',
    description: '±N orbits ground track for the selected satellite',
  },
  {
    key: 'marquee',
    glyph: '◰',
    label: '3D shapes',
    description: 'Boxes/cylinders for ISS, Tiangong, Hubble & cargo capsules when zoomed in',
  },
  {
    key: 'stations',
    glyph: '⏚',
    label: 'Ground stations',
    description: '~40 NEN / DSN / ESTRACK / JAXA / ISRO / commercial sites with 5° FOV cones',
  },
  {
    key: 'lightPollution',
    glyph: '☀',
    label: 'Light pollution (Phase 3 chunk 5)',
    description: 'Coming next chunk: VIIRS night-lights raster overlay',
  },
];

/**
 * <sat-overlays-menu>
 *
 * Topbar dropdown that toggles the Phase 3 visual overlays.
 * Subscribes to OverlayService so other components can mutate state
 * (or storage rehydrate) without us having to poll.
 */
@customElement('sat-overlays-menu')
export class SatOverlaysMenu extends LitElement {
  @state() private open = false;
  @state() private overlayState: OverlayState = {
    ribbons: true,
    marquee: true,
    stations: false,
    lightPollution: false,
  };

  private unsubscribe: (() => void) | null = null;

  static styles = css`
    :host {
      position: relative;
      display: inline-block;
      font-family: var(--font-mono);
    }
    .toggle {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      padding: 0.3rem 0.6rem;
      background: transparent;
      color: var(--color-text);
      border: 1px solid var(--color-border);
      border-radius: 4px;
      font-family: var(--font-mono);
      font-size: 0.85rem;
      cursor: pointer;
    }
    .toggle:hover { border-color: var(--color-accent); color: var(--color-accent); }
    .menu {
      position: absolute;
      top: calc(100% + 0.4rem);
      right: 0;
      min-width: 320px;
      background: var(--color-bg-elevated);
      border: 1px solid var(--color-border);
      border-radius: 4px;
      padding: 0.4rem 0;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
      z-index: 100;
    }
    .menu h3 {
      margin: 0;
      padding: 0 0.8rem 0.4rem;
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--color-text-dim);
    }
    .item {
      display: flex;
      align-items: flex-start;
      gap: 0.6rem;
      padding: 0.45rem 0.8rem;
      cursor: pointer;
      transition: background 0.1s ease;
    }
    .item:hover { background: rgba(255, 255, 255, 0.04); }
    .item__check {
      flex-shrink: 0;
      width: 1rem;
      height: 1rem;
      margin-top: 0.15rem;
      border: 1px solid var(--color-border);
      border-radius: 2px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.75rem;
      color: var(--color-bg);
      background: transparent;
    }
    .item__check[data-on='true'] {
      background: var(--color-accent);
      border-color: var(--color-accent);
    }
    .item__glyph {
      flex-shrink: 0;
      font-size: 0.95rem;
      width: 1rem;
      text-align: center;
      color: var(--color-text-muted);
    }
    .item__body { display: flex; flex-direction: column; gap: 0.1rem; }
    .item__label { font-size: 0.85rem; color: var(--color-text); }
    .item__desc { font-size: 0.7rem; color: var(--color-text-dim); }
  `;

  connectedCallback(): void {
    super.connectedCallback();
    this.unsubscribe = getSharedOverlays().subscribe((s) => { this.overlayState = s; });
    document.addEventListener('click', this.handleOutsideClick);
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    this.unsubscribe?.();
    this.unsubscribe = null;
    document.removeEventListener('click', this.handleOutsideClick);
  }

  private handleOutsideClick = (e: MouseEvent): void => {
    if (!e.composedPath().includes(this)) {
      this.open = false;
    }
  };

  private toggle(): void {
    this.open = !this.open;
  }

  private onItemClick(key: OverlayKey): void {
    getSharedOverlays().setEnabled(key, !this.overlayState[key]);
  }

  render() {
    const enabledCount = Object.values(this.overlayState).filter(Boolean).length;
    return html`
      <button class="toggle" @click=${this.toggle} aria-haspopup="menu" aria-expanded=${this.open ? 'true' : 'false'}>
        <span aria-hidden="true">§</span>
        <span>overlays</span>
        <span style="color: var(--color-text-dim); font-size: 0.75rem;">${enabledCount}</span>
      </button>
      ${this.open
        ? html`
            <div class="menu" role="menu">
              <h3>Visual overlays</h3>
              ${OVERLAYS.map((o) => html`
                <div class="item" role="menuitemcheckbox" aria-checked=${this.overlayState[o.key] ? 'true' : 'false'} @click=${() => this.onItemClick(o.key)}>
                  <span class="item__check" data-on=${this.overlayState[o.key] ? 'true' : 'false'}>${this.overlayState[o.key] ? '✓' : ''}</span>
                  <span class="item__glyph" aria-hidden="true">${o.glyph}</span>
                  <span class="item__body">
                    <span class="item__label">${o.label}</span>
                    <span class="item__desc">${o.description}</span>
                  </span>
                </div>
              `)}
            </div>
          `
        : nothing}
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-overlays-menu': SatOverlaysMenu;
  }
}
