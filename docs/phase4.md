# Phase 4 Design — sat.trackr.live

**Status:** Plan locked at the close of Phase 3; chunk 1 not yet started.
**Scope:** §28 of `req_spec.md` Phase 4 — "Situational awareness." Add the four data surfaces that turn the catalog from "things in orbit" into "things in orbit, what they're doing, what's about to happen" — plus fold in three deferred items carried forward from Phases 2 + 3.
**Target:** A dashboard you can leave open and learn things from. Conjunction warnings, current space weather, real-time catalog stats, a syndicated event feed, recognizable spacecraft models, magnitude-aware pass predictions.

---

## § I — Goals & Acceptance

### Goals

1. **Conjunction warnings.** Pull SOCRATES (CelesTrak's Satellite Orbital Conjunction Reports Assessing Threatening Encounters in Space), parse the full HTML reports (TCA, miss distance, relative velocity, max probability, secondary NORAD, repeat probability), render the top close-approaches with deep links to each object. New `/conjunctions` text view + JSON endpoints + topbar `§ conjunctions` link.
2. **Space weather widget.** Current Kp index, solar X-ray flux, F10.7, geomagnetic storm warnings — sourced from NOAA SWPC. Compact pill in the topbar (Kp + storm-level glyph); click opens a popover with the current values + a 24h trend chart. Full `/text/space-weather` view for the non-JS path.
3. **Aurora overlay.** Optional globe overlay rendering NOAA OVATION auroral oval forecast for now + 30 min ahead. Fits into the existing `§ overlays` menu from Phase 3 chunk 4 as the 5th toggle.
4. **Stats dashboard.** `/stats` view — live counts by country/operator/type/launch year, largest constellations, mass in orbit, debris vs payload ratio. Powered entirely by aggregations over the existing `satellites` table; no new ingest.
5. **Events feed + RSS.** `/events` view chronologically merging recent launches (Phase 2 chunk 3) + recent reentries (Phase 2 chunk 4) + significant conjunctions + space weather alerts. Atom 1.0 feed at `/events.atom` for syndication.
6. **Click ground station → "tracking N satellites" tooltip.** Phase 3 chunk 4 deferral. Uses chunk-6A elevation logic against cached satellite positions.
7. **N2YO magnitude enrichment for pass predictions.** Phase 2 chunk 6 deferral. Adds a magnitude column to the §Visibility pass table when N2YO returns data; degrades silently on quota exhaustion.
8. **Real glTF for marquee satellites.** Phase 3 chunk 3 deferral. Replaces the BoxGeometry/CylinderGeometry stand-ins with actual self-hosted glTF models. Acquisition is the hard part; the swap-in is a one-method change in `MarqueeShapeLayer.buildPrimitive`.

### Acceptance criteria

- [ ] `make ingest-socrates` populates a new `conjunctions` table from CelesTrak's report (~150-300 records typical) in <60s.
- [ ] `GET /api/v1/conjunctions/upcoming?within_hours=N` returns conjunctions with TCA in [now, now+N], cache 10min + swr=15min.
- [ ] `GET /api/v1/space-weather/now` returns current Kp + X-ray flux + F10.7 + storm-watch level, cache 5min. `/api/v1/space-weather/24h` returns the trend sample.
- [ ] `/text/conjunctions` and `/text/space-weather` mirror the JSON endpoints as plain HTML.
- [ ] `<sat-space-weather-pill>` in the topbar shows Kp + storm-level glyph; click opens a popover with the trend chart.
- [ ] `<sat-aurora-overlay>` (lazy-loaded raster, ~few hundred KB) toggles on/off via the `§ overlays` menu.
- [ ] `/stats` view renders with sortable tables: Top 20 operators, Top 20 countries, mass-in-orbit by class, debris vs payload pie. JSON `/api/v1/stats/{breakdown}` powers it.
- [ ] `/events.atom` validates as proper Atom 1.0 + carries a deep-link `<link>` to each event's detail page on the SPA.
- [ ] Clicking a ground station in the globe shows a small tooltip with "Currently tracking N satellites" + a "view list" affordance.
- [ ] N2YO magnitude enrichment surfaces in `/api/v1/satellites/{norad}/passes` as `magnitude: 2.4 | null` per pass; daily quota guard logs `warn` + sets `null` instead of erroring on quota exhaustion.
- [ ] Marquee satellites (ISS / Tiangong / Hubble / cargo capsules / Starlink) render as actual recognizable glTF models when zoomed in; if a free glTF can't be sourced for a given satellite, the chunk-3 procedural stand-in stays as the documented fallback.
- [ ] All new code passes `make ci`. Test count grows by ~60-80 (estimate ~50 PHP + ~10 JS + a handful of e2e).
- [ ] `README.md` reflects the new surfaces; this doc closes when chunks are all done.

### Explicitly NOT in Phase 4 (deferred)

Pushed to Phase 5+:
- AMSAT / SatNOGS radio-pass enrichment (req_spec §17)
- Mobile PWA optimization, sharing deep-links, OG image generation, sitemap, SEO
- Conjunction-replay 3D scene (revisit after Phase 4 chunks 1-2 land — the data + UI need to settle first)
- ICS calendar export for passes
- Browser-worker compute_passes (dropped — cached server path is already <30ms warm)
- Per-station sensor-cone half-angles (overrides per ground station for chunk-4 cones; chunk-4 spec uses uniform 5°)
- Custom-GLSL atmosphere shader (Phase 3 chunk 1 deferred; revisit only if the Cesium default looks bad once Phase 4 settles)

---

## § II — Locked decisions

| # | Decision | Locked answer |
|---|---|---|
| 1 | Conjunction data source | **CelesTrak SOCRATES** (https://celestrak.org/SOCRATES/) |
| 2 | Conjunction ingest depth | **Full HTML scrape** — TCA + miss distance + relative velocity + max probability + secondary NORAD + repeat probability. Parser tolerated; snapshot-fixture regression test pins the current layout. |
| 3 | Space weather data source | **NOAA SWPC JSON endpoints** (services.swpc.noaa.gov) |
| 4 | Space weather widget richness | **Topbar pill + popover with current values + 24h trend chart** (Kp + X-ray + F10.7 + storm-watch in the popover; Kp + storm glyph in the pill) |
| 5 | Aurora overlay source | **NOAA OVATION-Aurora** raster |
| 6 | Conjunction-rank threshold in pill | **Top 20 by probability** (full list in `/conjunctions`) |
| 7 | Atom feed window | **Last 7 days** of events |
| 8 | Visual-diff Playwright baselines | **Land in chunk 8 polish** (Phase 4 surfaces are stable HTML, easy to baseline) |
| 9 | N2YO magnitude enrichment (Phase-2 deferral) | **Fold into chunk 7** (`N2YOClient` + quota guard + magnitude column in §Visibility pass table) |
| 10 | Real glTF for marquee (Phase-3 deferral) | **Fold into chunk 8** — swap in real glTF where free/correctly-licensed files exist; document the stand-in fallback per satellite. |
| 11 | Click ground station → tooltip (Phase-3 deferral) | **Fold into chunk 6** (alongside events feed; small ~50-line addition) |
| 12 | Browser-worker compute_passes (Phase-2 deferral) | **DROPPED** — server path is fast enough; the worker path was never load-bearing |
| 13 | Per-station sensor-cone half-angles (Phase-3 deferral) | **Push to Phase 5** — chunk-4 uniform-5° is fine for situational awareness |

---

## § III — Chunk plan

| # | Chunk | Net new code | Tests | Bundle delta | Depends on |
|---|---|---|---|---|---|
| **1** | **Conjunctions ingest + schema** | Migration for `conjunctions` table; `SocratesClient` (Guzzle + HTML scrape) + `SocratesIngester` + `bin/console ingest:socrates` + `make ingest-socrates`; snapshot-fixture regression test for parser. | ~10 PHP | Server-only | Phase-2 schema |
| **2** | **Conjunctions API + text view** | 2 JSON endpoints (`/api/v1/conjunctions/upcoming?within_hours=N`, `/api/v1/conjunctions/{primary}/{secondary}`); `/text/conjunctions` template + topbar `§ conjunctions` nav link. | ~8 PHP + smoke e2e | Main +5KB | chunk 1 |
| **3** | **Space weather ingest + pill + popover** | Migration for `space_weather_samples`; `SwpcClient` + `SwpcIngester` + `bin/console ingest:swpc`; `<sat-space-weather-pill>` (Kp + glyph in topbar) + `<sat-weather-popover>` (current values + 24h trend chart via hand-rolled SVG, no Chart.js dep); `/text/space-weather` mirror. | ~10 PHP + ~5 JS | Main +12KB | nothing |
| **4** | **Aurora overlay** | OVATION raster fetcher + lazy image-overlay primitive (`AuroraOverlayLayer`); `§ overlays` menu grows a 5th toggle. Server-side cache keyed on OVATION's 15-min update tick. | ~3 PHP + ~3 JS | Lazy ~250KB | Phase-3 chunk 4 OverlayService |
| **5** | **Stats dashboard** | `/stats` text view + JSON `/api/v1/stats/{breakdown}` (operators/countries/types/years/mass/debris-ratio); aggregations over the existing `satellites` table — no new ingest. | ~8 PHP + smoke e2e | Server-only | nothing |
| **6** | **Events feed + Atom + station tooltip** | `/events` text view + `/events.atom` Atom 1.0 generator merging launches / reentries / conjunctions / storm-warnings; `AtomGenerator` unit-tested against a snapshot fixture; click-station tooltip via `SelectionController` upgrade to emit station-pick events. | ~5 PHP + ~3 JS | Main +3KB | chunks 1-5 |
| **7** | **N2YO magnitude enrichment** | `N2YOClient` (Guzzle + daily quota guard via `quota_state` table or simple cache file); pass-cache schema grows `magnitude REAL NULL`; `PassCalculator` calls N2YO best-effort after compute; `§ Visibility` pass table gains a `Mag` column. | ~10 PHP + ~3 JS | Main +1KB | Phase-2 chunks 5+6 |
| **8** | **Real glTF + Playwright baselines + Phase 5 outline + README close** | Source free/CC-BY glTF for ISS / Tiangong / Hubble / Dragon / Cygnus / Soyuz / Starlink (best-effort — falls back to chunk-3 stand-in per satellite); `MarqueeShapeLayer.buildPrimitive` learns to call `Cesium.Model.fromGltfAsync` when `MarqueeSpec.gltfUri` is set. Visual-diff baseline screenshots for the new HTML surfaces (`/conjunctions`, `/stats`, `/events`, `/text/space-weather`). README closes Phase 4; drafts `docs/phase5.md`. | Playwright suite + ~3 vitest | Lazy ~few MB | all prior |

**Estimated 55-75 new tests**, bringing total from 228 → ~300.
**Bundle target:** ≤275 KB gzipped main (Phase 3 ended at 136 KB; budget ~140 KB cushion). Lazy assets (aurora raster + glTF models) add server-side weight but don't bloat the main bundle.

---

## § IV — Dependencies & risk

- **SOCRATES format stability.** The CelesTrak HTML report is parsed; any layout change breaks ingest. **Mitigation:** keep the parser tolerant + a snapshot-fixture regression test pins the current layout. CelesTrak has been stable for years but rewrites do happen.
- **NOAA SWPC rate limits.** Their free JSON endpoints don't enforce hard limits but expect "polite" use. Cron-driven at 5-min intervals; cache aggressively.
- **Aurora overlay refresh cadence.** OVATION updates every 15 minutes; need a cache/refresh story so we don't refetch every page load. **Mitigation:** server-side cache keyed on OVATION's update timestamp.
- **Atom feed validity.** Generated XML must validate as Atom 1.0. **Mitigation:** unit-test the generator against a snapshot fixture + CI runs the feed-validator output through `xmllint --noout`.
- **Stats query perf.** Several `GROUP BY` aggregations over ~15K satellites are fine in SQLite, but if the catalog grows past 100K (post-Alpha-5) we'll need covering indexes on `country` + `operator` + `launch_date_year`. **Mitigation:** add the indexes as part of chunk 5.
- **N2YO daily quota.** 1000 req/day on the free tier. Per-pass-calc enrichment burns through it fast if many users query. **Mitigation:** quota guard hard-caps to 800/day; log `warn` + return `magnitude: null` when exhausted; UI gracefully shows "—" in the Mag column.
- **glTF acquisition cost.** Free + correctly-licensed satellite glTFs are hard to find. **Mitigation:** ship what we can source (ISS is the most likely available; Starlink/Soyuz less so); fall back to the chunk-3 procedural stand-in per-satellite; document the per-sat licensing in `public/models/CREDITS.md`.

---

## § V — Out of scope for Phase 4 (truly deferred)

- AMSAT / SatNOGS radio-pass enrichment (Phase 5)
- ICS calendar export for passes (Phase 5)
- Mobile PWA optimization (Phase 5)
- Sharing deep-links, OG image generation, sitemap, SEO (Phase 5)
- 3D-conjunction-replay scene (revisit in Phase 5 after Phase 4 chunks 1-2 land)
- Browser-worker compute_passes (dropped — cached server path is fast enough)
- Per-station sensor-cone half-angles (Phase 5)
- Custom-GLSL atmosphere shader (Phase 5 if Cesium default still looks bad)

---

## § VI — Workflow reminders

Same chunk-by-chunk pacing as Phases 2 and 3:

- Each chunk pauses for explicit OK before the next starts.
- Granular commits per sub-chunk; push after each.
- README updated at the end of every chunk to reflect current state.
- Dev server bound to `0.0.0.0` for phone testing throughout.
- End each chunk with: README update → commit/push → report → pause.
