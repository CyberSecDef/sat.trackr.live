# Phase 1 Design — sat.trackr.live

**Status:** Draft for review
**Scope:** §28 of `req_spec.md` — Foundation / MVP
**Target completion:** A live globe rendering ~30K CelesTrak objects, click-to-detail, search, ±48h time scrub. No launches, reentries, conjunctions, weather, or radio yet.

---

## § I — Phase 1 Goals & Acceptance

### Goals
1. **Foundation in place** for everything that comes after — repo skeleton, build tooling, dev workflow, deploy story, code-quality gates.
2. **Catalog data flowing** end-to-end: CelesTrak → SQLite → JSON API → browser.
3. **A globe that works.** Cesium renders ~30K point primitives. Click selects. Detail panel shows the object. Search finds objects by NORAD ID or name. Timeline scrubs ±48h.
4. **Family aesthetic in place** from day one — dark default, theme switcher, § dividers, ⌘K search, monospace IDs, freshness badges. The chrome around Cesium should feel like cyber.trackr.live the first time you open it.

### Acceptance criteria
- [ ] `composer install && npm install && composer migrate && composer ingest celestrak && npm run build && php -S localhost:8000 -t public/` boots a working dev instance from a fresh clone.
- [ ] CelesTrak full-catalog ingest completes in <60s, populates ~30K rows, idempotent on rerun.
- [ ] `/api/v1/satellites` returns paginated JSON with all documented filters.
- [ ] `/api/v1/satellites/{norad}` returns full detail with current TLE inlined.
- [ ] `/api/v1/groups/{slug}/tles` returns bulk TLE for a group as one gzipped response.
- [ ] Browser fetches the bulk TLE for the `active` group, propagates via satellite.js in a Web Worker, and renders ~7K-25K points on the globe at ≥30fps on a modern desktop.
- [ ] Clicking a point selects it; right-side panel populates with §10's "Identity", "Current state", and "Orbital elements" subsections.
- [ ] ⌘K opens search; typing "ISS" or "25544" finds and selects the ISS.
- [ ] Bottom timeline shows the full now-7d to now+7d range; play/pause works; objects move accordingly. Region beyond ±48h is rendered with a yellow warning band ("no historical TLE in Phase 1") but the slider can still travel there.
- [ ] Theme switcher cycles ≥3 themes (Dark / Light / High Contrast) with state persisted in localStorage.
- [ ] All PHP files pass `make lint-php` (PHP-CS-Fixer) and `make analyze` (PHPStan level 6).
- [ ] All TS files pass `make lint-js` and `make typecheck` with no errors.
- [ ] PHPUnit + Vitest both run green via `make test`.
- [ ] `README.md` reflects the actual current state of the project at all times — every chunk that lands also updates the README.

### Explicitly NOT in Phase 1
Defer to later phases: Space-Track ingest, decay/TIP, launches, reentries view, conjunctions, space weather, aurora overlay, ground stations, sensor cones, light pollution overlay, radio frequencies, pass predictions, observer location, sky chart, mobile PWA, OG image generation, sitemap, RSS feeds, stats dashboard, 3D models, orbit ribbons, ground tracks, footprints, OpenAPI docs page. Plumbing for some of these (e.g. routing slots, schema-adjacent tables) may be added but the features themselves are not built.

---

## § II — Architecture (Phase 1 slice)

```
┌─────────── Browser (SPA) ───────────────────────────────────────┐
│                                                                  │
│  index.html shell ──► main.ts                                    │
│                          │                                        │
│                          ├─► ThemeSwitcher  (dark/light/HC)       │
│                          ├─► TopBar  (logo, search, theme btn)    │
│                          ├─► Globe  (Cesium init, OSM imagery)    │
│                          │     │                                  │
│                          │     ├─► fetch /api/v1/groups/active    │
│                          │     ├─► PropagatorWorker (satellite.js)│
│                          │     └─► PrimitiveCollection (points)   │
│                          ├─► DetailPanel  (on point select)       │
│                          ├─► Search  (⌘K, autocomplete)           │
│                          └─► Timeline  (±48h scrub, play/pause)   │
└──────────────────────────────────────────────────────────────────┘
                          │ HTTPS / JSON
┌─────────────────────────▼────────────────────────────────────────┐
│  PHP / Slim 4   (public/index.php → SatTrackr\App\Kernel)        │
│                                                                   │
│  Middleware: ErrorHandler, Cors, ETag, GzipNegotiator             │
│  Routes:                                                          │
│    GET /                              → SPA shell (Vite-aware)    │
│    GET /satellite/{norad}             → SPA shell + preload hint  │
│    GET /api/v1/satellites             → SatelliteListController   │
│    GET /api/v1/satellites/{norad}     → SatelliteDetailController │
│    GET /api/v1/satellites/{norad}/tle → SatelliteTleController    │
│    GET /api/v1/groups                 → GroupListController       │
│    GET /api/v1/groups/{slug}          → GroupDetailController     │
│    GET /api/v1/groups/{slug}/tles     → GroupTlesController       │
│    GET /api/v1/search                 → SearchController          │
│    GET /api/v1/autocomplete           → AutocompleteController    │
└──────────────────────────────┬───────────────────────────────────┘
                               │
            ┌──────────────────┴────────────────────────┐
            │                                            │
┌───────────▼────────────┐                  ┌───────────▼──────────┐
│  SQLite  (data/sat.db) │                  │  CLI (bin/console)   │
│   satellites           │                  │   migrate / rollback │
│   satellites_fts (FTS5)│                  │   ingest celestrak   │
│   tle_current          │                  │   health             │
│   tle_history          │                  └──────────────────────┘
│   migrations           │
└────────────────────────┘
```

The boundary is sharp: **server hands out catalog rows and TLE strings; browser does all propagation and rendering.** No server-side SGP4 in Phase 1.

---

## § III — Repo Structure After Bootstrap

