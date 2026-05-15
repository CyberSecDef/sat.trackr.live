import { LitElement, html, css, nothing } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import * as Cesium from 'cesium';
import { ApiError, getSatelliteDetail, getSatellitePasses } from '../api/client';
import type { PassRecord, SatelliteDetail } from '../api/types';
import { getSharedObserver, type Observer } from '../observer/Observer';
import './FreshnessBadge';

/**
 * <sat-detail-panel
 *   .norad=${number | null}
 *   .getCurrentPosition=${(norad: number) => Cesium.Cartesian3 | null}>
 *
 * Right-rail panel showing the §10 sections we can populate in Phase 1:
 * Identity, Current state (live), Orbital elements, Raw data. Empty
 * Phase-2 fields (operator, country, mass, etc.) are surfaced as
 * "—" placeholders so the structure is visible.
 *
 * Closes via × button, Escape key, or .norad set to null.
 */
@customElement('sat-detail-panel')
export class SatDetailPanel extends LitElement {
  @property({ type: Number }) norad: number | null = null;

  /** Provided by <sat-app> — looks up live position from PointPrimitiveLayer. */
  @property({ attribute: false })
  getCurrentPosition: ((norad: number) => Cesium.Cartesian3 | null) | null = null;

  @state() private detail: SatelliteDetail | null = null;
  @state() private loading = false;
  @state() private error: string | null = null;
  @state() private liveLat: number | null = null;
  @state() private liveLon: number | null = null;
  @state() private liveAlt: number | null = null;
  @state() private observer: Observer | null = null;
  @state() private passes: PassRecord[] = [];
  @state() private passesLoading = false;
  @state() private passesError: string | null = null;
  @state() private passesFromCache = false;

  private liveTimer: number | null = null;
  private lastFetchedNorad: number | null = null;
  private observerUnsub: (() => void) | null = null;
  private lastPassKey = '';
  private passesToken = 0;

  static styles = css`
    :host {
      position: absolute;
      top: 0;
      right: 0;
      bottom: 0;
      width: 380px;
      max-width: 100vw;
      background: var(--color-bg-overlay);
      border-left: 1px solid var(--color-border);
      backdrop-filter: blur(12px);
      color: var(--color-text);
      font-family: var(--font-body);
      font-size: 0.85rem;
      z-index: 20;
      transform: translateX(100%);
      transition: transform 0.25s ease;
      overflow-y: auto;
      pointer-events: auto;
    }
    :host([open]) {
      transform: translateX(0);
    }

    .header {
      position: sticky;
      top: 0;
      padding: 0.75rem 1rem;
      background: var(--color-bg-overlay);
      border-bottom: 1px solid var(--color-border);
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 0.5rem;
    }
    .header__name {
      font-family: var(--font-mono);
      font-size: 1rem;
      font-weight: 500;
      color: var(--color-text);
      line-height: 1.2;
    }
    .header__id {
      font-family: var(--font-mono);
      font-size: 0.75rem;
      color: var(--color-text-muted);
      margin-top: 0.2rem;
    }
    .close {
      background: transparent;
      border: 1px solid var(--color-border);
      color: var(--color-text-muted);
      width: 28px;
      height: 28px;
      border-radius: 4px;
      font-size: 1rem;
      line-height: 1;
      cursor: pointer;
      flex-shrink: 0;
    }
    .close:hover {
      color: var(--color-accent);
      border-color: var(--color-accent);
    }

    section {
      padding: 0.75rem 1rem;
      border-bottom: 1px solid var(--color-border);
    }
    section h2 {
      margin: 0 0 0.5rem;
      font-family: var(--font-mono);
      font-size: 0.8rem;
      font-weight: 500;
      color: var(--color-text-muted);
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.4rem 0.8rem;
    }
    .field {
      display: flex;
      flex-direction: column;
      gap: 0.1rem;
    }
    .field__label {
      font-size: 0.7rem;
      color: var(--color-text-dim);
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }
    .field__value {
      font-family: var(--font-mono);
      color: var(--color-text);
      font-size: 0.85rem;
    }
    .field__value--muted {
      color: var(--color-text-muted);
      font-style: italic;
    }

    .badges {
      display: flex;
      gap: 0.4rem;
      flex-wrap: wrap;
      margin-bottom: 0.6rem;
    }
    .badge {
      display: inline-flex;
      padding: 0.1rem 0.45rem;
      font-family: var(--font-mono);
      font-size: 0.7rem;
      font-weight: 500;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      border-radius: 3px;
      border: 1px solid currentColor;
    }
    .badge--type    { color: var(--color-accent); }
    .badge--status  { color: var(--color-text-muted); }
    .badge--orbit   { color: var(--color-text-muted); }

    .tle {
      background: var(--color-bg);
      border: 1px solid var(--color-border);
      border-radius: 4px;
      padding: 0.5rem;
      margin-top: 0.4rem;
      font-family: var(--font-mono);
      font-size: 0.7rem;
      color: var(--color-text);
      overflow-x: auto;
      white-space: pre;
      cursor: pointer;
    }
    .tle:hover { border-color: var(--color-accent); }
    .tle__hint {
      color: var(--color-text-dim);
      font-size: 0.7rem;
      margin-top: 0.3rem;
      font-style: italic;
    }

    .links {
      display: flex;
      gap: 0.4rem;
      flex-wrap: wrap;
      margin-top: 0.4rem;
    }
    .links a {
      color: var(--color-accent);
      font-family: var(--font-mono);
      font-size: 0.75rem;
      padding: 0.15rem 0.4rem;
      border: 1px solid var(--color-border);
      border-radius: 3px;
      text-decoration: none;
    }
    .links a:hover {
      border-color: var(--color-accent);
    }

    .epoch-row {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .loading,
    .error {
      padding: 2rem 1rem;
      text-align: center;
      color: var(--color-text-muted);
    }
    .error { color: var(--color-danger); }

    @media (max-width: 700px) {
      :host {
        top: auto;
        right: 0;
        bottom: 0;
        left: 0;
        width: 100%;
        /* Cap at 70% of the .globe-area's actual height (NOT 70vh — on
           small viewports 70vh exceeded .globe-area, pushing the panel's
           sticky header above .globe-area's overflow:hidden boundary
           and clipping the × button behind the top bar). */
        max-height: 70%;
        border-left: none;
        border-top: 1px solid var(--color-border);
        transform: translateY(100%);
      }
      :host([open]) {
        transform: translateY(0);
      }
    }
  `;

