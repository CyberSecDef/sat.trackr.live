import { LitElement, html, css, nothing } from 'lit';
import { customElement, state } from 'lit/decorators.js';
import { getSharedObserver, type CitySearchResult, type Observer } from '../observer/Observer';

type Mode = 'menu' | 'manual' | 'city';

/**
 * <sat-observer-pill>
 *
 * Top-bar pill that owns the user's observer location.  Inert (just
 * shows "📍 set location") until the user clicks and chooses one of:
 *   - Use my location  → navigator.geolocation
 *   - Search for a city → Nominatim, debounced
 *   - Manual lat/lon   → typed
 *
 * Subscribes to the shared ObserverService so other components can
 * react to changes (chunk 6's pass predictions will tap into this).
 */
@customElement('sat-observer-pill')
export class SatObserverPill extends LitElement {
  @state() private observer: Observer | null = null;
  @state() private open = false;
  @state() private mode: Mode = 'menu';
  @state() private busy = false;
  @state() private error = '';
  @state() private cityQuery = '';
  @state() private cityResults: CitySearchResult[] = [];
  @state() private manualLat = '';
  @state() private manualLon = '';
  @state() private manualAlt = '0';

  private unsubscribe: (() => void) | null = null;
  private cityDebounce: number | null = null;
  private cityToken = 0;

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
      max-width: 220px;
      overflow: hidden;
      white-space: nowrap;
      text-overflow: ellipsis;
    }
    .pill:hover { border-color: var(--color-accent); color: var(--color-accent); }
    .pill[data-set="true"] { color: var(--color-accent); border-color: currentColor; }
    .pill__glyph { font-size: 0.95rem; }

    .panel {
      position: absolute;
      top: calc(100% + 0.4rem);
      right: 0;
      min-width: 280px;
      background: var(--color-bg-elevated);
      border: 1px solid var(--color-border);
      border-radius: 4px;
      padding: 0.5rem;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
      z-index: 100;
      font-size: 0.85rem;
    }
    .panel h3 {
      margin: 0 0 0.5rem;
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--color-text-dim);
    }
    .panel button.option {
      display: block;
      width: 100%;
      text-align: left;
      padding: 0.45rem 0.5rem;
      margin-bottom: 0.25rem;
      background: transparent;
      color: var(--color-text);
      border: 1px solid transparent;
      border-radius: 3px;
      font-family: var(--font-mono);
      font-size: 0.85rem;
      cursor: pointer;
    }
    .panel button.option:hover { border-color: var(--color-accent); color: var(--color-accent); }
    .panel .back {
      display: inline-block;
      margin-bottom: 0.4rem;
      color: var(--color-text-dim);
      cursor: pointer;
      font-size: 0.75rem;
    }
    .panel .back:hover { color: var(--color-accent); }
    .panel .row { display: flex; gap: 0.4rem; margin-bottom: 0.4rem; }
    .panel input {
      flex: 1;
      padding: 0.35rem 0.5rem;
      background: var(--color-bg);
      color: var(--color-text);
      border: 1px solid var(--color-border);
      border-radius: 3px;
      font-family: var(--font-mono);
      font-size: 0.85rem;
    }
    .panel input:focus { outline: none; border-color: var(--color-accent); }
    .panel button.primary {
      padding: 0.4rem 0.7rem;
      background: var(--color-accent);
      color: var(--color-bg);
      border: none;
      border-radius: 3px;
      font-family: var(--font-mono);
      cursor: pointer;
    }
    .panel button.primary:disabled { opacity: 0.5; cursor: wait; }
    .results { margin: 0.4rem 0 0; padding: 0; list-style: none; max-height: 200px; overflow-y: auto; }
    .results li {
      padding: 0.35rem 0.4rem;
      border-radius: 3px;
      cursor: pointer;
      color: var(--color-text);
    }
    .results li:hover { background: var(--color-bg); color: var(--color-accent); }
    .meta {
      margin-top: 0.5rem;
      padding-top: 0.4rem;
      border-top: 1px dashed var(--color-border);
      font-size: 0.75rem;
      color: var(--color-text-dim);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .meta a { color: var(--color-text-dim); cursor: pointer; }
    .meta a:hover { color: var(--color-accent); }
    .error { color: #d66161; font-size: 0.75rem; margin-top: 0.3rem; }
    .hint { color: var(--color-text-dim); font-size: 0.75rem; margin: 0.2rem 0 0.4rem; }
  `;

  connectedCallback(): void {
    super.connectedCallback();
    this.unsubscribe = getSharedObserver().subscribe((o) => { this.observer = o; });
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
      this.error = '';
    }
  };

  private toggle(): void {
    this.open = !this.open;
    this.mode = 'menu';
    this.error = '';
  }

  private async useGeolocation(): Promise<void> {
    this.busy = true;
    this.error = '';
    try {
      await getSharedObserver().requestGeolocation();
      this.open = false;
    } catch (err) {
      this.error = err instanceof Error ? err.message : 'Geolocation failed';
    } finally {
      this.busy = false;
    }
  }

  private switchMode(mode: Mode): void {
    this.mode = mode;
    this.error = '';
    this.cityResults = [];
    this.cityQuery = '';
  }

  private onCityInput(e: Event): void {
    const value = (e.target as HTMLInputElement).value;
    this.cityQuery = value;
    if (this.cityDebounce !== null) {
      window.clearTimeout(this.cityDebounce);
    }
    this.cityDebounce = window.setTimeout(() => void this.runCitySearch(value), 350);
  }

  private async runCitySearch(query: string): Promise<void> {
    if (query.trim().length < 2) {
      this.cityResults = [];
      return;
    }
    const myToken = ++this.cityToken;
    this.busy = true;
    this.error = '';
    try {
      const results = await getSharedObserver().searchCity(query);
      if (myToken === this.cityToken) {
        this.cityResults = results;
      }
    } catch (err) {
      if (myToken === this.cityToken) {
        this.error = err instanceof Error ? err.message : 'City search failed';
      }
    } finally {
      if (myToken === this.cityToken) {
        this.busy = false;
      }
    }
  }

  private pickCity(result: CitySearchResult): void {
    getSharedObserver().setFromCity(result);
    this.open = false;
  }

  private submitManual(): void {
    const lat = Number.parseFloat(this.manualLat);
    const lon = Number.parseFloat(this.manualLon);
    const alt = Number.parseFloat(this.manualAlt) || 0;
    try {
      getSharedObserver().setManual(lat, lon, alt);
      this.open = false;
    } catch (err) {
      this.error = err instanceof Error ? err.message : 'Could not save location';
    }
  }

  private clearLocation(e: Event): void {
    e.stopPropagation();
    getSharedObserver().clear();
    this.open = false;
  }

  private formatPill(): string {
    if (this.observer === null) {
      return '📍 set location';
    }
    const lat = this.observer.latitude.toFixed(2);
    const lon = this.observer.longitude.toFixed(2);
    if (this.observer.label) {
      const short = this.observer.label.split(',')[0].trim();
      return `📍 ${short} (${lat}°, ${lon}°)`;
    }
    return `📍 ${lat}°, ${lon}°`;
  }

  render() {
    return html`
      <button
        class="pill"
        data-set=${this.observer !== null ? 'true' : 'false'}
        @click=${this.toggle}
        aria-haspopup="dialog"
        aria-expanded=${this.open ? 'true' : 'false'}
        title="${this.observer
          ? `Observer: ${this.observer.latitude.toFixed(4)}°, ${this.observer.longitude.toFixed(4)}° (${this.observer.source})`
          : 'Set your observer location'}"
      >
        <span class="pill__glyph" aria-hidden="true">📍</span>
        <span>${this.formatPill().replace(/^📍\s*/, '')}</span>
      </button>

      ${this.open ? this.renderPanel() : nothing}
    `;
  }

  private renderPanel() {
    return html`
      <div class="panel" role="dialog" aria-label="Observer location">
        ${this.mode === 'menu' ? this.renderMenu() : nothing}
        ${this.mode === 'manual' ? this.renderManual() : nothing}
        ${this.mode === 'city' ? this.renderCity() : nothing}
        ${this.error ? html`<div class="error">${this.error}</div>` : nothing}
        ${this.observer
          ? html`
              <div class="meta">
                <span>${this.observer.source}</span>
                <a @click=${this.clearLocation}>clear</a>
              </div>
            `
          : nothing}
      </div>
    `;
  }

  private renderMenu() {
    return html`
      <h3>Set observer location</h3>
      <button class="option" ?disabled=${this.busy} @click=${() => void this.useGeolocation()}>
        🛰 Use my location
      </button>
      <button class="option" @click=${() => this.switchMode('city')}>
        🌍 Search for a city
      </button>
      <button class="option" @click=${() => this.switchMode('manual')}>
        ⌖ Enter latitude / longitude
      </button>
    `;
  }

  private renderCity() {
    return html`
      <span class="back" @click=${() => this.switchMode('menu')}>‹ back</span>
      <div class="row">
        <input
          type="text"
          placeholder="London, Tokyo, Sydney..."
          .value=${this.cityQuery}
          @input=${this.onCityInput}
          autofocus
        />
      </div>
      <p class="hint">Search powered by OpenStreetMap Nominatim. Limited to 1 query/sec.</p>
      ${this.cityResults.length > 0
        ? html`
            <ul class="results">
              ${this.cityResults.map(
                (r) => html`<li @click=${() => this.pickCity(r)}>${r.label}</li>`
              )}
            </ul>
          `
        : nothing}
      ${this.busy ? html`<p class="hint">Searching…</p>` : nothing}
    `;
  }

  private renderManual() {
    return html`
      <span class="back" @click=${() => this.switchMode('menu')}>‹ back</span>
      <div class="row">
        <input
          type="number" step="0.0001" min="-90" max="90"
          placeholder="Latitude (°)"
          .value=${this.manualLat}
          @input=${(e: Event) => (this.manualLat = (e.target as HTMLInputElement).value)}
        />
        <input
          type="number" step="0.0001" min="-180" max="180"
          placeholder="Longitude (°)"
          .value=${this.manualLon}
          @input=${(e: Event) => (this.manualLon = (e.target as HTMLInputElement).value)}
        />
      </div>
      <div class="row">
        <input
          type="number" step="1"
          placeholder="Altitude (m, optional)"
          .value=${this.manualAlt}
          @input=${(e: Event) => (this.manualAlt = (e.target as HTMLInputElement).value)}
        />
        <button class="primary" @click=${this.submitManual}>Save</button>
      </div>
      <p class="hint">Decimal degrees. East/north positive.</p>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-observer-pill': SatObserverPill;
  }
}
