# Phase 3 Design — sat.trackr.live

**Status:** Plan locked at the close of Phase 2; chunk 1 not yet started.
**Scope:** §28 of `req_spec.md` Phase 3 — "Showcase visuals." A render-quality pass that turns the working catalog from Phase 1 + 2 into something visibly *good* on a laptop and not just *correct*.
**Target:** A globe that's pleasant to leave open in a tab. Day/night terminator polish, sun/moon/stars, selected-object orbit ribbons, named 3D models for ISS/Tiangong/Hubble + Dragon/Cygnus/Soyuz cargo capsules + a Starlink stand-in, ground stations with sensor cones, and a light-pollution overlay.

---

## § I — Goals & Acceptance

### Goals

1. **Day/night terminator looks deliberate.** Cesium's `enableLighting` driving the scrubbed time, city lights on the night side, atmosphere edge at the limb (custom GLSL only if the default looks flat).
2. **Sun, Moon, and stars in their right places.** A starfield background (BSC5, ~9000 stars magnitude-graded) and accurate sun + moon positions for the current scrubbed time.
3. **Orbit ribbons for selected objects.** When a satellite is selected, its full ground track appears as a fading ribbon on the globe — past dim, future bright. ±1 orbit by default; toggle to ±N orbits.
4. **Real 3D models for the marquee objects.** ISS, Tiangong, Hubble, Dragon, Cygnus, Soyuz, plus a single Starlink stand-in reused for the whole constellation. Models scale-clamped + LOD-swapped so they're recognizable when zoomed in, dots when zoomed out.
5. **Ground stations + sensor cones.** Toggle to overlay tracking-station locations (NASA NEN, ESA ESTRACK, JAXA, ISRO, ~10 commercial) with uniform 5° sensor cones; click for "currently tracking N satellites" computed client-side via the chunk-6 elevation logic.
6. **Light pollution overlay.** Optional VIIRS night-lights raster (~40MB, lazy-loaded only when toggled on) so observers can see if the satellite they want to spot will actually be naked-eye visible.

### Acceptance criteria

- [ ] Terminator respects scrubbed time (drag the timeline, watch the day/night line move smoothly).
- [ ] BSC5 stars visible against the night-side; magnitude-faded so they don't drown the satellite dots.
- [ ] Selecting a satellite renders a ground-track ribbon within 200ms; ribbon updates as time scrubs and disappears on deselect.
- [ ] ISS / Tiangong / Hubble / Dragon / Cygnus / Soyuz / Starlink render as recognizable shapes when zoomed in (zoom < ~2000 km), dots when zoomed out.
- [ ] New `§ overlays` topbar menu flips ground stations / light pollution / ribbons / 3D models on and off; settings persist to localStorage.
- [ ] Production main bundle stays under 250KB gzipped (current 89KB; budget ~160KB cushion).
- [ ] Light pollution raster only downloaded when the user toggles the overlay on.
- [ ] Playwright + visual-diff baseline screenshots pass for terminator + stars + ribbons + 3D models in chunk 6.
- [ ] All new code passes `make ci`. Test count grows by ~26-31 (estimate ~10 PHP + ~15-20 JS + Playwright suite).
- [ ] `README.md` reflects the new overlays and tooling; this doc closes when chunks are all marked done.

### Explicitly NOT in Phase 3 (deferred)

Pushed to Phase 4+:
- Conjunctions (SOCRATES) and the conjunction-replay scene
- Space weather widget + aurora overlay
- Stats dashboard
- Event feed + RSS
- AMSAT / SatNOGS radio info, sky-charts, ICS export
- N2YO magnitude enrichment (chunk-6 deferral)
- Browser-worker `compute_passes` path (chunk-6 deferral; server-cached path is already <30ms warm)
- Per-station sensor-cone half-angles + amateur-station catalog (Phase 5)

---

## § II — Locked decisions (was "open questions")

| # | Decision | Rationale |
|---|---|---|
| 1 | **Self-host glTF in `public/models/`** | Offline-friendly, no Cesium ion dependency. Lazy-loaded only when on-screen. |
| 2 | **BSC5 starfield (~9000 stars to mag 6.5)** | ~150KB gzipped, ships in main bundle. HYG was overkill; "no stars" was too sparse. |
| 3 | **Cesium `enableLighting` for terminator** | Free + easy. Custom GLSL pushed to chunk 6 polish if the limb looks flat. |
| 4 | **VIIRS night-lights raster (~40MB, lazy)** | Best-looking option. Self-hosted, no external service dependency. Only paid for when user toggles overlay on. |
| 5 | **Manually-curated ground stations (~50)** | NEN + ESTRACK + JAXA + ISRO + ~10 commercial. Amateur (satnogs.org) deferred to Phase 5. |
| 6 | **Uniform 5° sensor cone half-angle** | Simple. Per-station angles deferred to Phase 4. |
| 7 | **±1 orbit ribbon default** | Semantically meaningful per object. User can toggle to ±N orbits. |
| 8 | **3D model roster** | ISS / Tiangong / Hubble + Dragon / Cygnus / Soyuz cargo capsules + a single Starlink stand-in reused for the constellation. |
| 9 | **Playwright + visual-diff in chunk 6** | Catches subtle regressions in terminator + ribbons + 3D model placement. ~150MB devDependency cost accepted. |
| 10 | **Above-horizon-now line in chunk 6 polish** | Small chunk-6-deferred item that fits naturally with the existing §Visibility section; ~50 lines. |