  connectedCallback(): void {
    super.connectedCallback();
    document.addEventListener('keydown', this.handleKeydown);
    this.observerUnsub = getSharedObserver().subscribe((o) => {
      this.observer = o;
      void this.maybeLoadPasses();
    });
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    document.removeEventListener('keydown', this.handleKeydown);
    this.stopLiveTimer();
    this.observerUnsub?.();
    this.observerUnsub = null;
  }

  willUpdate(changed: Map<string, unknown>): void {
    if (changed.has('norad')) {
      this.toggleAttribute('open', this.norad !== null);
      if (this.norad !== null && this.norad !== this.lastFetchedNorad) {
        void this.loadDetail(this.norad);
        void this.maybeLoadPasses();
      } else if (this.norad === null) {
        this.detail = null;
        this.lastFetchedNorad = null;
        this.stopLiveTimer();
        this.passes = [];
        this.passesError = null;
        this.lastPassKey = '';
      }
    }
  }

  private async maybeLoadPasses(): Promise<void> {
    if (this.norad === null || this.observer === null) {
      this.passes = [];
      this.passesError = null;
      this.lastPassKey = '';
      return;
    }
    const key = `${this.norad}:${this.observer.latitude.toFixed(3)}:${this.observer.longitude.toFixed(3)}`;
    if (key === this.lastPassKey && this.passes.length > 0) {
      return;
    }
    this.lastPassKey = key;
    const myToken = ++this.passesToken;
    this.passesLoading = true;
    this.passesError = null;
    try {
      const response = await getSatellitePasses(this.norad, {
        lat: this.observer.latitude,
        lon: this.observer.longitude,
        alt: this.observer.altitudeMeters,
        days: 7,
      });
      if (myToken !== this.passesToken) return;
      this.passes = response.data.slice(0, 5);
      this.passesFromCache = response.meta.from_cache;
    } catch (err) {
      if (myToken !== this.passesToken) return;
      this.passesError = err instanceof ApiError
        ? `Pass API error ${err.status}`
        : (err instanceof Error ? err.message : String(err));
      this.passes = [];
    } finally {
      if (myToken === this.passesToken) {
        this.passesLoading = false;
      }
    }
  }

