import * as Cesium from 'cesium';
import { findMarqueeSpec, type MarqueeSpec, type ShapeKind } from './marqueeRegistry';

/**
 * Phase 3 chunk 3B: 3D-shape layer for marquee satellites.
 *
 * When a satellite is selected AND its NORAD/name is in
 * {@link MARQUEE_SPECS} AND the camera is within
 * {@link LOD_DISTANCE_METERS} of it, we render a colored geometric
 * primitive at the satellite's position instead of (or in addition to)
 * the standard dot.
 *
 * Geometry source today is `Cesium.BoxGeometry` / `Cesium.CylinderGeometry`
 * (procedural, no asset files).  This is an honest stand-in for real
 * glTF — the LOD-swap infrastructure works the same, only the
 * geometry source differs.  To swap in real models later, replace
 * {@link buildPrimitive} with a `Cesium.Model.fromGltfAsync(...)` call
 * keyed on `spec.shape` (or extend `MarqueeSpec` with a glTF URI).
 *
 * Why exaggerated `visualScale` — even ISS (~108m) is sub-pixel at
 * any practical camera zoom.  The marquee shapes are caricatures
 * scaled to ~5km on screen so the user can actually see them when
 * they zoom in to inspect.
 */
/**
 * Cesium has two distinct primitive types we use here:
 *   - Cesium.Primitive  — procedural BoxGeometry / CylinderGeometry
 *   - Cesium.Model       — loaded from a glTF/glb URL via fromGltfAsync()
 *
 * Both have a modelMatrix field we can update each tick; both can be
 * .add()ed to a PrimitiveCollection and .remove()d from one.
 */
type AnyPrimitive = Cesium.Primitive | Cesium.Model;

export class MarqueeShapeLayer {
  private readonly primitives: Cesium.PrimitiveCollection;
  private active: {
    norad: number;
    spec: MarqueeSpec;
    primitive: AnyPrimitive;
  } | null = null;
  /** Token incremented on every show() so async glTF loads from previous
   * selections lose to the current selection in case the user re-clicks
   * mid-load. */
  private loadToken = 0;

  constructor(private readonly scene: Cesium.Scene) {
    this.primitives = scene.primitives.add(new Cesium.PrimitiveCollection());
  }

  /**
   * Show the model for `norad` if it's in the marquee roster AND
   * the camera is within range.  No-op otherwise.  Caller passes the
   * satellite's current ECEF position (from PointPrimitiveLayer).
   */
  show(norad: number, name: string | null, position: Cesium.Cartesian3): void {
    const spec = findMarqueeSpec(norad, name);
    if (spec === null) {
      this.hide();
      return;
    }
    if (!this.cameraIsCloseEnough(position)) {
      this.hide();
      return;
    }
    if (this.active?.norad === norad) {
      // Same satellite — just reposition.
      this.active.primitive.modelMatrix = matrixForPosition(position);
      return;
    }
    this.hide();

    if (spec.gltfUri) {
      // Async path: render nothing until the model loads, then add it
      // iff this satellite is still the active selection.
      const myToken = ++this.loadToken;
      const modelMatrix = matrixForPosition(position);
      const scale = spec.visualScale;
      void Cesium.Model.fromGltfAsync({
        url: spec.gltfUri,
        modelMatrix,
        scale,
      }).then((model: Cesium.Model) => {
        if (myToken !== this.loadToken || this.scene.isDestroyed()) {
          // Selection changed before the model finished loading.
          return;
        }
        this.primitives.add(model);
        this.active = { norad, spec, primitive: model };
      }).catch((err: unknown) => {
        // eslint-disable-next-line no-console
        console.warn(`[MarqueeShapeLayer] glTF load failed for ${spec.label}, falling back to procedural primitive`, err);
        if (myToken !== this.loadToken) return;
        const primitive = this.buildProceduralPrimitive(spec, position);
        this.primitives.add(primitive);
        this.active = { norad, spec, primitive };
      });
      return;
    }

    // Synchronous procedural path (chunk-3A behavior — current default).
    const primitive = this.buildProceduralPrimitive(spec, position);
    this.primitives.add(primitive);
    this.active = { norad, spec, primitive };
  }

