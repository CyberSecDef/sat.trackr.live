# Phase 2 Design — sat.trackr.live

**Status:** Draft for review
**Scope:** §28 of `req_spec.md` Phase 2 (Data depth) + the SATCAT prerequisite that unblocks Phase-1 detail-panel "—" placeholders
**Target:** A catalog where every satellite carries operator/country/launch metadata, plus three new feature surfaces (Launches, Reentries, Pass predictions with observer location) wired through to the SPA and the `/text` fallback.

---

## § I — Phase 2 Goals & Acceptance

### Goals

1. **Stop showing "—" in the detail panel.** SATCAT ingest populates operator, country, launch date, launch vehicle, mass, RCS, status, decayed_at across the existing `satellites` table.
2. **Launches surface.** Both upcoming countdowns and recent (90-day) launch history, sourced from Launch Library 2, with a dedicated SPA view + text fallback.
3. **Reentries surface.** Predicted decays from Space-Track TIP messages + CelesTrak SATCAT decay dates, with a dedicated view + risk scoring.
4. **Pass predictions.** Given an observer location, compute "next 5 passes" for any selected satellite — showing rise / peak / set times, max elevation, and visual magnitude when N2YO data is available.
5. **Observer location.** Browser geolocation API, manual lat/lon entry, optional Nominatim city search; persisted to localStorage; surfaced as a pill in the top bar.

### Acceptance criteria

- [ ] `make ingest:satcat` populates SATCAT metadata into `satellites` for ~30K objects in <60s; idempotent re-runs preserve CelesTrak-only fields.
- [ ] Detail panel's §Identity section shows real operator + country + launch date + mass for ISS, Hubble, GPS, Starlink samples.
- [ ] `/text/satellite/{norad}` shows the same enriched fields.
- [ ] `make ingest:ll2` populates `launches` + `launch_sites` (next 50 upcoming + last 100 previous) in <30s.
- [ ] `/api/v1/launches/upcoming` returns countdown-friendly JSON; `/launches` SPA route renders sortable list with countdowns; `/text/launches` mirrors as plain HTML.
- [ ] `make ingest:spacetrack` populates `reentries` from TIP messages; ~5-20 records typical.
- [ ] `/api/v1/reentries/upcoming` returns predicted decays; `/decays` view shows them with risk scores; `/text/decays` mirrors.
- [ ] `/api/v1/satellites/{norad}/passes?lat=X&lon=Y&days=7` returns up to 7 days of pass predictions in <2s (cold), <50ms (cached); interactive SPA pass list updates in <250ms when observer changes.
- [ ] Detail panel "§ Visibility from observer" section populated when observer is set; shows next 5 passes + a quick "currently above horizon: Y/N + el/az" line.
- [ ] Top bar shows a `📍 lat,lon` observer pill; tap to set/change.
- [ ] All new code passes `make ci` (lint + analyze + typecheck + test). Test count grows by ~40 (estimate ~30 PHP + ~10 JS).
- [ ] `README.md` reflects the new surfaces; `docs/phase2.md` (this doc) becomes the design reference for chunks 1-7.

### Explicitly NOT in Phase 2

Defer to later phases:
- Conjunctions (req_spec §16 — Phase 4 per §28)
- Space weather widget + aurora overlay (Phase 4)
- Stats dashboard (Phase 4)
- Events feed + RSS (Phase 4)
- Ground station markers + sensor cones (Phase 3 showcase visuals)
- Light pollution overlay, day/night terminator polish, Milky Way (Phase 3)
- Sky chart visualization for individual passes (Phase 3 — pass list table is enough for Phase 2)
- AMSAT/SatNOGS radio info (Phase 5)
- 3D models for ISS/Hubble/etc. (Phase 3)
- Orbit ribbons, ground tracks, footprints (Phase 3)
- ICS calendar export for passes (Phase 3+)
- Mobile PWA optimization (Phase 5)

---

## § II — Architecture (Phase 2 slice)

