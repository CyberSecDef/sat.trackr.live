import { LitElement, html, css, nothing, svg } from 'lit';
import { customElement, state } from 'lit/decorators.js';
import { getSpaceWeather24h, getSpaceWeatherNow } from '../api/client';
import type { SpaceWeatherSample } from '../api/types';

/**
 * <sat-space-weather-pill>
 *
 * Topbar pill showing current Kp + storm-level glyph.  Click opens a
 * popover with the current values + a 24h SVG Kp trend (no Chart.js
 * dependency — Phase 4 chunk 3 decision).
 *
 * Polls /api/v1/space-weather/now every 5 minutes; lazy-fetches the
 * 24h trend only when the popover opens.  Both endpoints are cached
 * server-side at 5min so this is friendly to the backend.
 */
@customElement('sat-space-weather-pill')
export class SatSpaceWeatherPill extends LitElement {
  @state() private current: SpaceWeatherSample | null = null;
  @state() private currentError = '';
  @state() private trend: SpaceWeatherSample[] = [];
  @state() private trendLoading = false;
  @state() private trendError = '';
  @state() private open = false;

  private pollTimer: number | null = null;

  static styles = css`
    :host {
      position: relative;
      display: inline-block;
      font-family: var(--font-mono);
    }
    .pill {
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
    .pill:hover { border-color: var(--color-accent); color: var(--color-accent); }
    .pill__glyph { font-size: 0.95rem; }

    .pill[data-storm="0"]   { color: var(--color-text); }
    .pill[data-storm="1"]   { color: #ffd28a; }
    .pill[data-storm="2"]   { color: #ffaa55; }
    .pill[data-storm="3"]   { color: #ff6644; }
    .pill[data-storm="4"],
    .pill[data-storm="5"]   { color: #d44; border-color: currentColor; }

    .panel {
      position: absolute;
      top: calc(100% + 0.4rem);
      right: 0;
      min-width: 320px;
      background: var(--color-bg-elevated);
      border: 1px solid var(--color-border);
      border-radius: 4px;
      padding: 0.6rem;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
      z-index: 100;
      font-size: 0.85rem;
    }
    .panel h3 {
      margin: 0 0 0.4rem;
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--color-text-dim);
    }
    .grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.4rem 0.8rem;
      font-family: var(--font-mono);
    }
    .grid .label { color: var(--color-text-dim); font-size: 0.75rem; }
    .grid .value { color: var(--color-text); }
    .grid .value strong { font-weight: 500; }
    .meta {
      margin-top: 0.5rem;
      padding-top: 0.4rem;
      border-top: 1px dashed var(--color-border);
      font-size: 0.7rem;
      color: var(--color-text-dim);
    }
    .meta a { color: var(--color-text-dim); }
    .meta a:hover { color: var(--color-accent); }
    .chart {
      width: 100%;
      height: 80px;
      margin: 0.6rem 0 0.2rem;
      background: rgba(255,255,255,0.02);
      border-radius: 3px;
    }
    .chart text {
      fill: var(--color-text-dim);
      font-family: var(--font-mono);
      font-size: 9px;
    }
    .chart polyline { fill: none; stroke: var(--color-accent); stroke-width: 1.5; }
    .chart line.grid { stroke: rgba(255,255,255,0.06); stroke-width: 1; }
    .error { color: #d66161; font-size: 0.75rem; margin-top: 0.3rem; }
  `;

  connectedCallback(): void {
    super.connectedCallback();
    void this.refreshNow();
    this.pollTimer = window.setInterval(() => void this.refreshNow(), 5 * 60_000);
    document.addEventListener('click', this.handleOutsideClick);
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    if (this.pollTimer !== null) window.clearInterval(this.pollTimer);
    this.pollTimer = null;
    document.removeEventListener('click', this.handleOutsideClick);
  }

  private handleOutsideClick = (e: MouseEvent): void => {
    if (!e.composedPath().includes(this)) this.open = false;
  };

  private async refreshNow(): Promise<void> {
    try {
      const r = await getSpaceWeatherNow();
      this.current = r.data;
      this.currentError = '';
    } catch (err) {
      // 404 = no samples ingested yet; treat as "unknown" not a UI error.
      this.current = null;
      this.currentError = err instanceof Error ? err.message : String(err);
    }
  }

  private async loadTrend(): Promise<void> {
    this.trendLoading = true;
    this.trendError = '';
    try {
      const r = await getSpaceWeather24h();
      this.trend = r.data;
    } catch (err) {
      this.trendError = err instanceof Error ? err.message : String(err);
      this.trend = [];
    } finally {
      this.trendLoading = false;
    }
  }

  private toggle(): void {
    this.open = !this.open;
    if (this.open && this.trend.length === 0) void this.loadTrend();
  }

