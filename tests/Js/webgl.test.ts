import { describe, expect, it, beforeEach } from 'vitest';

// hasWebGL() touches document.createElement('canvas') and asks for GL
// contexts. Mock the document/canvas surface just enough that the
// function can run.
function installCanvasMock(getContext: (type: string) => unknown): void {
  const fakeCanvas = { getContext };
  // @ts-expect-error: stubbing a global for the test only
  global.document = { createElement: () => fakeCanvas };
}

beforeEach(() => {
  // Reset the global before each test.
  // @ts-expect-error: clearing the stub
  delete global.document;
});

describe('hasWebGL', () => {
  it('returns true when webgl2 is available', async () => {
    installCanvasMock((type) => (type === 'webgl2' ? {} : null));
    const { hasWebGL } = await import('../../resources/js/util/webgl');
    expect(hasWebGL()).toBe(true);
  });

  it('falls back to webgl when webgl2 is unavailable', async () => {
    installCanvasMock((type) => (type === 'webgl' ? {} : null));
    const { hasWebGL } = await import('../../resources/js/util/webgl');
    expect(hasWebGL()).toBe(true);
  });

  it('falls back to experimental-webgl for legacy browsers', async () => {
    installCanvasMock((type) => (type === 'experimental-webgl' ? {} : null));
    const { hasWebGL } = await import('../../resources/js/util/webgl');
    expect(hasWebGL()).toBe(true);
  });

  it('returns false when no WebGL context is available', async () => {
    installCanvasMock(() => null);
    const { hasWebGL } = await import('../../resources/js/util/webgl');
    expect(hasWebGL()).toBe(false);
  });

  it('returns false when getContext throws (e.g. iOS Lockdown Mode)', async () => {
    installCanvasMock(() => {
      throw new Error('WebGL is disabled in this context');
    });
    const { hasWebGL } = await import('../../resources/js/util/webgl');
    expect(hasWebGL()).toBe(false);
  });
});