  /**
   * Update the active primitive's position (called per tick from the
   * host).  Also re-checks the LOD threshold and hides if the camera
   * has moved too far away.
   */
  update(norad: number | null, name: string | null, position: Cesium.Cartesian3 | null): void {
    if (norad === null || position === null) {
      this.hide();
      return;
    }
    this.show(norad, name, position);
  }

  hide(): void {
    if (this.active === null) return;
    this.primitives.remove(this.active.primitive);
    this.active = null;
  }

  /**
   * True iff the marquee primitive should currently be visible.
   * Useful for callers that want to suppress the dot when the model
   * is on screen.
   */
  isActive(): boolean {
    return this.active !== null;
  }

  destroy(): void {
    if (!this.scene.isDestroyed()) {
      this.scene.primitives.remove(this.primitives);
    }
    this.active = null;
  }

  // ─── Internals ─────────────────────────────────────────────────────────

  private cameraIsCloseEnough(satellitePos: Cesium.Cartesian3): boolean {
    const cam = this.scene.camera.positionWC;
    return Cesium.Cartesian3.distance(cam, satellitePos) < LOD_DISTANCE_METERS;
  }

  private buildProceduralPrimitive(spec: MarqueeSpec, position: Cesium.Cartesian3): Cesium.Primitive {
    const dx = spec.dimensionsMeters.x * spec.visualScale;
    const dy = spec.dimensionsMeters.y * spec.visualScale;
    const dz = spec.dimensionsMeters.z * spec.visualScale;

    const geometry = createGeometry(spec.shape, dx, dy, dz);

    return new Cesium.Primitive({
      geometryInstances: new Cesium.GeometryInstance({
        geometry,
        modelMatrix: matrixForPosition(position),
        attributes: {
          color: Cesium.ColorGeometryInstanceAttribute.fromColor(spec.color),
        },
      }),
      appearance: new Cesium.PerInstanceColorAppearance({
        flat: false,            // honor lighting (terminator)
        translucent: false,
      }),
      releaseGeometryInstances: false,
      asynchronous: false,
    });
  }
}

/** Distance threshold (meters) below which the marquee model takes over from the dot. */
const LOD_DISTANCE_METERS = 5_000_000; // 5,000 km — see chunk-3 design notes

function matrixForPosition(position: Cesium.Cartesian3): Cesium.Matrix4 {
  // Place the primitive centered at the satellite, oriented to local-east-north-up.
  return Cesium.Transforms.eastNorthUpToFixedFrame(position);
}

function createGeometry(shape: ShapeKind, dx: number, dy: number, dz: number): Cesium.Geometry {
  switch (shape) {
    case 'cylinder':
      // Hubble-style tube. Radius is half the largest planar dimension; length is dz.
      return Cesium.CylinderGeometry.createGeometry(
        new Cesium.CylinderGeometry({
          length: dz,
          topRadius: Math.max(dx, dy) / 2,
          bottomRadius: Math.max(dx, dy) / 2,
          vertexFormat: Cesium.PerInstanceColorAppearance.VERTEX_FORMAT,
        })
      ) ?? boxGeometryFallback(dx, dy, dz);

    case 'panel':
    case 'box':
    default:
      return boxGeometryFallback(dx, dy, dz);
  }
}

function boxGeometryFallback(dx: number, dy: number, dz: number): Cesium.Geometry {
  const geometry = Cesium.BoxGeometry.createGeometry(
    Cesium.BoxGeometry.fromDimensions({
      dimensions: new Cesium.Cartesian3(dx, dy, dz),
      vertexFormat: Cesium.PerInstanceColorAppearance.VERTEX_FORMAT,
    })
  );
  if (geometry === undefined) {
    // Cesium docs say this never returns undefined for valid input —
    // hard-fail rather than ship a half-built primitive.
    throw new Error('BoxGeometry.createGeometry returned undefined for valid input');
  }
  return geometry;
}
