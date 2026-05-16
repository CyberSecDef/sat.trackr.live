/* eslint-disable no-restricted-globals */
/**
 * sat.trackr.live service worker — Phase 5 chunk 2B.
 *
 * Scope: '/'.  Strategies:
 *   • cache-first   for /build/*           — Vite emits content-hashed
 *                                            filenames, so the URL itself
 *                                            is the cache key; safe to
 *                                            keep forever within a version
 *   • network-first for /text/* + /text    — fresh when online, falls back
 *                                            to the cached copy when not;
 *                                            on full miss falls back to
 *                                            the offline shell
 *   • passthrough   for everything else    — /api/*, Cesium assets, OSM
 *                                            tiles, satnogs, etc.
 *
 * Cache invalidation: bump CACHE_VERSION on each deploy.  The activate
 * handler purges any cache whose name doesn't start with the current
 * version prefix, so old buckets are reclaimed automatically.  The
 * `skipWaiting()` + `clients.claim()` pair makes new SWs take over on
 * the very next navigation instead of waiting for every tab to close.
 *
 * Built-in opt-out: clients can post {type:'unregister'} and the SW
 * will tear itself down.  Useful in dev when you want plain network.
 */

const CACHE_VERSION = 'v1';
const STATIC_CACHE  = `sat-trackr-static-${CACHE_VERSION}`;
const TEXT_CACHE    = `sat-trackr-text-${CACHE_VERSION}`;
const OFFLINE_URL   = '/offline.html';

/** Resources we want available on first offline visit. */
const PRECACHE_URLS = [
  OFFLINE_URL,
  '/favicon.svg',
  '/manifest.webmanifest',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(STATIC_CACHE);
      await cache.addAll(PRECACHE_URLS);
      await self.skipWaiting();
    })(),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(
        keys
          .filter((k) => !k.endsWith(`-${CACHE_VERSION}`))
          .map((k) => caches.delete(k)),
      );
      await self.clients.claim();
    })(),
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'unregister') {
    void self.registration.unregister().then(() => self.clients.matchAll())
      .then((clients) => clients.forEach((c) => c.navigate(c.url)));
  }
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return; // passthrough cross-origin

  if (url.pathname.startsWith('/build/')) {
    event.respondWith(cacheFirst(request, STATIC_CACHE));
    return;
  }
  if (url.pathname === '/text' || url.pathname.startsWith('/text/')) {
    event.respondWith(networkFirstWithOfflineFallback(request));
    return;
  }
  // Everything else: let the browser do its thing.
});

async function cacheFirst(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  if (cached) return cached;
  try {
    const response = await fetch(request);
    if (response.ok) cache.put(request, response.clone());
    return response;
  } catch (err) {
    // Last-ditch return of the cached copy if put() raced and we lost the network.
    return cached || Response.error();
  }
}

async function networkFirstWithOfflineFallback(request) {
  const cache = await caches.open(TEXT_CACHE);
  try {
    const response = await fetch(request);
    if (response.ok) cache.put(request, response.clone());
    return response;
  } catch (_) {
    const cached = await cache.match(request);
    if (cached) return cached;
    const offline = await caches.match(OFFLINE_URL);
    if (offline) return offline;
    return Response.error();
  }
}
