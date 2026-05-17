# public/models/ — marquee satellite glTF assets

This directory holds 3D models loaded by `MarqueeShapeLayer` when a
marquee satellite is selected and the camera is within ~5,000 km.

## Status — Phase 5 chunk 7

**One real model wired (ISS), six still procedural.**

Phase 5 chunk 7 added the **ISS** glTF (NASA Solar System Exploration,
public-domain) plus the fetcher tooling.  Real model files are
**gitignored** because committing 40+ MB binaries inflates the repo
forever — run `make fetch-models` (or `bin/fetch-marquee-models.sh`)
to populate `public/models/` after cloning.

If the file is absent the procedural primitive renders instead — same
correctness baseline as Phase 4 chunk 8A.  Honest framing: licensing-
clean glTF for amateur-radio cubesats, cargo capsules, and Chinese-
station hardware is genuinely scarce; the remaining six marquee slots
(Tiangong, Hubble, Dragon, Cygnus, Soyuz, Starlink) stay procedural
until contributors find sources.  See `bin/fetch-marquee-models.sh`
for the pattern to add more.

## How to add a real model

1. Source a glTF (.gltf or .glb) that is licensed under CC0, CC-BY,
   Apache-2.0, MIT, or a similarly permissive license that lets us
   redistribute alongside this AGPL-3.0-or-later codebase.
   Good places to look:
     - NASA 3D Resources — but most are STL/OBJ; needs conversion via
       Blender's glTF exporter.
     - KhronosGroup glTF Sample Models — generic shapes, not satellites.
     - Sketchfab — search by license; many ISS / Hubble / etc models
       exist, only some are downloadable + CC-BY.

2. Place the file at `public/models/{slug}.glb` (binary glTF preferred
   for smaller payload).  Slug should be short + ASCII, e.g. `iss.glb`,
   `hubble.glb`, `starlink.glb`.

3. Add a `gltfUri` to the matching `MarqueeSpec` in
   `resources/js/globe/marqueeRegistry.ts`:

   ```ts
   {
     label: 'ISS (ZARYA)',
     norad: 25544,
     shape: 'panel',                    // ignored when gltfUri is set
     dimensionsMeters: { ... },          // ignored when gltfUri is set
     visualScale: 120,                   // becomes the Cesium.Model scale
     color: C('#e8e8e8'),                // ignored when gltfUri is set
     gltfUri: '/models/iss.glb',         // ← new
   },
   ```

4. Add an attribution line to this file under the **Credits** section
   below, including the source URL, author, and exact license string.

5. Verify with `make dev`, click the satellite, zoom in past the
   ~5,000 km LOD threshold.  The Cesium model loads asynchronously;
   `MarqueeShapeLayer` handles a race where the user re-selects before
   the previous load finishes.

If the load fails for any reason, `MarqueeShapeLayer` logs a console
warning and falls back to the procedural primitive — the same
behavior as `gltfUri` being unset.

## Credits

- **iss.glb** — *ISS Stationary* model, NASA Solar System Exploration
  (Public Domain under [NASA's standard image-use guidelines](https://www.nasa.gov/multimedia/guidelines/)).
  Source page: https://solarsystem.nasa.gov/resources/2378/international-space-station-3d-model/
  Direct URL (used by `bin/fetch-marquee-models.sh`):
  `https://solarsystem.nasa.gov/rails/active_storage/blobs/redirect/.../ISS_stationary.glb`
  File size: ~44.5 MB binary glTF 2.0; assets are real-meter scale.
  Mapped to NORAD 25544 in `MarqueeSpec` with `visualScale: 120` so the
  on-screen rendering matches the legacy procedural primitive.

Add lines below as further models get sourced:

```
- tiangong.glb  — © Author Name, CC-BY 4.0, https://source.url/
- hubble.glb    — © Author Name, CC0 1.0,   https://source.url/
```
