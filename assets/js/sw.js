/* GHOSTLIKE Portal – Service Worker: zeigt Push-Benachrichtigungen und öffnet beim Antippen die passende Stelle im Portal. */
const ICON = '/wp-content/plugins/ghostlike-portal/assets/img/app/icon-192.png';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'GHOSTLIKE Portal', {
    body: d.body || '',
    icon: ICON,
    badge: ICON,
    tag: d.tag || undefined,
    renotify: !!d.tag,
    data: { url: d.url || '/portal/' },
  }));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = new URL(e.notification.data && e.notification.data.url ? e.notification.data.url : '/portal/', self.location.origin).href;
  e.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const w of wins) {
      if (w.url.includes('/portal/')) { await w.focus(); return w.navigate(url); }
    }
    return self.clients.openWindow(url);
  })());
});
