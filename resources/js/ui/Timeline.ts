import { LitElement, html, css, nothing } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import type { Clock, ClockBounds } from '../time/Clock';

const SPEEDS: Array<{ label: string; value: number }> = [
  { label: '0.5×',   value: 0.5 },
  { label: '1×',     value: 1 },
  { label: '10×',    value: 10 },
  { label: '60×',    value: 60 },
  { label: '600×',   value: 600 },
];

/**
 * Yellow extrapolation bands always sit at fixed fractions of the slider:
 * the safe ±48h window is 4d / 14d total = 2/7 of the slider centered on now.
 * Left yellow runs 0% → ((7d − 2d) / 14d) = 35.71%; right yellow runs 64.29% → 100%.
 */
const SAFE_FRACTION = 2 / 7;
const LEFT_YELLOW_PCT  = (1 - SAFE_FRACTION) * 50;  // 35.71
const RIGHT_YELLOW_PCT = 100 - LEFT_YELLOW_PCT;     // 64.29

/**
 * <sat-timeline .clock=${clock}>
 *
 * Bottom-bar control. Slider spans now ± 7 days (per req_spec §11);
 * regions outside ±48h are shaded yellow (Phase 1 doesn't have
 * historical TLE backfill, so positions in those regions are
 * extrapolated from the current TLE only). Play/pause + speed buttons
 * + "Now" reset + UTC + relative time display.
 *
 * Reads/writes the underlying Cesium.Clock through the Clock facade.
 * Subscribes to clock.onTick so the slider stays in sync during playback.
 */
@customElement('sat-timeline')
export class SatTimeline extends LitElement {
  @property({ attribute: false }) clock: Clock | null = null;

  @state() private currentTimeMs = Date.now();
  @state() private bounds: ClockBounds = { startMs: 0, endMs: 0, nowMs: Date.now() };
  @state() private playing = false;
  @state() private speed = 1;
  @state() private extrapolated = false;

  private unsubscribeTick: (() => void) | null = null;
  private wallNowTimer: number | null = null;

  static styles = css`
    :host {
      display: block;
      background: var(--color-bg-overlay);
      border-top: 1px solid var(--color-border);
      backdrop-filter: blur(8px);
      /* Reserve room for the iOS home indicator / Android nav gestures /
         Chrome's bottom toolbar so the slider isn't clipped. The
         max() keeps a sensible minimum padding when no safe area
         is reported (desktop browsers). */
      padding:
        0.5rem
        max(1rem, env(safe-area-inset-right))
        max(0.6rem, env(safe-area-inset-bottom))
        max(1rem, env(safe-area-inset-left));
      color: var(--color-text);
      font-family: var(--font-body);
      font-size: 0.8rem;
      user-select: none;
    }

    .controls {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      margin-bottom: 0.45rem;
    }
    .button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 32px;
      height: 28px;
      padding: 0 0.5rem;
      background: transparent;
      color: var(--color-text);
      border: 1px solid var(--color-border);
      border-radius: 4px;
      font-family: var(--font-mono);
      font-size: 0.8rem;
      cursor: pointer;
    }
    .button:hover {
      border-color: var(--color-accent);
      color: var(--color-accent);
    }
    .button--active {
      border-color: var(--color-accent);
      color: var(--color-accent);
    }
    .button--play {
      min-width: 44px;
    }

    .time {
      font-family: var(--font-mono);
      color: var(--color-text);
      font-size: 0.85rem;
      flex-shrink: 0;
    }
    .offset {
      color: var(--color-text-muted);
      margin-left: 0.4rem;
      font-size: 0.75rem;
    }
    .spacer {
      flex: 1;
    }
    .speeds {
      display: inline-flex;
      gap: 0.3rem;
    }
    .warning {
      display: inline-flex;
      align-items: center;
      gap: 0.3rem;
      color: var(--color-warning);
      font-family: var(--font-mono);
      font-size: 0.7rem;
      padding: 0.15rem 0.45rem;
      border: 1px solid var(--color-warning);
      border-radius: 3px;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .slider-wrap {
      position: relative;
      padding: 0.2rem 0;
    }
    /* Banded background for the track — matches the slider's full width. */
    .track {
      position: absolute;
      left: 0;
      right: 0;
      top: 50%;
      height: 6px;
      transform: translateY(-50%);
      border-radius: 3px;
      pointer-events: none;
      background: linear-gradient(
        to right,
        rgba(255, 183, 0, 0.35) 0%,
        rgba(255, 183, 0, 0.35) ${LEFT_YELLOW_PCT}%,
        var(--color-bg) ${LEFT_YELLOW_PCT}%,
        var(--color-bg) ${RIGHT_YELLOW_PCT}%,
        rgba(255, 183, 0, 0.35) ${RIGHT_YELLOW_PCT}%,
        rgba(255, 183, 0, 0.35) 100%
      );
      border: 1px solid var(--color-border);
    }
    /* Tick mark for "now" at the dead center. */
    .now-tick {
      position: absolute;
      top: 0;
      bottom: 0;
      left: 50%;
      width: 1px;
      background: var(--color-text-dim);
      pointer-events: none;
    }
    input[type='range'] {
      position: relative;
      width: 100%;
      height: 18px;
      background: transparent;
      -webkit-appearance: none;
      appearance: none;
      margin: 0;
      cursor: pointer;
      z-index: 2;
    }
    input[type='range']::-webkit-slider-runnable-track {
      height: 6px;
      background: transparent;
    }
    input[type='range']::-moz-range-track {
      height: 6px;
      background: transparent;
    }
    input[type='range']::-webkit-slider-thumb {
      -webkit-appearance: none;
      width: 14px;
      height: 14px;
      border-radius: 50%;
      background: var(--color-accent);
      border: 2px solid var(--color-bg);
      margin-top: -4px;
      cursor: grab;
    }
    input[type='range']::-webkit-slider-thumb:active { cursor: grabbing; }
    input[type='range']::-moz-range-thumb {
      width: 14px;
      height: 14px;
      border-radius: 50%;
      background: var(--color-accent);
      border: 2px solid var(--color-bg);
      cursor: grab;
    }

    .legend {
      display: flex;
      justify-content: space-between;
      margin-top: 0.25rem;
      font-family: var(--font-mono);
      font-size: 0.65rem;
      color: var(--color-text-dim);
    }

    @media (max-width: 700px) {
      .speeds .button:nth-child(n+4) { display: none; } /* hide 60×, 600× */
      .controls { flex-wrap: wrap; }
    }
  `;