  private handleKeydown = (e: KeyboardEvent): void => {
    if (e.key === 'Escape' && this.norad !== null) {
      this.dispatchClose();
    }
  };

  private async loadDetail(norad: number): Promise<void> {
    this.loading = true;
    this.error = null;
    this.detail = null;
    this.lastFetchedNorad = norad;

    try {
      const response = await getSatelliteDetail(norad);
      // Race guard — user may have selected another satellite while fetching
      if (this.norad !== norad) return;
      this.detail = response.data;
      this.startLiveTimer();
    } catch (err) {
      if (this.norad !== norad) return;
      this.error = err instanceof ApiError
        ? `API error ${err.status}: ${err.body || 'unknown'}`
        : (err instanceof Error ? err.message : String(err));
    } finally {
      if (this.norad === norad) {
        this.loading = false;
      }
    }
  }

  private startLiveTimer(): void {
    this.stopLiveTimer();
    this.tickLive(); // immediately
    this.liveTimer = window.setInterval(() => this.tickLive(), 500);
  }

  private stopLiveTimer(): void {
    if (this.liveTimer !== null) {
      window.clearInterval(this.liveTimer);
      this.liveTimer = null;
    }
    this.liveLat = this.liveLon = this.liveAlt = null;
  }

  private tickLive(): void {
    if (this.norad === null || this.getCurrentPosition === null) return;
    const pos = this.getCurrentPosition(this.norad);
    if (pos === null) {
      this.liveLat = this.liveLon = this.liveAlt = null;
      return;
    }
    const carto = Cesium.Cartographic.fromCartesian(pos);
    this.liveLat = Cesium.Math.toDegrees(carto.latitude);
    this.liveLon = Cesium.Math.toDegrees(carto.longitude);
    this.liveAlt = carto.height / 1000; // m → km
  }

  private dispatchClose(): void {
    this.dispatchEvent(
      new CustomEvent('panel-close', { bubbles: true, composed: true }),
    );
  }

  private async copyTle(): Promise<void> {
    if (this.detail?.tle_current === null || this.detail?.tle_current === undefined) return;
    const text = `${this.detail.name}\n${this.detail.tle_current.line1}\n${this.detail.tle_current.line2}`;
    try {
      await navigator.clipboard.writeText(text);
    } catch {
      // ignore — older browsers / non-https origins may block clipboard
    }
  }

  render() {
    if (this.norad === null) return nothing;
    if (this.loading) {
      return html`
        ${this.renderHeader('Loading…', null)}
        <div class="loading">Loading NORAD ${this.norad}…</div>
      `;
    }
    if (this.error !== null) {
      return html`
        ${this.renderHeader('Error', null)}
        <div class="error">${this.error}</div>
      `;
    }
    if (this.detail === null) return nothing;

    const d = this.detail;
    return html`
      ${this.renderHeader(d.name, d)}
      ${this.renderIdentity(d)}
      ${this.renderCurrentState(d)}
      ${this.renderVisibility(d)}
      ${d.tle_current !== null ? this.renderOrbital(d) : null}
      ${d.tle_current !== null ? this.renderRaw(d) : null}
    `;
  }

