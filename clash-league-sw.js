const CLASH_CACHE_VERSION = "20260814-login-point-fix-v57";
const CLASH_MEDIA_CACHE = `gnex-clash-media-${CLASH_CACHE_VERSION}`;
const CLASH_API_URL = new URL("api/clash-league.php", self.location.origin + "/").href;
const CLASH_HOME_URL = new URL("clash-league.html", self.location.origin + "/").href;
const CLASH_ICON_URL = new URL("images/clash-league.png", self.location.origin + "/").href;
const CLASH_MEDIA_ASSETS = [
  "images/logo baru gnex .webp",
  "images/logo baru gnex .png",
  "images/clash-league.png",
  "images/clash-league-tour-poster.png?v=20260726-2",
  "images/clash-league-icon-192.png?v=20260726",
  "images/clash-league-icon-512.png?v=20260726",
  "images/question-chat-profile.png?v=20260726-2",
  "images/topup-ff-ml-banner.png?v=20260807c",
  "images/horizon-studio-banner.jpg?v=20260807c"
].map((path) => new URL(path, self.location.origin + "/").href);

self.addEventListener("install", (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CLASH_MEDIA_CACHE);
    await Promise.allSettled(CLASH_MEDIA_ASSETS.map(async (assetUrl) => {
      const response = await fetch(assetUrl, {cache: "reload", credentials: "same-origin"});
      if (response.ok) await cache.put(assetUrl, response);
    }));
    await self.skipWaiting();
  })());
});

self.addEventListener("activate", (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys
      .filter((key) => key.startsWith("gnex-clash-media-") && key !== CLASH_MEDIA_CACHE)
      .map((key) => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // Safari/iOS tidak membenarkan navigation response daripada service worker
  // yang melalui redirect (contohnya /order-record -> /order-record/).
  // Biarkan browser mengurus semua page navigation terus melalui network.
  if (request.mode === "navigate" || request.destination === "document") return;

  const isImageRequest = request.destination === "image"
    || /\.(?:avif|gif|jpe?g|png|svg|webp)$/i.test(url.pathname);

  if (isImageRequest) {
    event.respondWith(cacheImage(request));
    return;
  }

  event.respondWith(fetch(request, {
    cache: "no-store",
    credentials: "same-origin",
    redirect: "follow"
  }));
});

async function cacheImage(request){
  const cache = await caches.open(CLASH_MEDIA_CACHE);
  const cached = await cache.match(request, {ignoreVary: true});
  if (cached) return cached;

  try {
    const response = await fetch(request, {
      cache: "no-cache",
      credentials: "same-origin",
      redirect: "follow"
    });
    if (response.ok && (response.type === "basic" || response.type === "default")) {
      try {
        await cache.put(request, response.clone());
      } catch (_) {
        // Storage penuh tidak patut menghalang gambar yang baru dimuat turun.
      }
    }
    return response;
  } catch (error) {
    const fallback = await cache.match(CLASH_ICON_URL, {ignoreSearch: true});
    if (fallback) return fallback;
    throw error;
  }
}

self.addEventListener("message", (event) => {
  if (event.data && event.data.type === "CLASH_SKIP_WAITING") {
    self.skipWaiting();
  }
});

self.addEventListener("push", (event) => {
  event.waitUntil(showLatestNotification(event));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const targetUrl = new URL(event.notification.data?.url || CLASH_HOME_URL, self.location.href).href;

  event.waitUntil((async () => {
    await acknowledgeNotification(event.notification.data?.event_id).catch(() => {});
    const clientList = await self.clients.matchAll({type: "window", includeUncontrolled: true});
    for (const client of clientList) {
      if (client.url.includes("clash-league") && "focus" in client) {
        await client.focus();
        if ("navigate" in client) await client.navigate(targetUrl);
        return;
      }
    }
    await self.clients.openWindow(targetUrl);
  })());
});

async function acknowledgeNotification(eventId){
  if (!eventId) return;
  const subscription = await self.registration.pushManager.getSubscription();
  if (!subscription) return;
  const data = new FormData();
  data.set("action", "acknowledgeNotification");
  data.set("event_id", String(eventId));
  data.set("subscription", JSON.stringify(subscription));
  await fetch(CLASH_API_URL, {
    method: "POST",
    body: data,
    cache: "no-store",
    credentials: "include"
  });
}

function fallbackNotification(){
  return {
    title: "Clash League",
    body: "Update baru Clash League. Buka page untuk semak.",
    url: CLASH_HOME_URL,
    tag: "clash-league"
  };
}

async function readPayloadFromServer(){
  const subscription = await self.registration.pushManager.getSubscription();
  const data = new FormData();
  data.set("action", "pushLatest");
  data.set("sw_version", CLASH_CACHE_VERSION);
  if (subscription) data.set("subscription", JSON.stringify(subscription));

  const response = await fetch(CLASH_API_URL, {
    method: "POST",
    body: data,
    cache: "no-store",
    credentials: "include"
  });

  if (!response.ok) {
    throw new Error(`Push latest failed ${response.status}`);
  }

  const payload = await response.json();
  return payload.notification || fallbackNotification();
}

async function showLatestNotification(event){
  let notification = fallbackNotification();

  try {
    if (event.data) {
      const text = event.data.text();
      if (text) {
        notification = JSON.parse(text);
      } else {
        notification = await readPayloadFromServer();
      }
    } else {
      notification = await readPayloadFromServer();
    }
  } catch (_) {
    notification = fallbackNotification();
  }

  await self.registration.showNotification(notification.title || "Clash League", {
    body: notification.body || notification.message || "Update baru Clash League.",
    icon: notification.icon || CLASH_ICON_URL,
    badge: notification.badge || CLASH_ICON_URL,
    tag: notification.tag || "clash-league",
    renotify: true,
    vibrate: [120, 70, 120],
    timestamp: Date.now(),
    data: {url: notification.url || CLASH_HOME_URL, event_id: notification.event_id || null}
  });
}
