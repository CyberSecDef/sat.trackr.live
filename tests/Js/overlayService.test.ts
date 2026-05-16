import { describe, it, expect, beforeEach } from 'vitest';
import { OverlayService } from '../../resources/js/overlays/OverlayService';

function makeStorage(): Storage & { snapshot(): Record<string, string> } {
  const map = new Map<string, string>();
  return {
    getItem: (k: string) => (map.has(k) ? (map.get(k) as string) : null),
    setItem: (k: string, v: string) => void map.set(k, v),
    removeItem: (k: string) => void map.delete(k),
    clear: () => map.clear(),
    key: (i: number) => Array.from(map.keys())[i] ?? null,
    get length() { return map.size; },
    snapshot: () => Object.fromEntries(map.entries()),
  };
}

describe('OverlayService', () => {
  let storage: Storage & { snapshot(): Record<string, string> };

  beforeEach(() => {
    storage = makeStorage();
  });

  it('starts with sane defaults (ribbons + marquee on; stations + lightPollution off)', () => {
    const svc = new OverlayService(storage);
    const state = svc.current();
    expect(state.ribbons).toBe(true);
    expect(state.marquee).toBe(true);
    expect(state.stations).toBe(false);
    expect(state.lightPollution).toBe(false);
  });

  it('persists toggles to localStorage', () => {
    const svc = new OverlayService(storage);
    svc.setEnabled('stations', true);
    svc.setEnabled('marquee', false);

    const persisted = JSON.parse(storage.snapshot()['sat:overlays']);
    expect(persisted.stations).toBe(true);
    expect(persisted.marquee).toBe(false);
    expect(persisted.ribbons).toBe(true);            // unchanged
  });

  it('rehydrates from storage on a fresh instance', () => {
    storage.setItem('sat:overlays', JSON.stringify({
      ribbons: false, marquee: true, stations: true, lightPollution: true,
    }));
    const svc = new OverlayService(storage);
    expect(svc.isEnabled('ribbons')).toBe(false);
    expect(svc.isEnabled('stations')).toBe(true);
    expect(svc.isEnabled('lightPollution')).toBe(true);
  });

  it('discards malformed JSON and falls back to defaults', () => {
    storage.setItem('sat:overlays', '{not valid json');
    const svc = new OverlayService(storage);
    expect(svc.current()).toEqual({
      ribbons: true, marquee: true, stations: false, lightPollution: false, aurora: false,
    });
  });

  it('partial persisted state merges defaults for missing keys', () => {
    storage.setItem('sat:overlays', JSON.stringify({ stations: true }));
    const svc = new OverlayService(storage);
    expect(svc.isEnabled('ribbons')).toBe(true);     // default
    expect(svc.isEnabled('stations')).toBe(true);    // persisted
  });

  it('notifies subscribers on change but not on no-op set', () => {
    const svc = new OverlayService(storage);
    const calls: boolean[] = [];
    const unsub = svc.subscribe((s) => calls.push(s.stations));

    expect(calls).toEqual([false]);                  // initial sync emit

    svc.setEnabled('stations', true);
    expect(calls).toEqual([false, true]);

    svc.setEnabled('stations', true);                // no change
    expect(calls).toEqual([false, true]);

    unsub();
    svc.setEnabled('stations', false);
    expect(calls).toEqual([false, true]);            // unsub stopped delivery
  });
});
