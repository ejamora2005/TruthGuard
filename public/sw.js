const CACHE_PREFIX = 'truthguard-static';
const CACHE_VERSION = '2026-08-05.2';
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