  private renderVisibility(d: SatelliteDetail) {
    if (d.tle_current === null) return null;
    if (this.observer === null) {
      return html`
        <section>
          <h2>§ Visibility from observer</h2>
          <p class="small" style="color: var(--color-text-dim); margin: 0;">
            Set your location with the 📍 pill in the top bar to see the next 5 passes.
          </p>
        </section>
      `;
    }
    if (this.passesLoading && this.passes.length === 0) {
      return html`<section><h2>§ Visibility from observer</h2><p class="small" style="margin:0;">Computing passes…</p></section>`;
    }
    if (this.passesError !== null) {
      return html`<section><h2>§ Visibility from observer</h2><p class="small" style="margin:0; color: var(--color-warning);">${this.passesError}</p></section>`;
    }
    if (this.passes.length === 0) {
      return html`
        <section>
          <h2>§ Visibility from observer</h2>
          <p class="small" style="margin: 0; color: var(--color-text-dim);">
            No passes ≥ 10° in the next 7 days from your location.
          </p>
        </section>
      `;
    }
    const obsLabel = this.observer.label?.split(',')[0]
      ?? `${this.observer.latitude.toFixed(2)}°, ${this.observer.longitude.toFixed(2)}°`;
    return html`
      <section>
        <h2>§ Visibility from observer
          <span style="color: var(--color-text-dim); text-transform: none; letter-spacing: 0; font-weight: normal;">— ${obsLabel}</span>
        </h2>
        <table class="passes" style="width: 100%; border-collapse: collapse; font-family: var(--font-mono); font-size: 0.8rem;">
          <thead>
            <tr style="text-align: left; color: var(--color-text-dim);">
              <th style="padding: 0.2rem 0.4rem 0.4rem 0;">Rise</th>
              <th style="padding: 0.2rem 0.4rem 0.4rem 0;">Peak</th>
              <th style="padding: 0.2rem 0.4rem 0.4rem 0;">Set</th>
              <th style="padding: 0.2rem 0 0.4rem 0; text-align: right;">Max el</th>
            </tr>
          </thead>
          <tbody>
            ${this.passes.map((p) => {
              const rise = new Date(p.rise_at);
              const set = new Date(p.set_at);
              const dateStr = `${rise.toISOString().slice(5, 10)}`;
              const fmt = (d: Date) => d.toISOString().slice(11, 16);
              return html`
                <tr>
                  <td style="padding: 0.2rem 0.4rem 0.2rem 0;">${dateStr} ${fmt(rise)}</td>
                  <td style="padding: 0.2rem 0.4rem 0.2rem 0;">${fmt(new Date(p.peak_at))}</td>
                  <td style="padding: 0.2rem 0.4rem 0.2rem 0;">${fmt(set)}</td>
                  <td style="padding: 0.2rem 0 0.2rem 0; text-align: right;">${p.max_elevation_deg.toFixed(0)}°</td>
                </tr>
              `;
            })}
          </tbody>
        </table>
        <p class="small" style="margin: 0.4rem 0 0; color: var(--color-text-dim);">
          UTC. Passes ≥ 10°. ${this.passesFromCache ? 'Cached.' : 'Fresh.'}
        </p>
      </section>
    `;
  }

  private renderHeader(name: string, d: SatelliteDetail | null) {
    return html`
      <div class="header">
        <div>
          <div class="header__name">${name}</div>
          <div class="header__id">
            NORAD ${this.norad}${d?.intl_designator ? ` · ${d.intl_designator}` : ''}
          </div>
        </div>
        <button class="close" @click=${this.dispatchClose} title="Close (Esc)">×</button>
      </div>
    `;
  }

  private renderIdentity(d: SatelliteDetail) {
    return html`
      <section>
        <h2>§ Identity</h2>
        <div class="badges">
          <span class="badge badge--type">${d.object_type}</span>
          <span class="badge badge--status">${d.status}</span>
          <span class="badge badge--orbit">${d.orbit_class}</span>
        </div>
        <div class="grid">
          ${this.field('Operator', d.operator)}
          ${this.field('Country', d.country)}
          ${this.field('Launched',
            d.launch_date !== null && d.launch_site_code !== null
              ? `${d.launch_date} · ${d.launch_site_code}`
              : (d.launch_date ?? d.launch_site_code))}
          ${this.field('Launch vehicle', d.launch_vehicle)}
          ${this.field('Mass (kg)', d.mass_kg !== null ? d.mass_kg.toLocaleString() : null)}
          ${this.field('RCS (m²)', d.rcs_meters !== null ? d.rcs_meters.toFixed(2) : null)}
        </div>
        ${d.purposes.length > 0
          ? html`<p class="small" style="margin: 0.4rem 0 0; color: var(--color-text-muted);">
              Purposes: ${d.purposes.join(', ')}
            </p>`
          : null}
        ${d.decayed_at !== null
          ? html`<p class="small" style="margin: 0.4rem 0 0; color: var(--color-warning);">
              ⚠ Reentered: ${d.decayed_at}
            </p>`
          : null}
        ${this.renderLinks(d)}
      </section>
    `;
  }

