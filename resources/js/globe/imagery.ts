import * as Cesium from 'cesium';

/**
 * Returns an imagery provider for the globe.
 * - If a Cesium ion token is supplied, returns the ion world imagery (Bing Aerial, asset 3).
 * - Otherwise returns an OpenStreetMap fallback (no token needed, lower quality).
 */
export async function createImageryProvider(
  cesiumIonToken: string
): Promise<Cesium.ImageryProvider> {
  if (cesiumIonToken) {
    Cesium.Ion.defaultAccessToken = cesiumIonToken;
    return Cesium.IonImageryProvider.fromAssetId(3);
  }

  return new Cesium.UrlTemplateImageryProvider({
    url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    credit: new Cesium.Credit(
      '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors',
      true
    ),
    maximumLevel: 19,
  });
}
