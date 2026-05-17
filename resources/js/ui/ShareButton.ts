import { LitElement, html, css } from 'lit';
import { customElement, state } from 'lit/decorators.js';
import type { SatApp } from '../App';
import { getSharedObserver } from '../observer/Observer';
import { buildShareUrl, type ShareState } from '../share/shareUrl';

/**
 * Phase 5 chunk 6B — topbar Share button.
 *
 * Reads current selection + observer + clock from the closest <sat-app>
 * ancestor, builds a deep-link URL with ?sat=&lat=&lon=&t=, and either:
 *   - calls navigator.share() if available (iOS / Android),
 *   - else copies to the clipboard with a brief "Copied" flash.
 *
 * No background timer — state is read at click-time so there's no
 * stale-state-vs-display drift.
 */
@customElement('sat-share-button')
export class SatShareButton extends LitElement {
  @state() private status: 'idle' | 'copied' | 'failed' = 'idle';

  static styles = css`
    button {
      background: transparent;
      border: 1px solid var(--color-border);
      color: var(--color-text-muted);
      font-family: var(--font-mono);
      font-size: 0.8rem;
      padding: 0.25rem 0.65rem;
      border-radius: 4px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      transition: color 0.1s ease, border-color 0.1s ease;
    }
    button:hover {
      color: var(--color-accent);
      border-color: var(--color-accent);
    }
    button[data-status='copied'] {
      color: var(--color-accent);
      border-color: var(--color-accent);
    }
    button[data-status='failed'] {
      color: var(--color-warning);
      border-color: var(--color-warning);
    }
    .glyph { font-size: 0.95rem; line-height: 1; }
  `;

  render() {
    const label = this.status === 'copied'
      ? 'Copied'
      : this.status === 'failed'
        ? 'Failed'
        : 'Share';
    return html`
      <button
        type="button"
        @click=${this.handleClick}
        data-status=${this.status}
        title="Copy a link that restores this satellite, observer, and time"
      >
        <span class="glyph" aria-hidden="true">↗</span>
        ${label}
      </button>
    `;
  }

  private async handleClick(): Promise<void> {
    const url = this.currentShareUrl();
    const canShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';

    try {
      if (canShare) {
        await navigator.share({
          url,
          title: 'sat.trackr.live',
          text: 'Live satellite-tracking link',
        });
        this.flash('copied');
        return;
      }
      await navigator.clipboard.writeText(url);
      this.flash('copied');
    } catch (err) {
      // navigator.share() rejects with AbortError when the user dismisses
      // the sheet — that's not a failure, just a no-op. Anything else
      // is a real problem and we flash the warning state.
      if (err instanceof DOMException && err.name === 'AbortError') {
        return;
      }
      this.flash('failed');
    }
  }

  private currentShareUrl(): string {
    const app = findAncestorAcrossShadow(this, 'sat-app') as SatApp | null;
    const state: ShareState = {};

    if (app !== null) {
      const sel = (app as unknown as { selected: number | null }).selected;
      if (typeof sel === 'number' && sel > 0) state.sat = sel;
      const clk = (app as unknown as { clock: { getTimeMs(): number } | null }).clock;
      if (clk !== null) state.t = new Date(clk.getTimeMs()).toISOString();
    }

    const obs = getSharedObserver().getCurrent();
    if (obs !== null) {
      state.lat = obs.latitude;
      state.lon = obs.longitude;
      if (obs.altitudeMeters !== 0) state.altMeters = obs.altitudeMeters;
    }

    return buildShareUrl(window.location.origin, window.location.pathname, state);
  }

  private flash(next: 'copied' | 'failed'): void {
    this.status = next;
    window.setTimeout(() => { this.status = 'idle'; }, 1500);
  }
}

/**
 * Element.closest() stops at shadow-root boundaries. Our DOM is
 * sat-app -> sat-top-bar (shadow) -> sat-share-button (shadow), so
 * closest('sat-app') from here returns null. This walker hops out of
 * each shadow root via its `host` until it finds a match or the
 * document is exhausted.
 */
function findAncestorAcrossShadow(start: Element, selector: string): Element | null {
  let node: Node | null = start;
  while (node !== null) {
    if (node instanceof Element && node.matches(selector)) return node;
    const parent: Node | null = (node as Node).parentNode ?? null;
    if (parent instanceof ShadowRoot) {
      node = parent.host;
    } else {
      node = parent;
    }
  }
  return null;
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-share-button': SatShareButton;
  }
}