  private renderCurrentState(_d: SatelliteDetail) {
    const lat = this.liveLat !== null ? `${this.liveLat.toFixed(3)}°` : '—';
    const lon = this.liveLon !== null ? `${this.liveLon.toFixed(3)}°` : '—';
    const alt = this.liveAlt !== null ? `${this.liveAlt.toFixed(1)} km` : '—';
    return html`
      <section>
        <h2>§ Current state <span style="color: var(--color-text-dim); text-transform: none; letter-spacing: 0; font-weight: normal;">— live</span></h2>
        <div class="grid">
          ${this.field('Latitude', lat)}
          ${this.field('Longitude', lon)}
          ${this.field('Altitude', alt)}
          ${this.field('Updated', '~2× / sec')}
        </div>
      </section>
    `;
  }

  private renderOrbital(d: SatelliteDetail) {
    const t = d.tle_current!;
    return html`
      <section>
        <h2>§ Orbital elements</h2>
        <div class="epoch-row" style="margin-bottom: 0.5rem;">
          <span style="font-family: var(--font-mono); font-size: 0.8rem;">${t.epoch}</span>
          <sat-freshness-badge epoch=${t.epoch}></sat-freshness-badge>
        </div>
        <div class="grid">
          ${this.field('Period', `${t.period_min.toFixed(2)} min`)}
          ${this.field('Inclination', `${t.inclination_deg.toFixed(4)}°`)}
          ${this.field('Eccentricity', t.eccentricity.toFixed(7))}
          ${this.field('Mean motion', `${t.mean_motion.toFixed(8)} rev/d`)}
          ${this.field('Perigee alt', `${t.perigee_km.toFixed(1)} km`)}
          ${this.field('Apogee alt', `${t.apogee_km.toFixed(1)} km`)}
          ${this.field('Semi-major', `${t.semimajor_km.toFixed(1)} km`)}
          ${this.field('B*', t.bstar.toExponential(4))}
          ${this.field('RAAN', `${t.raan_deg.toFixed(4)}°`)}
          ${this.field('Arg perigee', `${t.arg_perigee_deg.toFixed(4)}°`)}
          ${this.field('Mean anomaly', `${t.mean_anomaly_deg.toFixed(4)}°`)}
          ${this.field('Rev number', t.rev_number.toLocaleString())}
        </div>
      </section>
    `;
  }

  private renderRaw(d: SatelliteDetail) {
    const t = d.tle_current!;
    return html`
      <section>
        <h2>§ Raw data</h2>
        <pre class="tle" @click=${this.copyTle} title="Click to copy">${d.name}
${t.line1}
${t.line2}</pre>
        <div class="tle__hint">Click to copy the 3-line TLE set.</div>
        <div class="links">
          <a href=${`/api/v1/satellites/${this.norad}`} target="_blank" rel="noopener">JSON detail</a>
          <a href=${`/api/v1/satellites/${this.norad}/tle`} target="_blank" rel="noopener">JSON TLE</a>
        </div>
      </section>
    `;
  }

  private renderLinks(d: SatelliteDetail) {
    const links: Array<{ label: string; href: string }> = [];
    links.push({ label: 'N2YO', href: `https://www.n2yo.com/satellite/?s=${this.norad}` });
    links.push({ label: 'Heavens-Above', href: `https://heavens-above.com/orbit.aspx?satid=${this.norad}` });
    if (d.intl_designator) {
      links.push({ label: 'Gunter', href: `https://space.skyrocket.de/find_id.html?searchtxt=${encodeURIComponent(d.intl_designator)}` });
    }
    if (d.wikipedia_slug) {
      links.push({ label: 'Wikipedia', href: `https://en.wikipedia.org/wiki/${encodeURIComponent(d.wikipedia_slug)}` });
    }
    return html`
      <div class="links">
        ${links.map((l) => html`<a href=${l.href} target="_blank" rel="noopener">${l.label}</a>`)}
      </div>
    `;
  }

  private field(label: string, value: string | null) {
    if (value === null || value === '') {
      return html`
        <div class="field">
          <span class="field__label">${label}</span>
          <span class="field__value field__value--muted">—</span>
        </div>
      `;
    }
    return html`
      <div class="field">
        <span class="field__label">${label}</span>
        <span class="field__value">${value}</span>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-detail-panel': SatDetailPanel;
  }
}
