# sat.trackr.live

> _Space situational awareness, **legible**._

A public, read-only, mobile-friendly 3D web app that visualizes everything humans have put into Earth orbit — plus the events shaping that environment (launches, reentries, conjunctions, space weather) — on a single time-scrubbable globe.

Part of the **trackr.live family** alongside [trackr.live](https://trackr.live) and [cyber.trackr.live](https://cyber.trackr.live).

---

## Status

🚧 **Phase 1 in progress.** Built incrementally; the README is updated as each chunk lands.

| Chunk | Status | What it adds |
|---|---|---|
| 1. Bootstrap + chrome | ✅ done | Repo skeleton, build tooling, Slim front controller, SPA shell, dark/light/high-contrast themes, Cesium globe with OSM imagery, search input + ⌘K, theme switcher |
| 1.5. WebGL fallback gate | ⏳ pending | Client-side WebGL detection; `<sat-no-webgl>` notice when absent, with link to text-only catalog |
| 2. Schema + migrations | ✅ done | `bin/console` (Symfony Console) with `migrate / rollback / migrate:status / make:migration / health`; `satellites`, `satellites_fts` (with sync triggers), `tle_current`, `tle_history`, `satellite_purposes` tables — verified by 7-test PHPUnit feature suite |
| 3. CelesTrak ingester | ⏳ pending | `make ingest` populates ~30K satellites from CelesTrak's ~33 GP groups |
| 4. API endpoints | ⏳ pending | `/api/v1/satellites*`, `/api/v1/groups/{slug}/tles`, `/api/v1/search`, ETag + CORS middleware |
| 5. Globe rendering | ⏳ pending | Bulk TLE fetch, satellite.js SGP4 in a Web Worker, Cesium point primitives color-coded by type |
| 6. Detail panel + search | ⏳ pending | Click-to-select, populated detail panel per req_spec §10, FTS5-backed search results |
| 7. Time scrubbing | ⏳ pending | ±7d timeline with yellow band beyond ±48h, play/pause, speed controls |
| 8. Text-only catalog at /text | ⏳ pending | Server-rendered fallback for browsers without WebGL or with JS disabled (depends on chunk 4 API) |

See [`docs/phase1.md`](docs/phase1.md) for the full phase design and [`req_spec.md`](req_spec.md) for the long-form vision (sections §1–§30).

---

## What's testable today

### In the browser

Open `http://localhost:8000` (or the LAN URL printed by `make`). You should see:

- **Top bar**: `⊕ sat.trackr.live` wordmark, `Space situational awareness, _legible_` tagline, `§ catalog · § launches · § events` nav (launches/events are dim placeholders), search input with `⌘K` shortcut hint, theme switcher button.
- **Cesium globe**: full-viewport 3D Earth using OpenStreetMap imagery (no Cesium ion token needed yet). Lighting from sun, atmosphere, fog enabled. Drag to rotate, pinch/scroll to zoom.
- **Theme switcher**: click the toggle (top right) to cycle Dark / Light / High contrast. Choice persists in `localStorage`. CSS variables propagate instantly through every component.
- **⌘K** (or `Ctrl-K`): focuses the search input. The input is functional but doesn't search anything yet — full search lands in chunk 6.
- **No satellites yet** — the catalog ingest + globe rendering chunks haven't landed. The globe is empty by design.

URL shapes already wired:
- `/` → SPA shell
- `/satellite/{norad}` → SPA shell with the NORAD ID surfaced as a property on `<sat-app>` (no detail panel yet to render it; chunk 6)

### From the CLI

```bash
make migrate           # apply the 5 Phase 1 migrations to data/sat.db
make migrate-status    # show what's applied vs pending
make rollback          # reverse the most recent batch
make health            # PHP version, pdo_sqlite, DB connection, table row counts
make make-migration NAME=add_foo_to_bar   # scaffold a new migration file
make test              # 7 migration feature tests + 1 JS smoke test
```

The schema after `make migrate` matches `docs/phase1.md` § V exactly:

| Table | Purpose | Notes |
|---|---|---|
| `satellites` | Catalog row per object | CHECK constraints on `object_type`, `status`, `orbit_class`, `size_class`; 6 indexes |
| `satellites_fts` | FTS5 virtual table for fuzzy search | Auto-synced via insert/update/delete triggers |
| `tle_current` | One TLE per active object | FK to satellites, ON DELETE CASCADE |
| `tle_history` | Append-only TLE archive | Composite PK `(norad_id, epoch)` |
| `satellite_purposes` | Join table for §5 SET-style purpose | CHECK on canonical 10 values |
| `migrations` | Auto-created by Migrator | Tracks applied filename + batch + timestamp |

---

## Installation

### Requirements

- **PHP 8.4** with extensions: `pdo`, `pdo_sqlite`, `json`, `mbstring`, `curl`
- **Composer 2**
- **Node 20+** with **npm 10+**
- **SQLite 3** CLI (optional but useful for poking at the DB)

For DreamHost VPS (production target): select PHP 8.4 in the panel and confirm `pdo_sqlite` is enabled via phpinfo.

### One-time setup

```bash
git clone git@github.com:CyberSecDef/sat.trackr.live.git
cd sat.trackr.live

# Copy env templates and edit if needed
cp .env.example     .env       # APP_ENV=dev, APP_NAME, etc
cp .env.dev.example .env.dev   # DB_PATH, CESIUM_ION_TOKEN, VITE_DEV_ORIGIN
# (later) cp .env.prod.example .env.prod  # for production deploy

make install                   # composer install + npm install
make build                     # production bundle into public/build/
```

### Run locally

```bash
make serve     # PHP server on 0.0.0.0:8000  — use this for production-style review
# or
make dev       # PHP + Vite dev server in parallel; HMR enabled
```

`make` (no args) prints the full target list and the detected LAN URL so you can hit the site from your phone or another device on the same network.

---

## Project layout

```
sat.trackr.live/
├── .env / .env.dev / .env.prod      gitignored; see .env*.example for shape
├── Makefile                         single user-facing entry point — run `make`
├── composer.json   php deps
├── package.json    js deps
├── vite.config.ts  Vite + vite-plugin-cesium
├── tsconfig.json   strict + experimentalDecorators (Lit)
├── docs/
│   └── phase1.md   Full Phase 1 design
├── public/                         ← Apache DocumentRoot
│   ├── index.php   Slim front controller
│   ├── .htaccess   SPA rewrites + cache headers
│   ├── favicon.svg
│   ├── robots.txt
│   ├── build/      Vite output (hashed JS/CSS) — gitignored
│   └── cesium/     Cesium runtime assets — gitignored
├── src/                            PHP, namespace SatTrackr\
│   ├── App/        EnvLoader, Container, Kernel
│   ├── Http/       Controllers + Middleware
│   └── Services/   ViteAssetResolver
├── resources/                      Frontend source
│   ├── js/         TypeScript: main.ts, App.ts, ui/, globe/, types/
│   ├── css/        main.css + themes/{dark,light,high-contrast}.css
│   └── views/      shell.php (SPA shell template)
├── data/           sat.db lives here (gitignored)
├── storage/        logs/, cache/ (gitignored)
└── tests/
    ├── Php/        PHPUnit
    └── Js/         Vitest
```

---

## Makefile targets

Run `make` with no arguments to print this list. Highlights:

| Target | Purpose |
|---|---|
| `make install` | composer + npm install |
| `make dev` | PHP + Vite dev servers in parallel (Ctrl-C kills both) |
| `make serve` | PHP server only, serves the production-built SPA |
| `make build` | production bundle into `public/build/` |
| `make migrate` | apply migrations (chunk 2+) |
| `make ingest` | run CelesTrak ingester (chunk 3+) |
| `make lint` / `make lint-fix` | PHP-CS-Fixer + ESLint |
| `make analyze` | PHPStan level 6 |
| `make typecheck` | tsc --noEmit |
| `make test` | PHPUnit + Vitest |
| `make ci` | full quality gate |
| `make deploy-check` | sanity-check deploy prerequisites |
| `make clean` | wipe vendor, node_modules, build artifacts |

Servers bind to `0.0.0.0` so the app is reachable from other devices on the LAN. Override with `make serve HOST=127.0.0.1` if you want loopback-only.

---

## Tech stack

- **PHP 8.4** + **Slim Framework 4** + **PHP-DI 7** + **Eloquent (illuminate/database 11)** + **illuminate/console 11** for migrations + **Monolog 3** + **Guzzle 7** + **vlucas/phpdotenv 5**
- **SQLite** (file-based, via `pdo_sqlite`)
- **Lit 3** (UI as `LitElement` custom elements with `sat-` prefix) + **Cesium.js 1.121** + **satellite.js** (SGP4 in a Web Worker, browser-side)
- **Vite 5** + **TypeScript 5.6 (strict)** + **ESLint 9 (flat config) + typescript-eslint** + **Prettier 3** + **Vitest 2**
- **PHPUnit 11**, **PHPStan level 6**, **PHP-CS-Fixer 3** (PSR-12 + `declare(strict_types=1)`)

---

## Production deployment (DreamHost VPS)

See [`docs/phase1.md` § X](docs/phase1.md) for the full deploy notes. TL;DR:

1. Point the DreamHost domain's web directory at `<repo>/public/`.
2. Select PHP 8.4; confirm `pdo_sqlite` enabled.
3. Place `.env` (`APP_ENV=prod`) and `.env.prod` (creds) at the repo root — both gitignored.
4. `git pull && make install-prod && make build && make migrate && make ingest`.
5. Add cron: `0 */6 * * * cd ~/sat.trackr.live && make ingest >> storage/logs/cron.log 2>&1`.

Apache config relies on `public/.htaccess` (rewrites + cache headers + security headers).

---

## Contributing

This project is **AGPL-3.0-or-later**. If you run a modified version on a public service, you must offer the modified source to your users.

Issues and PRs welcome at <https://github.com/CyberSecDef/sat.trackr.live>.

---

## License

[AGPL-3.0-or-later](LICENSE)
