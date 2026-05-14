import * as Cesium from 'cesium';

/**
 * Listens for canvas left-clicks; if a PointPrimitive is under the
 * cursor, calls the supplied onSelect with its NORAD ID. Empty-space
 * clicks call onSelect(null) so the host can deselect.
 */
export class SelectionController {
  private handler: Cesium.ScreenSpaceEventHandler;

  constructor(
    private readonly viewer: Cesium.Viewer,
    private readonly onSelect: (norad: number | null) => void,
  ) {
    this.handler = new Cesium.ScreenSpaceEventHandler(viewer.scene.canvas);
    this.handler.setInputAction(
      (event: Cesium.ScreenSpaceEventHandler.PositionedEvent) => this.handleClick(event),
      Cesium.ScreenSpaceEventType.LEFT_CLICK,
    );
  }

  destroy(): void {
    this.handler.destroy();
  }

  private handleClick(event: Cesium.ScreenSpaceEventHandler.PositionedEvent): void {
    const picked: unknown = this.viewer.scene.pick(event.position);
    // Picked PointPrimitive surfaces .id which we set to the NORAD number.
    if (
      picked !== undefined &&
      picked !== null &&
      typeof picked === 'object' &&
      'id' in picked &&
      typeof (picked as { id: unknown }).id === 'number'
    ) {
      this.onSelect((picked as { id: number }).id);
    } else {
      this.onSelect(null);
    }
  }
}
