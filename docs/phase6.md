# Phase 6 Design — sat.trackr.live

**Status:** Plan locked 2026-05-16; chunk 1 not yet started.
**Scope:** The marquee showcase moment Phase 4 deferred — a 3D replay of
two satellites converging at their closest approach (TCA).
**Target:** Convert the conjunction *data* Phase 4 built into something
visually striking and shareable. Tweet-bait, basically.

---

## § I — Goals & Acceptance

### Goals

1. **A dedicated `/conjunction/{primary}/{secondary}` SPA route** that
   loads a focused scene showing only the two satellites involved in
   their next predicted close approach.
2. **Chase camera framing.** The camera follows the midpoint between
   the two satellites and orients to keep both in frame throughout the
   ±5 min replay window.
3. **HUD overlay** with live miss-distance, relative velocity, satellite
   names, NORAD IDs, and a T-minus countdown to TCA.
4. **Replay controls** — load paused at T−2 min with a prominent ▶ Play
   button so deep-link recipients get setup context before motion
   starts; scrubbable timeline bracketed at the ±5 min window with a
   visual marker at the TCA moment itself.
5. **Entry points** from `/text/conjunctions` rows + the events Atom
   feed; deep-link sharing reuses the chunk-6 Web Share button.

### Acceptance criteria

- [ ] `/conjunction/25544/44713` loads, fetches the latest TCA for that
      pair, enters replay mode with both sats visible and the rest of
      the catalog hidden.
- [ ] The chase camera keeps both satellites in frame across the full
      ±5 min window without the user touching the camera.
- [ ] HUD shows miss distance updating in real time as the satellites
      approach + part, plus relative velocity, names, NORAD IDs, and
      countdown.
- [ ] Replay loads paused at T−2 min; clicking ▶ Play starts the clock;
      pause/seek work via the replay timeline.
- [ ] At TCA itself, a visual indicator (small glowing ring or short
      flash) calls out the closest-approach moment.
- [ ] `/text/conjunctions` rows have a "▶ Replay" link that opens the
      scene; Atom feed conjunction entries link to the same.
- [ ] Existing chunk-6 Share button produces a URL that restores the
      replay (route + paused/play state preserved is a nice-to-have
      but not required; route-only is the floor).
- [ ] All new code passes `make ci`. Test count grows by ~20-30
      (estimate ~10 PHP + ~10 JS + ~5 Playwright).
- [ ] README closes Phase 6; this doc retires.

### Explicitly NOT in Phase 6

- Replay of *historical* conjunctions (TCA in the past) — the current
  ingester only keeps upcoming SOCRATES rows. Backfill is its own
  project.
