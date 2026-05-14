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
| 3. CelesTrak ingester | ✅ done | `make ingest` (or `--group=slug`) populates ~15.6K distinct satellites from CelesTrak's 38 GP groups in ~40s; idempotent re-runs honor CelesTrak's 403 "not modified" politeness signal. TleParser does mod-10 checksum + epoch + element extraction; CelesTrakIngester does upsert-preserving-SATCAT-fields + INSERT OR IGNORE history. 12 new tests, 19 total passing. |
| 4. API endpoints | ✅ done | 8 JSON endpoints under `/api/v1/`: `/satellites`, `/satellites/{norad}`, `/satellites/{norad}/tle`, `/groups`, `/groups/{slug}`, `/groups/{slug}/tles`, `/search`, `/autocomplete`. App-level CORS (handles OPTIONS preflight before routing); per-group ETag (304 round-trip) + JSON Content-Type + Cache-Control middleware. New `group_membership` migration tracks per-group inclusion. 16 new feature tests, **36 total passing**. |
| 5. Globe rendering | ✅ done | The globe is no longer empty: ~15K satellites rendered as `Cesium.PointPrimitiveCollection`, color-coded by `object_type`. SGP4 propagation runs in a `Web Worker` at 4Hz (every 250ms), positions transferred as `Float32Array` (no copy). Click-to-select wired via `Cesium.Scene.pick`; `<sat-globe>` dispatches `'select'` CustomEvents that `<sat-app>` displays as a placeholder pill (real detail panel arrives in chunk 6). Live status pill shows "Tracking 15,665 satellites" then fades. 9 new Vitest cases for the API client; **45 total tests passing**. |
| 6. Detail panel + search | ✅ done | Right-rail `<sat-detail-panel>` slides in on selection with four §10 sections: Identity (badges + 6 grid fields), Current state (live lat/lon/alt polled from worker), Orbital elements (epoch + `<sat-freshness-badge>` + 12 fields), Raw data (clickable TLE + JSON links). Functional `<sat-search>` with debounced autocomplete dropdown — ↑/↓ navigates, Enter/click selects, camera flies to the satellite. Highlighted primitive turns white + 9px on selection; clicking empty space, the × button, or Esc clears. Mobile: bottom-sheet panel. **52 total tests passing** (6 new Vitest cases for FreshnessBadge classification). |
| 7. Time scrubbing | ⏳ pending | ±7d timeline with yellow band beyond ±48h, play/pause, speed controls |
| 8. Text-only catalog at /text | ⏳ pending | Server-rendered fallback for browsers without WebGL or with JS disabled (depends on chunk 4 API) |

See [`docs/phase1.md`](docs/phase1.md) for the full phase design and [`req_spec.md`](req_spec.md) for the long-form vision (sections §1–§30).

---

## What's testable today

### From the browser or your phone — public JSON API

The catalog API is live at `http://localhost:8000/api/v1/...` (or the LAN URL). Try any of these in a browser, with `curl`, or from your phone:

```bash
# Catalog
curl http://localhost:8000/api/v1/satellites?limit=5
curl http://localhost:8000/api/v1/satellites?country=US&type=PAYLOAD&limit=10
curl http://localhost:8000/api/v1/satellites?q=hubble        # FTS5 fuzzy
curl http://localhost:8000/api/v1/satellites/25544           # ISS detail (TLE inlined)
curl http://localhost:8000/api/v1/satellites/25544/tle       # ISS current TLE only

# Groups (38 CelesTrak groups configured)
curl http://localhost:8000/api/v1/groups
curl http://localhost:8000/api/v1/groups/stations            # 27 NORAD IDs
curl http://localhost:8000/api/v1/groups/starlink/tles       # bulk: 10K Starlinks

# Search
curl 'http://localhost:8000/api/v1/search?q=ISS'             # ISS family modules
curl 'http://localhost:8000/api/v1/search?q=1998-067A'       # by intl designator
curl 'http://localhost:8000/api/v1/autocomplete?q=star'      # typeahead
```

