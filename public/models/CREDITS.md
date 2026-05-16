# public/models/ — marquee satellite glTF assets

This directory holds 3D models loaded by `MarqueeShapeLayer` when a
marquee satellite is selected and the camera is within ~5,000 km.

## Status

**Empty by default.**  Phase 4 chunk 8A shipped the swap-in
*infrastructure* (`MarqueeSpec.gltfUri` + the loader branch in
`MarqueeShapeLayer.show()`) but did **not** ship the model files.
Sourcing free, correctly-licensed glTF/glb of every marquee satellite
in one session would have been a yak-shave; better to ship the
hooks and let real models drop in as they're found.

While this directory is empty, every marquee satellite falls back to
the Phase 3 chunk 3A procedural primitive (a colored Cesium
`BoxGeometry` / `CylinderGeometry` per `shape` field).  That's the
*correct* baseline, not a bug.

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

*No third-party models are bundled yet.*  Add lines here as models
get added:

```
- iss.glb       — © Author Name, CC-BY 4.0, https://source.url/
- tiangong.glb  — © Author Name, CC0 1.0,   https://source.url/
```