```
sat.trackr.live/
├── .env.example              ← templates committed; real .env / .env.dev / .env.prod gitignored
├── .env.dev.example          ← .env loaded first (defaults + APP_ENV marker), then .env.{APP_ENV} overlaid
├── .env.prod.example
├── .gitignore
├── .editorconfig
├── .php-cs-fixer.php         ← PSR-12 + small project tweaks
├── phpstan.neon              ← level: 6, includes src/ and bin/
├── phpunit.xml
├── composer.json
├── composer.lock
├── package.json
├── package-lock.json
├── tsconfig.json             ← strict: true, experimentalDecorators for Lit
├── eslint.config.js          ← @typescript-eslint/recommended + extras
├── .prettierrc.json
├── vite.config.ts            ← uses vite-plugin-cesium
├── Makefile                  ← single user-facing entry point for all dev/build/test/deploy commands
├── README.md                 ← living doc: maintained current as each section/subsection lands
├── LICENSE                   ← AGPLv3 full text
├── req_spec.md               (already committed)
│
├── docs/
│   └── phase1.md             ← this file
│
├── public/                   ← Apache DocumentRoot
│   ├── index.php             ← Slim front controller
│   ├── .htaccess             ← rewrites + cache headers
│   ├── favicon.ico
│   ├── robots.txt
│   ├── build/                (generated by `npm run build`; gitignored)
│   └── cesium/               (generated by vite-plugin-cesium; gitignored)
│
├── src/                      ← PHP namespace SatTrackr\
│   ├── App/
│   │   ├── Kernel.php
│   │   ├── Container.php
│   │   └── EnvLoader.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── SpaShellController.php
│   │   │   ├── SatelliteListController.php
│   │   │   ├── SatelliteDetailController.php
│   │   │   ├── SatelliteTleController.php
│   │   │   ├── GroupListController.php
│   │   │   ├── GroupDetailController.php
│   │   │   ├── GroupTlesController.php
│   │   │   ├── SearchController.php
│   │   │   └── AutocompleteController.php
│   │   ├── Middleware/
│   │   │   ├── CorsMiddleware.php
│   │   │   ├── ETagMiddleware.php
│   │   │   ├── ErrorHandlerMiddleware.php
│   │   │   └── JsonResponseMiddleware.php
│   │   └── ResponseFactory.php
│   ├── Models/
│   │   ├── Satellite.php
│   │   ├── TleCurrent.php
│   │   └── TleHistory.php
│   ├── Ingest/
│   │   ├── IngesterContract.php
│   │   ├── CelesTrakIngester.php
│   │   ├── TleParser.php
│   │   ├── OmmJsonParser.php
│   │   └── IngestReport.php
│   ├── Services/
│   │   ├── FreshnessClassifier.php       ← maps epoch age → Fresh/Stale/Aged/Old
│   │   ├── OrbitElementsCalculator.php   ← derives perigee/apogee/period/SMA from TLE
│   │   ├── HttpClientFactory.php         ← Guzzle with retry/timeout defaults
│   │   └── ViteAssetResolver.php         ← reads hot-file or manifest.json
│   ├── Cli/
│   │   ├── ConsoleKernel.php             ← illuminate/console wrapper
│   │   └── Commands/
│   │       ├── MigrateCommand.php        (provided by illuminate/database)
│   │       ├── RollbackCommand.php       (provided)
│   │       ├── MakeMigrationCommand.php  (provided)
│   │       ├── IngestCelesTrakCommand.php
│   │       └── HealthCommand.php
│   └── Support/
│       ├── Json.php                      ← strict json_encode/decode wrappers
│       └── Paginator.php
│
├── resources/                ← Frontend source (compiled by Vite)
│   ├── js/
│   │   ├── main.ts                       ← entry
│   │   ├── App.ts                        ← top-level orchestrator
│   │   ├── api/
│   │   │   └── client.ts                 ← typed fetch wrappers
│   │   ├── globe/
│   │   │   ├── Globe.ts                  ← Cesium viewer setup
│   │   │   ├── PointPrimitiveLayer.ts    ← bulk satellite rendering
│   │   │   ├── SelectionController.ts
│   │   │   └── imagery.ts                ← OSM provider config (no-ion fallback)
│   │   ├── ui/
│   │   │   ├── TopBar.ts
│   │   │   ├── DetailPanel.ts
│   │   │   ├── Search.ts
│   │   │   ├── Timeline.ts
│   │   │   ├── ThemeSwitcher.ts
│   │   │   └── FreshnessBadge.ts
│   │   ├── time/
│   │   │   └── Clock.ts                  ← wraps Cesium.Clock + ±48h bounds
│   │   ├── workers/
│   │   │   └── propagator.ts             ← satellite.js SGP4 worker
│   │   └── types/
│   │       └── api.d.ts                  ← shared TS types matching API responses
│   ├── css/
│   │   ├── main.css                      ← imports themes + components
│   │   ├── themes/
│   │   │   ├── dark.css                  ← default
│   │   │   ├── light.css
│   │   │   └── high-contrast.css
│   │   └── components/                   ← topbar.css, panel.css, timeline.css, …
│   └── views/
│       └── shell.php                     ← rendered by SpaShellController
│
├── migrations/
│   ├── 2026_05_14_000001_create_satellites_table.php
│   ├── 2026_05_14_000002_create_satellites_fts_table.php
│   ├── 2026_05_14_000003_create_tle_current_table.php
│   └── 2026_05_14_000004_create_tle_history_table.php
│
├── bin/
│   ├── console               ← single CLI entry point — `bin/console <command> [args]`
│   └── ingest                ← shim: `bin/ingest celestrak` → `bin/console ingest:celestrak`
│
├── data/                     ← gitignored (sqlite file lives here)
│   └── .gitkeep
│
├── storage/                  ← gitignored (logs, caches)
│   ├── logs/.gitkeep
│   └── cache/.gitkeep
│
└── tests/
    ├── Php/
    │   ├── Unit/
    │   │   ├── TleParserTest.php
    │   │   ├── FreshnessClassifierTest.php
    │   │   └── OrbitElementsCalculatorTest.php
    │   ├── Feature/
    │   │   ├── SatelliteListEndpointTest.php
    │   │   ├── SatelliteDetailEndpointTest.php
    │   │   └── GroupTlesEndpointTest.php
    │   └── bootstrap.php
    └── Js/
        ├── propagator.test.ts
        ├── FreshnessBadge.test.ts
        └── api-client.test.ts
```

---

## § IV — Backend Modules

### Bootstrap & environment

**`src/App/EnvLoader.php`** — layered `.env` loading via `vlucas/phpdotenv`:

1. Load `.env` from repo root with `safeLoad()` (does not fail if missing). This file holds shared defaults and the `APP_ENV` marker (`dev` or `prod`).
2. Read `APP_ENV` from `$_ENV` (defaults to `dev` if unset).
3. Load `.env.{APP_ENV}` with `load()` — this file is **required** and contains the environment-specific overrides (real DB paths, API credentials, Cesium ion token, etc.). Fails fast with a descriptive error if missing.

