import { describe, expect, it, beforeEach, afterEach, vi } from 'vitest';

// Mock the cesium module so we don't pull the full library into vitest.
// Only the bits the Clock facade actually touches need to exist.
vi.mock('cesium', () => ({
  JulianDate: {
    fromDate: (d: Date) => ({ _date: d }),
    toDate: (j: { _date: Date }) => j._date,
  },
  ClockRange:  { CLAMPED: 1 },
  ClockStep:   { SYSTEM_CLOCK_MULTIPLIER: 2 },
}));

// Import AFTER vi.mock so the mock is in place.
const { Clock, FULL_WINDOW_MS, SAFE_WINDOW_MS } = await import('../../resources/js/time/Clock');

interface FakeCesiumClock {
  startTime: { _date: Date } | null;
  stopTime: { _date: Date } | null;
  currentTime: { _date: Date } | null;
  clockRange: number;
  clockStep: number;
  multiplier: number;
  shouldAnimate: boolean;
  onTick: {
    addEventListener: (fn: (clock: FakeCesiumClock) => void) => void;
    removeEventListener: (fn: (clock: FakeCesiumClock) => void) => void;
    _fire: () => void;
  };
}

function makeFakeCesiumClock(): FakeCesiumClock {
  const listeners: Array<(clock: FakeCesiumClock) => void> = [];
  const c: FakeCesiumClock = {
    startTime: null,
    stopTime: null,
    currentTime: null,
    clockRange: 0,
    clockStep: 0,
    multiplier: 1,
    shouldAnimate: false,
    onTick: {
      addEventListener: (fn) => {
        listeners.push(fn);
      },
      removeEventListener: (fn) => {
        const i = listeners.indexOf(fn);
        if (i >= 0) listeners.splice(i, 1);
      },
      _fire: () => listeners.forEach((fn) => fn(c)),
    },
  };
  return c;
}

const NOW = new Date('2026-05-14T20:00:00Z').getTime();

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date(NOW));
});
afterEach(() => {
  vi.useRealTimers();
});

describe('Clock facade', () => {
  it('initializes Cesium clock with ±7d bounds and current=now, paused', () => {
    const cc = makeFakeCesiumClock();
    new Clock(cc as unknown as never);

    expect(cc.startTime?._date.getTime()).toBe(NOW - FULL_WINDOW_MS);
    expect(cc.stopTime?._date.getTime()).toBe(NOW + FULL_WINDOW_MS);
    expect(cc.currentTime?._date.getTime()).toBe(NOW);
    expect(cc.clockRange).toBe(1);   // CLAMPED
    expect(cc.clockStep).toBe(2);    // SYSTEM_CLOCK_MULTIPLIER
    expect(cc.multiplier).toBe(1);
    expect(cc.shouldAnimate).toBe(false);
  });

  it('getTimeMs round-trips with setTimeMs', () => {
    const cc = makeFakeCesiumClock();
    const clock = new Clock(cc as unknown as never);
    clock.setTimeMs(NOW + 3600_000);
    expect(clock.getTimeMs()).toBe(NOW + 3600_000);
  });

  it('setTimeMs clamps below the start bound', () => {
    const cc = makeFakeCesiumClock();
    const clock = new Clock(cc as unknown as never);
    clock.setTimeMs(NOW - FULL_WINDOW_MS - 86_400_000); // 8 days back
    expect(clock.getTimeMs()).toBe(NOW - FULL_WINDOW_MS);
  });

  it('setTimeMs clamps above the end bound', () => {
    const cc = makeFakeCesiumClock();
    const clock = new Clock(cc as unknown as never);
    clock.setTimeMs(NOW + FULL_WINDOW_MS + 86_400_000); // 8 days ahead
    expect(clock.getTimeMs()).toBe(NOW + FULL_WINDOW_MS);
  });

  it('play/pause/togglePlay drive shouldAnimate', () => {
    const cc = makeFakeCesiumClock();
    const clock = new Clock(cc as unknown as never);
    expect(clock.isPlaying()).toBe(false);
    clock.play();
    expect(clock.isPlaying()).toBe(true);
    expect(cc.shouldAnimate).toBe(true);
    clock.pause();
    expect(cc.shouldAnimate).toBe(false);
    clock.togglePlay();
    expect(cc.shouldAnimate).toBe(true);
    clock.togglePlay();
    expect(cc.shouldAnimate).toBe(false);
  });

  it('setSpeed updates the multiplier', () => {
    const cc = makeFakeCesiumClock();
    const clock = new Clock(cc as unknown as never);
    expect(clock.getSpeed()).toBe(1);
    clock.setSpeed(60);
    expect(clock.getSpeed()).toBe(60);
    expect(cc.multiplier).toBe(60);
  });

  it('isExtrapolated false within ±48h, true beyond', () => {
    const cc = makeFakeCesiumClock();
    const clock = new Clock(cc as unknown as never);
    expect(clock.isExtrapolated()).toBe(false);
    clock.setTimeMs(NOW + SAFE_WINDOW_MS - 1000);
    expect(clock.isExtrapolated()).toBe(false);
    clock.setTimeMs(NOW + SAFE_WINDOW_MS + 1000);
    expect(clock.isExtrapolated()).toBe(true);
    clock.setTimeMs(NOW - SAFE_WINDOW_MS - 1000);
    expect(clock.isExtrapolated()).toBe(true);
  });

  it('resetToNow snaps current back to wall-clock now', () => {
    const cc = makeFakeCesiumClock();
    const clock = new Clock(cc as unknown as never);
    clock.setTimeMs(NOW - 3600_000);
    expect(clock.getTimeMs()).toBe(NOW - 3600_000);
    clock.resetToNow();
    expect(clock.getTimeMs()).toBe(NOW);
  });

  it('onTick subscribes and is invoked with the current ms; unsubscribe removes', () => {
    const cc = makeFakeCesiumClock();
    const clock = new Clock(cc as unknown as never);
    clock.setTimeMs(NOW + 5000);
    const seen: number[] = [];
    const unsubscribe = clock.onTick((ms) => seen.push(ms));

    cc.onTick._fire();
    cc.onTick._fire();
    expect(seen).toEqual([NOW + 5000, NOW + 5000]);

    unsubscribe();
    cc.onTick._fire();
    expect(seen.length).toBe(2); // no new event after unsubscribe
  });

  it('getBounds recomputes from wall-clock (advances even while paused)', () => {
    const cc = makeFakeCesiumClock();
    const clock = new Clock(cc as unknown as never);
    expect(clock.getBounds().nowMs).toBe(NOW);

    vi.setSystemTime(new Date(NOW + 60_000));
    const bounds = clock.getBounds();
    expect(bounds.nowMs).toBe(NOW + 60_000);
    expect(bounds.startMs).toBe(NOW + 60_000 - FULL_WINDOW_MS);
    expect(bounds.endMs).toBe(NOW + 60_000 + FULL_WINDOW_MS);
  });
});
