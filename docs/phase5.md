# Phase 5 Design — sat.trackr.live

**Status:** Outline (drafted at the close of Phase 4)
**Scope:** §28 of `req_spec.md` Phase 5 — "Polish & ecosystem" + the carry-forward deferrals from Phases 2/3/4.
**Target:** Turn the project from "working app" into "shippable open-source artifact": discoverable on the web, friendly on mobile, well-documented for API consumers, and visually distinguished where it matters most.

---

## § I — Goals & Acceptance

### Goals

1. **AMSAT / SatNOGS amateur-radio enrichment.** Layer satellite-radio metadata onto the existing catalog: downlink/uplink frequencies, modulation, transponder modes, beacon status. Surface in the detail panel's `§ Identity` block + a new `§ Radio` section when present.
2. **Mobile PWA optimization.** `manifest.webmanifest`, install-to-homescreen on iOS + Android, service worker for offline `/text` access (read-only catalog after first load), `theme-color` tuned per theme, touch-target sizing pass.
3. **OpenAPI 3.1 docs.** Spec generated from the existing controllers; mounted at `/api/v1/openapi.json` + a Swagger UI viewer at `/api/v1/docs`. Phase 1-4 endpoints all documented + linked from README.
4. **OG image generation.** Per-page Open Graph images: `/satellite/{norad}` gets a satellite card; `/text/launches/{uuid}` gets a mission card; `/text/conjunctions` gets a "top close approaches today" card. PHP GD generator with deterministic templates.
5. **Sitemap + SEO.** `/sitemap.xml` enumerating every `/text/satellite/{norad}` + `/text/launches/{uuid}` + the static text views; `robots.txt`; `<link rel="canonical">` everywhere; structured-data JSON-LD for satellites + launches.
6. **Deep-link sharing.** Stable URLs that restore application state: `/?sat=25544&lat=51.5&lon=-0.1&t=2026-05-18T03:14:00Z` opens the SPA with that satellite selected, that observer set, and the clock at that time. Web Share API integration on the topbar.
7. **Real glTF for marquee satellites (Phase 3/4 carry-forward).** Source CC0/CC-BY models for ISS / Tiangong / Hubble / Dragon / Cygnus / Soyuz / Starlink and drop into `public/models/`; the chunk-8A infrastructure already loads them when present.

### Acceptance criteria

- [ ] `make ingest-satnogs` populates a `satellite_radio` table with at least the top 200 amateur-radio satellites; detail panel surfaces frequencies + modulation when known.
- [ ] PWA installable on iOS Safari + Chromium; offline page loads after first visit.
- [ ] `/api/v1/openapi.json` validates against the 3.1 schema; Swagger UI renders without errors; every Phase 1-4 endpoint has a documented response shape.
- [ ] `/satellite/25544` returns an `<meta property="og:image">` pointing at a generated card showing ISS's name + intl designator + current state.
- [ ] `/sitemap.xml` enumerates ≥10 K URLs; passes the W3C sitemap validator.
- [ ] `/?sat=25544&lat=51.5&lon=-0.1` opens the SPA with ISS pre-selected and the observer pre-set.
- [ ] At least 3 marquee satellites (ideally ISS + Hubble + a cargo capsule) ship with real glTF; the others continue to fall back to procedural primitives.
- [ ] All new code passes `make ci`. Test count grows by ~40-55 (estimate ~30 PHP + ~10 JS + a handful of e2e + visual baselines for the new SPA modal states).
- [ ] `README.md` reflects the new surfaces; this doc closes when chunks are all done.

### Explicitly NOT in Phase 5 (deferred forever or to a hypothetical Phase 6)

- ICS calendar export for passes — small win, never quite urgent enough; could be a Phase 5 stretch.
- The "conjunction-replay 3D scene" originally listed in `req_spec.md` §16 — Phase 4 chunks 1-2 settled the data + UI, and the 3D replay feels like a Phase 6 showcase moment, not a Phase 5 polish item.
- Browser-worker `compute_passes` path (Phase 2 chunk 6 deferral) — server-cached path is fast enough.
- Per-station sensor-cone half-angles (Phase 3 chunk 4 deferral) — chunk 4's uniform 5° is fine for situational awareness.
- Custom-GLSL atmosphere shader (Phase 3 chunk 1 deferral) — Cesium's default `enableLighting` still looks good.

---

## § II — Open decisions (need answers before chunk 1)