```
┌─────────── New cron-driven ingesters ──────────────────────────────┐
│  bin/console ingest:satcat           (CelesTrak SATCAT, daily)     │
│  bin/console ingest:ll2 upcoming     (LL2, hourly)                 │
│  bin/console ingest:ll2 previous     (LL2, every 6h)               │
│  bin/console ingest:spacetrack decay (Space-Track decay/TIP, 12h)  │
└───────────────────────────────────────────┬────────────────────────┘
                                            │
            ┌───────────────────────────────┴───────────────────────┐
            │                                                       │
┌───────────▼─────────────────┐               ┌───────────────────▼─────────┐
│  SQLite                     │               │  External APIs               │
│   satellites  (ENRICHED)    │               │  - celestrak.org/satcat       │
│   tle_current               │               │  - ll.thespacedevs.com/2.2.0/ │
│   tle_history               │               │  - www.space-track.org        │
│   satellite_purposes (POP)  │               │  - api.n2yo.com/rest/v1/      │
│   group_membership          │               └─────────────────────────────┘
│                             │
│   launches      (NEW)       │               ┌─────────────────────────────┐
│   launch_sites  (NEW)       │               │  Pass calculation           │
│   reentries     (NEW)       │               │  ────────────────────────── │
│   pass_cache    (NEW)       │               │  Browser:  satellite.js     │
└─────────────────────────────┘               │            (already loaded) │
                                              │  Server:   PHP exec()s a    │
                                              │            small Node       │
                                              │            subprocess       │
                                              │            running          │
                                              │            satellite.js     │
                                              │            (same algo,      │
                                              │            consistent       │
                                              │            results)         │
                                              └─────────────────────────────┘
                                            │
            ┌───────────────────────────────┴───────────────────────┐
            │                                                       │
┌───────────▼──────────────┐                  ┌───────────────────▼─────────┐
│  Slim API additions      │                  │  SPA additions              │
│                          │                  │                             │
│  /api/v1/launches/       │                  │  /launches  (SPA route)     │
│    upcoming, recent, {id}│                  │  /decays    (SPA route)     │
│  /api/v1/launch-sites    │                  │                             │
│  /api/v1/reentries/      │                  │  Detail panel:              │
│    upcoming, {norad}     │                  │   - Identity now populated  │
│  /api/v1/satellites/     │                  │   - Visibility from observer│
│    {norad}/passes        │                  │     section (passes + above │
│                          │                  │     horizon)                │
│                          │                  │                             │
│                          │                  │  Top bar: observer pill     │
│                          │                  │                             │
│  Text additions:         │                  │                             │
│  /text/launches          │                  │                             │
│  /text/decays            │                  │                             │
└──────────────────────────┘                  └─────────────────────────────┘
```

The boundary stays sharp: server hands out catalog rows and TLE strings (now enriched with SATCAT fields). Pass predictions are computed in two places using the **same satellite.js implementation** — browser for interactive UI, Node-via-PHP-exec for server-rendered JSON / shareable URLs.

---

## § III — New schema (5 migrations)

All types per [[sqlite-schema-adaptations]] (CHECK constraints instead of MySQL ENUM, TEXT for ISO datetimes, JSON1 for JSON columns).

### Migration 7: `satcat_columns_present_check` (no-op confirmation)

The existing `satellites` table already has every SATCAT-derived column — `operator`, `country`, `launch_date`, `launch_vehicle`, `launch_site_id`, `mass_kg`, `dimensions`, `rcs_meters`, `size_class`, `decayed_at`. SATCAT ingest is **pure UPSERT** with no schema change. This is documentation only — no actual SQL.

Skip writing this as a migration; mention in the chunk-1 commit message.

### Migration 8: `create_launch_sites_table`

```sql
CREATE TABLE launch_sites (
  id          INTEGER PRIMARY KEY,           -- LL2 numeric ID
  name        TEXT NOT NULL,
  latitude    REAL,
  longitude   REAL,
  country     TEXT,
  operator    TEXT,
  description TEXT,
  url         TEXT,                          -- LL2 record URL
  updated_at  TEXT NOT NULL
);

CREATE INDEX idx_launch_sites_country ON launch_sites(country);
```

### Migration 9: `create_launches_table`

