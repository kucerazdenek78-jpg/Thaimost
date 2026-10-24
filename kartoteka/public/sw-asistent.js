/* Service worker aplikace Asistent Thai Most.
   Nic neukládá do mezipaměti (kartotéka obsahuje osobní údaje a musí být vždy aktuální).
   Jen při výpadku spojení ukáže stránku „Bez připojení“ místo chyby prohlížeče. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
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
