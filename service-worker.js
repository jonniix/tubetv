const CACHE_NAME = 'tubetv-static-v7';

function shouldBypassServiceWorker(request) {
  const url = new URL(request.url);

  if (request.method !== 'GET') return true;

  const path = url.pathname.toLowerCase();
  const search = url.search.toLowerCase();

  // Pages must always come from the server so UI releases are visible immediately.
  if (request.mode === 'navigate') return true;
  if (path.endsWith('.html') || path.endsWith('/')) return true;
  if (path.endsWith('/index.html') || path.endsWith('/mobile.html')) return true;

  if (search.includes('nosw=1')) return true;
  if (search.includes('fresh=1')) return true;
  if (search.includes('ts=')) return true;
  if (search.includes('v=')) return true;
  if (search.includes('manual=1')) return true;
  if (search.includes('cron=1')) return true;
  if (search.includes('status=1')) return true;
  if (search.includes('diag=1')) return true;

  if (path.endsWith('/admin.html')) return true;

  if (path.includes('/api/')) return true;
  if (path.includes('/data/tubetv-data.json')) return true;
  if (path.endsWith('/tubetv-data.json')) return true;

  return false;
}

self.addEventListener('install', event => {
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const req = event.request;

  if (shouldBypassServiceWorker(req)) {
    event.respondWith(
      fetch(req, { cache: 'no-store' }).catch(err => {
        console.warn('[SW BYPASS FETCH FAILED]', req.url, err);
        throw err;
      })
    );
    return;
  }

  event.respondWith(
    caches.match(req).then(cached => {
      if (cached) return cached;

      return fetch(req).then(response => {
        if (!response || response.status !== 200 || response.type !== 'basic') {
          return response;
        }

        const clone = response.clone();

        caches.open(CACHE_NAME).then(cache => {
          cache.put(req, clone).catch(err => {
            console.warn('[SW CACHE PUT FAILED]', req.url, err);
          });
        }).catch(err => {
          console.warn('[SW CACHE OPEN FAILED]', req.url, err);
        });

        return response;
      });
    }).catch(err => {
      console.warn('[SW FETCH FAILED]', req.url, err);
      return fetch(req, { cache: 'no-store' });
    })
  );
});
