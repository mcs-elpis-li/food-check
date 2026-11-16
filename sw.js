/* Food Check service worker: network-first pages, cache-first static assets. */
const CACHE = 'food-check-v2';
const ASSETS = [
  '/',
  '/index.html',
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
  if (url.pathname === '/vlm-process.php') return;

  const isPage = request.mode === 'navigate'
    || url.pathname === '/'
    || url.pathname.endsWith('.html');

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
        .catch(() => caches.match(request).then((cached) => cached || caches.match('/index.html')))
        .then((response) => response || new Response('Offline', {
          status: 503,
          headers: { 'Content-Type': 'text/plain' }
        }))
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
