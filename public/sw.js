const CACHE_PREFIX = 'truthguard-static';
const CACHE_VERSION = '2026-10-10.1';
const STATIC_CACHE = `${CACHE_PREFIX}-${CACHE_VERSION}`;
const OFFLINE_URL = '/offline.html';

const PRECACHE_URLS = [
  OFFLINE_URL,
  '/manifest.webmanifest',
  '/favicon.ico',
  '/images/truthguard-logo-transparent.png',
  '/pwa/icon-32.png',
  '/pwa/icon-192.png',
  '/pwa/icon-512.png',
  '/pwa/maskable-192.png',
  '/pwa/maskable-512.png',
  '/pwa/apple-touch-icon.png'
];

const STATIC_EXTENSION = /\.(?:css|js|mjs|png|jpg|jpeg|gif|webp|svg|ico|woff2?|ttf)$/i;
const SAFE_STATIC_PREFIXES = ['/build/', '/images/', '/pwa/', '/vendor/tailadmin/'];
const SAFE_STATIC_PATHS = ['/manifest.webmanifest', OFFLINE_URL];
const PRIVATE_PREFIXES = [
  '/admin',
  '/api',
  '/auth',
  '/broadcasting',
  '/dashboard',
  '/detections',
  '/forgot-password',
  '/history',
  '/livewire',
  '/login',
  '/logout',
  '/notifications',
  '/onboarding',
  '/password',
  '/privacy-policy/consent',
  '/profile',
  '/push',
  '/register',
  '/reset-password',
  '/sanctum',
  '/storage'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(STATIC_CACHE)
      .then((cache) => cache.addAll(PRECACHE_URLS))
      .catch(() => undefined)
  );

  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((cacheNames) =>
        Promise.all(
          cacheNames
            .filter((cacheName) => cacheName.startsWith(CACHE_PREFIX) && cacheName !== STATIC_CACHE)
            .map((cacheName) => caches.delete(cacheName))
        )
      )
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET') {
    return;
  }

  if (request.cache === 'only-if-cached' && request.mode !== 'same-origin') {
    return;
  }

  const requestUrl = new URL(request.url);

  if (requestUrl.origin !== self.location.origin) {
    return;
  }

  if (isNavigationRequest(request)) {
    event.respondWith(networkOnlyNavigation(request));
    return;
  }

  if (isSafeStaticRequest(request, requestUrl)) {
    event.respondWith(staleWhileRevalidate(request));
  }
});

function isNavigationRequest(request) {
  return request.mode === 'navigate';
}

function isPrivatePath(pathname) {
  const normalizedPath = pathname === '/' ? '/' : pathname.replace(/\/+$/, '');

  return PRIVATE_PREFIXES.some((prefix) => normalizedPath === prefix || normalizedPath.startsWith(`${prefix}/`));
}

function isSafeStaticRequest(request, requestUrl) {
  if (isPrivatePath(requestUrl.pathname)) {
    return false;
  }

  if (request.headers.get('accept')?.includes('text/html')) {
    return false;
  }

  return (
    SAFE_STATIC_PATHS.includes(requestUrl.pathname) ||
    SAFE_STATIC_PREFIXES.some((prefix) => requestUrl.pathname.startsWith(prefix)) ||
    STATIC_EXTENSION.test(requestUrl.pathname)
  );
}

async function networkOnlyNavigation(request) {
  try {
    return await fetch(request);
  } catch (error) {
    return (
      (await caches.match(OFFLINE_URL)) ||
      new Response('TruthGuard is offline. Reconnect and try again.', {
        status: 503,
        headers: { 'Content-Type': 'text/plain; charset=utf-8' }
      })
    );
  }
}

async function staleWhileRevalidate(request) {
  const cache = await caches.open(STATIC_CACHE);
  const cachedResponse = await cache.match(request);

  const networkResponsePromise = fetch(request)
    .then((response) => {
      if (response && response.ok && response.type === 'basic') {
        cache.put(request, response.clone());
      }

      return response;
    })
    .catch(() => undefined);

  return (
    cachedResponse ||
    networkResponsePromise.then((response) =>
      response ||
      new Response('', {
        status: 504,
        statusText: 'Offline'
      })
    )
  );
}


// FCM sends data-only Web Push messages. One native handler displays them in
// both foreground and background, so no second worker or SDK auto-display is used.
function safeNotificationUrl(value) {
  try {
    const url = new URL(value || '/notifications', self.location.origin);
    const allowed = /^\/(?:notifications(?:\/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})?|profile|claim-reviews|detections\/[0-9]+\/result|dashboard\/fact-checks\/[A-Za-z0-9_-]+)$/;
    if (url.origin === self.location.origin && !url.search && !url.hash && allowed.test(url.pathname)) {
      return url.href;
    }
  } catch { /* Fall back to the protected notification center. */ }
  return `${self.location.origin}/notifications`;
}

// Do not trust a previously queued push after logout or an account switch.
// This cookie-bound endpoint also works when no page/login session is active.
async function authorizedNotification(id) {
  if (!/^[a-f0-9-]{36}$/i.test(id || '')) return null;
  try {
    const response = await fetch(`/push/deliveries/${id}`, {
      credentials: 'include', cache: 'no-store', redirect: 'error',
      headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(10000)
    });
    if (!response.ok) return null;
    return await response.json();
  } catch {
    console.warn('TruthGuard push validation unavailable; notification remains in the in-app history.');
    return null;
  }
}

let pushSequence = Promise.resolve();
self.addEventListener('push', (event) => {
  let payload;
  try { payload = event.data?.json(); } catch { return; }
  const incoming = payload?.data;
  if (!incoming?.notification_id) return;
  // Serialize local deliveries; server retries cannot produce overlapping displays.
  pushSequence = pushSequence.catch(() => {}).then(async () => {
    const data = await authorizedNotification(incoming.notification_id);
    if (!data || (incoming.subscription_id && incoming.subscription_id !== data.subscription_id)) return;
    const receipts = await caches.open('truthguard-push-receipts-v1');
    const key = new URL(`/push/receipts/${data.notification_id}`, self.location.origin).href;
    if (await receipts.match(key)) return;
    await self.registration.showNotification(data.title, {
      body: data.body, icon: '/pwa/icon-192.png', badge: '/pwa/icon-32.png',
      tag: `truthguard-${data.notification_id}`, renotify: false,
      timestamp: Number(data.timestamp) || Date.now(),
      data: { url: safeNotificationUrl(data.url), notificationId: data.notification_id },
    });
    // Store IDs only, never notification text or private URLs, across worker restarts.
    await receipts.put(key, new Response('shown'));
    const keys = await receipts.keys();
    await Promise.all(keys.slice(0, Math.max(0, keys.length - 256)).map(key => receipts.delete(key)));
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    windows.forEach(client => client.postMessage({ type: 'TRUTHGUARD_NOTIFICATION' }));
  });
  event.waitUntil(pushSequence);
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil((async () => {
    const authorized = await authorizedNotification(event.notification.data?.notificationId);
    const url = safeNotificationUrl(authorized?.url);
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const existing = windows.find((client) => client.url === url)
      || windows.find((client) => new URL(client.url).origin === self.location.origin);
    if (existing) {
      try {
        const target = existing.url === url ? existing : await existing.navigate(url);
        if (target) { await target.focus(); return; }
      } catch { /* Closed windows may no longer be navigable. */ }
    }
    await self.clients.openWindow(url);
  })());
});