```sql
CREATE TABLE launches (
  id                   TEXT PRIMARY KEY,     -- LL2 UUID
  name                 TEXT NOT NULL,
  net                  TEXT NOT NULL,        -- "no earlier than" ISO datetime
  status               TEXT NOT NULL DEFAULT 'TBD'
                       CHECK (status IN ('GO','TBD','HOLD','SUCCESS','FAILURE','PARTIAL_FAILURE','UNKNOWN')),
  provider             TEXT,
  vehicle              TEXT,
  pad_id               INTEGER REFERENCES launch_sites(id) ON DELETE SET NULL,
  mission_name         TEXT,
  mission_type         TEXT,
  orbit_target         TEXT,
  customer             TEXT,
  webcast_url          TEXT,
  image_url            TEXT,
  description          TEXT,
  associated_norad_ids TEXT,                 -- JSON array, populated lazily once TLEs appear
  updated_at           TEXT NOT NULL
);

CREATE INDEX idx_launches_net    ON launches(net);
CREATE INDEX idx_launches_status ON launches(status);
CREATE INDEX idx_launches_pad    ON launches(pad_id);
```

### Migration 10: `create_reentries_table`

```sql
CREATE TABLE reentries (
  id                      INTEGER PRIMARY KEY AUTOINCREMENT,
  norad_id                INTEGER NOT NULL REFERENCES satellites(norad_id) ON DELETE CASCADE,
  predicted_decay         TEXT NOT NULL,     -- ISO datetime
  confidence_window_hours REAL,              -- ± hours
  source                  TEXT NOT NULL DEFAULT 'SPACE_TRACK_TIP'
                          CHECK (source IN ('SPACE_TRACK_TIP','CELESTRAK_SATCAT','COMPUTED')),
  risk_score              REAL,              -- 0.0–5.0; nullable
  raw_message             TEXT,              -- JSON of original TIP/SATCAT record
  created_at              TEXT NOT NULL,
  updated_at              TEXT NOT NULL
);

CREATE INDEX idx_reentries_predicted_decay ON reentries(predicted_decay);
CREATE INDEX idx_reentries_norad           ON reentries(norad_id);
CREATE UNIQUE INDEX idx_reentries_norad_source ON reentries(norad_id, source);
```

The `(norad_id, source)` unique index lets the ingester `INSERT … ON CONFLICT(norad_id, source) DO UPDATE SET …` to refresh predictions without duplicating rows.

### Migration 11: `create_pass_cache_table`

```sql
CREATE TABLE pass_cache (
  cache_key    TEXT PRIMARY KEY,             -- "{norad}:{lat:.3f}:{lon:.3f}:{day}"
  norad_id     INTEGER NOT NULL,
  observer_lat REAL NOT NULL,
  observer_lon REAL NOT NULL,
  observer_alt REAL NOT NULL DEFAULT 0,
  day          TEXT NOT NULL,                -- YYYY-MM-DD
  passes_json  TEXT NOT NULL,                -- JSON: list of {rise,peak,set,max_el,...}
  computed_at  TEXT NOT NULL,
  expires_at   TEXT NOT NULL                 -- 6h after computed_at per req_spec §4.3
);

CREATE INDEX idx_pass_cache_expires ON pass_cache(expires_at);
CREATE INDEX idx_pass_cache_norad   ON pass_cache(norad_id);
```

A small daily `bin/console pass-cache:prune` cron entry sweeps expired rows.

### Migration 12: (optional) `add_pass_cache_satellite_index`

Not strictly needed; the indexes above cover the lookup paths.

---

## § IV — New backend modules

### Ingest

**`src/Ingest/SatCatIngester`** — fetches one CelesTrak SATCAT JSON per group (or a single full pull from `https://celestrak.org/satcat/records.php?FORMAT=JSON` if available), maps SATCAT fields to our `satellites` columns, UPSERTs preserving CelesTrak-populated `name` + `intl_designator` (idempotent, safe to re-run hourly even though daily is enough). Status code mapping: `+/P/B → ACTIVE`, `S/X/- → INACTIVE`, `D → DECAYED`, `?/null → UNKNOWN`. Owner code stays as-is in `country`.

Purposes derivation: a small heuristic mapper consults `group_membership` per satellite. e.g.:
- group ∈ {gps-ops, glo-ops, galileo, beidou, gnss, sbas, musson} → 'nav'
- group ∈ {weather, noaa, goes, dmc, planet, spire, sarsat} → 'earth_obs'
- group ∈ {intelsat, ses, iridium, iridium-NEXT, starlink, oneweb, orbcomm, globalstar, swarm, amateur} → 'comms'
- group ∈ {military, radar} → 'military'
- group ∈ {science, geodetic} → 'science'
- group ∈ {stations} → 'station'
- group ∈ {cubesat, engineering, education} → 'tech_demo'
- otherwise → 'unknown'

