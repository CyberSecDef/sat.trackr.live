import { LitElement, html, css, nothing } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import type { Globe } from '../globe/Globe';
import type { ReplayContext } from '../replay/replayContext';
import {
  formatMissDistance,
  formatTcaCountdown,
  liveMissKm,
} from '../replay/replayContext';

/**
 * Phase 6 chunk 2B — replay HUD overlay.
 *
 * Fixed top-center card visible only while a conjunction replay is
 * active.  Shows:
 *   - Both satellites' names + NORADs (primary in white, secondary in
 *     accent-orange to match the secondary ribbon hue)
 *   - Live miss distance (computed each tick from main-thread SGP4
 *     positions; falls back to the SOCRATES `tca_range_km` when the
 *     props haven't propagated yet)
 *   - TCA countdown (T-MM:SS / T+MM:SS)
 *   - Relative velocity at TCA (cached from the conjunction row;
 *     SOCRATES doesn't ship this for every pair → "—" when unknown)
 *   - Foster collision probability (same caveat)
 *   - ▶ play / ❚❚ pause button + Back link to the catalog
 *
 * No background timer — subscribes to the globe's Clock.onTick.
 */
@customElement('sat-conjunction-hud')
export class SatConjunctionHud extends LitElement {
  /** Replay context — supplied by <sat-app> when the route is /conjunction/p/s. */
  @property({ attribute: false }) ctx: ReplayContext | null = null;

  /** Globe handle; needed for clock + scene position accessors. */
  @property({ attribute: false }) globe: Globe | null = null;

  @state() private nowMs = 0;
  @state() private playing = false;
  @state() private liveMiss: number | null = null;

  private tickUnsub: (() => void) | null = null;

  static styles = css`
    :host {
      position: absolute;
      top: 0.75rem;
      left: 50%;
      transform: translateX(-50%);
      background: var(--color-bg-overlay);
      border: 1px solid var(--color-border);
      backdrop-filter: blur(10px);
      color: var(--color-text);
      font-family: var(--font-mono);
      padding: 0.75rem 1rem 0.6rem;
      border-radius: 6px;
      z-index: 30;
      pointer-events: auto;
      min-width: min(420px, 92vw);
    }
    .row {
      display: flex;
      align-items: baseline;
      gap: 0.8rem;
    }
    .pair {
      flex: 1;
      display: grid;
      grid-template-columns: auto 1fr;
      gap: 0.15rem 0.55rem;
      font-size: 0.78rem;
      color: var(--color-text-muted);
    }
    .pair .swatch {
      width: 0.6rem;
      height: 0.6rem;
      border-radius: 1px;
      transform: translateY(1px);
    }
    .pair .swatch--primary   { background: #ffffff; }
    .pair .swatch--secondary { background: #ffb700; }
    .pair .name {
      color: var(--color-text);
      font-weight: 500;
    }
    .stats {
      display: flex;
      gap: 1.2rem;
      margin-top: 0.55rem;
      align-items: baseline;
      flex-wrap: wrap;
    }
    .stat {
      display: flex;
      flex-direction: column;
      gap: 0.1rem;
    }
    .stat__label {
      font-size: 0.65rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--color-text-dim);
    }
    .stat__value {
      font-size: 1rem;
      color: var(--color-text);
    }
    .stat__value--miss { color: var(--color-accent); font-weight: 500; }
    .stat__value--cd   { font-variant-numeric: tabular-nums; }

    .controls {
      display: flex;
      gap: 0.6rem;
      margin-top: 0.55rem;
      align-items: center;
      justify-content: space-between;
    }
    button.play {
      background: var(--color-accent);
      color: var(--color-bg);
      border: none;
      border-radius: 4px;
      padding: 0.35rem 0.8rem;
      font-family: var(--font-mono);
      font-size: 0.85rem;
      font-weight: 500;
      cursor: pointer;
      transition: filter 0.1s ease;
    }
    button.play:hover { filter: brightness(1.1); }
    button.play[data-state='playing'] {
      background: transparent;
      color: var(--color-accent);
      border: 1px solid var(--color-accent);
    }
    a.back {
      color: var(--color-text-muted);
      font-size: 0.78rem;
      text-decoration: none;
      border: 1px solid var(--color-border);
      border-radius: 3px;
      padding: 0.2rem 0.55rem;
    }
    a.back:hover {
      color: var(--color-accent);
      border-color: var(--color-accent);
    }
  `;

