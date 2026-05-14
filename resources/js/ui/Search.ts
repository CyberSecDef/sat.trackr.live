import { LitElement, html, css, nothing } from 'lit';
import { customElement, state } from 'lit/decorators.js';
import { ref, createRef, type Ref } from 'lit/directives/ref.js';
import { autocomplete } from '../api/client';
import type { AutocompleteResult } from '../api/types';

const DEBOUNCE_MS = 200;

/**
 * <sat-search>
 *
 * Top-bar search input. Hits /api/v1/autocomplete on each debounced
 * keystroke and renders a dropdown of up to 10 results. Click or
 * Enter selects; Escape closes; ↑/↓ navigates within the list.
 *
 * Selection dispatches:
 *   CustomEvent<'search-select'>(detail: { norad: number, name: string })
 *
 * <sat-app> handles the event by setting the active selection and
 * flying the camera to it.
 */
@customElement('sat-search')
export class SatSearch extends LitElement {
  @state() private query = '';
  @state() private results: AutocompleteResult[] = [];
  @state() private open = false;
  @state() private activeIndex = -1;
  @state() private loading = false;

  private inputRef: Ref<HTMLInputElement> = createRef();
  private debounceTimer: number | null = null;
  private inflightToken = 0;

  static styles = css`
    :host {
      display: inline-block;
      position: relative;
    }
    .wrap { position: relative; }
    input {
      width: 240px;
      padding: 0.35rem 2.4rem 0.35rem 0.8rem;
      background: var(--color-bg-elevated);
      color: var(--color-text);
      border: 1px solid var(--color-border);
      border-radius: 4px;
      font-family: var(--font-body);
      font-size: 0.85rem;
    }
    input:focus {
      outline: none;
      border-color: var(--color-accent);
    }
    input::placeholder { color: var(--color-text-dim); }
    .shortcut {
      position: absolute;
      top: 50%;
      right: 0.5rem;
      transform: translateY(-50%);
      color: var(--color-text-dim);
      font-family: var(--font-mono);
      font-size: 0.7rem;
      pointer-events: none;
    }

    .dropdown {
      position: absolute;
      top: calc(100% + 0.3rem);
      left: 0;
      right: 0;
      max-height: 60vh;
      overflow-y: auto;
      background: var(--color-bg-elevated);
      border: 1px solid var(--color-border);
      border-radius: 4px;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
      z-index: 100;
    }
    .empty {
      padding: 0.5rem 0.8rem;
      color: var(--color-text-muted);
      font-size: 0.8rem;
      font-style: italic;
    }
    .item {
      display: grid;
      grid-template-columns: minmax(60px, auto) 1fr auto;
      gap: 0.6rem;
      align-items: baseline;
      padding: 0.4rem 0.7rem;
      cursor: pointer;
      border-bottom: 1px solid var(--color-border);
      font-size: 0.8rem;
    }
    .item:last-child { border-bottom: none; }
    .item:hover,
    .item--active {
      background: var(--color-bg);
    }
    .item__norad {
      color: var(--color-accent);
      font-family: var(--font-mono);
      font-size: 0.75rem;
    }
    .item__name {
      color: var(--color-text);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .item__country {
      color: var(--color-text-dim);
      font-family: var(--font-mono);
      font-size: 0.7rem;
    }

    @media (max-width: 800px) {
      input { width: 160px; }
      .shortcut { display: none; }
    }
  `;

  connectedCallback(): void {
    super.connectedCallback();
    document.addEventListener('keydown', this.handleGlobalKeydown);
    document.addEventListener('click', this.handleOutsideClick);
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    document.removeEventListener('keydown', this.handleGlobalKeydown);
    document.removeEventListener('click', this.handleOutsideClick);
    if (this.debounceTimer !== null) {
      window.clearTimeout(this.debounceTimer);
    }
  }

  private handleGlobalKeydown = (e: KeyboardEvent): void => {
    const isMac = navigator.platform.toUpperCase().includes('MAC');
    if ((isMac ? e.metaKey : e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      this.inputRef.value?.focus();
      this.inputRef.value?.select();
    }
  };

  private handleOutsideClick = (e: MouseEvent): void => {
    if (!e.composedPath().includes(this)) {
      this.open = false;
    }
  };

  private handleInput = (e: Event): void => {
    const target = e.target as HTMLInputElement;
    this.query = target.value;
    this.scheduleAutocomplete();
  };

  private scheduleAutocomplete(): void {
    if (this.debounceTimer !== null) {
      window.clearTimeout(this.debounceTimer);
    }
    const q = this.query.trim();
    if (q === '') {
      this.results = [];
      this.open = false;
      return;
    }
    this.debounceTimer = window.setTimeout(() => {
      void this.runAutocomplete(q);
    }, DEBOUNCE_MS);
  }

  private async runAutocomplete(q: string): Promise<void> {
    const token = ++this.inflightToken;
    this.loading = true;
    try {
      const response = await autocomplete(q);
      if (token !== this.inflightToken) return; // a newer request is in flight
      this.results = response.data;
      this.open = true;
      this.activeIndex = this.results.length > 0 ? 0 : -1;
    } catch (err) {
      if (token !== this.inflightToken) return;
      console.warn('Autocomplete failed:', err);
      this.results = [];
      this.open = false;
    } finally {
      if (token === this.inflightToken) {
        this.loading = false;
      }
    }
  }

  private handleInputKeydown = (e: KeyboardEvent): void => {
    if (e.key === 'Escape') {
      this.open = false;
      this.inputRef.value?.blur();
      return;
    }
    if (!this.open || this.results.length === 0) return;

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      this.activeIndex = (this.activeIndex + 1) % this.results.length;
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      this.activeIndex = (this.activeIndex - 1 + this.results.length) % this.results.length;
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const idx = this.activeIndex >= 0 ? this.activeIndex : 0;
      this.selectResult(this.results[idx]);
    }
  };

  private selectResult(result: AutocompleteResult): void {
    this.dispatchEvent(
      new CustomEvent<{ norad: number; name: string }>('search-select', {
        detail: { norad: result.norad_id, name: result.name },
        bubbles: true,
        composed: true,
      }),
    );
    // Reset input so the user can search again with a fresh slate.
    this.query = '';
    this.results = [];
    this.open = false;
    this.activeIndex = -1;
    this.inputRef.value?.blur();
  }

  render() {
    return html`
      <div class="wrap">
        <input
          type="search"
          placeholder="Search satellites…"
          autocomplete="off"
          aria-label="Search satellites"
          .value=${this.query}
          @input=${this.handleInput}
          @keydown=${this.handleInputKeydown}
          @focus=${() => { if (this.results.length > 0) this.open = true; }}
          ${ref(this.inputRef)}
        />
        <span class="shortcut" aria-hidden="true">⌘K</span>
        ${this.renderDropdown()}
      </div>
    `;
  }

  private renderDropdown() {
    if (!this.open) return nothing;
    if (this.results.length === 0) {
      return html`<div class="dropdown"><div class="empty">${this.loading ? 'Searching…' : 'No matches'}</div></div>`;
    }
    return html`
      <div class="dropdown" role="listbox">
        ${this.results.map(
          (r, i) => html`
            <div
              role="option"
              class="item ${i === this.activeIndex ? 'item--active' : ''}"
              @click=${() => this.selectResult(r)}
              @mouseenter=${() => { this.activeIndex = i; }}
            >
              <span class="item__norad">${r.norad_id}</span>
              <span class="item__name">${r.name}</span>
              <span class="item__country">${r.country ?? ''}</span>
            </div>
          `,
        )}
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-search': SatSearch;
  }
}