A satellite in multiple groups gets multiple purpose rows (per the chunk-2 join-table design).

**`src/Ingest/LaunchLibraryClient`** — Guzzle wrapper around `ll.thespacedevs.com/2.2.0/`. Auth via `Authorization: Token {LL2_API_TOKEN}` header (paid personal tier). Endpoints used: `/launch/upcoming/?limit=50`, `/launch/previous/?limit=100&ordering=-net`, `/pad/?limit=200` (one-shot during chunk 3), `/agencies/?limit=200` (one-shot).

**`src/Ingest/LaunchLibraryIngester`** — uses the client to refresh launches + launch_sites. Maps LL2's nested response shape to our schema. Detects "did this launch produce a satellite already in our catalog?" by joining post-launch on `launch_date ± 24h` against satellites + populating `launches.associated_norad_ids` as a JSON array. Refreshed lazily on each upcoming-pull.

**`src/Ingest/SpaceTrackClient`** — cookie-based session via PHP cURL with a CookieJar. Login URL: `https://www.space-track.org/ajaxauth/login`. Auth payload: `identity={user}&password={pass}`. Cookie persists in a temp file across multiple queries within an ingest run; re-login on next ingest invocation. Aggressive retry-on-429 with backoff per Space-Track's etiquette.

**`src/Ingest/SpaceTrackIngester`** — pulls TIP messages: `GET /basicspacedata/query/class/tip/orderby/INSERT_EPOCH desc/format/json` (returns ~20 most recent TIPs typically). Maps TIP fields (NORAD_CAT_ID, INSERT_EPOCH, MSG_EPOCH, NEXT_REPORT, DECAY_EPOCH, WINDOW, REV, DIRECTION, LAT, LON, INCL, etc.) to our `reentries` schema. UPSERTs by `(norad_id, source)`.

### CLI commands

- `bin/console ingest:satcat` — CelesTrak SATCAT
- `bin/console ingest:ll2 [--mode=upcoming|previous|both] [--refresh-pads]` — Launch Library 2
- `bin/console ingest:spacetrack [--mode=decay|tip|all]` — Space-Track
- `bin/console pass-cache:prune` — sweep expired pass_cache rows

Makefile gets matching targets: `make ingest-satcat`, `make ingest-ll2`, `make ingest-spacetrack`, `make pass-cache-prune`.

### Services

**`src/Services/PassCalculator`** — server-side pass calculation. Takes a TLE record + observer (lat/lon/alt) + time window, returns a list of pass objects `{rise_at, peak_at, set_at, max_elevation_deg, max_azimuth_deg, start_az_deg, end_az_deg}`. Implementation: shells out to a tiny `bin/sgp4-passes` Node script (input/output via stdin/stdout JSON) that uses satellite.js. Output is cached in `pass_cache` keyed on `(norad, lat-3dp, lon-3dp, day)` for 6h per req_spec §4.3.

**`bin/sgp4-passes`** — small standalone Node script (~100 lines) that reads `{tles: [{line1,line2}], observer: {lat,lon,alt}, durationDays: 7}` from stdin, runs satellite.js's pass-prediction routines, writes the result to stdout as JSON. Same satellite.js version as the browser bundle (kept in sync via a small build step). Deployed to DreamHost as part of the `make build` artifact.

**`src/Services/PassCache`** — read-through cache wrapper around `pass_cache`. Methods: `get(norad, lat, lon, day): ?array`, `put(...)`, `prune(): int`.

**`src/Services/N2YOClient`** — Guzzle wrapper for `api.n2yo.com/rest/v1/satellite/visualpasses/...`. Used in chunk 6 to enhance pass results with magnitude estimates. Daily quota guard: if exhausted, log warn + skip enhancement (passes still surface, magnitude column shows "—").

### Controllers

**`src/Http/Controllers/`** — new JSON endpoints:
- `LaunchListController` — `/api/v1/launches/upcoming` and `/api/v1/launches/recent`
- `LaunchDetailController` — `/api/v1/launches/{id}`
- `LaunchSiteListController` — `/api/v1/launch-sites`
- `ReentryListController` — `/api/v1/reentries/upcoming?within_hours=N`
- `ReentryDetailController` — `/api/v1/reentries/{norad}`
- `SatellitePassesController` — `/api/v1/satellites/{norad}/passes?lat=&lon=&days=`