  connectedCallback(): void {
    super.connectedCallback();
    this.maybeSubscribe();
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    this.tickUnsub?.();
    this.tickUnsub = null;
  }

  willUpdate(changed: Map<string, unknown>): void {
    if ((changed.has('globe') || changed.has('ctx')) && this.globe !== null && this.ctx !== null) {
      this.maybeSubscribe();
    }
  }

  private maybeSubscribe(): void {
    if (this.tickUnsub !== null) return;
    const clock = this.globe?.clock;
    if (clock === undefined) return;
    this.nowMs = clock.getTimeMs();
    this.playing = clock.isPlaying();
    this.tickUnsub = clock.onTick((ms) => {
      this.nowMs = ms;
      this.playing = this.globe?.clock?.isPlaying() ?? false;
      this.refreshLiveMiss();
    });
  }

  private refreshLiveMiss(): void {
    const scene = this.globe?.conjunctionScene;
    if (scene === undefined) {
      this.liveMiss = null;
      return;
    }
    this.liveMiss = liveMissKm(scene.livePrimaryEcefMeters(), scene.liveSecondaryEcefMeters());
  }

  private togglePlay(): void {
    const clock = this.globe?.clock;
    if (clock === undefined) return;
    clock.togglePlay();
    this.playing = clock.isPlaying();
  }

  render() {
    if (this.ctx === null) return nothing;
    const tcaMs = Date.parse(this.ctx.tca);
    const offset = this.nowMs - tcaMs;
    const missText = this.liveMiss !== null
      ? formatMissDistance(this.liveMiss)
      : formatMissDistance(this.ctx.missKm);
    const velText = this.ctx.relSpeedKmS !== null
      ? `${this.ctx.relSpeedKmS.toFixed(2)} km/s`
      : '—';
    const probText = this.ctx.probability !== null
      ? this.ctx.probability.toExponential(2)
      : '—';

    return html`
      <div class="row">
        <div class="pair">
          <span class="swatch swatch--primary" aria-hidden="true"></span>
          <span class="name">${this.ctx.primaryName} <span style="color: var(--color-text-dim);">(${this.ctx.primary})</span></span>
          <span class="swatch swatch--secondary" aria-hidden="true"></span>
          <span class="name">${this.ctx.secondaryName} <span style="color: var(--color-text-dim);">(${this.ctx.secondary})</span></span>
        </div>
      </div>
      <div class="stats">
        <div class="stat">
          <span class="stat__label">Miss</span>
          <span class="stat__value stat__value--miss">${missText}</span>
        </div>
        <div class="stat">
          <span class="stat__label">TCA</span>
          <span class="stat__value stat__value--cd">${formatTcaCountdown(offset)}</span>
        </div>
        <div class="stat">
          <span class="stat__label">Rel velocity</span>
          <span class="stat__value">${velText}</span>
        </div>
        <div class="stat">
          <span class="stat__label">Probability</span>
          <span class="stat__value">${probText}</span>
        </div>
      </div>
      <div class="controls">
        <a class="back" href="/text/conjunctions">‹ back to list</a>
        <button class="play" data-state=${this.playing ? 'playing' : 'paused'} @click=${this.togglePlay}>
          ${this.playing ? '❚❚ Pause' : '▶ Play'}
        </button>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-conjunction-hud': SatConjunctionHud;
  }
}
