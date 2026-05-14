import { LitElement, html, css } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import type { Freshness } from '../api/types';

/**
 * <sat-freshness-badge epoch="2026-05-14T04:45:57Z">
 *
 * Tiny pill that classifies a TLE epoch's age into FRESH / STALE / AGED / OLD
 * using the same thresholds as the PHP-side FreshnessClassifier:
 *   <48h FRESH, 48h–7d STALE, 7d–14d AGED, >14d OLD
 *
 * The classification is computed locally (no API round-trip) so it stays
 * accurate as the page sits open.
 */
@customElement('sat-freshness-badge')
export class SatFreshnessBadge extends LitElement {
  @property({ type: String }) epoch = '';

  @state() private now = Date.now();

  private tickInterval: number | null = null;

  static styles = css`
    :host {
      display: inline-flex;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 0.3rem;
      padding: 0.1rem 0.45rem;
      border-radius: 3px;
      font-family: var(--font-mono);
      font-size: 0.7rem;
      font-weight: 500;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      border: 1px solid currentColor;
      background: transparent;
    }
    .badge--fresh { color: var(--color-fresh); }
    .badge--stale { color: var(--color-stale); }
    .badge--aged  { color: var(--color-aged); }
    .badge--old   { color: var(--color-old); }
    .age {
      color: var(--color-text-dim);
      font-size: 0.7rem;
      margin-left: 0.4rem;
    }
  `;

  connectedCallback(): void {
    super.connectedCallback();
    // Re-tick every minute so a long-open page doesn't show a stale label.
    this.tickInterval = window.setInterval(() => {
      this.now = Date.now();
    }, 60_000);
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    if (this.tickInterval !== null) {
      window.clearInterval(this.tickInterval);
      this.tickInterval = null;
    }
  }

  private classify(): { label: Freshness; ageText: string } {
    if (!this.epoch) {
      return { label: 'OLD', ageText: '' };
    }
    const epochMs = Date.parse(this.epoch);
    if (Number.isNaN(epochMs)) {
      return { label: 'OLD', ageText: '' };
    }
    const ageSec = Math.max(0, Math.floor((this.now - epochMs) / 1000));
    let label: Freshness;
    if (ageSec < 48 * 3600)        label = 'FRESH';
    else if (ageSec <  7 * 86400)  label = 'STALE';
    else if (ageSec < 14 * 86400)  label = 'AGED';
    else                            label = 'OLD';

    return { label, ageText: formatAge(ageSec) };
  }

  render() {
    const { label, ageText } = this.classify();
    const cls = `badge badge--${label.toLowerCase()}`;
    return html`
      <span class=${cls} title=${`Epoch: ${this.epoch}`}>${label}</span>
      ${ageText ? html`<span class="age">${ageText}</span>` : null}
    `;
  }
}

function formatAge(seconds: number): string {
  if (seconds < 60) return `${seconds}s ago`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  return `${Math.floor(seconds / 86400)}d ago`;
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-freshness-badge': SatFreshnessBadge;
  }
}