---

## § III — Chunk plan

| # | Chunk | Net new code | Tests | Bundle delta | Depends on |
|---|---|---|---|---|---|
| **1** | **Terminator + sun/moon/stars** | Enable Cesium `enableLighting`; subsolar/sublunar position computer for scrubbed time; BSC5 starfield (`resources/data/bsc5.json`, magnitude-graded); inline atmosphere edge if Cesium's limb looks flat. | 5-8 (time-scrub correctness, sun/moon position, magnitude binning) + Playwright baseline | Main +150KB | nothing |
| **2** | **Selected-object orbit ribbons** | Trail generator (±1 orbit forward + ±1 orbit back, propagation via the same SGP4 path as the worker); `Cesium.PolylineCollection`; fade shader (past dim, future bright); reactive to time-scrub + observer changes; ribbon-length toggle on the panel. | ~5 + UI smoke | Main +5KB | chunk 1 |
| **3** | **3D models for marquee objects** | Self-host glTF in `public/models/` (ISS / Tiangong / Hubble / Dragon / Cygnus / Soyuz + single Starlink); LOD swap (model when zoom < ~2000 km, dot otherwise); lazy-loaded only when on-screen + selected. | 3-5 (LOD switch logic + loader smoke) | Lazy ~few MB | chunk 1 |
| **4** | **Ground stations + sensor cones** | Curated `resources/data/ground_stations.json` (~50 stations: NEN / ESTRACK / JAXA / ISRO / commercial); `PointPrimitiveCollection` for sites + uniform-5° `ConeGeometry`; new `§ overlays` topbar menu; click → "currently tracking N satellites" via chunk-6 elevation logic. | ~5 | Main +10KB | chunks 1-3 |
| **5** | **Light pollution overlay** | VIIRS night-lights raster (~40MB, lazy-loaded); image overlay primitive layered on the globe; toggle in `§ overlays`; deploy artifact grows by 40MB. | ~3 (toggle smoke + asset present) | Lazy +40MB | chunks 1-4 |
| **6** | **Polish + perf + Playwright + Phase-4 outline** | Bundle audit (target ≤250KB gzipped main); lazy-load orchestration verified; "above horizon now?" line in §Visibility; Playwright + visual-diff baseline screenshots for chunks 1-4; README closes Phase 3; `docs/phase4.md` drafts conjunctions + space weather work; cron entries unchanged. | ~5 + Playwright suite | None | all prior |

**Estimated 26-31 new tests**, bringing total from 198 → ~225-230.
**Bundle target:** ≤250KB gzipped main + lazy assets (~few MB models, 40MB raster).

---

## § IV — Dependencies & risk

- **Bundle weight.** Biggest risk. Star data + ground stations land in the main bundle (~165KB additional); 3D models + light pollution raster are lazy. Mitigation: actively monitor `make build` output in chunks 1, 4, 6.
- **Cesium primitives surface area.** Phase 3 is the first chunk that pushes hard on Cesium's primitive APIs (`PointPrimitiveCollection` has been enough through Phase 2). Ground-track ribbons + sensor cones use `PolylineCollection` / `ConeGeometry`; expect API friction.
- **External data freshness.** Ground stations move occasionally (~once/year). Light pollution raster updates annually from NASA. Refresh story: manual JSON edits for chunk 4; raster swap-in during chunk 6 polish or on demand.
- **Playwright tooling weight.** ~150MB of devDependencies is non-trivial; chunk 6 will need to verify it doesn't break the existing `make ci` targets.

---

## § V — Out of scope for Phase 3 (truly deferred)

These were considered for Phase 3 and pushed:

- N2YO magnitude enrichment of pass predictions (Phase 4 — depends on N2YO quota guard wiring + UX value uncertain)
- Browser-worker `compute_passes` path (Phase 4 if ever — server-cached path is already <30ms warm)
- Conjunctions / SOCRATES (Phase 4 per `req_spec.md` §28)
- Space weather widget + aurora overlay (Phase 4)
- Stats dashboard, event feed, RSS (Phase 4)
- AMSAT / SatNOGS, sharing deep links, sitemap, OG images (Phase 5)
- Per-station sensor-cone half-angles (Phase 5)
- Amateur ground-station catalog (Phase 5)
- Custom-GLSL atmosphere shader (Phase 5 unless chunk 1 finds the default unacceptable)

---

## § VI — Workflow reminders

Same chunk-by-chunk pacing as Phase 2:

- Each chunk pauses for explicit OK before the next starts.
- Granular commits per sub-chunk; push after each.
- README updated at the end of every chunk to reflect current state.
- Dev server bound to `0.0.0.0` for phone testing throughout.
- End each chunk with: README update → commit/push → report → pause.
