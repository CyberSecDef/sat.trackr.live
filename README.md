# sat.trackr.live

> _Space situational awareness, **legible**._

A public, read-only, mobile-friendly 3D web app that visualizes everything humans have put into Earth orbit — plus the events shaping that environment (launches, reentries, conjunctions, space weather) — on a single time-scrubbable globe.

Part of the **trackr.live family** alongside [trackr.live](https://trackr.live) and [cyber.trackr.live](https://cyber.trackr.live).

---

## Status

✅ **Phase 3 complete.** All 22 chunks across phases 1-3 are live. **Foundation:** full SPA with globe + detail panel + time scrubbing + text-only fallback at `/text`. **Data depth (Phase 2):** SATCAT enrichment, Launch Library 2 ingester + 4 launch JSON endpoints + `/text/launches`, Space-Track TIP ingester + 2 reentry JSON endpoints + `/text/decays`, `📍` observer pill, Node-SGP4 pass-prediction pipeline + `§ Visibility` panel section, CelesTrak `FORMAT=JSON` (OMM) ingest with Alpha-5 NORAD encoding. **Showcase visuals (Phase 3):** Cesium lighting + sun + moon + BSC5 starfield, fading orbit ribbons for the selected satellite, 3D shape stand-ins for marquee satellites with LOD swap, ~40-station ground-station layer with 5° sensor cones, NASA VIIRS Earth-at-Night overlay (`dayAlpha=0`/`nightAlpha=0.85`), `§ overlays` topbar menu persisting to localStorage, "Above horizon now?" elevation/azimuth line in the panel, Playwright smoke suite. **Phase 4 next** — see [`docs/phase4.md`](docs/phase4.md) for the situational-awareness outline (SOCRATES conjunctions, NOAA space weather, aurora overlay, stats dashboard, events feed + Atom).

### Phase 1 — Foundation MVP (✅ complete)

| Chunk | Status | What it adds |
|---|---|---|
| 1. Bootstrap + chrome | ✅ done | Repo skeleton, build tooling, Slim front controller, SPA shell, dark/light/high-contrast themes, Cesium globe with OSM imagery, search input + ⌘K, theme switcher |
| 1.5. WebGL fallback gate | ✅ done | `hasWebGL()` probes for `webgl2` / `webgl` / `experimental-webgl` (try/catch for restricted contexts); `<sat-app>` renders `<sat-no-webgl>` notice with CTA → `/text` when absent |
| 2. Schema + migrations | ✅ done | `bin/console` (Symfony Console) with `migrate / rollback / migrate:status / make:migration / health`; `satellites`, `satellites_fts` (with sync triggers), `tle_current`, `tle_history`, `satellite_purposes` tables |
| 3. CelesTrak GP ingester | ✅ done | `make ingest` populates ~15.6K distinct satellites from CelesTrak's 38 GP groups in ~40s; idempotent re-runs honor CelesTrak's 403 "not modified" signal |
| 4. JSON API | ✅ done | 8 endpoints under `/api/v1/`: satellites/groups/search/autocomplete; CORS + ETag + JSON middleware; `group_membership` table powers `/groups/{slug}/tles` |
| 5. Globe rendering | ✅ done | ~15K satellites as `Cesium.PointPrimitiveCollection`, color-coded by type; SGP4 in a Web Worker @ 4Hz; click-to-select via `Cesium.Scene.pick` |
| 6. Detail panel + search | ✅ done | Right-rail panel with Identity / Current state / Orbital elements / Raw data; functional `<sat-search>` with autocomplete + camera fly-to |
| 7. Time scrubbing | ✅ done | `<sat-timeline>` with ±7d slider + yellow bands beyond ±48h, play/pause, 5 speed buttons, Now reset; `Clock` facade drives both worker + UI |
| 8. Text-only catalog `/text` | ✅ done | 4 PHP routes (catalog list / satellite detail / groups / search) — server-rendered HTML, no JS required, sitemap-friendly. Self-contained inline CSS. SPA top-bar links to it. |

### Phase 2 — Data depth (✅ complete)

| Chunk | Status | What it adds |
|---|---|---|
| 1. SATCAT ingester + Phase-2 schema | ✅ done | `make ingest-satcat` enriches ~28.8K records (98.5% of catalog) with operator/country/launch_date/launch_site_code/RCS/status/decayed_at in 30s; rebuilds `satellite_purposes` from `group_membership` (12,757 rows). 5 new migrations land the chunks-3-6 tables (`launch_sites`, `launches`, `reentries`, `pass_cache` + a column on `satellites`). 31 new PHPUnit cases, **108 total tests passing**. |
| 2. Detail panel reads enriched fields | ✅ done | DECAYED satellites filtered out of `/groups/{slug}/tles` (per phase2.md decision 9); `launch_site_code` surfaced in JSON detail + SPA panel + `/text/satellite/{norad}`; "⚠ Reentered" callout when `decayed_at` set; new `Status` column on `/text` catalog list; `make health` reports SATCAT enrichment % (currently 98.5%) and counts all 9 app tables. No new tests (controller polish reuses existing test bed). |
| 3. Launch Library 2 ingester + launches view | ✅ done | `make ingest-ll2` pulls 50 upcoming + 100 previous launches from ll.thespacedevs.com in ~4s, populating 51 pads + 150 launch records (idempotent UPSERT, FK-safe). Four JSON endpoints: `GET /api/v1/launches/{upcoming,recent,{id}}` + `GET /api/v1/launch-sites`. Server-rendered text views at `/text/launches` (countdowns), `/text/launches/recent`, `/text/launches/{uuid}`. Topbar's `§ launches` placeholder is now a real link. **92 PHP / 31 JS passing.** |
| 4. Space-Track ingester + reentries view | ✅ done | `make ingest-spacetrack` pulls TIP messages from www.space-track.org via cookie-jar session in ~1.2s; UPSERT keyed on `(norad_id, source)`; TIPs for objects we don't catalog are skipped (44/50 in the first real run). Two JSON endpoints — `GET /api/v1/reentries/upcoming?within_hours=N` (default 168, max 720) + `GET /api/v1/reentries/{norad}` — and a server-rendered `/text/decays` mirror with countdowns + tri-color risk badge. SPA topbar + text nav grow `§ decays`. **110 PHP / 31 JS passing.** |
| 5. Observer location handling | ✅ done | New `<sat-observer-pill>` lives in the topbar between search and the theme switcher; collapses to `📍 set location` when unset, `📍 short-label (lat°, lon°)` once chosen. Three input modes: 🛰 use my location (geolocation), 🌍 city search (Nominatim, debounced 350ms + rate-limited 1 req/s), ⌖ manual lat/lon. Persisted to localStorage as `sat:observer`; survives reload + discards malformed JSON cleanly. Subscriber-friendly so chunk 6 can react. **110 PHP / 44 JS passing** (+13 new vitest specs). |
| 6. Pass predictions (calc + UI) | ✅ done | Pure-function pass detector (`resources/js/passes/computePasses.ts`) walks SGP4 elevation curves and refines rise/peak/set with 12-step bisection. Mirrored in `bin/sgp4-passes.mjs` Node CLI; PHP `PassCalculator` shells out via `proc_open` with a 15s timeout. `PassCache` (6h TTL keyed on NORAD + observer-3dp + day) keeps repeats under ~30ms. `GET /api/v1/satellites/{norad}/passes?lat&lon` (cache 5min + swr=10min) and a new `§ Visibility from observer` section in the detail panel that fetches the next 5 passes when the 📍 pill has a location set. `make pass-cache-prune` sweeps expired rows. **124 PHP / 49 JS passing.** *Deferred to chunk 7: N2YO magnitude enrichment + browser-worker compute path.* |
| 7. CelesTrak FORMAT=JSON migration + Phase 2 polish | ✅ done | `NoradId::encode/decode` Alpha-5 helper (`A0000`–`Z9999` = 100000–339999, `I` and `O` skipped) so `TleParser` keeps parsing once CelesTrak hits 6-digit NORAD IDs (~mid-2026). New `OmmJsonParser` consumes CelesTrak `FORMAT=JSON` records and produces the same `ParsedTle` value object the TLE path emits — including byte-perfect synthesized line1/line2 strings via `TleEmitter` so `satellite.js`, the SPA worker, and the copy-to-clipboard panel keep working unchanged. `bin/console ingest:celestrak --format=json` flips the source format end-to-end (TLE remains the default while we cut over). Phase 3 outline lands at `docs/phase3.md`. **149 PHP / 49 JS passing.** *Deferred to Phase 3 / 4: N2YO magnitude enrichment, browser-worker compute_passes path, "above horizon now?" line in §Visibility — see `docs/phase3.md` § V.* |

### Phase 3 — Showcase visuals (✅ complete)

| Chunk | Status | What it adds |
|---|---|---|
| 1. Terminator + sun/moon/stars | ✅ done | `bin/build-skybox.php` fetches the Bright Star Catalog 5 (~9100 stars), projects them onto an inertial-frame cubemap, and emits 6 magnitude-graded PNG faces at `public/textures/skybox/` (~120KB total). Globe.ts replaces Cesium's default skybox with that BSC5 cubemap and explicitly enables sun + moon. Terminator already moved with the time-scrub from Phase 1's `enableLighting = true`; this chunk delivers the visible night-side starfield. Bundle: 89.17 → 89.46 KB gzipped main (skybox is asset-loaded at runtime). **149 PHP / 49 JS passing.** |
| 2. Selected-object orbit ribbons | ✅ done | New `resources/js/passes/computeGroundTrack.ts` walks SGP4 over `pastOrbits + futureOrbits` revolutions and returns timestamped sub-satellite points via `gstime` + `eciToGeodetic`. `OrbitRibbonLayer` (Cesium `PolylineCollection`) renders a fading ground-track ribbon for the currently-selected satellite — past dim, future bright, gradient via 24 short-segment polylines. `<sat-detail-panel>` grew a `Ribbon: ½ · 1 · 2 · 3 orbits` toggle. Refreshes ~every 1/30 of the satellite's period as the user scrubs time (~3min for ISS at 1× speed). Bundle: 89.5 → 115.3 KB gzipped main (+12.5 KB from `twoline2satrec`/`gstime`/`eciToGeodetic` joining the main bundle). **149 PHP / 56 JS passing.** |
| 3. 3D models (ISS / Tiangong / Hubble / Dragon / Cygnus / Soyuz / Starlink) | ✅ done (stand-in shapes) | New `marqueeRegistry` (7 entries: 3 stations/telescopes + 3 cargo capsules + a Starlink stand-in matched by name prefix). `MarqueeShapeLayer` renders a colored Cesium `BoxGeometry`/`CylinderGeometry` primitive at the satellite's ECEF position, oriented to local east-north-up, when (a) the selected satellite is in the roster AND (b) the camera is within 5,000km of it. Visual scale exaggerated (×120 ISS, ×900 Starlink) so the primitive is visible at LOD threshold. **Honest tradeoff vs spec:** ships procedural primitives instead of self-hosted glTF for chunk 3 — the LOD swap, scaling, color-coding, and host wiring all work the same; swapping in real glTF is a one-method change in `MarqueeShapeLayer.buildPrimitive` (extend `MarqueeSpec` with a `gltfUri` field + call `Cesium.Model.fromGltfAsync`). Bundle: 115.3 → 119.2 KB gzipped main. **149 PHP / 64 JS passing.** |
| 4. Ground stations + sensor cones | ✅ done | New `resources/data/ground_stations.json` (41 sites: 6 NEN + 3 DSN + 9 ESTRACK + 4 JAXA + 5 ISRO + 8 KSAT + 5 AWS + 1 ATLAS). `GroundStationLayer` renders each as a network-colored 6px PointPrimitive + a 5°-half-angle CylinderGeometry cone (apex at the station, 1,000 km tall, base in the sky). New `<sat-overlays-menu>` topbar dropdown sits between the 📍 pill and the theme switcher with four checkboxes (orbit ribbon / 3D shapes / ground stations / light pollution). State persists to localStorage as `sat:overlays`; partial JSON merges into defaults. Globe subscribes — toggling rebuilds visibility for ribbons + marquee + stations layers. Bundle: 119.2 → 133.7 KB gzipped main. **149 PHP / 76 JS passing.** |
| 5. Light pollution overlay | ✅ done | NASA's 2012 VIIRS Earth-at-Night composite (3600×1800 JPG, 794 KB committed at `public/textures/earth-at-night.jpg`) — much lighter than the 40 MB budget. `LightPollutionLayer` adds a `Cesium.SingleTileImageryProvider` on top of the base imagery with `dayAlpha=0` + `nightAlpha=0.85` so city lights show only on the dark side, composing naturally with the chunk-1 terminator. Genuinely lazy-loaded: the JPG isn't requested until the user toggles "Light pollution" on for the first time. Bundle: 133.7 → 134.8 KB gzipped main (+0.3 KB — layer file only). **149 PHP / 76 JS passing.** |
| 6. Polish + Playwright + Phase 4 outline | ✅ done | "Above horizon now?" line in `§ Visibility` (live elevation/azimuth + compass octant, accent-colored Yes/No verdict, updated every 500ms via the existing `tickLive()` loop). Bundle audit: 41.87 KB gzipped main, well under the 250 KB target. Lazy-load verified: VIIRS JPG isn't requested until "Light pollution" is toggled on for the first time; ground-station catalog + marquee registry inlined. Playwright + chromium installed; 3 smoke specs (`tests/E2E/smoke.spec.ts`) pass in 9.8s — `make test-e2e` runs them. Visual-diff baselines for the Cesium globe deferred to Phase 4 chunk 6 polish where the new HTML surfaces are easier to baseline. `docs/phase4.md` outlines situational-awareness chunks. **149 PHP / 76 JS / 3 e2e passing.** |

### Phase 4 — Situational awareness (⏳ next)

See [`docs/phase4.md`](docs/phase4.md) for the outline. TL;DR: SOCRATES conjunctions, NOAA SWPC space-weather widget, OVATION aurora overlay, stats dashboard, events feed + Atom 1.0 syndication.

See [`docs/phase3.md`](docs/phase3.md) for the locked Phase 3 plan, decisions, dependencies, and risk.

See [`docs/phase1.md`](docs/phase1.md) and [`docs/phase2.md`](docs/phase2.md) for design details, and [`req_spec.md`](req_spec.md) for the long-form vision (sections §1–§30).

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

# Launches (Phase 2 chunk 3 — populated by `make ingest-ll2`)
curl 'http://localhost:8000/api/v1/launches/upcoming?limit=5'  # next launches by NET
curl 'http://localhost:8000/api/v1/launches/recent?days=30'    # last 30 days
curl 'http://localhost:8000/api/v1/launches/{uuid}'            # detail incl. pad + cataloged objects
curl http://localhost:8000/api/v1/launch-sites                 # all 51 pads alphabetical

# Reentries (Phase 2 chunk 4 — populated by `make ingest-spacetrack`)
curl 'http://localhost:8000/api/v1/reentries/upcoming?within_hours=720'  # next 30 days
curl http://localhost:8000/api/v1/reentries/54837                        # one prediction by NORAD

# Pass predictions (Phase 2 chunk 6 — Node SGP4 + 6h SQLite cache)
curl 'http://localhost:8000/api/v1/satellites/25544/passes?lat=51.5072&lon=-0.1276&days=2'  # ISS over London, next 2 days
```

Every response carries an `ETag`; pass it back via `If-None-Match` to get a 304 Not Modified. CORS is fully open (`Access-Control-Allow-Origin: *`) and OPTIONS preflight returns 204 in <5ms.

### From any browser — text-only catalog at `/text` (no JS / no WebGL required)

For environments without WebGL (older browsers, restricted IT, GPU disabled, headless tools, or JS fully off), the same data is browseable as plain HTML:

```
http://localhost:8000/text                      # paginated catalog with filter form
http://localhost:8000/text?q=ISS&type=PAYLOAD   # filtered (FTS5 q + type/country/status/orbit)
http://localhost:8000/text/satellite/25544      # full §10 detail page for ISS
http://localhost:8000/text/groups               # all 38 CelesTrak groups + counts
http://localhost:8000/text/groups/stations      # group members
http://localhost:8000/text/search?q=hubble      # search form + results
```

Self-contained — inline dark-theme CSS in the layout, no external assets, sitemap-friendly. The SPA's `<sat-no-webgl>` notice (auto-shown when `hasWebGL()` returns false) links here as the primary CTA.

### In the browser

Open `http://localhost:8000` (or the LAN URL printed by `make`). You should see:

- **Top bar**: `⊕ sat.trackr.live` wordmark, `Space situational awareness, _legible_` tagline, `§ catalog · § launches · § decays · § events` nav (launches/decays link to text views; events placeholder for Phase 4), search input with `⌘K` shortcut hint, `📍 observer-location` pill (Phase 2 chunk 5), theme switcher button.
- **Cesium globe with ~15,000 satellites** rendered as point primitives, color-coded by `object_type` (cyan = payloads + unknown, amber = rocket bodies, red = debris, gray = TBA). SGP4 propagation runs in a Web Worker at 4Hz; you should see the ISS marching across the planet, Starlink trains in formation, and ~10K LEO objects in slow-motion swarm. Drag to rotate, pinch/scroll to zoom. OpenStreetMap imagery (no Cesium ion token needed yet).
- **Click any dot** → it turns white + 9px and the right-rail **detail panel** slides in with four `§` sections:
  - **§ Identity** — type/status/orbit-class badges + 6-cell grid (operator, country, launch date, launch vehicle, mass, RCS). After Phase 2 chunk 1 (SATCAT), object_type/status/country/launch_date/launch_site_code/RCS now populated for ~98.5% of objects (operator + mass + dimensions remain empty until later sources). External links: N2YO, Heavens-Above, Gunter, Wikipedia.
  - **§ Current state** — live latitude / longitude / altitude (km) updated 2× per second from the propagator worker.
  - **§ Orbital elements** — epoch with `<sat-freshness-badge>` (FRESH/STALE/AGED/OLD), period, inclination, eccentricity, mean motion, perigee, apogee, semi-major axis, B*, RAAN, arg perigee, mean anomaly, rev number.
  - **§ Raw data** — clickable 3-line TLE (click to copy) + JSON detail/TLE links.
- **Search the catalog** in the top-right input (⌘K focuses it). Type to get a debounced autocomplete dropdown of up to 10 matches; ↑/↓ navigates, Enter or click selects. On selection the camera flies to the satellite and the detail panel opens.
- **Close the panel** via the × button, the Esc key, or by clicking empty space on the globe.
- **Status pill** in the bottom-left corner shows the load progression: "Loading satellite catalog…" → "Parsing 15,665 TLEs…" → "Tracking 15,665 satellites" (then fades to half-opacity).
- **Bottom timeline** spans now-7d → now+7d. The yellow shaded bands beyond ±48h mark the "extrapolated" zone (Phase 1 doesn't have historical TLE backfill yet). Drag the slider to scrub time — the swarm jumps immediately to the new positions. Play/pause + speed buttons (0.5×–600×) animate forward (or back) at the chosen multiplier; the live state in the detail panel reflects the scrubbed time. "Now" button snaps back to wall-clock present.
- **Theme switcher**: click the toggle (top right) to cycle Dark / Light / High contrast. Choice persists in `localStorage`.

URL shapes already wired:
- `/` → SPA shell with full globe
- `/satellite/{norad}` → SPA shell with the NORAD ID set as initial selection so the detail panel opens for that satellite on page load (camera doesn't auto-fly — that's only triggered by the search picker)

### From the CLI

```bash
# Schema management
make migrate                          # apply migrations (11 total: 6 Phase 1 + 5 Phase 2 chunk 1)
make migrate-status                   # show what's applied vs pending
make rollback                         # reverse the most recent batch
make make-migration NAME=add_foo      # scaffold a new migration file

# Catalog ingest
make ingest                           # CelesTrak GP — TLE data for ~15.6K satellites in ~40s (chunk 3)
make ingest-group GROUP=stations      # just one GP group
make ingest-satcat                    # CelesTrak SATCAT — operator/country/launch_date/RCS/status enrichment in ~30s (Phase 2 chunk 1)
make ingest-satcat-group GROUP=starlink  # just one SATCAT group
make ingest-ll2                       # Launch Library 2 — 50 upcoming + 100 previous launches in ~4s (Phase 2 chunk 3)
make ingest-spacetrack                # Space-Track TIP — predicted reentries in ~1.2s (Phase 2 chunk 4)
make pass-cache-prune                 # Sweep expired pass-cache rows (Phase 2 chunk 6)
make build-skybox                     # Regenerate BSC5 starfield cubemap into public/textures/skybox/ (Phase 3 chunk 1)
make health                           # PHP / pdo_sqlite / DB / per-table row counts

# Quality gates
make test                             # 149 PHP + 76 JS = 225 cases passing
make test-e2e                         # Playwright smoke specs (Phase 3 chunk 6) — needs `npx playwright install chromium` once
make lint / make analyze / make typecheck / make ci
```

After `make ingest`, the database holds ~15.6K distinct satellites (deduplicated across overlapping CelesTrak groups), ~15.6K current TLEs, and a history row per (norad_id, epoch) pair — typically 1 per object on the first run, growing by the number of objects with refreshed epochs on each subsequent run.

The schema after `make migrate` matches `docs/phase1.md` § V exactly:

| Table | Purpose | Notes |
|---|---|---|
| `satellites` | Catalog row per object | CHECK constraints on `object_type`, `status`, `orbit_class`, `size_class`; 6 indexes. CelesTrak GP populates `name` + `intl_designator`; CelesTrak SATCAT (Phase 2 chunk 1) fills `object_type`/`status`/`country`/`launch_date`/`launch_site_code`/`decayed_at`/`rcs_meters`. Operator/mass/dimensions still empty pending later sources. |
| `satellites_fts` | FTS5 virtual table for fuzzy search | Auto-synced via insert/update/delete triggers |
| `tle_current` | One TLE per active object | FK to satellites, ON DELETE CASCADE; mean motion + eccentricity + inclination + RAAN + arg perigee + mean anomaly + BSTAR + rev number, plus derived period / perigee / apogee / semi-major axis |
| `tle_history` | Append-only TLE archive | Composite PK `(norad_id, epoch)`; INSERT OR IGNORE makes re-ingests cheap |
| `satellite_purposes` | Join table for §5 SET-style purpose | Populated by SATCAT ingester via `group_membership` heuristic (Phase 2 chunk 1); 12,757 rows after first run |
| `group_membership` | Join table tracking which CelesTrak group(s) include each satellite | Composite PK `(norad_id, group_slug)` + `last_seen_at`; populated by the ingester on each pass; powers `/api/v1/groups/{slug}*` |
| `launch_sites` | LL2 launch pads | Populated by `make ingest-ll2` (Phase 2 chunk 3); ~51 rows on first run |
| `launches` | LL2 launch records | Populated by `make ingest-ll2` (Phase 2 chunk 3); 150 rows (50 upcoming + 100 previous) |
| `reentries` | Predicted decays from Space-Track TIP + CelesTrak SATCAT | Populated by `make ingest-spacetrack` (Phase 2 chunk 4); UPSERT keyed on `(norad_id, source)` so re-runs refresh predictions in place |
| `pass_cache` | Server-side pass-prediction cache (6h TTL) | Populated by `PassCache::put()` whenever the chunk-6 controller spawns a fresh Node subprocess; key = `{norad}:{lat-3dp}:{lon-3dp}:{day}`. Sweep with `make pass-cache-prune`. |
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
| `GET /api/v1/launches/upcoming` | Next launches by NET, with pad block | `limit` (default 50, max 100); cache 5min + swr=10min |
| `GET /api/v1/launches/recent` | Past N days of launches, most-recent first | `limit` (default 100, max 200), `days` (default 90, max 365); cache 1h |
| `GET /api/v1/launches/{uuid}` | Single launch with full detail + pad + decoded `associated_norad_ids` | 404 on unknown UUID |
| `GET /api/v1/launch-sites` | All ~51 pads alphabetical | Cache 24h |
| `GET /api/v1/reentries/upcoming` | Predicted reentries within `within_hours` (default 168, max 720) | Joined with satellite name + object_type; cache 10min + swr=15min |
| `GET /api/v1/reentries/{norad}` | Most-recently-updated prediction for a NORAD; raw TIP message decoded; nested satellite block | 404 on no prediction; cache 5min + swr=10min |
| `GET /api/v1/satellites/{norad}/passes` | Up to 14 days of pass predictions for an observer; required `lat`+`lon`, optional `alt`/`days`/`min_elevation_deg`. Each pass is rise/peak/set ISO + duration + max elevation + 3 azimuths. `meta.from_cache` flags hits | Cold ~250ms (Node spawn), warm ~30ms; cache 5min + swr=10min |

Default response headers: `Content-Type: application/json; charset=utf-8`, `Cache-Control: public, max-age=60, stale-while-revalidate=120` (controllers override per-route — bulk-TLE uses 300s, group lists use 3600s), `ETag: W/"<sha1-of-body>"` plus open CORS (`*`). `If-None-Match` → 304.

### CelesTrak ingest details

- **Format:** the ingester defaults to `FORMAT=TLE` (3-line sets) but accepts `--format=json` to consume `FORMAT=JSON` (OMM) records via `OmmJsonParser`. When NORAD IDs cross 6 digits (~mid-2026) the JSON path stays valid; the TLE path also keeps working via `NoradId::encode` / `NoradId::decode` Alpha-5 encoding (`A0000`–`Z9999` = 100000–339999). Both paths produce the same `ParsedTle` shape; `OmmJsonParser` synthesizes byte-perfect `line1`/`line2` strings via `TleEmitter` so the rest of the system (satellite.js, copy-to-clipboard, raw-data panel) doesn't care which path served the data.
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
| `make build-skybox` | regenerate BSC5 starfield cubemap into `public/textures/skybox/` (Phase 3 chunk 1) |
| `make migrate` | apply migrations (11 total) |
| `make ingest` | run CelesTrak GP ingester |
| `make ingest-satcat` | enrich catalog from CelesTrak SATCAT (Phase 2 chunk 1) |
| `make ingest-ll2` | upsert launches + pads from Launch Library 2 (Phase 2 chunk 3) |
| `make ingest-spacetrack` | refresh predicted reentries from Space-Track TIP (Phase 2 chunk 4) |
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
5. Add cron entries (Phase 2 chunks 1–6 ingest schedule + cache pruning):

   ```cron
   0 */6 * * *   cd ~/sat.trackr.live && make ingest            >> storage/logs/cron.log 2>&1
   0 4 * * *     cd ~/sat.trackr.live && make ingest-satcat     >> storage/logs/cron.log 2>&1
   0 * * * *     cd ~/sat.trackr.live && make ingest-ll2 MODE=upcoming   >> storage/logs/cron.log 2>&1
   0 */6 * * *   cd ~/sat.trackr.live && make ingest-ll2 MODE=previous   >> storage/logs/cron.log 2>&1
   0 */12 * * *  cd ~/sat.trackr.live && make ingest-spacetrack >> storage/logs/cron.log 2>&1
   30 4 * * *    cd ~/sat.trackr.live && make pass-cache-prune  >> storage/logs/cron.log 2>&1
   ```

6. Confirm Node ≥ 20 is on PATH for the prod user (chunk 6's `bin/sgp4-passes.mjs` shells out to it). DreamHost VPS supports `nvm`; set `NODE_BINARY=/full/path/to/node` in `.env.prod` if `node` isn't on the cron `$PATH`.

Apache config relies on `public/.htaccess` (rewrites + cache headers + security headers).

---

## Contributing

This project is **AGPL-3.0-or-later**. If you run a modified version on a public service, you must offer the modified source to your users.

Issues and PRs welcome at <https://github.com/CyberSecDef/sat.trackr.live>.

---

## License

[AGPL-3.0-or-later](LICENSE)