**`src/Http/Controllers/Text/`** — text fallback parallels:
- `TextLaunchesController` — `/text/launches`
- `TextLaunchDetailController` — `/text/launches/{id}`
- `TextDecaysController` — `/text/decays`

---

## § V — API endpoints

All Phase-2 endpoints follow chunk-4's middleware stack (CORS open, ETag, JSON Content-Type, Cache-Control). Cache TTLs vary per route.

### Launches

```
GET /api/v1/launches/upcoming?limit=50
  Cache-Control: public, max-age=300, stale-while-revalidate=600
  → { "data": [{id, name, net, status, provider, vehicle, pad, mission, ...}] }

GET /api/v1/launches/recent?limit=100&days=90
  Cache-Control: public, max-age=3600
  → same shape; ordered by net DESC

GET /api/v1/launches/{id}
  Cache-Control: public, max-age=300
  → { "data": { …full LL2 record… , associated_norad_ids: [...] } }

GET /api/v1/launch-sites
  Cache-Control: public, max-age=86400
  → { "data": [{id, name, latitude, longitude, country, operator}] }
```

### Reentries

```
GET /api/v1/reentries/upcoming?within_hours=168
  Cache-Control: public, max-age=600
  → { "data": [{id, norad_id, name, predicted_decay, confidence_window_hours, risk_score, source}] }

GET /api/v1/reentries/{norad}
  Cache-Control: public, max-age=300
  → { "data": { …reentry detail + linked satellite summary… } }
```

### Pass predictions

```
GET /api/v1/satellites/{norad}/passes?lat=51.5&lon=-0.1&alt=10&days=7&min_elevation_deg=10
  Cache-Control: public, max-age=300, stale-while-revalidate=600
  → { "data": [
        {
          rise_at:  "2026-05-15T03:14:32Z",
          peak_at:  "2026-05-15T03:18:11Z",
          set_at:   "2026-05-15T03:21:50Z",
          duration_seconds: 438,
          max_elevation_deg: 47.3,
          rise_azimuth_deg:  324.0,
          set_azimuth_deg:    61.5,
          visible: true,        // sunlit + observer in darkness
          magnitude: 2.4        // null if N2YO quota exhausted
        }, …
      ],
      "meta": { "norad_id": …, "observer": {…}, "computed_at": "…", "from_cache": true } }
```

The endpoint resolves the request to a `pass_cache` lookup first; on miss, runs `PassCalculator` (Node subprocess), writes to cache, returns. Magnitude enrichment from N2YO is best-effort.

---

## § VI — Frontend additions

### `<sat-detail-panel>` enrichments (chunk 2)

Already-present "—" fields populate from SATCAT data automatically — no panel changes needed. Add three new optional sections:

- **§ Visibility from observer** (rendered when observer is set):
  - Above horizon now? Yes/No, with elevation° + azimuth°
  - Range km
  - Magnitude (if N2YO data available)
  - Visible to naked eye now? Yes/No (sunlit + observer in darkness)
  - "Next 5 passes" sub-table (rise / peak / set / max el / direction)
