import { LitElement, html, css } from 'lit';
import { customElement, state } from 'lit/decorators.js';

type Theme = 'dark' | 'light' | 'high-contrast';

interface ThemeMeta {
  value: Theme;
  label: string;
  glyph: string;
}

const THEMES: ThemeMeta[] = [
  { value: 'dark', label: 'Dark', glyph: '☽' },
  { value: 'light', label: 'Light', glyph: '☀' },
  { value: 'high-contrast', label: 'High contrast', glyph: '◐' },
];

const STORAGE_KEY = 'sat-trackr-theme';

@customElement('sat-theme-switcher')
export class SatThemeSwitcher extends LitElement {
  @state() private current: Theme = 'dark';
  @state() private open = false;

  static styles = css`
    :host {
      position: relative;
      display: inline-block;
    }
    .toggle {
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
    .toggle:hover {
      border-color: var(--color-accent);
      color: var(--color-accent);
    }
    .menu {
      position: absolute;
      top: calc(100% + 0.4rem);
      right: 0;
      min-width: 180px;
      background: var(--color-bg-elevated);
      border: 1px solid var(--color-border);
      border-radius: 4px;
      padding: 0.3rem 0;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
      z-index: 100;
    }
    .menu__item {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      padding: 0.4rem 0.8rem;
      font-family: var(--font-mono);
      font-size: 0.85rem;
      color: var(--color-text);
      cursor: pointer;
    }
    .menu__item:hover {
      background: var(--color-bg);
      color: var(--color-accent);
    }
    .menu__item--active {
      color: var(--color-accent);
    }
    .menu__glyph {
      font-size: 1rem;
      min-width: 1rem;
    }
  `;

  connectedCallback(): void {
    super.connectedCallback();
    const stored = localStorage.getItem(STORAGE_KEY) as Theme | null;
    if (stored && THEMES.some((t) => t.value === stored)) {
      this.current = stored;
    }
    document.addEventListener('click', this.handleOutsideClick);
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    document.removeEventListener('click', this.handleOutsideClick);
  }

  private handleOutsideClick = (e: MouseEvent): void => {
    if (!e.composedPath().includes(this)) {
      this.open = false;
    }
  };

  private toggle(): void {
    this.open = !this.open;
  }

  private select(theme: Theme): void {
    this.current = theme;
    this.open = false;
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem(STORAGE_KEY, theme);
  }

  render() {
    const currentMeta = THEMES.find((t) => t.value === this.current) ?? THEMES[0];
    return html`
      <button class="toggle" @click=${this.toggle} aria-label="Switch theme" aria-haspopup="menu">
        <span aria-hidden="true">${currentMeta.glyph}</span>
        <span>${currentMeta.label}</span>
      </button>
      ${this.open
        ? html`
            <div class="menu" role="menu">
              ${THEMES.map(
                (t) => html`
                  <div
                    role="menuitem"
                    class="menu__item ${t.value === this.current ? 'menu__item--active' : ''}"
                    @click=${() => this.select(t.value)}
                  >
                    <span class="menu__glyph" aria-hidden="true">${t.glyph}</span>
                    <span>${t.label}</span>
                  </div>
                `
              )}
            </div>
          `
        : null}
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'sat-theme-switcher': SatThemeSwitcher;
  }
}
