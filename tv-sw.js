const TV_CACHE = 'tubetv-tv-shell-v4';
const TV_SHELL = [
  './tv.html',
  './tv-lite.html',
  './tv-manifest.webmanifest',
  './tv-pwa.js',
  './icons/icon-192.png',
  './icons/icon-512.png',
  './assets/vendor/qrcode.min.js'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(TV_CACHE)
      .then(cache => Promise.allSettled(TV_SHELL.map(url => cache.add(url))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(key => key.startsWith('tubetv-tv-shell-') && key !== TV_CACHE).map(key => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin || url.pathname.includes('/api/') || url.pathname.includes('/data/')) return;
  const cacheKey = new Request(url.origin + url.pathname, { method: 'GET' });
  event.respondWith(
    fetch(request)
      .then(response => {
        if (response.ok) {
          const copy = response.clone();
          caches.open(TV_CACHE).then(cache => cache.put(cacheKey, copy));
        }
        return response;
      })
      .catch(() => caches.match(cacheKey).then(cached => cached || (request.mode === 'navigate' ? caches.match('./tv.html') : new Response('', {status:503}))))
  );
});
