self.addEventListener('install',event=>event.waitUntil(self.skipWaiting()));
self.addEventListener('activate',event=>event.waitUntil(self.clients.claim()));

self.addEventListener('push',event=>{
  let data={};
  try{data=event.data?event.data.json():{};}catch(error){data={};}
  const count=Math.max(1,Number(data.badge_count)||1);
  event.waitUntil(Promise.all([
    self.registration.showNotification(data.title||'GNEX Admin',{
      body:data.body||'Chat user baharu.',
      tag:data.tag||'gnex-admin-chat',
      icon:'images/gnex-home-192.png',
      badge:'images/gnex-home-192.png',
      data:{...data,url:data.url||'topup-admin.html'},
      renotify:true,
      silent:false,
      requireInteraction:true,
      timestamp:Date.now(),
      vibrate:[180,80,180]
    }),
    'setAppBadge' in self.navigator?self.navigator.setAppBadge(count):Promise.resolve()
  ]));
});

self.addEventListener('notificationclick',event=>{
  event.notification.close();
  const target=new URL(event.notification.data?.url||'topup-admin.html',self.location.origin).href;
  event.waitUntil((async()=>{
    const windows=await self.clients.matchAll({type:'window',includeUncontrolled:true});
    for(const client of windows){if('focus' in client){await client.navigate(target);return client.focus();}}
    return self.clients.openWindow?self.clients.openWindow(target):undefined;
  })());
});
