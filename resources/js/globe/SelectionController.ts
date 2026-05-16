import * as Cesium from 'cesium';

/** Object shape used by chunk-4A GroundStationLayer as the picked .id. */
export interface StationPick {
  kind: 'station';
  stationId: string;
  name: string;
}

export interface SelectionCallbacks {
  /** Called when a satellite PointPrimitive is picked (id = NORAD).  Null = deselect. */
  onSelect: (norad: number | null) => void;
  /** Optional: called when a ground-station PointPrimitive is picked. */
  onStationSelect?: (pick: StationPick, screenPos: { x: number; y: number }) => void;
}

/**
 * Listens for canvas left-clicks.  Three pick outcomes:
 *
 *   - Picked .id is a number       → satellite (NORAD), onSelect(id)
 *   - Picked .id is a station shape (chunk 4A) → onStationSelect(pick, pos)
 *   - Otherwise                    → onSelect(null) (deselect)
 */
export class SelectionController {
  private handler: Cesium.ScreenSpaceEventHandler;

  constructor(
    private readonly viewer: Cesium.Viewer,
    callbacks: SelectionCallbacks | ((norad: number | null) => void),
  ) {
    // Accept the original (norad)=>void callback to keep existing call sites
    // working without ceremony; new callers can pass the full callbacks object.
    const cb: SelectionCallbacks = typeof callbacks === 'function'
      ? { onSelect: callbacks }
      : callbacks;

    this.handler = new Cesium.ScreenSpaceEventHandler(viewer.scene.canvas);
    this.handler.setInputAction(
      (event: Cesium.ScreenSpaceEventHandler.PositionedEvent) => this.handleClick(event, cb),
      Cesium.ScreenSpaceEventType.LEFT_CLICK,
    );
  }

  destroy(): void {
    this.handler.destroy();
  }

  private handleClick(
    event: Cesium.ScreenSpaceEventHandler.PositionedEvent,
    cb: SelectionCallbacks,
  ): void {
    const picked: unknown = this.viewer.scene.pick(event.position);
    if (picked !== undefined && picked !== null && typeof picked === 'object' && 'id' in picked) {
      const id = (picked as { id: unknown }).id;
      if (typeof id === 'number') {
        cb.onSelect(id);
        return;
      }
      if (
        typeof id === 'object'
        && id !== null
        && 'kind' in id
        && (id as { kind: unknown }).kind === 'station'
      ) {
        const pick = id as StationPick;
        cb.onStationSelect?.(pick, { x: event.position.x, y: event.position.y });
        return;
      }
    }
    cb.onSelect(null);
  }
}
