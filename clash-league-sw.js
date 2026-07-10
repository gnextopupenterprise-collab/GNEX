self.addEventListener("install", (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener("activate", (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener("push", (event) => {
  event.waitUntil(showLatestNotification());
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const targetUrl = new URL(event.notification.data?.url || "clash-league.html", self.location.href).href;

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

async function showLatestNotification(){
  const fallback = {
    title: "Clash League",
    body: "Update baru Clash League. Buka page untuk semak.",
    url: "clash-league.html",
    tag: "clash-league"
  };

  try {
    const subscription = await self.registration.pushManager.getSubscription();
    const data = new FormData();
    data.set("action", "pushLatest");
    if (subscription) data.set("subscription", JSON.stringify(subscription));

    const response = await fetch("api/clash-league.php", {
      method: "POST",
      body: data,
      cache: "no-store",
      credentials: "include"
    });
    const payload = await response.json();
    const notification = payload.notification || fallback;

    await self.registration.showNotification(notification.title || fallback.title, {
      body: notification.body || fallback.body,
      icon: "images/clash-league.png",
      badge: "images/clash-league.png",
      tag: notification.tag || fallback.tag,
      renotify: true,
      data: {url: notification.url || fallback.url}
    });
  } catch (_) {
    await self.registration.showNotification(fallback.title, {
      body: fallback.body,
      icon: "images/clash-league.png",
      badge: "images/clash-league.png",
      tag: fallback.tag,
      data: {url: fallback.url}
    });
  }
}