- Multi-conjunction comparison view ("show me the 5 worst this week
  side by side").
- The other Phase 6 outline candidates: GNSS layers, ICS export, push
  notifications, WebGPU dots — all stay in `docs/phase6.md`'s former
  outline as Phase 7+ material.

---

## § II — Locked decisions

| # | Decision | Locked answer |
|---|---|---|
| 1 | Primary theme | **Showcase: conjunction-replay 3D scene** |
| 2 | Size | **Focused: 3-4 chunks** (final plan = 4) |
| 3 | Camera mode | **Chase camera at midpoint** between the two sats |
| 4 | Replay time window | **±5 min around TCA** |
| 5 | Playback on entry | **Paused at T−2 min** with prominent ▶ Play button |
| 6 | Scene scope | **Just the two satellites + their ribbons**; hide rest of catalog |
| 7 | Marquee glTF carry-forward | **Skip** — leave six remaining slots procedural |
| 8 | URL scheme | `/conjunction/{primary}/{secondary}` (latest TCA wins; no explicit `/tca` segment until a user actually asks for one) |
| 9 | Sitemap inclusion | **No** — there are ~145K SOCRATES rows; would bloat the index. Conjunction-replay URLs are discoverable via the events feed + /text listing instead. |

---

## § III — Chunk plan

| # | Chunk | Net new code | Tests | Depends on |
|---|---|---|---|---|
| **1** | **Routing + replay scene scaffold** | New `/conjunction/{primary}/{secondary}` SPA route in `Kernel.php` + `SpaShellController` (resolves the latest conjunction for the pair from the DB, passes context to the shell template). `App.ts.connectedCallback` detects replay mode, fetches the conjunction via existing `/api/v1/conjunctions/{primary}/{secondary}`, enters replay state. New `ConjunctionScene` class on the globe layer: configures both sats' marquee shapes + ribbons, hides every other entity, frames the chase camera at the midpoint, sets the clock to TCA − 2 min paused. | ~3 PHP + ~5 JS | Phase 4 chunk 2 conjunctions API |
| **2** | **HUD overlay + replay timeline** | New `<sat-conjunction-hud>` Lit element — fixed overlay (corner card) with live miss distance (computed from current sat positions), relative velocity (cached from the conjunction row), names + NORAD IDs, T-minus countdown. Replay-specific timeline override (`<sat-conjunction-timeline>` or extension of existing Timeline): bracket markers at −5/−2/0/+2/+5 min, prominent ▶ play/pause, draggable seek head. Visual TCA marker — small accent-cyan ring at the midpoint position that pulses for ~1 s straddling TCA. | ~10 JS | chunk 1 |
| **3** | **Entry points + sharing** | "▶ Replay" links injected into `/text/conjunctions` rows (server-side, via `TextConjunctionListController`) and the conjunction entries in the Atom events feed (`AtomEventsController` + `EventsAggregator`). Deep-link `/conjunction/N/M` URLs are reachable from chunk-6's Share button automatically (the URL is the route; no extra wiring needed). README mention. | ~5 PHP | chunks 1+2 |
| **4** | **Tests + README + Phase 6 close** | Vitest for the replay state machine (`ConjunctionScene` setup/teardown, clock window math). Playwright e2e: navigate to `/conjunction/25544/44713`, HUD appears with right names, click ▶ Play, time advances, click pause works. README closes Phase 6; this doc retires. | ~5 PHP + ~5 JS + ~3 e2e | chunks 1-3 |

**Estimated 20-30 new tests**, bringing total from 361 → ~385.
**Bundle target:** ≤200 KB gzipped main (current 158.95 KB; +HUD + scene state shouldn't break 200).

---

## § IV — Dependencies & risk

- **Chase camera math.** Cesium's camera API is finicky; "frame two
  moving points at all times" is non-trivial. **Mitigation:** look at
  `Cesium.Camera.lookAt` + computed `BoundingSphere` of the two sat
  positions; tune the multiplier so they sit comfortably inside the
  frustum, not at the edges. Fall back to a slightly-zoomed-out
  static viewpoint above the midpoint if chase math breaks.
- **Live miss-distance accuracy.** We have SGP4 in a Web Worker
  already (Phase 2 chunk 6); pulling pos for two sats every frame and
  Euclidean-distancing them is cheap, but at relative velocities of
  ~14 km/s a frame-stepping clock skip could miss the TCA exactly.
  **Mitigation:** use the conjunction row's stored `tca_range_km` as
  the authoritative miss; the live HUD value is for visual feel
  only — disclose this in the HUD if they diverge >1%.
- **Two ribbons in the same scene.** Phase 3 chunk 5 ribbons render
  one sat at a time. Need to extend to two without performance
  regression. **Mitigation:** cap each at ±5 min worth of orbit
  (much shorter than the chunk-5 default of 1-3 orbits).
- **Replay state vs share URL.** Restoring "paused at T−2 min" is
  trivial (always the entry state); restoring "playing, currently at
  T+1 min" via deep link would need extra query params. **Mitigation:**
  Phase 6 doesn't promise this; the share URL just gets you to the
  scene's entry state. Good enough.

---

## § V — Carry-forward deferrals

These were in the Phase 6 outline as candidates; we explicitly
deferred them past Phase 6:

- **GNSS constellation layers** (Galileo / BeiDou / GLONASS)
- **Sun-synchronous ground-track ribbons** for Landsat/Sentinel
- **Decay-event historical timelapse**
- **Touch-optimized detail panel** (swipe-up gesture)
- **ICS calendar export** for upcoming passes
- **Notifications API** for high-elevation passes
- **WebGPU instanced rendering** for the dot layer
- **Server-side TLE propagation cache**
- **Remaining six marquee glTFs** — accepted as ad-hoc contributor PRs
- **Multi-region prod deploy / push-based ingest** — ops work

These remain candidates for an eventual Phase 7+ if there's appetite.

---

## § VI — Workflow reminders

Same chunk-by-chunk pacing as Phases 2-5:

- Each chunk pauses for explicit OK before the next starts.
- Granular commits per sub-chunk; push after each.
- README updated at the end of every chunk to reflect current state.
- Dev server bound to `0.0.0.0` for phone testing throughout.
- End each chunk with: README update → commit/push → report → pause.
