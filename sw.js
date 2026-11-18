/* Food Check service worker: network-first pages, cache-first static assets. */
const CACHE = 'food-check-v7';
const ASSETS = [
  '/manifest.json',
  '/icon.svg?v=2',
  '/icon-192.png?v=2',
  '/icon-512.png?v=2',
  '/icon-maskable-512.png?v=2',
  '/apple-touch-icon.png?v=2'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE)
      .then((cache) => cache.addAll(ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // Never cache the API, the token endpoint or the log viewer: they are
  // per-session and carry credentials. The page itself embeds a signed token,
  // so it is always fetched from the network as well.
  if (url.pathname === '/vlm-process.php'
    || url.pathname === '/token.php'
    || url.pathname.startsWith('/log/')
    || url.pathname === '/index.php'
    || url.pathname === '/') {
    event.respondWith(
      fetch(request).catch(() => new Response('Offline', {
        status: 503,
        headers: { 'Content-Type': 'text/plain' }
      }))
    );
    return;
  }

  const isPage = url.pathname.endsWith('.php') || url.pathname.endsWith('.html');

  if (isPage) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (response.ok) {
            const copy = response.clone();
            caches.open(CACHE).then((cache) => cache.put(request, copy));
          }
          return response;
        })
        .catch(() => caches.match(request).then((cached) => cached || new Response('Offline', {
          status: 503,
          headers: { 'Content-Type': 'text/plain' }
        })))
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) return cached;
      return fetch(request).then((response) => {
        if (response.ok) {
          const copy = response.clone();
          caches.open(CACHE).then((cache) => cache.put(request, copy));
        }
        return response;
      });
    })
  );
});
