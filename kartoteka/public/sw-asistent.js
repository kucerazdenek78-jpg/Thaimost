/* Service worker aplikace Asistent Thai Most.
   Nic neukládá do mezipaměti (kartotéka obsahuje osobní údaje a musí být vždy aktuální).
   Jen při výpadku spojení ukáže stránku „Bez připojení“ místo chyby prohlížeče. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
// Push notifikace: přijde „prázdný“ push, text si dotáhneme z /api/push_info (podle přihlášení v kartotéce).
self.addEventListener('push', function (e) {
  e.waitUntil(
    fetch('/api/push_info', { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .catch(function () { return null; })
      .then(function (d) {
        d = d || {};
        return self.registration.showNotification(d.title || 'Thai Most', {
          body: d.body || 'Nová poptávka – otevřete kartotéku',
          tag: d.tag || 'tm-poptavka', renotify: true, lang: 'cs',
          icon: '/asistent-192.png', badge: '/asistent-192.png',
          data: { url: d.url || '/?r=dnes' }
        });
      })
  );
});

self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var cil = (e.notification.data && e.notification.data.url) || '/?r=dnes';
  e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (cs) {
    for (var i = 0; i < cs.length; i++) { if ('focus' in cs[i]) { if ('navigate' in cs[i]) cs[i].navigate(cil); return cs[i].focus(); } }
    if (clients.openWindow) return clients.openWindow(cil);
  }));
});

self.addEventListener('fetch', function (e) {
  if (e.request.mode !== 'navigate') return;
  e.respondWith(fetch(e.request).catch(function () {
    return new Response('<!doctype html><html lang="cs"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
      '<title>Bez připojení</title><body style="font:18px/1.5 system-ui,sans-serif;background:#f6f1ea;color:#2b2520;text-align:center;padding:60px 20px">' +
      '<div style="font-size:56px">📡</div><h1 style="font-size:22px">Kartotéka není dostupná</h1>' +
      '<p>Zkontrolujte připojení k internetu a zkuste to znovu.</p>' +
      '<button onclick="location.reload()" style="font:inherit;padding:12px 22px;border:0;border-radius:10px;background:#b07a3a;color:#fff">Zkusit znovu</button></body></html>',
      { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
  }));
});
