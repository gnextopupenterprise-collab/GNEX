const CLASH_CACHE_VERSION = "20260726-pwa-v4";
const CLASH_API_URL = new URL("api/clash-league.php", self.location.origin + "/").href;
const CLASH_HOME_URL = new URL("clash-league.html", self.location.origin + "/").href;
const CLASH_ICON_URL = new URL("images/clash-league.png", self.location.origin + "/").href;

self.addEventListener("install", (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener("activate", (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.map((key) => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  event.respondWith(fetch(request, {
    cache: "no-store",
    credentials: "same-origin",
    redirect: "follow"
  }));
});

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
    data: {url: notification.url || CLASH_HOME_URL}
  });
}