  connectedCallback(): void {
    super.connectedCallback();
    this.subscribe();
    // Refresh wall-now periodically so the bounds animate forward even if
    // the clock is paused (no clock ticks fire while paused).
    this.wallNowTimer = window.setInterval(() => {
      if (this.clock !== null) {
        this.bounds = this.clock.getBounds();
      }
    }, 5_000);
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    this.unsubscribe();
    if (this.wallNowTimer !== null) {
      window.clearInterval(this.wallNowTimer);
      this.wallNowTimer = null;
    }
  }

  willUpdate(changed: Map<string, unknown>): void {
    if (changed.has('clock')) {
      this.unsubscribe();
      this.subscribe();
    }
  }

  private subscribe(): void {
    if (this.clock === null) return;
    this.bounds = this.clock.getBounds();
    this.currentTimeMs = this.clock.getTimeMs();
    this.playing = this.clock.isPlaying();
    this.speed = this.clock.getSpeed();
    this.extrapolated = this.clock.isExtrapolated();
    this.unsubscribeTick = this.clock.onTick((ms) => {
      this.currentTimeMs = ms;
      this.extrapolated = this.clock!.isExtrapolated();
    });
  }

  private unsubscribe(): void {
    if (this.unsubscribeTick !== null) {
      this.unsubscribeTick();
      this.unsubscribeTick = null;
    }
  }

  private handleScrub = (e: Event): void => {
    if (this.clock === null) return;
    const ms = parseInt((e.target as HTMLInputElement).value, 10);
    this.clock.setTimeMs(ms);
    this.currentTimeMs = ms;
    this.extrapolated = this.clock.isExtrapolated();
  };

  private togglePlay = (): void => {
    if (this.clock === null) return;
    this.clock.togglePlay();
    this.playing = this.clock.isPlaying();
  };

  private resetToNow = (): void => {
    if (this.clock === null) return;
    this.clock.resetToNow();
    this.currentTimeMs = this.clock.getTimeMs();
    this.extrapolated = false;
  };

  private setSpeed = (multiplier: number): void => {
    if (this.clock === null) return;
    this.clock.setSpeed(multiplier);
    this.speed = multiplier;
  };

  render() {
    if (this.clock === null) return nothing;
    return html`
      <div class="controls">
        <button class="button button--play" @click=${this.togglePlay} title=${this.playing ? 'Pause' : 'Play'}>
          ${this.playing ? '⏸' : '▶'}
        </button>
        <button class="button" @click=${this.resetToNow} title="Reset to wall-clock now">Now</button>
        <span class="time">
          ${formatUtc(this.currentTimeMs)}
          <span class="offset">${formatOffset(this.currentTimeMs, this.bounds.nowMs)}</span>
        </span>
        ${this.extrapolated
          ? html`<span class="warning" title="SGP4 accuracy degrades beyond ±2 days of TLE epoch">⚠ Extrapolated</span>`
          : null}
        <span class="spacer"></span>
        <span class="speeds">
          ${SPEEDS.map(
            (s) => html`
              <button
                class="button ${this.speed === s.value ? 'button--active' : ''}"
                @click=${() => this.setSpeed(s.value)}
              >${s.label}</button>
            `,
          )}
        </span>
      </div>
      <div class="slider-wrap">
        <div class="track"></div>
        <div class="now-tick"></div>
        <input
          type="range"
          min=${this.bounds.startMs}
          max=${this.bounds.endMs}
          step="60000"
          .value=${String(this.currentTimeMs)}
          @input=${this.handleScrub}
          aria-label="Scrub time"
        />
      </div>
      <div class="legend">
        <span>now − 7d</span>
        <span>now</span>
        <span>now + 7d</span>
      </div>
    `;
  }
}

function formatUtc(ms: number): string {
  const d = new Date(ms);
  const yyyy = d.getUTCFullYear();
  const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
  const dd = String(d.getUTCDate()).padStart(2, '0');
  const hh = String(d.getUTCHours()).padStart(2, '0');
  const mn = String(d.getUTCMinutes()).padStart(2, '0');
  const ss = String(d.getUTCSeconds()).padStart(2, '0');
  return `${yyyy}-${mm}-${dd} ${hh}:${mn}:${ss}Z`;
}

function formatOffset(ms: number, nowMs: number): string {
  const delta = ms - nowMs;
  const abs = Math.abs(delta);
  const sign = delta < 0 ? '−' : '+';
  if (abs < 30_000) return 'now';
  if (abs < 3_600_000) return `${sign}${Math.round(abs / 60_000)}m`;
  if (abs < 86_400_000) return `${sign}${(abs / 3_600_000).toFixed(1)}h`;
  return `${sign}${(abs / 86_400_000).toFixed(1)}d`;
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-timeline': SatTimeline;
  }
}
