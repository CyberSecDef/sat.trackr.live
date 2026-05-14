/**
 * Detect WebGL availability. Returns true if the browser can produce
 * either a webgl2 or webgl rendering context. Wrapped in try/catch
 * because some restricted contexts (e.g. iOS Lockdown Mode) throw
 * instead of returning null.
 */
export function hasWebGL(): boolean {
  try {
    const canvas = document.createElement('canvas');
    return !!(
      canvas.getContext('webgl2') ||
      canvas.getContext('webgl') ||
      // Older Edge/IE 11 used "experimental-webgl"
      canvas.getContext('experimental-webgl')
    );
  } catch {
    return false;
  }
}