This means in dev: `.env` says `APP_ENV=dev`, `.env.dev` has dev creds. On DreamHost: `.env` says `APP_ENV=prod`, `.env.prod` has prod creds. No Apache `SetEnv` needed — environment selection is data-driven from the repo itself.

**`src/App/Container.php`** — minimal PHP-DI or hand-rolled container. Wires Eloquent's Capsule manager, Guzzle client, Monolog logger, all controllers and middleware. No service-provider abstraction (overkill for Phase 1).

**`src/App/Kernel.php`** — `Kernel::createApp(): Slim\App` returns the configured Slim app with all routes and middleware bound. `public/index.php` is one-liner: `Kernel::createApp()->run();`. `bin/console` reuses the same kernel for CLI bootstrap.

### HTTP layer

**Routes & controllers** — one controller class per endpoint. Controllers are thin: parse request, call a model/service, return JSON. No business logic in controllers. Route registration lives in `Kernel::registerRoutes(Slim\App $app)`.

**`CorsMiddleware`** — adds `Access-Control-Allow-Origin: *` (per §6, CORS open). Handles OPTIONS preflight.

**`ETagMiddleware`** — computes weak ETag from response body hash, returns `304 Not Modified` if `If-None-Match` matches. Applied to all `/api/v1/*` routes.

**`ErrorHandlerMiddleware`** — catches all throwables, returns JSON error shape `{"error": {"code": "...", "message": "...", "request_id": "..."}}`. Logs to Monolog. In `dev` env includes stack traces; in `prod` does not.

**`JsonResponseMiddleware`** — sets `Content-Type: application/json; charset=utf-8` and `Cache-Control` defaults per route group (60s for live data, 3600s for catalog metadata).

**`SpaShellController`** — serves `resources/views/shell.php` for `/`, `/satellite/{norad}`, and any other non-API path the SPA owns (router catch-all). Uses `ViteAssetResolver` to inject either Vite dev-server URLs (when `public/build/.vite-hot` exists) or built `manifest.json` asset paths.

### Models (Eloquent)

**`Satellite`** — primary model. Relationships: `hasOne TleCurrent`, `hasMany TleHistory`, `belongsToMany SatellitePurpose` (via `satellite_purposes` join table; populated in Phase 2). Casts: `alt_names` JSON, `purposes` exposed as a string array via the join. Scopes: `scopeActive()`, `scopeByCountry()`, `scopeByOrbitClass()`. PHP 8.1+ enums for `object_type`, `status`, `orbit_class`, `size_class`.

**`TleCurrent`** — one row per object. Stores raw line1/line2 plus parsed mean elements (mean_motion, eccentricity, inclination_deg, raan_deg, arg_perigee_deg, mean_anomaly_deg, bstar) and computed derived values (period_min, perigee_km, apogee_km, semimajor_km). Computed fields are populated at ingest time by `OrbitElementsCalculator`.

**`TleHistory`** — append-only. Composite PK `(norad_id, epoch)`. Same columns as `TleCurrent`. UPSERT-by-PK for idempotency. No partitioning in Phase 1 — single table is fine for ~6 months of history before we revisit.

### Ingest

**`IngesterContract`** — interface with one method: `run(IngestReport $report): void`. All ingesters implement it; common code (HTTP fetch with retry, idempotent upsert helpers) lives in an abstract `BaseIngester`.

**`CelesTrakIngester`** — Phase 1's only ingester. Iterates the configured group list, fetches `https://celestrak.org/NORAD/elements/gp.php?GROUP={slug}&FORMAT=JSON` for each, parses the OMM JSON array, upserts into `satellites` (creating missing) and `tle_current` (replacing), and inserts new rows into `tle_history` (skip on duplicate PK). Uses `HttpClientFactory` with 30s timeout and 3 retries with exponential backoff.

Phase 1 group list — comprehensive, all stable CelesTrak GP groups (organized as on celestrak.org). Group config lives in `src/Ingest/CelesTrakGroups.php` so adding/removing is a one-line change.

| Category | Groups |
|---|---|
| Special-Interest | `active`, `stations`, `last-30-days`, `analyst` |
| Weather & Earth-Obs | `weather`, `noaa`, `goes`, `resource`, `sarsat`, `dmc`, `planet`, `spire` |
| Communications | `geo`, `intelsat`, `ses`, `iridium`, `iridium-NEXT`, `starlink`, `oneweb`, `orbcomm`, `globalstar`, `swarm`, `amateur` |
| Navigation | `gnss`, `gps-ops`, `glo-ops`, `galileo`, `beidou`, `sbas`, `musson` |
| Scientific | `science`, `geodetic`, `engineering`, `education` |
| Miscellaneous | `military`, `radar`, `cubesat`, `other` |

That's ~34 groups. Many objects appear in more than one (e.g. an active GPS satellite is in both `active` and `gps-ops`); the upsert-by-norad_id logic dedupes naturally. Estimated full ingest: ~30-40K distinct objects, ~34 HTTP requests at 6h cadence = 136 requests/day. Comfortably under any reasonable rate limit.

**`TleParser`** — validates TLE checksum (line 1 col 69, line 2 col 69), line lengths (69 each), epoch sanity (within ±50 years of now). Rejects with reason; ingester accumulates rejections in `IngestReport`.

**`OmmJsonParser`** — converts CelesTrak's OMM JSON record into our `(satellite, tle_current_data)` tuple. Maps `OBJECT_NAME` → `satellites.name`, `OBJECT_ID` → `intl_designator`, `NORAD_CAT_ID` → `norad_id`, etc. Synthesizes line1/line2 strings from OMM for backward-compat with the SPA propagator (satellite.js accepts both but we store both).

**`IngestReport`** — accumulates counts: `groupsProcessed`, `satellitesUpserted`, `tlesAdded`, `tlesRejected`, `errors[]`. Logged at completion. The §24 "alert on >1% reject rate" is **deferred to Phase 5** — Phase 1 just logs.

### CLI

Single entry point `bin/console <command> [args]`. Commands:
- `migrate` / `migrate:rollback` / `migrate:status` (provided by illuminate/database)
- `make:migration {name}` (provided)
- `ingest:celestrak [--group=slug]` (custom)
- `health` (custom — pings DB, reports row counts, last ingest time)

`bin/ingest celestrak [...]` is a one-liner shim that exec's `bin/console ingest:celestrak ...`. Matches the §23 cron manifest's command form.

