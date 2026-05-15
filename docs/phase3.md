# Phase 3 Design — sat.trackr.live

**Status:** Outline (drafted at the close of Phase 2)
**Scope:** §28 of `req_spec.md` Phase 3 — "Showcase visuals." A render-quality pass that turns the working catalog from Phase 1 + 2 into something visibly *good* on a laptop and not just *correct*.
**Target:** A globe that's pleasant to leave open in a tab. Day/night terminator polish, sun/moon/stars, selected-object orbit ribbons, named 3D models for ISS/Tiangong/Hubble, ground stations with sensor cones, and a light-pollution overlay.

---

## § I — Goals & Acceptance

### Goals

1. **Day/night terminator looks deliberate.** A real solar position-aware shader (not just Cesium's default), city lights on the night side, atmosphere edge at the limb.
2. **Sun, Moon, and stars in their right places.** A starfield background (BSC5 or HYG database, ~9000 stars magnitude-graded) and accurate sun + moon positions for the current scrubbed time.
3. **Orbit ribbons for selected objects.** When a satellite is selected (or pinned), its full ground track appears as a fading ribbon on the globe — past = dim, future = bright. ±1 orbit by default; toggle to ±N orbits.
4. **Real 3D models for the marquee objects.** ISS, Tiangong, Hubble, and a small set of named satellites get glTF models replacing the dot. Models scale-clamped so they're visible at planet zoom.
5. **Ground stations + sensor cones.** Toggle to overlay tracking-station locations (NASA NEN, ESA ESTRACK, AMSAT, etc.) with sensor cones showing field-of-view; click for "currently tracking N satellites" + recent contacts.
6. **Light pollution overlay.** Optional checkerboard overlay (VIIRS or LightPollutionMap.info-style data) so observers can see if the satellite they want to spot will actually be naked-eye visible from their location.

### Acceptance criteria

- [ ] Terminator shader respects scrubbed time (drag the timeline, watch the day/night line move smoothly).
- [ ] Stars visible against the night-side; magnitude-faded so they don't drown the satellite dots.
- [ ] Selecting a satellite renders a ground-track ribbon within 200ms; ribbon updates as time scrubs.
- [ ] ISS / Tiangong / Hubble render as recognizable shapes when zoomed in, dots when zoomed out (level-of-detail switch).
- [ ] Toggle in the topbar (or a new `§ overlays` menu) flips ground stations / light pollution / ribbons / 3D models on and off; settings persist to localStorage.
- [ ] Production bundle stays under 250kB gzipped. The 3D models are lazy-loaded only when zoom + selection put them on screen.
- [ ] All new code passes `make ci`. New visual features have screenshot regression tests where practical (Playwright + visual diff).
- [ ] `README.md` reflects the new overlays; this doc closes when chunks are all marked done.

### Explicitly NOT in Phase 3

Stays for Phase 4+:
- Conjunctions (SOCRATES) and the conjunction-replay scene
- Space weather widget + aurora overlay
- Stats dashboard
- Event feed + RSS
- AMSAT / SatNOGS radio info, sky-charts, ICS export

---

## § II — Open design questions

These need a decision before chunk planning lands. Drafting here so the conversation has a place to anchor.

1. **3D models — Cesium ion or self-host?** Cesium ion's free tier hosts the standard NASA glTFs. Self-hosting them means downloading + placing in `public/models/` (a few MB). Self-host probably wins (offline-friendly, no Cesium ion dependency).

2. **Star catalog — BSC5 or HYG?** BSC5 is ~9100 stars to magnitude 6.5; HYG has 119,617 stars but is overkill for Phase 3. Probably BSC5 + Hipparcos enrichment for the brightest 100.

3. **Terminator shader — custom GLSL or Cesium's `enableLighting`?** Cesium has built-in solar lighting; the question is whether we need a custom fragment shader for atmosphere scattering at the limb. Probably ship Cesium's lighting first, ship custom only if the limb looks flat.

4. **Light pollution data source?** VIIRS night lights raster (NASA, free, ~40MB) gives the best-looking overlay but is a large asset. LightPollutionMap.info has a tiled service. Decide between asset weight and external dependency.

5. **Ground station catalog — manually curated or scraped?** ~50 stations covers NEN + ESTRACK + JAXA + ISRO + commercial. A small JSON file in `resources/data/ground_stations.json` is probably enough; full catalog from satnogs.org if we ever need amateur stations.

6. **Sensor cones — fixed half-angle or per-station?** Real stations have variable FOV. For Phase 3, a uniform 5° cone is probably fine; per-station angles can come in Phase 4.

7. **Ribbon length default — ±1 orbit or ±90 minutes?** ±1 orbit is more semantically meaningful (one full ground track) but mean motion varies. ±90 min is the lazy default.

---

## § III — Tentative chunk plan (subject to revision before chunk 1 starts)

The exact split waits until the open questions above are resolved, but the rough shape:

| # | Chunk | Net new code | Depends on |
|---|---|---|---|
| 1 | Terminator + sun/moon/stars | Enable Cesium lighting; sun/moon position computer; BSC5 star renderer; smoke tests for time-scrub correctness | nothing |
| 2 | Selected-object orbit ribbons | Trail generator (uses chunk-6A `computePasses`-style propagation), Cesium PolylineCollection, ribbon-style fade shader | chunk 1 |
| 3 | 3D models for marquee objects | glTF loader + LOD swap (model when zoom > N, dot otherwise); model placement in `public/models/`; ISS / Tiangong / Hubble seeded | chunk 1 |
| 4 | Ground stations + sensor cones | Curated JSON catalog; new globe layer; toggle in topbar; cone primitive | chunks 1-3 |
| 5 | Light pollution overlay | Decide source (chunk 5 open question); image overlay primitive; toggle | chunks 1-4 |
| 6 | Polish + perf + README + Phase-4 outline | Bundle audit, screenshot tests, lazy-load 3D models, cron entries unchanged, drafts `docs/phase4.md` | all prior |

Estimated 25-35 new tests, mostly visual smoke + a few unit tests for the trail generator. Bundle target: under 250kB gzipped (currently 89kB; budget is generous).

---

## § IV — Dependencies & risk

- **Bundle weight.** The biggest risk. 3D models + star data + light pollution all add weight. Mitigation: lazy-loading + LOD + asset compression.
- **Cesium primitives surface area.** Phase 3 is the first chunk that pushes hard on Cesium's primitive APIs (PointPrimitiveCollection has been enough through Phase 2). Ground-track ribbons + sensor cones use PolylineCollection / ConeGeometry; expect API friction.
- **External data freshness.** Ground stations move. Light pollution changes. Need a refresh story (manual? cron-driven? Phase 4 problem?).

---

## § V — Out of scope for Phase 3 (deferred)

These were considered for Phase 3 but are intentionally pushed:

- N2YO magnitude enrichment of pass predictions (Phase 2 chunk 7 deferral; lands wherever the appetite is in Phase 4)
- "Currently above horizon now?" line in the Visibility section (chunk 7 deferral; same)
- Browser-worker `compute_passes` path (chunk 6 deferral; the server-cached path is already <30ms warm)
- Conjunctions / SOCRATES (Phase 4 per `req_spec.md` §28)
- Space weather widget + aurora overlay (Phase 4)
- Stats dashboard, event feed, RSS (Phase 4)
- AMSAT/SatNOGS, sharing deep links, sitemap, OG images (Phase 5)
