import * as Cesium from 'cesium';

const SEVEN_DAYS_MS = 7 * 86_400 * 1000;
const FORTY_EIGHT_HOURS_MS = 48 * 3600 * 1000;

export interface ClockBounds {
  /** Earliest time the user can scrub to (now − 7d). */
  startMs: number;
  /** Latest time the user can scrub to (now + 7d). */
  endMs: number;
  /** Wall-clock "now" used to compute the bounds. */
  nowMs: number;
}

/**
 * Thin facade around Cesium.Clock. Exposes JS millisecond epochs at the
 * boundary so the rest of the app doesn't have to deal with JulianDate.
 *
 * Bounds are clamped to [now − 7d, now + 7d] and recomputed on each call
 * to getBounds() — the wall clock keeps moving even while the user
 * fiddles with the slider.
 */
export class Clock {
  /** ms epoch of the most recent tick. Useful for change detection. */
  public lastTickMs: number;

  /**
   * Phase 6 chunk 1 — read-only escape hatch onto the underlying
   * Cesium.Clock so ConjunctionScene can temporarily rewrite the
   * scrubbable window (startTime / stopTime / clockRange) for replay
   * mode without forcing the Clock facade to grow methods for every
   * one-off use case.
   */
  public get cesium(): Cesium.Clock {
    return this.cesiumClock;
  }

  constructor(private readonly cesiumClock: Cesium.Clock) {
    const nowMs = Date.now();
    this.cesiumClock.startTime = Cesium.JulianDate.fromDate(new Date(nowMs - SEVEN_DAYS_MS));
    this.cesiumClock.stopTime = Cesium.JulianDate.fromDate(new Date(nowMs + SEVEN_DAYS_MS));
    this.cesiumClock.currentTime = Cesium.JulianDate.fromDate(new Date(nowMs));
    this.cesiumClock.clockRange = Cesium.ClockRange.CLAMPED;
    this.cesiumClock.clockStep = Cesium.ClockStep.SYSTEM_CLOCK_MULTIPLIER;
    this.cesiumClock.multiplier = 1;
    this.cesiumClock.shouldAnimate = false; // start paused at "now"
    this.lastTickMs = nowMs;
  }

  getTimeMs(): number {
    return Cesium.JulianDate.toDate(this.cesiumClock.currentTime).getTime();
  }

  setTimeMs(ms: number): void {
    const { startMs, endMs } = this.getBounds();
    const clamped = Math.max(startMs, Math.min(endMs, ms));
    this.cesiumClock.currentTime = Cesium.JulianDate.fromDate(new Date(clamped));
  }

  resetToNow(): void {
    this.setTimeMs(Date.now());
  }

  getSpeed(): number {
    return this.cesiumClock.multiplier;
  }

  setSpeed(multiplier: number): void {
    this.cesiumClock.multiplier = multiplier;
  }

  isPlaying(): boolean {
    return this.cesiumClock.shouldAnimate;
  }

  play(): void {
    this.cesiumClock.shouldAnimate = true;
  }

  pause(): void {
    this.cesiumClock.shouldAnimate = false;
  }

  togglePlay(): void {
    this.cesiumClock.shouldAnimate = !this.cesiumClock.shouldAnimate;
  }

  /** Recomputed each call — wall clock advances while the user is paused. */
  getBounds(): ClockBounds {
    const nowMs = Date.now();
    return {
      startMs: nowMs - SEVEN_DAYS_MS,
      endMs: nowMs + SEVEN_DAYS_MS,
      nowMs,
    };
  }

  /**
   * True iff the current scrub position is outside ±48h of wall-clock now.
   * Used by the timeline UI to surface the §11 "extrapolated" warning.
   */
  isExtrapolated(): boolean {
    const delta = Math.abs(this.getTimeMs() - Date.now());
    return delta > FORTY_EIGHT_HOURS_MS;
  }

  /**
   * Subscribe to clock ticks. Cesium fires these on every render frame
   * (~60Hz) — callers throttle as needed. Returns an unsubscribe handle.
   */
  onTick(callback: (timeMs: number) => void): () => void {
    const handler = (clock: Cesium.Clock): void => {
      const ms = Cesium.JulianDate.toDate(clock.currentTime).getTime();
      this.lastTickMs = ms;
      callback(ms);
    };
    this.cesiumClock.onTick.addEventListener(handler);
    return () => this.cesiumClock.onTick.removeEventListener(handler);
  }
}

export const SAFE_WINDOW_MS = FORTY_EIGHT_HOURS_MS;
export const FULL_WINDOW_MS = SEVEN_DAYS_MS;