---

## § V — Database Schema (SQLite)

All types translated from §5's MySQL flavor per [[sqlite-schema-adaptations]].

### `satellites`

```sql
CREATE TABLE satellites (
  norad_id          INTEGER PRIMARY KEY,           -- NORAD catalog number; INTEGER is 64-bit, ready for 6-digit transition
  intl_designator   TEXT,                          -- YYYY-NNNAAA
  name              TEXT NOT NULL,
  alt_names         TEXT,                          -- JSON array
  object_type       TEXT NOT NULL DEFAULT 'UNKNOWN'
                    CHECK (object_type IN ('PAYLOAD','ROCKET_BODY','DEBRIS','TBA','UNKNOWN')),
  status            TEXT NOT NULL DEFAULT 'UNKNOWN'
                    CHECK (status IN ('ACTIVE','INACTIVE','PARTIALLY_OPERATIONAL','DECAYED','UNKNOWN')),
  operator          TEXT,
  country           TEXT,
  launch_date       TEXT,                          -- ISO-8601 date
  launch_site_id    INTEGER,                       -- FK reserved for Phase 2
  launch_vehicle    TEXT,
  mission           TEXT,
  orbit_class       TEXT NOT NULL DEFAULT 'UNKNOWN'
                    CHECK (orbit_class IN ('LEO','MEO','GEO','HEO','MOLNIYA','SSO','POLAR','GTO','UNKNOWN')),
  rcs_meters        REAL,
  size_class        TEXT CHECK (size_class IN ('SMALL','MEDIUM','LARGE')),
  mass_kg           INTEGER,
  dimensions        TEXT,
  has_3d_model      INTEGER NOT NULL DEFAULT 0,    -- boolean
  image_url         TEXT,
  wikipedia_slug    TEXT,
  decayed_at        TEXT,                          -- ISO-8601 datetime
  created_at        TEXT NOT NULL,
  updated_at        TEXT NOT NULL
);

CREATE INDEX idx_satellites_country     ON satellites(country);
CREATE INDEX idx_satellites_operator    ON satellites(operator);
CREATE INDEX idx_satellites_status_type ON satellites(status, object_type);
CREATE INDEX idx_satellites_orbit_class ON satellites(orbit_class);
CREATE INDEX idx_satellites_launch_date ON satellites(launch_date);
CREATE INDEX idx_satellites_name        ON satellites(name);
```

**`purpose` is modeled as a join table** (per §V's SQLite-friendly translation of §5's SET). Created now, populated in Phase 2 when SATCAT metadata flows in:

```sql
CREATE TABLE satellite_purposes (
  norad_id  INTEGER NOT NULL REFERENCES satellites(norad_id) ON DELETE CASCADE,
  purpose   TEXT NOT NULL
            CHECK (purpose IN ('comms','earth_obs','nav','science','military',
                               'human_sf','weather','station','tech_demo','unknown')),
  PRIMARY KEY (norad_id, purpose)
);

CREATE INDEX idx_satellite_purposes_purpose ON satellite_purposes(purpose);
```

In Phase 1 this table is empty; the API exposes `purpose: []` for every satellite. The `Satellite` Eloquent model has a `purposes()` relation that returns the joined values as a string array.

### `satellites_fts` (FTS5 virtual table for fuzzy name search)

```sql
CREATE VIRTUAL TABLE satellites_fts USING fts5(
  name, intl_designator, operator,
  content='satellites', content_rowid='norad_id',
  tokenize='unicode61 remove_diacritics 2'
);

-- Sync triggers
CREATE TRIGGER satellites_fts_ai AFTER INSERT ON satellites BEGIN
  INSERT INTO satellites_fts(rowid, name, intl_designator, operator)
  VALUES (new.norad_id, new.name, new.intl_designator, new.operator);
END;
CREATE TRIGGER satellites_fts_ad AFTER DELETE ON satellites BEGIN
  INSERT INTO satellites_fts(satellites_fts, rowid, name, intl_designator, operator)
  VALUES ('delete', old.norad_id, old.name, old.intl_designator, old.operator);
END;
CREATE TRIGGER satellites_fts_au AFTER UPDATE ON satellites BEGIN
  INSERT INTO satellites_fts(satellites_fts, rowid, name, intl_designator, operator)
  VALUES ('delete', old.norad_id, old.name, old.intl_designator, old.operator);
  INSERT INTO satellites_fts(rowid, name, intl_designator, operator)
  VALUES (new.norad_id, new.name, new.intl_designator, new.operator);
END;
```

### `tle_current`

```sql
CREATE TABLE tle_current (
  norad_id          INTEGER PRIMARY KEY REFERENCES satellites(norad_id) ON DELETE CASCADE,
  epoch             TEXT NOT NULL,                 -- ISO-8601 with fractional seconds
  line1             TEXT NOT NULL,                 -- 69 chars
  line2             TEXT NOT NULL,                 -- 69 chars
  mean_motion       REAL NOT NULL,
  eccentricity      REAL NOT NULL,
  inclination_deg   REAL NOT NULL,
  raan_deg          REAL NOT NULL,
  arg_perigee_deg   REAL NOT NULL,
  mean_anomaly_deg  REAL NOT NULL,
  bstar             REAL NOT NULL,
  rev_number        INTEGER NOT NULL,
  period_min        REAL NOT NULL,
  perigee_km        REAL NOT NULL,
  apogee_km         REAL NOT NULL,
  semimajor_km      REAL NOT NULL,
  source            TEXT NOT NULL DEFAULT 'CELESTRAK'
                    CHECK (source IN ('CELESTRAK','SPACE_TRACK')),
  updated_at        TEXT NOT NULL
);

CREATE INDEX idx_tle_current_epoch ON tle_current(epoch);
```

### `tle_history`

```sql
CREATE TABLE tle_history (
  norad_id          INTEGER NOT NULL REFERENCES satellites(norad_id) ON DELETE CASCADE,
  epoch             TEXT NOT NULL,
  line1             TEXT NOT NULL,
  line2             TEXT NOT NULL,
  mean_motion       REAL NOT NULL,
  eccentricity      REAL NOT NULL,
  inclination_deg   REAL NOT NULL,
  raan_deg          REAL NOT NULL,
  arg_perigee_deg   REAL NOT NULL,
  mean_anomaly_deg  REAL NOT NULL,
  bstar             REAL NOT NULL,
  rev_number        INTEGER NOT NULL,
  period_min        REAL NOT NULL,
  perigee_km        REAL NOT NULL,
  apogee_km         REAL NOT NULL,
  semimajor_km      REAL NOT NULL,
  source            TEXT NOT NULL DEFAULT 'CELESTRAK',
  ingested_at       TEXT NOT NULL,
  PRIMARY KEY (norad_id, epoch)
);

CREATE INDEX idx_tle_history_epoch ON tle_history(epoch);
```