Every response carries an `ETag`; pass it back via `If-None-Match` to get a 304 Not Modified. CORS is fully open (`Access-Control-Allow-Origin: *`) and OPTIONS preflight returns 204 in <5ms.

### In the browser

Open `http://localhost:8000` (or the LAN URL printed by `make`). You should see:

- **Top bar**: `⊕ sat.trackr.live` wordmark, `Space situational awareness, _legible_` tagline, `§ catalog · § launches · § events` nav (launches/events are dim placeholders), search input with `⌘K` shortcut hint, theme switcher button.
- **Cesium globe with ~15,000 satellites** rendered as point primitives, color-coded by `object_type` (cyan = payloads + unknown, amber = rocket bodies, red = debris, gray = TBA). SGP4 propagation runs in a Web Worker at 4Hz; you should see the ISS marching across the planet, Starlink trains in formation, and ~10K LEO objects in slow-motion swarm. Drag to rotate, pinch/scroll to zoom. OpenStreetMap imagery (no Cesium ion token needed yet).
- **Click any dot** → it turns white + 9px and the right-rail **detail panel** slides in with four `§` sections:
  - **§ Identity** — type/status/orbit-class badges + 6-cell grid (operator, country, launch date, launch vehicle, mass, RCS). Phase-1 fields not yet populated by SATCAT show as italic "—" placeholders. External links: N2YO, Heavens-Above, Gunter, Wikipedia.
  - **§ Current state** — live latitude / longitude / altitude (km) updated 2× per second from the propagator worker.
  - **§ Orbital elements** — epoch with `<sat-freshness-badge>` (FRESH/STALE/AGED/OLD), period, inclination, eccentricity, mean motion, perigee, apogee, semi-major axis, B*, RAAN, arg perigee, mean anomaly, rev number.
  - **§ Raw data** — clickable 3-line TLE (click to copy) + JSON detail/TLE links.
- **Search the catalog** in the top-right input (⌘K focuses it). Type to get a debounced autocomplete dropdown of up to 10 matches; ↑/↓ navigates, Enter or click selects. On selection the camera flies to the satellite and the detail panel opens.
- **Close the panel** via the × button, the Esc key, or by clicking empty space on the globe.
- **Status pill** in the bottom-left corner shows the load progression: "Loading satellite catalog…" → "Parsing 15,665 TLEs…" → "Tracking 15,665 satellites" (then fades to half-opacity).
- **Theme switcher**: click the toggle (top right) to cycle Dark / Light / High contrast. Choice persists in `localStorage`.

URL shapes already wired:
- `/` → SPA shell with full globe
- `/satellite/{norad}` → SPA shell with the NORAD ID set as initial selection so the detail panel opens for that satellite on page load (camera doesn't auto-fly — that's only triggered by the search picker)

### From the CLI

```bash
# Schema management
make migrate                         # apply the 5 Phase 1 migrations to data/sat.db
make migrate-status                  # show what's applied vs pending
make rollback                        # reverse the most recent batch
make make-migration NAME=add_foo     # scaffold a new migration file

# Catalog ingest (chunk 3)
make ingest                          # all 38 CelesTrak groups, ~40s on a fresh DB
make ingest-group GROUP=stations     # just one group
make health                          # PHP / pdo_sqlite / DB / per-table row counts

# Quality gates
make test                            # 19 PHP tests (TleParser, MigrationsTest, CelesTrakIngesterTest) + 1 JS smoke
make lint / make analyze / make typecheck / make ci
```

After `make ingest`, the database holds ~15.6K distinct satellites (deduplicated across overlapping CelesTrak groups), ~15.6K current TLEs, and a history row per (norad_id, epoch) pair — typically 1 per object on the first run, growing by the number of objects with refreshed epochs on each subsequent run.

The schema after `make migrate` matches `docs/phase1.md` § V exactly:

