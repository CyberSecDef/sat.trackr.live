# Phase 5 Design — sat.trackr.live

**Status:** Plan locked at the close of Phase 4; chunk 1 not yet started.
**Scope:** §28 of `req_spec.md` Phase 5 — "Polish & ecosystem" + the carry-forward deferrals from Phases 2/3/4.
**Target:** Turn the project from "working app" into "shippable open-source artifact": discoverable on the web, friendly on mobile, well-documented for API consumers, and visually distinguished where it matters most.

---

## § I — Goals & Acceptance

### Goals

1. **AMSAT / SatNOGS amateur-radio enrichment.** Layer satellite-radio metadata onto the existing catalog: downlink/uplink frequencies, modulation, transponder modes, beacon status. Surface in the detail panel's `§ Identity` block + a new `§ Radio` section when present.
2. **Mobile PWA optimization.** `manifest.webmanifest`, install-to-homescreen on iOS + Android, service worker for offline `/text` access (read-only catalog after first load), `theme-color` tuned per theme, touch-target sizing pass.
3. **OpenAPI 3.1 docs.** Spec generated from controllers via `swagger-php` attributes; mounted at `/api/v1/openapi.json` + a Swagger UI viewer at `/api/v1/docs`. Phase 1-4 endpoints all documented + linked from README.
4. **OG image generation.** Per-page Open Graph images: `/satellite/{norad}` gets a satellite card; `/text/launches/{uuid}` gets a mission card; `/text/conjunctions` gets a "top close approaches today" card. PHP GD generator with deterministic templates + 6h server-side cache.
5. **Sitemap + SEO.** `/sitemap.xml` enumerating every `/text/satellite/{norad}` + `/text/launches/{uuid}` + the static text views; `robots.txt`; `<link rel="canonical">` everywhere; structured-data JSON-LD for satellites + launches.
6. **Deep-link sharing.** Stable URLs that restore application state: `/?sat=25544&lat=51.5&lon=-0.1&t=2026-05-18T03:14:00Z` opens the SPA with that satellite selected, that observer set, and the clock at that time. Web Share API integration on the topbar.
7. **Real glTF for marquee satellites (Phase 3/4 carry-forward).** Best-effort source CC0/CC-BY models for ISS / Tiangong / Hubble / Dragon / Cygnus / Soyuz / Starlink and drop into `public/models/`; the chunk-8A (Phase 4) infrastructure already loads them when present. Procedural primitive falls back per-sat for anything we can't source.

### Acceptance criteria

- [ ] `make ingest-satnogs` populates a `satellite_radio` table with at least the top 200 amateur-radio satellites; detail panel surfaces frequencies + modulation when known.
- [ ] PWA installable on iOS Safari + Chromium; `/text` routes load offline after first visit; service worker cache invalidates cleanly on rebuild.
- [ ] `/api/v1/openapi.json` validates against the 3.1 schema; Swagger UI renders without errors; every Phase 1-4 endpoint has a documented response shape.
- [ ] `/satellite/25544` returns an `<meta property="og:image">` pointing at a generated card showing ISS's name + intl designator + current state.
- [ ] `/sitemap.xml` enumerates ≥10 K URLs (chunked via `sitemap_index.xml`); passes the W3C sitemap validator.
- [ ] `/?sat=25544&lat=51.5&lon=-0.1` opens the SPA with ISS pre-selected and the observer pre-set; Share button copies the current URL with state via Web Share API (clipboard fallback on desktop).
- [ ] At least 3 marquee satellites (ideally ISS + Hubble + a cargo capsule) ship with real glTF; the others continue to fall back to the chunk-3A procedural primitives.
- [ ] All new code passes `make ci`. Test count grows by ~40-55 (estimate ~30 PHP + ~10 JS + e2e for the PWA install flow + share button).
- [ ] `README.md` reflects the new surfaces; this doc closes when chunks are all done.

### Explicitly NOT in Phase 5 (deferred forever or to a hypothetical Phase 6)

- ICS calendar export for passes — small win, never urgent.
- Conjunction-replay 3D scene (`req_spec.md` §16) — Phase 4 settled the data + UI; the 3D replay belongs in a Phase 6 showcase moment.
- Browser-worker `compute_passes` path (Phase 2 chunk 6 deferral) — **dropped permanently**.
- Per-station sensor-cone half-angles (Phase 3 chunk 4 deferral) — chunk 4's uniform 5° is fine.
- Custom-GLSL atmosphere shader (Phase 3 chunk 1 deferral) — Cesium's default `enableLighting` still looks good.

---

## § II — Locked decisions

| # | Decision | Locked answer |
|---|---|---|
| 1 | SatNOGS data source | **SatNOGS DB JSON API** (db.satnogs.org/api/transmitters/, satellites/) — free, no key |
| 2 | PWA scope | **All routes installable** (manifest root scope `/`) |
| 3 | Offline coverage | **`/text` routes only** — SPA + Cesium too large to cache reasonably; `/text` is small and naturally offline-friendly |
| 4 | OpenAPI generator | **swagger-php attributes on controllers** + `bin/console openapi:dump` |
| 5 | OG image style | **trackr.live family** — dark bg, monospace, accent cyan, brand glyph top-left |
| 6 | Sitemap pagination | **Sitemap index + 10 K-URL chunks** — handles current 15 K + future Alpha-5 growth |
| 7 | Deep-link parameter format | **Query-string** — `?sat=&lat=&lon=&t=` |
| 8 | Real glTF acquisition | **Best-effort manual sourcing during chunk 7** — ship what I can find, fall back per-sat to procedural primitives, document everything in `public/models/CREDITS.md` |
| 9 | All seven chunks ship | Yes |

