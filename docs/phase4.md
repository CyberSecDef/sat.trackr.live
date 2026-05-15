# Phase 4 Design — sat.trackr.live

**Status:** Outline (drafted at the close of Phase 3)
**Scope:** §28 of `req_spec.md` Phase 4 — "Situational awareness." Add the four data surfaces that turn the catalog from "things in orbit" into "things in orbit, what they're doing, what's about to happen."
**Target:** A dashboard you can leave open and learn things from. Conjunction warnings, current space weather, real-time catalog stats, and a syndicated event feed.

---

## § I — Goals & Acceptance

### Goals

1. **Conjunction warnings.** Pull SOCRATES (CelesTrak's Satellite Orbital Conjunction Reports Assessing Threatening Encounters in Space) and render the top close-approaches with TCA, miss distance, and probability. New `/conjunctions` text view + JSON endpoints + topbar `§ conjunctions` link.
2. **Space weather widget.** Current Kp index, solar X-ray flux, F10.7, geomagnetic storm warnings — sourced from NOAA SWPC. Compact widget in the topbar; full `/space-weather` view with current values + 24h trend + historical context.
3. **Aurora overlay.** Optional globe overlay rendering NOAA OVATION auroral oval forecast for now + 30 min ahead. Fits into the existing `§ overlays` menu from Phase 3 chunk 4.
4. **Stats dashboard.** `/stats` view — live counts by country/operator/type/launch year, largest constellations, mass in orbit, debris vs payload ratio. Powered entirely by aggregations over the existing `satellites` table; no new ingest.
5. **Event feed + RSS.** `/events` view chronologically merging recent launches (Phase 2 chunk 3) + recent reentries (Phase 2 chunk 4) + significant conjunctions + space weather alerts. Atom feed at `/events.atom` for syndication.

### Acceptance criteria

- [ ] `make ingest-socrates` populates a new `conjunctions` table from CelesTrak's report (~150-300 records typical) in <60s.
- [ ] `GET /api/v1/conjunctions/upcoming?within_hours=N` returns conjunctions with TCA in [now, now+N], cache 10min + swr=15min.
- [ ] `GET /api/v1/space-weather/now` returns current Kp + X-ray flux + F10.7 + storm-watch level, cache 5min.
- [ ] `/text/conjunctions` and `/text/space-weather` mirror the JSON endpoints as plain HTML.
- [ ] `<sat-space-weather-pill>` in the topbar shows Kp + storm-level glyph; click opens a popover with the trend chart.
- [ ] `<sat-aurora-overlay>` (lazy-loaded raster, ~few hundred KB) toggles on/off via the `§ overlays` menu.
- [ ] `/stats` view renders with sortable tables: Top 20 operators, Top 20 countries, mass-in-orbit by class, debris vs payload pie.
- [ ] `/events.atom` validates as proper Atom 1.0 + carries a deep-link `<link>` to each event's detail page on the SPA.
- [ ] All new code passes `make ci`.  Test count grows by ~30-40 (estimate ~25 PHP + ~10 JS + a handful of e2e).
- [ ] `README.md` reflects the new surfaces; this doc closes when chunks are all done.

### Explicitly NOT in Phase 4 (deferred)

Pushed to Phase 5+:
- AMSAT / SatNOGS radio-pass enrichment (req_spec §17 — Phase 5)
- Mobile PWA optimization, sharing deep-links, OG image generation, sitemap, SEO (Phase 5)
- Conjunction-replay 3D scene (req_spec §16 mentions; deferred until conjunctions data is well-understood)
- ICS calendar export for passes (req_spec §17 — Phase 5)

---

## § II — Locked-and-default decisions

(Will lock on review before chunk 1 starts; defaults based on what would land naturally.)

| # | Decision | Default | Rationale |
|---|---|---|---|
| 1 | Conjunction data source | **CelesTrak SOCRATES** (https://celestrak.org/SOCRATES/) | Free, public, aligns with the existing CelesTrak ingest path. |
| 2 | Space weather data source | **NOAA SWPC JSON endpoints** (services.swpc.noaa.gov) | Free, no key, well-documented, sub-15min refresh. |
| 3 | Aurora overlay source | **NOAA OVATION-Aurora** raster | ~250KB lazy raster updated every 15min; acceptable bundle weight. |
| 4 | Conjunction-rank threshold | **Top 20 by probability** in the topbar pill; full list in `/conjunctions` | Avoid scrolling a 300-row list every page load. |
| 5 | Atom feed window | **Last 7 days** of events | RSS readers typically poll once/day; 7d window keeps the file <50KB. |
| 6 | N2YO magnitude (Phase-2 deferral) | **Defer further to Phase 5** | UX value uncertain vs N2YO quota cost; revisit when there's user demand. |
| 7 | Browser-worker compute_passes (Phase-2 deferral) | **Drop entirely** | Server-cached path is already <30ms warm; the worker path was never load-bearing. |
| 8 | Visual-diff baselines (Phase-3 deferral) | **Land in chunk 6 polish of Phase 4** | Phase 4 surfaces (`/conjunctions`, `/stats`, `/events`) are stable HTML, much easier to baseline than the Cesium globe. |

---

## § III — Tentative chunk plan (subject to revision before chunk 1 starts)

| # | Chunk | Net new code | Tests | Depends on |
|---|---|---|---|---|
| **1** | **Conjunctions ingest + schema** | Migration for `conjunctions` table; SocratesClient + SocratesIngester + ingest:socrates command + Make target. | ~10 PHP | Phase-2 schema |
| **2** | **Conjunctions API + text view** | 2 JSON endpoints (`/upcoming`, `/{id}` or `/{norad_a}/{norad_b}`); `/text/conjunctions` template + nav link. | ~8 PHP + smoke e2e | chunk 1 |
| **3** | **Space weather ingest + widget** | Migration for `space_weather_samples`; SwpcClient + SwpcIngester + ingest:swpc command; `<sat-space-weather-pill>` + popover trend chart. | ~10 PHP + ~5 JS | nothing |
| **4** | **Aurora overlay** | NOAA OVATION raster fetcher + lazy image-overlay primitive; `§ overlays` menu grows a 5th toggle. | ~3 JS | Phase-3 chunk 4 OverlayService |
| **5** | **Stats dashboard** | New `/stats` text view + JSON `/api/v1/stats/{breakdown}` (operators/countries/types/years/mass); reuses chunk-1 satellites table — no new ingest. | ~8 PHP + smoke e2e | nothing |
| **6** | **Events feed + Atom + polish + Phase 5 outline** | `/events` text view + `/events.atom` Atom 1.0 generator merging launches/reentries/conjunctions/storm-warnings; visual-diff Playwright baselines for the new surfaces; README closes Phase 4; drafts `docs/phase5.md`. | ~5 PHP + Playwright suite | all prior |

**Estimated 30-45 new tests**, bringing total from 228 → ~270.
**Bundle target:** ≤275KB gzipped main (room for the space-weather pill + aurora overlay; Phase 3 ended at 136KB).

---

## § IV — Dependencies & risk

- **SOCRATES format stability.** The CelesTrak HTML report is parsed; any layout change breaks ingest. Mitigation: keep the parser tolerant + add a sample-fixture regression test that pins the current layout.
- **NOAA SWPC rate limits.** Their free JSON endpoints don't enforce hard limits but do expect "polite" use. Cap at 1 req/min (cron driven) and cache aggressively.
- **Aurora overlay refresh cadence.** OVATION updates every 15 minutes; we'll need a cache/refresh story so we don't refetch every page load.  Mitigation: server-side cache keyed on the OVATION update timestamp (15-min granularity).
- **Atom feed validity.** Generated XML must validate against Atom 1.0 + Feed Validator. Mitigation: unit-test the generator against a snapshot fixture + a CI check that runs the W3C feed-validator output through `xmllint --noout`.
- **Stats query perf.** Several `GROUP BY` aggregations over ~15K satellites are fine in SQLite, but if the catalog grows past 100K (post-Alpha-5) we'll need indexes on `country` + `operator` + `launch_date_year`. Mitigation: add covering indexes as part of chunk 5.

---

## § V — Out of scope for Phase 4 (truly deferred)

- AMSAT / SatNOGS radio-pass enrichment (Phase 5)
- ICS calendar export for passes (Phase 5)
- Mobile PWA optimization (Phase 5)
- Sharing deep-links, OG image generation, sitemap, SEO (Phase 5)
- 3D-conjunction-replay scene (revisit after Phase 4 chunks 1-2 land — the data + UI need to settle first)
- N2YO magnitude enrichment (Phase 5)
- Browser-worker compute_passes (dropped — cached server path is fast enough)

---

## § VI — Workflow reminders

Same chunk-by-chunk pacing as Phases 2 and 3:

- Each chunk pauses for explicit OK before the next starts.
- Granular commits per sub-chunk; push after each.
- README updated at the end of every chunk to reflect current state.
- Dev server bound to `0.0.0.0` for phone testing throughout.
- End each chunk with: README update → commit/push → report → pause.