  /** Storm level for color-coding the pill: max of G/S/R (geomag dominates the visual ranking). */
  private stormLevel(): number {
    if (this.current === null) return 0;
    return Math.max(this.current.g_level ?? 0, this.current.s_level ?? 0, this.current.r_level ?? 0);
  }

  private stormGlyph(level: number): string {
    if (level >= 4) return '⛈';
    if (level >= 2) return '⚠';
    if (level >= 1) return '◐';
    return '☼';
  }

  render() {
    const storm = this.stormLevel();
    const kp = this.current?.kp;
    const kpStr = kp !== null && kp !== undefined ? kp.toFixed(1) : '—';
    return html`
      <button class="pill" data-storm=${storm} @click=${this.toggle} aria-haspopup="dialog" aria-expanded=${this.open ? 'true' : 'false'}>
        <span class="pill__glyph" aria-hidden="true">${this.stormGlyph(storm)}</span>
        <span>Kp ${kpStr}</span>
      </button>
      ${this.open ? this.renderPanel() : nothing}
    `;
  }

  private renderPanel() {
    return html`
      <div class="panel" role="dialog" aria-label="Space weather">
        <h3>Space weather — now</h3>
        ${this.current === null
          ? html`<p style="margin:0;color:var(--color-text-dim);">${this.currentError || 'No samples ingested yet — run make ingest-swpc.'}</p>`
          : this.renderCurrent(this.current)}
        <h3 style="margin-top:0.7rem;">Last 24 hours — Kp trend</h3>
        ${this.trendLoading ? html`<p class="meta">Loading…</p>` : this.renderChart()}
        <div class="meta">
          Source: <a href="https://www.swpc.noaa.gov/" target="_blank" rel="noopener">NOAA SWPC</a>
          · Cron 5-min · <a href="/text/space-weather">/text/space-weather</a>
        </div>
      </div>
    `;
  }

  private renderCurrent(s: SpaceWeatherSample) {
    return html`
      <div class="grid">
        <span class="label">Planetary K</span><span class="value"><strong>${s.kp?.toFixed(2) ?? '—'}</strong></span>
        <span class="label">X-ray class</span><span class="value"><strong>${s.x_ray_class ?? '—'}</strong>${s.x_ray_flux !== null ? html` <span style="color:var(--color-text-dim);font-size:0.7rem;">${s.x_ray_flux.toExponential(2)} W/m²</span>` : nothing}</span>
        <span class="label">R (radio)</span><span class="value">${s.r_level ?? '—'}</span>
        <span class="label">S (radiation)</span><span class="value">${s.s_level ?? '—'}</span>
        <span class="label">G (geomag)</span><span class="value">${s.g_level ?? '—'}</span>
        <span class="label">Sampled</span><span class="value" style="font-size:0.7rem;">${s.sampled_at.slice(11, 16)} UTC</span>
      </div>
    `;
  }

  private renderChart() {
    if (this.trendError) {
      return html`<p class="error">${this.trendError}</p>`;
    }
    if (this.trend.length < 2) {
      return html`<p class="meta">Need at least 2 samples for a trend — the cron schedule will fill this in over time.</p>`;
    }
    const W = 300;
    const H = 80;
    const padL = 22;
    const padR = 8;
    const padT = 8;
    const padB = 16;
    const innerW = W - padL - padR;
    const innerH = H - padT - padB;
    const samples = this.trend.filter((s) => s.kp !== null) as Array<SpaceWeatherSample & { kp: number }>;
    if (samples.length < 2) {
      return html`<p class="meta">Need at least 2 Kp samples for a trend.</p>`;
    }
    const t0 = new Date(samples[0].sampled_at).getTime();
    const tN = new Date(samples[samples.length - 1].sampled_at).getTime();
    const span = Math.max(1, tN - t0);
    const xFor = (s: { sampled_at: string }) =>
      padL + ((new Date(s.sampled_at).getTime() - t0) / span) * innerW;
    const yFor = (kp: number) => padT + ((9 - Math.min(9, Math.max(0, kp))) / 9) * innerH;

    const points = samples.map((s) => `${xFor(s).toFixed(1)},${yFor(s.kp).toFixed(1)}`).join(' ');
    // Gridlines at Kp = 0, 3, 6, 9 with labels.
    const gridLines = [0, 3, 6, 9].map((kp) => {
      const y = yFor(kp);
      return svg`
        <line class="grid" x1="${padL}" y1="${y}" x2="${W - padR}" y2="${y}"></line>
        <text x="2" y="${(y + 3).toFixed(1)}">${kp}</text>
      `;
    });
    return html`
      <svg class="chart" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none">
        ${gridLines}
        <polyline points=${points}></polyline>
        <text x="${padL}" y="${H - 2}">${samples[0].sampled_at.slice(11, 16)}</text>
        <text x="${W - padR}" y="${H - 2}" text-anchor="end">${samples[samples.length - 1].sampled_at.slice(11, 16)}</text>
      </svg>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-space-weather-pill': SatSpaceWeatherPill;
  }
}
