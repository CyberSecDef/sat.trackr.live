# Phase 6 Design (outline) — sat.trackr.live

**Status:** Outline only.  Phases 1-5 are done; Phase 6 is *optional*
and unscoped.  This document captures the things that *could* land
in a follow-up phase if Robert wants to keep building, with no
implication that any of it must.

---

## § I — What Phases 1-5 settled

By the close of Phase 5 chunk 7, the project covers:

- **Catalog & ingest** — 15K+ tracked objects from CelesTrak GP +
  SATCAT, with weekly SatNOGS radio enrichment, hourly SWPC space
  weather, 8-hourly SOCRATES conjunctions, and Space-Track TIP
  reentries.
- **3D SPA** — Cesium globe with BSC5 starfield, lighting, marquee
  3D shapes (one real glTF + procedural fallback), fading orbit
  ribbons, ground stations, VIIRS night-lights overlay, time
  scrubbing.
- **Pass predictions** — SGP4 in a Node subprocess, observer-local,
  cache-friendly; optional N2YO magnitude enrichment when quota
  allows.
- **Text fallback** — every surface mirrored at `/text/*`, fully
  usable without WebGL or JavaScript.
- **Polish & ecosystem** — installable PWA + offline `/text` cache,
  OpenAPI 3.1 + Swagger UI, OG cards per page, sitemap +
  canonical + schema.org JSON-LD, deep-link sharing, real ISS glTF.
- **Quality gates** — PHPUnit, Vitest, Playwright e2e (28 specs),
  PHPStan L6, PHP-CS-Fixer, ESLint, TypeScript strict.

The product is complete in the sense that the original `req_spec.md`
acceptance criteria are met.

---

## § II — Candidates for Phase 6

None of these are committed; they're a menu, not a plan.

### Showcase
- **Conjunction-replay 3D scene.**  Phase 4's data + UI is in place;
  the visual replay of two satellites converging is the kind of
  marquee moment that earns a tweet.  Camera scripting, ribbon
  intersection highlighting, time-stretched scrub.

### Data depth
- **Bring back GPS / Galileo / BeiDou / GLONASS constellations** as
  named layers with per-constellation tinting.  CelesTrak data is
  already pulled; needs a UI toggle + per-constellation visual
  treatment.
- **Sun-synchronous orbit visualization.**  A "ground track over the
  next 24 h" ribbon variant for sun-sync birds (Landsat, Sentinel)
  that shows the cross-track repeat pattern.
- **Decay-event historical scrub.**  Backfill `reentries.actual_decay`
  from Space-Track and add a "play me the last 30 days of decays"
  timelapse.

### UX / mobile
- **Touch-optimized detail panel.**  Phase 3 chunk 5 made the panel
  mobile-friendly; a swipe-up gesture + collapsible sections would
  make it feel native.
- **ICS calendar export** for upcoming passes.  Tiny, but the
  Phase 5 § V "deferred forever" list had it as "small win, never
  urgent" — Phase 6 could be the day.
- **Notifications API for high-visibility passes.**  Opt-in: tell
  me when ISS goes over my house at >40° elevation in the next 7d.

### Performance
- **WebGL2 + WebGPU instanced rendering for the dot layer.**  The
  current point-primitive layer hits 60 fps on desktop but groans
  on lower-end Android.  A custom shader could 4× the draw budget.
- **Server-side TLE propagation cache.**  For groups with stable
  membership (ISS visitors, Hubble servicing), precompute the
  next 24 h of positions on the server and stream as a binary
  payload.

### Real glTF expansion
- Pick up the seven still-procedural marquee slots as contributor
  models become available.  Pattern is documented in
  `public/models/CREDITS.md` and `bin/fetch-marquee-models.sh`.

### Ops
- **Multi-region prod deploy.**  Current setup is DreamHost VPS in
  one DC; an edge-cached frontend with regional API replicas would
  cut TTFB for international traffic.
- **Push-based ingest replacement for cron.**  CelesTrak doesn't
  push, but Space-Track + LL2 do — moving to webhooks would cut
  ingest latency from hours to minutes.

---

## § III — How to start Phase 6

If/when there's appetite:

1. Pick one or two candidates from § II.
2. Write `docs/phase6.md` (real one, this file overwritten) with the
   same § I-VI structure as `docs/phase5.md`: goals, locked
   decisions, chunk plan, dependencies, carry-forwards.
3. Resume the chunk-by-chunk cadence: each chunk pauses for OK,
   granular commits, README + tests updated at each close.

Until that happens the project is in maintenance mode — keep cron
running, keep `composer update` + `npm update` happening on a
reasonable cadence, fix bugs as they surface, monitor space-track
auth (it rotates).