- **§ Predicted decay** (rendered when there's a row in `reentries`):
  - Predicted at + confidence window
  - Risk score (1-5 with tooltip)
  - Source badge (Space-Track TIP vs CelesTrak SATCAT)
- **§ Launch** (rendered when `launches.associated_norad_ids` includes this NORAD):
  - Launch name + provider + vehicle + pad
  - Net + status
  - Link to `/launch/{id}` detail page

### Observer location handling (chunk 5)

**`resources/js/observer/Observer.ts`** — small singleton:
- `getCurrent(): Observer | null` (lat/lon/alt/source/setAt)
- `setManual(lat, lon, alt = 0)` — persists to localStorage
- `requestGeolocation(): Promise<Observer>` — wraps `navigator.geolocation.getCurrentPosition`
- `searchCity(q): Promise<Result[]>` — Nominatim, 1 req/sec rate-limited (only fired on explicit search)
- `subscribe(callback)` — observers (panel, top bar) react to changes

**`<sat-observer-pill>`** — top-bar pill `📍 51.5°, −0.1° (London)` (or just `📍 set location` if unset). Click opens a small popover with three input modes: Use my location (geolocation), City search (Nominatim), Manual lat/lon.

### Launches view (chunk 3)

**`/launches` SPA route** — server-rendered shell + client-hydrated. Layout:
- Sortable list (sortable by NET / provider / status)
- Each row: countdown timer (`DDD:HH:MM:SS`), status badge, name, vehicle, pad, customer
- Click row → `/launch/{id}` detail page (mini-map of pad, webcast embed when available, payload list with NORAD links once cataloged)
- Filter: status, provider, time window
- "Recent launches" toggle pulls last 90 days

**`/text/launches`** — same data, plain table. Detail page at `/text/launches/{id}`.

### Reentries view (chunk 4)

**`/decays` SPA route** — list of upcoming reentries:
- Each row: object name + NORAD, predicted time + countdown, confidence band, risk score (1-5), source
- Reentry corridor visualization on a small inset globe (later — Phase 2 ships the table only; corridor is Phase 3)
- "Notable" reentries flagged (large mass, uncontrolled)

**`/text/decays`** — same as table.

### Cron entries (deploy-time)

Per req_spec §23, append to DreamHost crontab:

```
0 4 * * *     cd ~/sat.trackr.live && make ingest-satcat       >> storage/logs/cron.log 2>&1
0 * * * *     cd ~/sat.trackr.live && make ingest-ll2 MODE=upcoming  >> storage/logs/cron.log 2>&1
0 */6 * * *   cd ~/sat.trackr.live && make ingest-ll2 MODE=previous  >> storage/logs/cron.log 2>&1
0 */12 * * *  cd ~/sat.trackr.live && make ingest-spacetrack   >> storage/logs/cron.log 2>&1
30 4 * * *    cd ~/sat.trackr.live && make pass-cache-prune    >> storage/logs/cron.log 2>&1
```

---

## § VII — Pass-prediction architecture

**Decision (locked at chunk-1 design):** hybrid — browser computes for interactive UI; server computes for `/api/...` endpoint.

### Browser path (instant)

When the detail panel is open and an observer is set:
- The propagator worker is already running for the globe at 4Hz
- Add a side-channel: when the user opens a panel, send a `compute_passes(norad, observer, durationDays=7)` message to the worker
- Worker uses satellite.js's pass-prediction routines (built into the library)
- Returns within ~50ms for a 7-day window
- Renders into the §Visibility section

### Server path (`/api/v1/satellites/{norad}/passes`)

Same algorithm via Node subprocess:
1. Load TLE from DB (we have it)
2. Hash `(norad, lat-3dp, lon-3dp, day)` → look up in `pass_cache`
3. Cache hit (and not expired) → return immediately
4. Cache miss → spawn `bin/sgp4-passes` Node subprocess:
   ```bash
   echo '{"tles":[…], "observer":{lat,lon,alt}, "days":7}' | node bin/sgp4-passes
   ```
5. Node script imports `satellite.js`, runs the same pass calc as the browser, writes JSON to stdout
6. PHP captures stdout, parses, writes to `pass_cache`, returns
7. Process spawn overhead: ~50-100ms cold; cache hit: <5ms

**Why not pure PHP SGP4?** A faithful PHP port would be 500-1000 lines of math (Hermitian matrices, Brouwer model, etc.) and error-prone. Node subprocess reuses the exact algorithm we trust client-side. Process overhead is fine because we cache 6h per (sat, location, day).

**N2YO magnitude enrichment** — after computing passes, fire a single N2YO `visualpasses` call per (sat, location) and merge magnitude into our pass list. Best-effort; degrade silently on quota exhaustion.

---

## § VIII — Chunk plan

| # | Chunk | Net new code | Depends on |
|---|---|---|---|
| 1 | Schema + SATCAT ingester | migrations 8-12, SatCatIngester, ingest:satcat command, status/purpose mappers, ~10 PHPUnit tests | nothing |
| 2 | SATCAT-enriched API + detail panel + text | controller polish (no new routes, just data flowing), README + screenshots refresh | chunk 1 |
| 3 | LL2 ingester + Launches API + view | LaunchLibraryClient, LaunchLibraryIngester, 3 launch controllers + 1 launch-sites, /launches SPA route, /text/launches + detail, ~12 tests | chunks 1-2 |
| 4 | Space-Track ingester + Reentries API + view | SpaceTrackClient, SpaceTrackIngester, 2 reentry controllers, /decays SPA route, /text/decays, ~10 tests | chunk 1 |
| 5 | Observer location handling | Observer service, `<sat-observer-pill>`, Nominatim wrapper, localStorage persistence, top-bar integration, ~6 Vitest tests | chunks 1-2 |
| 6 | Pass predictions (calc + UI) | `bin/sgp4-passes` Node script, PassCalculator service, PassCache, SatellitePassesController, N2YOClient enrichment, detail-panel "Visibility" section, browser worker pass path, ~15 tests | chunks 1, 5 |
| 7 | CelesTrak FORMAT=JSON migration + Phase 2 polish + cron + Phase 3 design | TleParser/OmmJsonParser switch from FORMAT=TLE to FORMAT=JSON (OMM) — works past the 6-digit NORAD ID transition (~July 2026); cron-entries doc update, README closes Phase 2, drafts `docs/phase3.md` outline | all prior |

Estimated 35-40 new tests, bringing total from 77 → ~115.

---

## § IX — Dev workflow updates

### Env credentials

`.env.dev` and `.env.prod` need three new variables (gitignored as before):

```
SPACE_TRACK_USER="..."
SPACE_TRACK_PASS="..."
N2YO_API_KEY="..."
LL2_API_TOKEN="..."   # paid personal tier; "Token …" header
```

`.env.dev.example` and `.env.prod.example` (committed) get the keys with empty values + comments pointing at sign-up URLs.

### Node on the deploy box

`bin/sgp4-passes` requires Node at runtime in production. Either:
- DreamHost VPS already has Node (need to verify)
- We bundle satellite.js + a Node binary in the Phase-2 deploy artifact (heavier)

Most likely DreamHost VPS has Node (we use `npm` for the SPA build); Robert to confirm.

### Makefile additions

```makefile
ingest-satcat:        ; php bin/console ingest:satcat
ingest-ll2:           ; php bin/console ingest:ll2 --mode=$(or $(MODE),both)
ingest-spacetrack:    ; php bin/console ingest:spacetrack --mode=$(or $(MODE),all)
pass-cache-prune:     ; php bin/console pass-cache:prune
ingest-all:           ; @make ingest && make ingest-satcat && make ingest-ll2 MODE=both && make ingest-spacetrack
```

---

## § X — Resolved Decisions (rationale log)

The substance of each decision is folded into the relevant section above; this log preserves the reasoning so future readers know *why* a choice was made.

1. **Pass cache TTL** → **6h** per (sat, location, day) per req_spec §4.3. *(See §III migration 11 + §VII server path.)*

2. **Observer location MVP scope** → include **Nominatim city search** in chunk 5 alongside browser geolocation + manual lat/lon + localStorage. Rate-limited to 1 req/sec per Nominatim's policy; only fired on explicit user search. *(See §VI Observer location handling.)*

3. **Launches view route** → `/launches` SPA route + `/text/launches`. *(See §VI Launches view.)*

4. **Reentries view route** → `/decays` SPA route + `/text/decays` per req_spec §15. *(See §VI Reentries view.)*

5. **Pass server-side architecture** → **Node subprocess** via PHP `proc_open`. Reuses satellite.js (same algorithm as the browser); ~50ms cold-spawn overhead is acceptable since we cache 6h per (sat, location, day). PHP SGP4 port deferred indefinitely — too much surface area for too little win. *(See §VII server path + §IX Node-on-deploy notes.)*

6. **SATCAT status code mapping** → as proposed: `+/P/B → ACTIVE`, `S/X/- → INACTIVE`, `D → DECAYED`, `?/null → UNKNOWN`. *(See §IV SatCatIngester.)*

7. **Purpose derivation** → heuristic from `group_membership` join (mapping listed in §IV under SatCatIngester). A satellite in multiple groups gets multiple purpose rows. Cleaner data sources can replace this in a future phase if needed. *(See §IV.)*

8. **CelesTrak FORMAT migration** → switch the CelesTrak ingester to **FORMAT=JSON (OMM)** before NORAD IDs cross 6 digits. **Lands in chunk 7** (Phase 2 polish) — tangential to Phase 2's data-depth thrust but keeps the migration in scope before the ~6-8 week deadline. The chunk 7 work covers TleParser/OmmJsonParser refactor + tests. *(Updated chunk plan in §VIII.)*

9. **Decayed satellites on the globe** → hide from `/api/v1/groups/{slug}/tles` entirely. After SATCAT marks them `status=DECAYED`, the bulk feed filters `status != 'DECAYED'`. The globe instantly loses a few thousand stale dots — cleanup, not regression. *(See §VI globe rendering note.)*

10. **Phase 2 commit cadence** → chunk-by-chunk with explicit OK pause, same as Phase 1. *(Workflow per [[feedback-remote-review-workflow]].)*

---

## § XI — Risks & Concerns

- **Space-Track rate limiting.** They throttle aggressively (~30 req/min for the free tier). Single-process ingest with sleep-between-queries should stay under. We do NOT bulk-fetch historical TLEs — lazy-fetch on demand only (per [[tech-stack-decisions]]).
- **N2YO daily quota (1000/hr).** Visual magnitude enrichment is per-(sat, location) — can burn through quota if many users probe many satellites. Hard cap with graceful degradation.
- **LL2 paid tier costs.** Robert's $5/mo personal tier removes the free 15-req/hr limit. At our cadence (1 req/hr upcoming + 4 req/day previous + 1 req/month for pads/agencies), we're well under any reasonable threshold.
- **Node subprocess overhead.** ~50-100ms cold spawn per uncached pass query. Cache hides this for repeat queries. If users hammer fresh sat/observer combos, we could pre-warm the worker (chunk-7 polish) — out of Phase 2 scope.
- **Schema migration on existing data.** Phase 2 only ADDS tables; SATCAT enrichment UPDATES existing satellites rows but doesn't change the schema. So `make migrate` after Phase 2 deploy is purely additive — safe to run on the populated `data/sat.db` without losing the 15K-row catalog.
- **Decayed satellites in old TLE history.** If we hide DECAYED from the bulk feed (per Open Decision #9), the globe instantly loses a few thousand dots. Cleanup, not regression.
- **DreamHost Node availability.** Need to confirm Node ≥ 20 is on the deploy host before chunk 6 ships. If not, Robert installs it (DreamHost VPS supports nvm).

---

## § XII — Phase 2 Done Walkthrough, Concretely

You open `https://sat.trackr.live`. The chunk-1 globe is unchanged in shape, but the dot density is slightly lower — DECAYED satellites are filtered out of the bulk feed.

You click ISS. The detail panel slides in, but unlike Phase 1 the §Identity grid is now FULL: Operator NASA / Roscosmos, Country ISS (consortium code), Launch date 1998-11-20, Launch vehicle Proton K, Mass ~419,725 kg, RCS 399.78 m². A small `purposes: station, human_sf` line appears beneath. The external links (N2YO, Heavens-Above, Wikipedia) all light up.

You tap the new top-bar pill `📍 set location`. A small popover offers three options: "📡 use my location" (geolocation), "🔍 search city" (Nominatim), or manual lat/lon. You hit "use my location" and it becomes `📍 51.5°, −0.1°`.

The detail panel grows a new **§ Visibility from observer** section: "Currently above horizon: No · Next pass: 2026-05-15 03:14 UTC, max elevation 47°, sunlit, mag 2.4." Below, a tight 5-row "Next 5 passes" table showing each pass's rise/peak/set times.

You navigate to the new top-bar link `📅 launches`. A list of upcoming launches, sorted by NET, with countdowns ticking down: `SpaceX Falcon 9 — Starlink 6-72 — 02:14:38:21 — GO`. You click one; it shows the mission, vehicle, pad map, embedded webcast.

You navigate to `/decays`. A list of predicted reentries with risk scores 1-5. You see "COSMOS 2535 R/B — predicted 2026-05-16 02:10 UTC ± 4h — risk 3 — Space-Track TIP."

You scrub the timeline back 6 hours. The dots rewind, including the §Visibility's "currently above horizon" recomputing for the past time. The detail panel's lat/lon updates accordingly.

That's Phase 2.

---

*End of Phase 2 design.*
