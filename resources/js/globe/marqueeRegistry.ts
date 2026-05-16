/**
 * Phase 3 chunk 3: registry of marquee satellites that get a 3D
 * model when the camera is close enough.  All other satellites
 * remain dots.
 *
 * The registry is intentionally sparse.  Adding a satellite here
 * does NOT add it to the catalog — it just upgrades the visual
 * representation when the user zooms in to inspect it.
 *
 * `nameMatcher` is used for constellation-style entries (Starlink:
 * thousands of NORADs, all visually identical).  Either `norad` or
 * `nameMatcher` must be set; both is allowed (norad takes priority
 * for lookup speed).
 */

import * as Cesium from 'cesium';

export type ShapeKind = 'box' | 'cylinder' | 'panel';

export interface MarqueeSpec {
  /** Display name used in tooltips / debug logs.  Not used for matching. */
  label: string;
  /** Shape primitive used at LOD threshold.  See MarqueeShapeLayer for the geometry. */
  shape: ShapeKind;
  /** Physical box dimensions in meters (x, y, z).  Real-world ISS is ~108m × 73m × 20m. */
  dimensionsMeters: { x: number; y: number; z: number };
  /** How much to multiply the physical size before rendering — without this, even ISS is invisible. */
  visualScale: number;
  /** Albedo color for the rendered primitive. */
  color: Cesium.Color;
  /** Optional exact NORAD match. */
  norad?: number;
  /** Optional name-prefix matcher (case-sensitive) for constellation entries. */
  namePrefix?: string;
  /**
   * Phase 4 chunk 8A — when set, MarqueeShapeLayer loads this glTF via
   * `Cesium.Model.fromGltfAsync()` instead of building a procedural
   * primitive.  Path is relative to the web root, e.g. `/models/iss.glb`.
   * Files belong in `public/models/`; see `public/models/CREDITS.md`
   * for the licensing checklist any new model must clear.
   *
   * Left undefined → falls back to the chunk-3A procedural primitive
   * (Box / Cylinder / Panel by `shape`).  This is deliberate: shipping
   * the swap-in *infrastructure* without paying the asset-acquisition
   * tax means contributors can drop in real models per satellite as
   * they get sourced, without touching MarqueeShapeLayer.
   */
  gltfUri?: string;
}

const C = (css: string) => Cesium.Color.fromCssColorString(css);

/**
 * Marquee roster locked in `docs/phase3.md` § II row 8.
 *
 * Visual scales are deliberately exaggerated so the model is
 * recognizable at typical zoom-to-inspect distances (~5000km
 * camera-to-satellite). Real-scale ISS at 5000km would be ~0.05
 * pixels — useless. ×100 puts it around 5km wide on screen.
 */
export const MARQUEE_SPECS: ReadonlyArray<MarqueeSpec> = [
  // Crewed stations
  {
    label: 'ISS (ZARYA)',
    norad: 25544,
    shape: 'panel',                // wide flat box approximating the truss + arrays
    dimensionsMeters: { x: 108, y: 73, z: 20 },
    visualScale: 120,
    color: C('#e8e8e8'),
  },
  {
    label: 'Tiangong (CSS)',
    norad: 48274,
    shape: 'panel',
    dimensionsMeters: { x: 55, y: 39, z: 16 },
    visualScale: 150,
    color: C('#ffd28a'),
  },

  // Telescope
  {
    label: 'Hubble',
    norad: 20580,
    shape: 'cylinder',
    dimensionsMeters: { x: 14, y: 14, z: 4 },     // diameter, diameter, length-ish (Cesium box stand-in)
    visualScale: 250,
    color: C('#c0c0d8'),
  },

  // Cargo capsules — small, need extra visual scale.
  // NORAD IDs change per launch; we match the most-recent attached
  // visit. Phase 5 cleanup: refresh these from LL2 ingest.
  {
    label: 'Dragon (CRS-30)',
    norad: 59365,
    shape: 'box',
    dimensionsMeters: { x: 4, y: 4, z: 8 },
    visualScale: 600,
    color: C('#f0f0f0'),
  },
  {
    label: 'Cygnus NG-21',
    norad: 60562,
    shape: 'cylinder',
    dimensionsMeters: { x: 3, y: 3, z: 6 },
    visualScale: 700,
    color: C('#e0e0c0'),
  },
  {
    label: 'Soyuz MS-26',
    norad: 60959,
    shape: 'box',
    dimensionsMeters: { x: 3, y: 3, z: 7 },
    visualScale: 700,
    color: C('#88ccaa'),
  },

  // Constellation: a single panel-shaped stand-in reused for every
  // STARLINK-prefixed satellite.  ~7000 sats — only renders for the
  // selected one.
  {
    label: 'Starlink (generic)',
    namePrefix: 'STARLINK',
    shape: 'panel',
    dimensionsMeters: { x: 3.7, y: 1.6, z: 0.3 },
    visualScale: 900,
    color: C('#88aacc'),
  },
];

/**
 * Resolve a NORAD + name to a marquee spec, or null if the satellite
 * isn't in the roster.
 */
export function findMarqueeSpec(norad: number, name: string | null = null): MarqueeSpec | null {
  for (const spec of MARQUEE_SPECS) {
    if (spec.norad === norad) return spec;
  }
  if (name !== null) {
    for (const spec of MARQUEE_SPECS) {
      if (spec.namePrefix && name.startsWith(spec.namePrefix)) return spec;
    }
  }
  return null;
}
