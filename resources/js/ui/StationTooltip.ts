import { LitElement, html, css, nothing } from 'lit';
import { customElement, state } from 'lit/decorators.js';

export interface StationTooltipDetail {
  stationId: string;
  name: string;
  trackingCount: number;
  /** Screen pixel where the user clicked, used for absolute positioning. */
  screenX: number;
  screenY: number;
}

/**
 * <sat-station-tooltip>
 *
 * Phase 4 chunk 6D — small pop-up that surfaces when the user clicks
 * a ground-station dot on the globe.  Shows the station name + a
 * live count of satellites currently above its 5° elevation horizon.
 *
 * Driven by `station-pick-info` CustomEvents dispatched from <sat-app>
 * after the chunk-4A SelectionController reports a station-pick.
 * Dismisses on Escape, on outside click, or after 8 seconds (the
 * count is a snapshot, not a live ticker — keep it ephemeral so it
 * doesn't compete visually with the persistent detail panel).
 */
@customElement('sat-station-tooltip')
export class SatStationTooltip extends LitElement {
  @state() private current: StationTooltipDetail | null = null;
  private autoCloseTimer: number | null = null;

  static styles = css`
    :host {
      position: fixed;
      top: 0;
      left: 0;
      pointer-events: none;
      z-index: 90;
    }
    .tooltip {
      position: absolute;
      transform: translate(-50%, -100%);
      margin-top: -0.6rem;
      background: var(--color-bg-elevated);
      border: 1px solid var(--color-accent);
      border-radius: 4px;
      padding: 0.5rem 0.7rem;
      box-shadow: 0 6px 18px rgba(0,0,0,0.35);
      font-family: var(--font-mono);
      font-size: 0.8rem;
      color: var(--color-text);
      min-width: 200px;
      pointer-events: auto;
    }
    .tooltip__name { color: var(--color-accent); font-weight: 500; }
    .tooltip__count { margin-top: 0.25rem; }
    .tooltip__count strong { color: var(--color-accent); }
    .tooltip__hint { margin-top: 0.4rem; color: var(--color-text-dim); font-size: 0.7rem; }
    .tooltip::after {
      content: '';
      position: absolute;
      top: 100%;
      left: 50%;
      transform: translateX(-50%);
      border-width: 6px 6px 0;
      border-style: solid;
      border-color: var(--color-accent) transparent transparent;
    }
  `;

  connectedCallback(): void {
    super.connectedCallback();
    window.addEventListener('station-pick-info', this.handleEvent as EventListener);
    document.addEventListener('keydown', this.handleKeyDown);
    document.addEventListener('click', this.handleOutsideClick, true);
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    window.removeEventListener('station-pick-info', this.handleEvent as EventListener);
    document.removeEventListener('keydown', this.handleKeyDown);
    document.removeEventListener('click', this.handleOutsideClick, true);
    if (this.autoCloseTimer !== null) {
      window.clearTimeout(this.autoCloseTimer);
      this.autoCloseTimer = null;
    }
  }

  private handleEvent = (e: CustomEvent<StationTooltipDetail>): void => {
    this.current = e.detail;
    if (this.autoCloseTimer !== null) window.clearTimeout(this.autoCloseTimer);
    this.autoCloseTimer = window.setTimeout(() => { this.current = null; }, 8000);
  };

  private handleKeyDown = (e: KeyboardEvent): void => {
    if (e.key === 'Escape') this.current = null;
  };

  private handleOutsideClick = (e: MouseEvent): void => {
    if (this.current === null) return;
    // Don't dismiss when the user clicks the tooltip itself.
    if (e.composedPath().includes(this)) return;
    this.current = null;
  };

  render() {
    const c = this.current;
    if (c === null) return nothing;
    return html`
      <div class="tooltip" style="left: ${c.screenX}px; top: ${c.screenY}px;">
        <div class="tooltip__name">⏚ ${c.name}</div>
        <div class="tooltip__count">
          Tracking <strong>${c.trackingCount.toLocaleString()}</strong>
          satellite${c.trackingCount === 1 ? '' : 's'} ≥ 5°
        </div>
        <div class="tooltip__hint">Click elsewhere or press Esc to dismiss.</div>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-station-tooltip': SatStationTooltip;
  }
  interface WindowEventMap {
    'station-pick-info': CustomEvent<StationTooltipDetail>;
  }
}