---

## § III — Chunk plan

| # | Chunk | Net new code | Tests | Depends on |
|---|---|---|---|---|
| **1** | **SatNOGS radio enrichment** | Migration for `satellite_radio` (norad, downlink_mhz, uplink_mhz, mode, status, transponder_type). `SatnogsClient` + `SatnogsIngester` + `bin/console ingest:satnogs` + `make ingest-satnogs`. Detail panel `§ Radio` section renders when known; `/text/satellite/{norad}` mirror. | ~10 PHP + ~3 JS | Phase-2 satellites schema |
| **2** | **PWA manifest + service worker** | `public/manifest.webmanifest` + 192/512 icons; `register-sw.ts` mounted from `shell.php`; service worker uses Workbox-style cache strategies (network-first for `/text/*`, cache-first for `/build/*` assets); offline-shell fallback for failed `/text` navigations. | ~3 e2e | nothing |
| **3** | **OpenAPI 3.1 + Swagger UI** | `swagger-php` composer dep; OA attribute annotations on every controller (~10 lines × 25 endpoints = ~250 lines); `bin/console openapi:dump` emits `public/openapi.json`; `/api/v1/openapi.json` serves it; `/api/v1/docs` mounts Swagger UI. | ~5 PHP + e2e | every Phase 1-4 endpoint |
| **4** | **OG image generator + per-page meta** | PHP GD card generator (deterministic templates per page type); `GET /og/{type}/{id}.png` controller with 6h disk-cache; `og:image` + `twitter:image` meta tags in shell.php + every text route. | ~5 PHP | nothing |
| **5** | **Sitemap + canonical + JSON-LD** | `bin/console sitemap:build` emits `public/sitemap.xml` + chunked `sitemap-{n}.xml` files (10K URLs each, sitemap_index.xml on top); canonical link tags + structured-data JSON-LD per text view; `public/robots.txt`. | ~5 PHP + sitemap-validator smoke | every Phase 1-4 endpoint |
| **6** | **Deep-link sharing + Web Share** | Query-param parser in `<sat-app>` connectedCallback (`?sat=&lat=&lon=&t=`); topbar Share button (`<sat-share-button>`) using `navigator.share()` with clipboard fallback; existing observer + selection plumbing extends without rewrites. | ~3 JS + e2e | nothing |
| **7** | **Real glTF + Phase 6 outline** | Source CC0/CC-BY glTF for at least 3 marquee satellites; populate `MarqueeSpec.gltfUri`; `public/models/CREDITS.md` updated with attribution lines. README closes Phase 5; `docs/phase6.md` outline. | smoke e2e | Phase 4 chunk 8 infrastructure |

**Estimated 40-55 new tests**, bringing total from 293 → ~340.
**Bundle target:** ≤300 KB gzipped main + service-worker registration (~5 KB); glTF lazy as before.

---

## § IV — Dependencies & risk

- **SatNOGS DB schema drift.** Community-curated; occasional breaks. **Mitigation:** snapshot-fixture regression test on the parser + tolerate missing fields silently.
- **PWA install heuristics on iOS.** Safari has its own rules and they change. **Mitigation:** include both `apple-touch-icon` + `manifest.webmanifest` with Apple-specific keys.
- **OpenAPI annotation density.** ~250 lines of attribute chrome across controllers. **Mitigation:** spread across chunk 3 sub-chunks; if attribute volume gets tedious, fall back to summary-only annotations + document response shapes once at the controller-class level.
- **OG image generation cost.** Per-request PNG generation is slow. **Mitigation:** 6h disk-cache file per `?sat=N` keyed on a content-hash; cache buster on the static asset URL when the underlying data changes.
- **glTF licensing.** Free + correctly-licensed satellite glTFs are still rare. **Mitigation:** ship what we find, fall back per-sat to the chunk-3A procedural primitive, document the per-sat status in `public/models/CREDITS.md`.
- **Service-worker invalidation.** Stale `/text` pages stuck on phones after a deploy is the classic PWA gotcha. **Mitigation:** include the deploy hash in the SW cache key; new SW version + `skipWaiting()` on update.

---

## § V — Carry-forward deferrals

These were called out in earlier phases' "deferred to Phase 5" lists. Phase 5 absorbs:

- **Real glTF for marquee** (Phase 3 chunk 3 + Phase 4 chunk 8 carry-forward) → **chunk 7 above** (best-effort manual sourcing)
- **AMSAT / SatNOGS radio enrichment** (Phase 4 § V) → **chunk 1 above**

Still deferred past Phase 5:

- **ICS calendar export** — never urgent; small win
- **Per-station sensor-cone half-angles** (Phase 3 chunk 4) — uniform 5° is fine
- **Custom-GLSL atmosphere shader** (Phase 3 chunk 1) — Cesium default still good
- **Conjunction-replay 3D scene** — Phase 6 showcase moment if ever

Dropped permanently:

- **Browser-worker `compute_passes` path** (Phase 2 chunk 6) — server-cached path is fast enough

---

## § VI — Workflow reminders

Same chunk-by-chunk pacing as Phases 2/3/4:

- Each chunk pauses for explicit OK before the next starts.
- Granular commits per sub-chunk; push after each.
- README updated at the end of every chunk to reflect current state.
- Dev server bound to `0.0.0.0` for phone testing throughout.
- End each chunk with: README update → commit/push → report → pause.