No partitioning in Phase 1 — revisit if row count justifies it (rough math: 30K objects × 6h cadence × 365 days ≈ 44M rows/year — *might* be fine in SQLite with the (norad_id, epoch) PK; we'll measure before optimizing).

### Pragma settings (applied at connection open)

```sql
PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;            -- concurrent reads during ingest writes
PRAGMA synchronous = NORMAL;          -- WAL + NORMAL is the standard durable+fast pairing
PRAGMA cache_size = -64000;           -- 64MB page cache
PRAGMA temp_store = MEMORY;
PRAGMA mmap_size = 268435456;         -- 256MB memory-mapped I/O
```

---

## § VI — API Endpoints (Phase 1)

All return JSON. All set `Cache-Control: public, max-age=60, stale-while-revalidate=120` unless noted. All carry weak ETags. All accept `Accept-Encoding: gzip`.

### `GET /api/v1/satellites`

Paginated catalog list.

**Query params:**
- `country` — exact match (multi via repeat: `?country=US&country=CN`)
- `operator` — substring match
- `type` — `PAYLOAD`|`ROCKET_BODY`|`DEBRIS`|`TBA`|`UNKNOWN` (multi)
- `status` — `ACTIVE`|`INACTIVE`|`PARTIALLY_OPERATIONAL`|`DECAYED`|`UNKNOWN` (multi)
- `orbit_class` — multi
- `launched_after`, `launched_before` — ISO date
- `q` — fuzzy name search (FTS5)
- `page` (default 1), `limit` (default 100, max 500)

**Response:**
```json
{
  "data": [
    {
      "norad_id": 25544,
      "intl_designator": "1998-067A",
      "name": "ISS (ZARYA)",
      "object_type": "PAYLOAD",
      "status": "ACTIVE",
      "operator": "NASA/Roscosmos",
      "country": "ISS",
      "orbit_class": "LEO",
      "launch_date": "1998-11-20"
    }
  ],
  "meta": { "page": 1, "limit": 100, "total": 30412, "pages": 305 },
  "links": {
    "self": "/api/v1/satellites?page=1",
    "next": "/api/v1/satellites?page=2",
    "prev": null
  }
}
```

### `GET /api/v1/satellites/{norad}`

Full detail. Includes the current TLE inline (saves a round trip for the detail panel).

**Response:**
```json
{
  "data": {
    "norad_id": 25544,
    "intl_designator": "1998-067A",
    "name": "ISS (ZARYA)",
    "alt_names": [],
    "object_type": "PAYLOAD",
    "status": "ACTIVE",
    "operator": "NASA/Roscosmos",
    "country": "ISS",
    "launch_date": "1998-11-20",
    "launch_vehicle": null,
    "mission": null,
    "orbit_class": "LEO",
    "rcs_meters": null,
    "size_class": "LARGE",
    "mass_kg": null,
    "dimensions": null,
    "wikipedia_slug": "International_Space_Station",
    "decayed_at": null,
    "tle_current": {
      "epoch": "2026-05-14T11:23:45.123Z",
      "epoch_age_seconds": 9876,
      "freshness": "FRESH",
      "line1": "1 25544U 98067A   ...",
      "line2": "2 25544  ...",
      "mean_motion": 15.5,
      "eccentricity": 0.0001,
      "inclination_deg": 51.64,
      "raan_deg": 12.34,
      "arg_perigee_deg": 56.78,
      "mean_anomaly_deg": 90.12,
      "bstar": 0.000012,
      "rev_number": 47000,
      "period_min": 92.7,
      "perigee_km": 415,
      "apogee_km": 422,
      "semimajor_km": 6790
    }
  }
}
```

### `GET /api/v1/satellites/{norad}/tle`

Just the current TLE (for clients that already have catalog metadata). Same `tle_current` shape as above. `Cache-Control: public, max-age=60`.

### `GET /api/v1/groups`

Lists predefined groups.

**Response:**
```json
{
  "data": [
    { "slug": "active",   "name": "Active satellites", "count": 8421 },
    { "slug": "starlink", "name": "Starlink",          "count": 6738 },
    { "slug": "stations", "name": "Space stations",    "count": 12 }
  ]
}
```

### `GET /api/v1/groups/{slug}`

Group metadata + member NORAD IDs.

### `GET /api/v1/groups/{slug}/tles`

**The hot endpoint for the SPA.** Returns one large gzipped JSON blob with all TLEs in the group. Designed for client-side bulk propagation.

**Response shape (full keys for readability — gzip absorbs the verbosity):**
```json
{
  "group": "active",
  "generated_at": "2026-05-14T11:30:00Z",
  "count": 8421,
  "tles": [
    {
      "norad_id": 25544,
      "name": "ISS (ZARYA)",
      "line1": "1 25544...",
      "line2": "2 25544...",
      "object_type": "PAYLOAD"
    },
    ...
  ]
}
```

`Cache-Control: public, max-age=300, stale-while-revalidate=600` — staleness up to 5 min is acceptable; the SPA re-propagates from TLE locally so a stale TLE just means slightly less accurate sub-second positions.

### `GET /api/v1/search?q=...`

Universal search. Tries exact NORAD ID match first, then exact intl designator, then FTS5 fuzzy name, then operator. Returns up to 50 results with a `match_type` field per result.

### `GET /api/v1/autocomplete?q=...`

Typeahead. Returns up to 10 results, optimized for low latency (no joins, just FTS5 + indexed lookup). `Cache-Control: public, max-age=300`.

---

## § VII — Frontend Modules

### Build pipeline

**Frontend framework:** [Lit 3.x](https://lit.dev). UI components are `LitElement` custom elements with reactive properties and shadow-DOM-scoped styles. Components register under a `sat-` prefix (e.g. `<sat-detail-panel>`, `<sat-top-bar>`). Picked over vanilla TS because we'll likely want reactive UI by Phase 4 (stats dashboard) anyway — better to start with the right primitives than refactor later.

**`vite.config.ts`** — uses `vite-plugin-cesium` to copy Cesium runtime assets (Workers, Widgets, Assets) into `public/cesium/` and set `window.CESIUM_BASE_URL`. Outputs to `public/build/` with hashed filenames + `manifest.json`. In dev mode, writes a `public/build/.vite-hot` sentinel file that PHP checks to inject dev-server URLs. Reads `CESIUM_ION_TOKEN` from `import.meta.env` (defined via Vite's env handling); empty/unset means OSM-fallback mode.

**`tsconfig.json`** — `strict: true`, plus `experimentalDecorators: true` and `useDefineForClassFields: false` for Lit's `@customElement` / `@property` / `@state` decorators. `target: ES2022`.

### App orchestration

**`main.ts`** — entry. Imports all custom-element definitions (so they self-register), reads the persisted theme from localStorage and applies it to `<html data-theme>` *before* first paint, then mounts `<sat-app>` into `<body>` and parses the URL for any pre-selected satellite.

**`<sat-app>`** (`App.ts`) — top-level Lit component. Holds the singletons (`Globe`, `Clock`, `apiClient`) as instance fields and exposes them via [Lit Context](https://lit.dev/docs/data/context/) so deeply-nested children can consume them without prop drilling. Renders the layout shell:

```ts
@customElement('sat-app')
export class SatApp extends LitElement {
  @state() private selected?: SatelliteDetail;
  // … context providers for globe / clock / apiClient

  render() {
    return html`
      <sat-top-bar @search-select=${this.onSearchSelect}></sat-top-bar>
      <sat-globe @select=${this.onSelect}></sat-globe>
      <sat-detail-panel .satellite=${this.selected}></sat-detail-panel>
      <sat-timeline @scrub=${this.onScrub}></sat-timeline>
    `;
  }
}
```

Cross-component communication uses standard `CustomEvent` bubbling. No external state library — Lit reactive props + context covers Phase 1.

### Globe

**`<sat-globe>`** (`Globe.ts`) — Lit component that *thinly* wraps a plain `Globe` class. The custom element gives us a clean `<sat-globe>` tag and lifecycle hooks (`firstUpdated` for Cesium init, `disconnectedCallback` for teardown), but most logic lives in the underlying `Globe` class because Cesium's imperative API doesn't benefit from Lit's reactive rendering. The element re-emits Cesium events (`select`, `camera-move`) as DOM CustomEvents.

Inside `Globe.ts` (the class, not the element): wraps `Cesium.Viewer`. Init disables most default UI (animation, baseLayerPicker, fullscreenButton, geocoder, homeButton, navigationHelpButton, sceneModePicker, selectionIndicator, timeline) — we provide our own. Configures imagery via `imagery.ts`.

**`imagery.ts`** — returns an imagery provider. If `CESIUM_ION_TOKEN` is set, uses `Cesium.IonImageryProvider` (Bing or similar). Otherwise uses `Cesium.UrlTemplateImageryProvider` pointing at OpenStreetMap (`https://tile.openstreetmap.org/{z}/{x}/{y}.png`) with appropriate attribution rendered in a credit container.

**`PointPrimitiveLayer.ts`** — owns one `Cesium.PointPrimitiveCollection`. Receives the bulk TLE response, spawns `PropagatorWorker`, and on each clock tick requests new positions for all objects from the worker, then updates each primitive's position. Color by type: PAYLOAD cyan `#00d9ff`, ROCKET_BODY amber `#ffb700`, DEBRIS red `#ff3860`, TBA gray `#888`. Pixel size scales with type (payloads slightly larger).

**`SelectionController.ts`** — handles click events on the canvas. Uses `Cesium.Scene.pick` to identify the clicked primitive, looks up the satellite by index, dispatches a `select` CustomEvent on `<sat-globe>`.

**`workers/propagator.ts`** — Web Worker. Receives `{ tles: [{ norad_id, line1, line2 }, ...] }` once. On each `propagate({time: ms})` message, runs satellite.js's `sgp4` for each TLE, returns `{ positions: Float32Array }` (interleaved x/y/z in km, ECI). Main thread converts ECI→ECEF using the time and updates Cesium positions. Float32Array is transferable — no copy.

### UI components

All UI components are `LitElement` subclasses, registered as custom elements with the `sat-` prefix. Reactive `@property` / `@state` drive re-renders; styles are scoped via shadow DOM (`static styles = css\`…\``). Cross-component communication uses bubbling `CustomEvent`s.

**`<sat-top-bar>`** (`TopBar.ts`) — logo (⊕ glyph + wordmark), embedded `<sat-search>`, embedded `<sat-theme-switcher>`. Listens for global `keydown` to bind ⌘K → focus search.

**`<sat-search>`** (`Search.ts`) — autocomplete-driven. `@property() value` for the input, `@state() results` for the dropdown. Debounced 200ms via a private `#debounceTimer`. Renders results as a dropdown with name, monospace NORAD ID, country flag, type badge. Emits `search-select` CustomEvent with the chosen satellite's NORAD ID in `detail`.

**`<sat-detail-panel>`** (`DetailPanel.ts`) — right side, fixed width (380px desktop), slides in via CSS transition when `satellite` property is set. Sections per §10: Identity, Current state (live-updating from the propagator worker), Orbital elements, Raw data (TLE lines with copy buttons). Live-updating fields are bound to a `@state() liveState` that the parent updates each clock tick (~250ms). Uses `<sat-freshness-badge>` for the TLE epoch age.

**`<sat-timeline>`** (`Timeline.ts`) — bottom bar. Slider spans now-7d to now+7d with a yellow warning band beyond ±48h ("no historical TLE in Phase 1 — positions extrapolated"). Play/pause button, speed buttons (0.5x/1x/10x/60x/600x). Emits `scrub` CustomEvent with the new time on user input. Re-renders when the underlying `Clock` ticks.

**`<sat-theme-switcher>`** (`ThemeSwitcher.ts`) — button that opens a dropdown of theme names. Sets `data-theme="dark"|"light"|"high-contrast"` on `<html>` (not on the element itself, since CSS variables need to cascade to all custom elements). Persists choice to `localStorage`. Themes are CSS-variable-driven so swap is instant, no re-render needed.

**`<sat-freshness-badge>`** (`FreshnessBadge.ts`) — small reusable element. `@property({type: String}) epoch` (ISO string). Renders a `<span>` with class `freshness-fresh|stale|aged|old` and label text. Re-evaluates on each clock tick if the host passes a `now` property.

### Time

**`Clock.ts`** — wraps `Cesium.Clock`. Bounds: `now-7d` to `now+7d` (forward-compatible with §11's full historical/forecast range). Default rate: 1x. Emits `tick(time)` on each clock cycle. Note: positions outside ±48h are extrapolated from the current TLE only — Phase 2 will replace this with historical-TLE re-propagation. The Timeline's yellow band makes this user-visible.

### API client

**`api/client.ts`** — typed fetch wrappers. `getSatellite(norad: number): Promise<SatelliteDetail>`, `getGroupTles(slug: string): Promise<GroupTleBundle>`, etc. Throws typed errors on non-2xx.

---

## § VIII — Family Aesthetic Implementation

Per [[visual-identity]]:

**Themes shipped in Phase 1 (3 of the family's 8):**
- `dark` (default) — `#0a0e27` bg, `#e0e6f0` text, `#00d9ff` accent
- `light` — `#fafafa` bg, `#1a1a1a` text, `#0066cc` accent
- `high-contrast` — `#000` bg, `#fff` text, `#ffff00` accent, full WCAG AAA contrast

Solarized Light/Dark, Nord, Dracula, Mono can be added later by dropping new files into `resources/css/themes/` and registering them in `ThemeSwitcher.ts`. Architecture supports them; we just don't ship them in Phase 1 to keep CSS audit small.

**§ symbol** — used in:
- Top-bar nav: `⊕ sat.trackr.live` logo, then `§ catalog`, `§ launches` (placeholder), `§ events` (placeholder)
- Detail panel section headings: `§ Identity`, `§ Current state`, `§ Orbital elements`, `§ Raw data`
- Future stats / about pages

**Glyphs in nav:** `⊕` as the brand mark (Earth), `☀` for theme=light, `☽` for theme=dark, `◐` for theme=high-contrast.

**Tagline:** `Space situational awareness, _legible_` — deliberately echoes cyber.trackr.live's "Compliance, made legible" as a family-aesthetic signal. Used in: page `<title>`, SPA shell `<h1>` subtitle, OG image, footer.

**Freshness mapping (FreshnessBadge):**
- Fresh — epoch <48h old (cyan)
- Stale — 48h–7d (amber, matches §11's first warning)
- Aged — 7–14d (orange)
- Old — >14d (red, matches §11's second warning)

**Monospace usage** (JetBrains Mono): NORAD IDs, intl designators, TLE lines, period values, lat/lon/alt readouts, all numeric data.

**Sans-serif** (Inter): everything else.

---

## § IX — Dev Workflow

The **Makefile is the single user-facing entry point** for every dev/build/test/deploy command. Composer scripts and npm scripts exist (because some tools assume them), but they are implementation details — day-to-day work uses `make`.

### One-time setup

```bash
git clone …
cp .env.example     .env        # then edit: APP_ENV=dev (or prod) + shared defaults
cp .env.dev.example .env.dev    # then edit: dev-specific creds
make install                    # composer install + npm install
make migrate                    # apply migrations to data/sat.db
make ingest                     # populates ~30K satellites from CelesTrak (~30s)
```

### Daily dev

```bash
make dev                        # starts php -S and vite dev server in parallel
```

`make dev` uses `make -j2` semantics under the hood to run both servers concurrently, with output prefixed by `[php]` or `[vite]` so you can tell them apart. Ctrl-C kills both. Open `http://localhost:8000`.

### Makefile target reference

| Target | What it does |
|---|---|
| `make help` | List all targets with short descriptions (default if you just run `make`) |
| `make install` | `composer install && npm install` |
| `make dev` | Start php-S + Vite dev server in parallel |
| `make build` | `npm run build` — production bundle into `public/build/` |
| `make serve` | Just php -S (no Vite); useful for testing the prod-built SPA locally |
| `make migrate` | Apply pending migrations |
| `make rollback` | Roll back the most recent migration batch |
| `make migrate-status` | Show applied vs pending migrations |
| `make make-migration name=add_foo` | Generate a new migration skeleton |
| `make ingest` | Run the CelesTrak ingester for all groups |
| `make ingest-group group=starlink` | Ingest a single group |
| `make health` | Run the `health` CLI command |
| `make lint` | All linters (PHP-CS-Fixer + ESLint + Prettier check) |
| `make lint-php` | PHP-CS-Fixer in dry-run/diff mode |
| `make lint-js` | ESLint over `resources/js` |
| `make lint-fix` | Auto-fix all linters (PHP-CS-Fixer + ESLint --fix + Prettier --write) |
| `make analyze` | PHPStan |
| `make typecheck` | `tsc --noEmit` |
| `make test` | PHPUnit + Vitest |
| `make test-php` | PHPUnit only |
| `make test-js` | Vitest only |
| `make ci` | `lint && analyze && typecheck && test` — what CI would run |
| `make clean` | Remove `public/build/`, `public/cesium/`, `vendor/`, `node_modules/`, caches |
| `make deploy-check` | Dry-run lint of `.htaccess`, env vars, deploy prerequisites |

### Underlying scripts the Makefile invokes

**`composer.json` scripts** (so PHP tooling that auto-discovers Composer scripts still works):
```json
{
  "serve":            "php -S localhost:8000 -t public/",
  "migrate":          "php bin/console migrate",
  "rollback":         "php bin/console migrate:rollback",
  "ingest:celestrak": "php bin/console ingest:celestrak",
  "lint":             "php-cs-fixer fix --dry-run --diff",
  "lint:fix":         "php-cs-fixer fix",
  "analyze":          "phpstan analyse --memory-limit=256M",
  "test":             "phpunit"
}
```

**`package.json` scripts:**
```json
{
  "dev":       "vite",
  "build":     "vite build",
  "preview":   "vite preview",
  "lint":      "eslint resources/js --max-warnings=0",
  "lint:fix":  "eslint resources/js --fix",
  "typecheck": "tsc --noEmit",
  "test":      "vitest run"
}
```

---

## § X — DreamHost Deployment

Out of scope to *execute* in Phase 1 (Robert deploys when he's ready), but the bootstrap should produce a deployable artifact. Notes for when you deploy:

1. **Domain config:** in DreamHost panel, set sat.trackr.live's web directory to `<repo-root>/public/`.
2. **PHP version:** select 8.4 in DreamHost panel.
3. **Environment files:** place two files at repo root, both gitignored, neither in version control:
   - `.env` — `APP_ENV=prod` plus shared defaults
   - `.env.prod` — production credentials (DB path, Cesium ion token, future Space-Track / N2YO / LL2 keys)

   No Apache `SetEnv` needed — the layered loader picks up `APP_ENV` from `.env` and overlays `.env.prod`.
4. **First deploy:**
   ```bash
   ssh dreamhost
   cd ~/sat.trackr.live
   git pull
   make install         # production install — Makefile sets COMPOSER_OPTS for --no-dev
   make build
   make migrate
   make ingest
   ```
5. **Cron** (DreamHost panel or shell crontab):
   ```
   0 */6 * * * cd ~/sat.trackr.live && make ingest >> storage/logs/cron.log 2>&1
   ```
6. **Apache `.htaccess`** in `public/`:
   ```apache
   <IfModule mod_rewrite.c>
     RewriteEngine On
     RewriteCond %{REQUEST_FILENAME} -f [OR]
     RewriteCond %{REQUEST_FILENAME} -d
     RewriteRule ^ - [L]
     RewriteRule ^ index.php [L]
   </IfModule>
   <IfModule mod_headers.c>
     <FilesMatch "\.(js|css|woff2|png|jpg|svg)$">
       Header set Cache-Control "public, max-age=31536000, immutable"
     </FilesMatch>
   </IfModule>
   ```

---

## § XI — Resolved Decisions (rationale log)

The substance of each decision is folded into the relevant section above; this log preserves the reasoning so future readers know *why* a choice was made.

1. **Tagline** → `Space situational awareness, _legible_`. Echoes cyber.trackr.live's "Compliance, made legible" — intentional family-aesthetic signal. *(See §VIII.)*

2. **Frontend framework** → **Lit 3.x**. Originally proposed vanilla TS for Phase 1, but Lit is where we'd land in Phase 4-5 anyway (stats dashboard reactivity); starting with the right primitives avoids a rewrite. *(See §VII.)*

3. **`purpose` representation** → `satellite_purposes` join table. Created in Phase 1 (empty), populated in Phase 2 from SATCAT. Avoids comma-text or speculative name-heuristic seeding. *(See §V.)*

4. **CelesTrak group list** → **all ~33 stable groups**, not the trimmed 17. "We might use them as we get them" — better to ingest broadly and filter in queries than to retroactively add groups. *(See §IV Ingest.)*

5. **Build runner** → **Makefile** as the single user-facing entry point. Composer/npm scripts still exist as underlying targets. *(See §IX.)*

6. **Timeline range** → **±7d slider with yellow warning band beyond ±48h**. Forward-compatible UI; Phase 2 historical TLE backfill makes the band disappear naturally. *(See §VII Timeline + Clock.)*

7. **Bulk-TLE keys** → **full keys** (`norad_id`, `name`, `line1`, `line2`, `object_type`). Readability for API consumers > a few percent of post-gzip size. *(See §VI.)*

8. **Environment selection** → **.env files**, no Apache `SetEnv`. Layered: `.env` provides defaults + `APP_ENV` marker; `.env.{APP_ENV}` overlays env-specific values. *(See §IV Bootstrap & §X.)*

9. **README** → **living doc**, kept current as each section/subsection lands. Not a one-shot bootstrap artifact. Aligns with AGPL's invitation to outside contribution. Saved as durable feedback memory for this project.

10. **Commit cadence** → **granular logical commits per section/subsection**, not one big bootstrap commit. Cleaner blame history. Saved as durable feedback memory for this project.

---

## § XII — Risks & Concerns I Want to Surface

- **Cesium bundle size.** Cesium is ~3MB+ minified. We'll use `vite-plugin-cesium`'s tree-shaking but it remains the dominant bundle. Mitigation: lazy-load Cesium after initial paint, show a wireframe-globe loading state. Worth doing in Phase 1 even though it's listed as Phase 5 polish — first-impression matters.
- **OSM tile attribution requirement.** OSM requires visible attribution. Easy to add a small "© OpenStreetMap" credit in the corner; just need to remember.
- **CelesTrak rate limits.** They don't publish hard limits but they ask you to be considerate. Our 6-hour cadence with 17 groups = 68 requests/day. Comfortably fine.
- **SQLite write contention during ingest.** WAL mode + a single ingest writer should be fine, but if a user lands during an ingest run we want reads to be fast. WAL is the right call for this; mentioned above.
- **TLE precision in `text` columns.** Storing line1/line2 as text preserves all original precision. Storing parsed mean elements as REAL (double) is fine — TLE precision is ~7 decimal digits, double has 15.
- **AGPL compliance in deps.** Need to verify all chosen deps are AGPL-compatible. Slim, illuminate/database, illuminate/console, Guzzle, Monolog, vlucas/phpdotenv: all MIT/BSD — fine. Cesium.js: Apache-2.0 — fine. satellite.js: MIT — fine. No conflicts spotted.

---

## § XIII — What "Phase 1 Done" Looks Like, Concretely

You open `https://sat.trackr.live` (or `localhost:8000` in dev). The page loads with a dark interface, the `⊕ sat.trackr.live` wordmark and the tagline `Space situational awareness, _legible_` in the top-left, a search box centered, and a theme switcher at right. Below: a Cesium globe rotating gently, populated with thousands of cyan/amber/red dots representing every active satellite, rocket body, and tracked debris. The bottom of the screen has a play/pause control and a slider spanning `now − 7d ←  now  → now + 7d`, with a soft yellow band shaded over the regions beyond ±48h labeled "extrapolated — no historical TLE in Phase 1".

You click a dot near the equator. The right side slides in a panel: `§ Identity` shows `STARLINK-3142`, country `US`, operator `SpaceX`, monospace NORAD ID `54321`. `§ Current state` shows lat/lon/altitude updating once a second. `§ Orbital elements` shows period, inclination, perigee, apogee. `§ Raw data` shows the two TLE lines with a copy button. A small `Fresh` badge sits next to the epoch.

You hit ⌘K. A search dropdown appears. You type `iss`. The first result is `ISS (ZARYA)` with monospace `25544` and a country flag. You hit Enter. The camera flies to it. The detail panel updates.

You drag the timeline back 6 hours (still inside the un-banded zone). Every dot smoothly rewinds to where it was 6 hours ago. You hit play at 60x speed. You watch a Starlink train march across the Pacific.

That's Phase 1.

---

*End of Phase 1 design.*
