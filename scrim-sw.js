self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

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