| # | Decision | Default | Why open |
|---|---|---|---|
| 1 | SatNOGS data source | **SatNOGS DB JSON API** (db.satnogs.org/api/transmitters/) | Free, no key. AMSAT's similar feed is older and less complete. |
| 2 | PWA install scope | **All routes** (root scope `/`) | vs. only `/text/*` if we want the SPA to be reload-only. Defaulting to all routes; SPA caches via Cesium's standard service-worker patterns. |
| 3 | Offline mode coverage | **`/text` only**, no SPA offline | The SPA needs ~150 KB JS + Cesium runtime + textures + the catalog API — too much to cache reasonably. `/text` is small and naturally offline-friendly. |
| 4 | OpenAPI generator | **swagger-php attributes on controllers** (no codegen) | Keeps spec next to code; OpenAPI YAML emitter is a small console command. |
| 5 | OG image style | **Match `cyber.trackr.live` / `trackr.live` family** — dark bg, monospace, accent cyan, brand glyph top-left | Brand consistency across the trackr.live family. Per the §visual identity memory. |
| 6 | Sitemap pagination | **Sitemap index + 10 K-URL chunks** | Standard for large catalogs. 15 K satellites need ~2 chunks; future Alpha-5 growth needs more. |
| 7 | Deep-link parameter format | **Query-string** (`?sat=&lat=&lon=&t=`) | Simpler than path-based; survives Cesium's hash usage. |
| 8 | Real glTF acquisition strategy | **Best-effort manual sourcing** during chunk 7 (no automated pipeline) | Sourcing is the bottleneck, not loading. Drop in what we can find; document the licensing checklist. |

---

## § III — Tentative chunk plan (subject to revision before chunk 1 starts)

| # | Chunk | Net new code | Tests | Depends on |
|---|---|---|---|---|
| **1** | **SatNOGS radio enrichment** | Migration for `satellite_radio` table; `SatnogsClient` + ingester; detail-panel §Radio section; per-NORAD endpoint. | ~10 PHP + ~3 JS | Phase-2 satellites schema |
| **2** | **PWA manifest + service worker** | `manifest.webmanifest`, icons, `register-sw.ts`, offline shell + cache strategy for `/text/*`. | ~3 e2e | nothing |
| **3** | **OpenAPI 3.1 + Swagger UI** | swagger-php attribute annotations on every controller; `bin/console openapi:dump` emits `public/openapi.json`; `/api/v1/docs` serves Swagger UI. | ~5 PHP | every Phase 1-4 endpoint |
| **4** | **OG image generator + per-page meta** | PHP GD card generator; `GET /og/satellite/{norad}.png` etc; `og:image` meta tags in shell.php + every text route. | ~5 PHP | nothing |
| **5** | **Sitemap + canonical + JSON-LD** | `bin/console sitemap:build` emits chunked sitemap.xml files; canonical link tags + structured data per page; `robots.txt`. | ~5 PHP + sitemap-validator smoke | every Phase 1-4 endpoint |
| **6** | **Deep-link sharing + Web Share** | Query-param parser in `<sat-app>`; topbar Share button using `navigator.share()` with copy-link fallback. | ~3 JS + e2e | nothing |
| **7** | **Real glTF for marquee + Phase 6 outline** | Source CC0/CC-BY glTF for at least 3 marquee satellites; `MarqueeSpec.gltfUri` populated; `public/models/CREDITS.md` updated. README closes Phase 5; `docs/phase6.md` outline. | smoke e2e | Phase 4 chunk 8A infrastructure |

**Estimated 40-55 new tests**, bringing total from ~285 → ~330.
**Bundle target:** ≤300 KB gzipped main + service worker; marquee glTFs lazy.

---

## § IV — Dependencies & risk

- **SatNOGS DB drift.** Their transmitter schema is community-curated and occasionally breaks. **Mitigation:** snapshot-fixture regression test on the parser + tolerate missing fields silently.
- **PWA install heuristics on iOS.** Safari has its own rules and they change. **Mitigation:** include both `apple-touch-icon` + `manifest.webmanifest` with Apple-specific keys.
- **OpenAPI annotation density.** Every controller needs annotations + DTO classes. ~25 endpoints × 10 lines = 250 lines of annotation chrome. **Mitigation:** spread across chunk 3; consider trimming to "summary + response code" if it gets tedious.
- **OG image generation cost.** Generating PNGs per-request is slow; need server-side caching. **Mitigation:** 6h cache file per `?sat=N` with content-hash check.
- **glTF licensing.** Free + correctly-licensed satellite glTFs are still rare. **Mitigation:** ship what we find, fall back per-sat to the chunk-3 procedural primitive.

---

## § V — Carry-forward deferrals being absorbed

These were called out in earlier phases' "deferred to Phase 5" lists and need explicit landing or re-deferral:

- **Real glTF for marquee** (Phase 3 chunk 3, Phase 4 chunk 8) → **chunk 7 above**
- **AMSAT / SatNOGS radio enrichment** (Phase 4 § V) → **chunk 1 above**
- **ICS calendar export** (Phase 4 § V) → still deferred; small win, never urgent
- **Per-station sensor-cone half-angles** (Phase 3 chunk 4, Phase 4 § V) → still deferred
- **Custom-GLSL atmosphere shader** (Phase 3 chunk 1) → still deferred

---

## § VI — Workflow reminders

Same chunk-by-chunk pacing as Phases 2/3/4:

- Each chunk pauses for explicit OK before the next starts.
- Granular commits per sub-chunk; push after each.
- README updated at the end of every chunk to reflect current state.
- Dev server bound to `0.0.0.0` for phone testing throughout.
- End each chunk with: README update → commit/push → report → pause.
