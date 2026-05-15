import * as Cesium from 'cesium';
import groundStationsData from '../../data/ground_stations.json';

/**
 * Phase 3 chunk 4A: ground-station overlay.
 *
 * Renders the ~40 stations from `resources/data/ground_stations.json`
 * as colored dots on the globe, each with a thin upward-pointing
 * sensor cone (5° half-angle, 1,000km tall).  Visibility is owned
 * by the chunk-4B OverlayService; this layer just exposes
 * setVisible(boolean).
 *
 * Per-network coloring lets the user tell at a glance which
 * organization runs each site (NEN/DSN red, ESTRACK blue,
 * commercial green/orange).
 */
export interface GroundStation {
  id: string;
  name: string;
  network: string;
  operator: string;
  country: string;
  latitude_deg: number;
  longitude_deg: number;
  altitude_m: number;
}

const STATIONS = groundStationsData as ReadonlyArray<GroundStation>;

const COLOR_FOR_NETWORK: Record<string, Cesium.Color> = {
  NEN:     Cesium.Color.fromCssColorString('#ff5050'),
  DSN:     Cesium.Color.fromCssColorString('#ff5050'),
  ESTRACK: Cesium.Color.fromCssColorString('#5090ff'),
  JAXA:    Cesium.Color.fromCssColorString('#ff80ff'),
  ISRO:    Cesium.Color.fromCssColorString('#ffaa00'),
  KSAT:    Cesium.Color.fromCssColorString('#50ff80'),
  AWS:     Cesium.Color.fromCssColorString('#ff8050'),
  ATLAS:   Cesium.Color.fromCssColorString('#80ffff'),
};

const CONE_HEIGHT_METERS = 1_000_000;            // 1,000 km
const CONE_HALF_ANGLE_DEG = 5;
const CONE_TOP_RADIUS = CONE_HEIGHT_METERS * Math.tan((CONE_HALF_ANGLE_DEG * Math.PI) / 180);

export class GroundStationLayer {
  private readonly points: Cesium.PointPrimitiveCollection;
  private readonly cones: Cesium.PrimitiveCollection;
  private builtCount = 0;

  constructor(private readonly scene: Cesium.Scene) {
    this.points = scene.primitives.add(new Cesium.PointPrimitiveCollection());
    this.cones = scene.primitives.add(new Cesium.PrimitiveCollection());
    this.build();
    this.setVisible(false);                       // hidden by default; OverlayService toggles
  }

  /** Returns the static catalog so callers (tests, UI) can iterate. */
  static catalog(): ReadonlyArray<GroundStation> {
    return STATIONS;
  }

  setVisible(visible: boolean): void {
    this.points.show = visible;
    for (let i = 0; i < this.cones.length; i++) {
      const p = this.cones.get(i) as Cesium.Primitive;
      p.show = visible;
    }
  }

  destroy(): void {
    if (!this.scene.isDestroyed()) {
      this.scene.primitives.remove(this.points);
      this.scene.primitives.remove(this.cones);
    }
  }

  /** Build the static primitives once at construction.  ~40 stations × 1 cone each. */
  private build(): void {
    for (const station of STATIONS) {
      const position = Cesium.Cartesian3.fromDegrees(
        station.longitude_deg,
        station.latitude_deg,
        station.altitude_m,
      );
      const color = COLOR_FOR_NETWORK[station.network] ?? Cesium.Color.YELLOW;

      this.points.add({
        position,
        color,
        pixelSize: 6,
        outlineColor: Cesium.Color.BLACK.withAlpha(0.6),
        outlineWidth: 1,
        id: { kind: 'station', stationId: station.id, name: station.name },
      });

      const coneGeometry = Cesium.CylinderGeometry.createGeometry(
        new Cesium.CylinderGeometry({
          length: CONE_HEIGHT_METERS,
          topRadius: CONE_TOP_RADIUS,
          bottomRadius: 0,                         // apex at station, base in the sky
          vertexFormat: Cesium.PerInstanceColorAppearance.VERTEX_FORMAT,
        })
      );
      if (coneGeometry === undefined) continue;

      // Cesium CylinderGeometry is centered at origin and extends along ±Z.
      // We need its base (apex) at the station and the body extending UP
      // along the local 'up' axis. Lift by length/2 in ENU's +Z direction.
      const enuFrame = Cesium.Transforms.eastNorthUpToFixedFrame(position);
      const liftMatrix = Cesium.Matrix4.fromTranslation(
        new Cesium.Cartesian3(0, 0, CONE_HEIGHT_METERS / 2),
      );
      const modelMatrix = Cesium.Matrix4.multiply(enuFrame, liftMatrix, new Cesium.Matrix4());

      this.cones.add(new Cesium.Primitive({
        geometryInstances: new Cesium.GeometryInstance({
          geometry: coneGeometry,
          modelMatrix,
          attributes: {
            color: Cesium.ColorGeometryInstanceAttribute.fromColor(color.withAlpha(0.18)),
          },
        }),
        appearance: new Cesium.PerInstanceColorAppearance({
          flat: true,
          translucent: true,
        }),
        asynchronous: false,
      }));
      this.builtCount++;
    }
  }

  /** Test/debug affordance: how many stations actually rendered. */
  getBuiltCount(): number {
    return this.builtCount;
  }
}
