self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    await cacheCoreAssets();
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    await cleanupOldCaches();
    await self.clients.claim();
  })());
});

const STATIC_CACHE = 'gnex-static-v1';
const IMAGE_CACHE = 'gnex-images-v1';
const MAX_IMAGE_CACHE_ITEMS = 180;
const CORE_ASSETS = [
  '/',
  'index.html',
  'index.css',
  'mobile-nav.js',
  'mobile-nav.css',
  'datacust.js',
  'manifest.webmanifest',
  'images/logo baru gnex .webp',
  'images/logo baru gnex .png',
  'images/logo-gnex-esport-64x64.png',
  'images/logo-gnex-esport-64x64.webp',
  'images/gnex-home-192.png',
  'images/gnex-home-512.png',
  'images/ff-wallpaper.webp',
  'images/ff-logo.webp',
  'images/ml-wallpaper.webp',
  'images/logo-ml.webp',
  'images/pubg-wallpaper.webp',
  'images/pubg-logo.webp',
  'images/BG-GUSION.jpg',
  'images/gtmllogo.png',
  'images/gnexlaga.png',
  'images/King Elite CS Logo Metalic.png',
  'images/jersey/jersey-hero.webp',
  'images/jersey/jersey-size-chart.webp',
  'iklan/banneriklan.png',
  'Card design/ESPORT TOURNAMENT CARD.png.png',
  'Card design/TOPUP GAMING CARD.png',
  'card jersey/JERSEY MERCHANDISE CARD (1).png',
  'card gnex esport/20260611_212528.png',
  'card gnex esport/gnex-guild-open-member.jpeg'
];

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (isImageRequest(request, url)) {
    event.respondWith(cacheFirst(request, IMAGE_CACHE));
    return;
  }

  if (isStaticAsset(url)) {
    event.respondWith(staleWhileRevalidate(request, STATIC_CACHE));
  }
});

async function cacheCoreAssets(){
  const staticCache = await caches.open(STATIC_CACHE);
  const imageCache = await caches.open(IMAGE_CACHE);
  await Promise.allSettled(CORE_ASSETS.map(async (asset) => {
    const url = new URL(asset, self.location.origin);
    const request = new Request(url, {cache:'reload'});
    const response = await fetch(request);
    if (response.ok) {
      const cache = isImageRequest({destination:''}, url) ? imageCache : staticCache;
      await cache.put(request, response);
    }
  }));
}

async function cleanupOldCaches(){
  const keep = new Set([STATIC_CACHE, IMAGE_CACHE]);
  const names = await caches.keys();
  await Promise.all(names.map((name) => {
    if (name.startsWith('gnex-') && !keep.has(name)) {
      return caches.delete(name);
    }
    return Promise.resolve();
  }));
}

function isImageRequest(request, url){
  return request.destination === 'image' || /\.(png|jpe?g|webp|gif|svg|ico)$/i.test(url.pathname);
}

function isStaticAsset(url){
  return /\.(css|js|webmanifest)$/i.test(url.pathname);
}

async function cacheFirst(request, cacheName){
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request, {ignoreSearch:true});
  if (cached) {
    refreshCache(request, cache, true);
    return cached;
  }

  try {
    const response = await fetch(request);
    if (response.ok) {
      await cache.put(request, response.clone());
      await trimCache(cacheName, MAX_IMAGE_CACHE_ITEMS);
    }
    return response;
  } catch (error) {
    return new Response('', {status:504, statusText:'Offline'});
  }
}

async function staleWhileRevalidate(request, cacheName){
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request, {ignoreSearch:true});
  const networkFetch = fetch(request).then((response) => {
    if (response.ok) {
      cache.put(request, response.clone());
    }
    return response;
  }).catch(() => cached);

  return cached || networkFetch;
}

async function refreshCache(request, cache, trimAfterUpdate){
  try {
    const response = await fetch(request);
    if (response.ok) {
      await cache.put(request, response.clone());
      if (trimAfterUpdate) {
        await trimCache(IMAGE_CACHE, MAX_IMAGE_CACHE_ITEMS);
      }
    }
  } catch (error) {
    // Keep the cached copy when offline or the network is slow.
  }
}

async function trimCache(cacheName, maxItems){
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  if (keys.length <= maxItems) return;

  await Promise.all(keys.slice(0, keys.length - maxItems).map((key) => cache.delete(key)));
}

self.addEventListener('message', (event) => {
  if (event.data === 'GNEX_SKIP_WAITING') {
    self.skipWaiting();
    return;
  }

  if (event.data?.type === 'GNEX_WARM_ASSETS') {
    event.waitUntil(warmAssetCache(event.data.urls || []));
  }
});

async function warmAssetCache(urls){
  const cache = await caches.open(IMAGE_CACHE);
  const sameOriginImages = urls
    .map((url) => {
      try {
        return new URL(url, self.location.origin);
      } catch (error) {
        return null;
      }
    })
    .filter((url) => url && url.origin === self.location.origin && isImageRequest({destination:'image'}, url));

  await Promise.allSettled(sameOriginImages.map(async (url) => {
    const request = new Request(url.href, {cache:'reload'});
    const cached = await cache.match(request, {ignoreSearch:true});
    if (cached) return;

    const response = await fetch(request);
    if (response.ok) {
      await cache.put(request, response);
    }
  }));

  await trimCache(IMAGE_CACHE, MAX_IMAGE_CACHE_ITEMS);
}

self.addEventListener('push', (event) => {
  event.waitUntil(showLatestScrimChat());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = new URL(event.notification.data?.url || 'scrim.html', self.location.origin).href;
  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({type:'window', includeUncontrolled:true});
    for (const client of windows) {
      if (client.url.includes('scrim.html') && 'focus' in client) {
        return client.focus();
      }
    }
    if (self.clients.openWindow) {
      return self.clients.openWindow(targetUrl);
    }
    return undefined;
  })());
});

async function showLatestScrimChat(){
  try {
    const response = await fetch('api/scrim.php?push_latest=1', {
      credentials:'include',
      cache:'no-store'
    });
    const payload = await response.json();
    const data = payload.notification || {
      title:'GNEX Scrim',
      body:'Phone notification aktif. Chat baru akan masuk di sini.',
      url:'scrim.html',
      tag:'scrim-chat'
    };
    await self.registration.showNotification(data.title, {
      body:data.body,
      tag:data.tag || 'scrim-chat',
      icon:'images/logo-gnex-esport-64x64.png',
      badge:'images/logo-gnex-esport-64x64.png',
      data:{url:data.url || 'scrim.html'}
    });
  } catch (error) {
    await self.registration.showNotification('GNEX Scrim', {
      body:'Phone notification aktif. Chat baru akan masuk di sini.',
      tag:'scrim-chat',
      icon:'images/logo-gnex-esport-64x64.png',
      data:{url:'scrim.html'}
    });
  }
}
