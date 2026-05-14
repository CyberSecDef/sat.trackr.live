import { LitElement, html, css } from 'lit';
import { customElement } from 'lit/decorators.js';
import { ref, createRef, type Ref } from 'lit/directives/ref.js';

/**
 * Stub for chunk 1 — full search lands in chunk 6.
 * Renders the input + ⌘K shortcut binding so the visual chrome is in place.
 */
@customElement('sat-search')
export class SatSearch extends LitElement {
  private inputRef: Ref<HTMLInputElement> = createRef();

  static styles = css`
    :host {
      display: inline-block;
    }
    .wrap {
      position: relative;
    }
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
    input::placeholder {
      color: var(--color-text-dim);
    }
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

    @media (max-width: 800px) {
      input {
        width: 160px;
      }
      .shortcut {
        display: none;
      }
    }
  `;

  connectedCallback(): void {
    super.connectedCallback();
    document.addEventListener('keydown', this.handleKeydown);
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    document.removeEventListener('keydown', this.handleKeydown);
  }

  private handleKeydown = (e: KeyboardEvent): void => {
    const isMac = navigator.platform.toUpperCase().includes('MAC');
    const cmdOrCtrl = isMac ? e.metaKey : e.ctrlKey;
    if (cmdOrCtrl && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      this.inputRef.value?.focus();
    }
  };

  render() {
    return html`
      <div class="wrap">
        <input
          type="search"
          placeholder="Search satellites…"
          aria-label="Search satellites"
          ${ref(this.inputRef)}
        />
        <span class="shortcut" aria-hidden="true">⌘K</span>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-search': SatSearch;
  }
}