| Table | Purpose | Notes |
|---|---|---|
| `satellites` | Catalog row per object | CHECK constraints on `object_type`, `status`, `orbit_class`, `size_class`; 6 indexes. CelesTrak ingest only populates `name` + `intl_designator`; SATCAT (chunk 3+, Phase 2) will fill operator/country/mass/etc. |
| `satellites_fts` | FTS5 virtual table for fuzzy search | Auto-synced via insert/update/delete triggers |
| `tle_current` | One TLE per active object | FK to satellites, ON DELETE CASCADE; mean motion + eccentricity + inclination + RAAN + arg perigee + mean anomaly + BSTAR + rev number, plus derived period / perigee / apogee / semi-major axis |
| `tle_history` | Append-only TLE archive | Composite PK `(norad_id, epoch)`; INSERT OR IGNORE makes re-ingests cheap |
| `satellite_purposes` | Join table for §5 SET-style purpose | Empty in Phase 1; populated by SATCAT in Phase 2 |
| `group_membership` | Join table tracking which CelesTrak group(s) include each satellite | Composite PK `(norad_id, group_slug)` + `last_seen_at`; populated by the ingester on each pass; powers `/api/v1/groups/{slug}*` |
| `migrations` | Auto-created by Migrator | Tracks applied filename + batch + timestamp |

### API endpoint reference (chunk 4)

| Method + path | Returns | Notes |
|---|---|---|
| `GET /api/v1/satellites` | Paginated list `{data, meta, links}` | Filters: `country`, `type`, `status`, `orbit_class` (multi via comma); `operator` (substring); `launched_after`/`launched_before` (ISO date); `q` (FTS5); `page`, `limit` (max 500) |
| `GET /api/v1/satellites/{norad}` | Full detail with `tle_current` inlined | Includes `freshness` label (FRESH/STALE/AGED/OLD per §11) and `epoch_age_seconds`; 404 on unknown NORAD |
| `GET /api/v1/satellites/{norad}/tle` | Current TLE only | For clients that already have catalog metadata |
| `GET /api/v1/groups` | All 38 CelesTrak groups + counts | 1h cacheable |
| `GET /api/v1/groups/{slug}` | Group + ordered NORAD IDs | 5min cacheable |
| `GET /api/v1/groups/{slug}/tles` | Bulk TLE blob `{group, count, tles: [...]}` | The hot SPA endpoint; full keys (`norad_id`, `name`, `line1`, `line2`, `object_type`) for readability |
| `GET /api/v1/search?q=` | Up to 50 results, each with `match_type` | NORAD ID exact > intl designator exact > FTS5 fuzzy |
| `GET /api/v1/autocomplete?q=` | Up to 10 typeahead results | NORAD ID prefix + FTS5 prefix; 5min cacheable |

Default response headers: `Content-Type: application/json; charset=utf-8`, `Cache-Control: public, max-age=60, stale-while-revalidate=120` (controllers override per-route — bulk-TLE uses 300s, group lists use 3600s), `ETag: W/"<sha1-of-body>"` plus open CORS (`*`). `If-None-Match` → 304.

### CelesTrak ingest details

- **Format:** we fetch `FORMAT=TLE` (3-line sets). When NORAD IDs cross 6 digits — CelesTrak forecasts ~mid-2026 — the legacy TLE format breaks and we'll need to switch to `FORMAT=JSON` (OMM). Tracked as a Phase 2 migration item.
- **Group list:** 38 groups configured in `src/Ingest/CelesTrakGroups.php` covering Special-Interest, Weather/Earth-Obs, Communications, Navigation, Scientific, and Miscellaneous. Many objects appear in multiple groups; the upsert-by-norad_id logic dedupes naturally.
- **Idempotency:** CelesTrak returns HTTP 403 with body "GP data has not updated since…" when you re-fetch a group it considers unchanged. We treat that as a polite skip — group counted but no records processed. INSERT OR IGNORE on `tle_history` ensures re-ingesting the same TLE adds no row.
- **Cron:** the schedule lands on prod once you wire DreamHost cron to `cd ~/sat.trackr.live && make ingest >> storage/logs/cron.log 2>&1` every 6 hours per `req_spec.md` §23.
- **SATCAT preservation:** CelesTrak's basic GP feed only carries name + intl designator + orbital elements. The upsert deliberately does **not** touch operator, country, mass, or other satellites-table columns on conflict, so the future SATCAT ingester can populate those without being clobbered.

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
