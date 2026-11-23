<?php
// Jednoduchý „Prodejna" režim pro recepci během přechodu na online objednávky – běží jako „čtečka"
// na služebním telefonu/tabletu na recepci: BEZ přihlášení a BEZ navigace kartotéky, chráněno jen PINem
// (nastaví admin ve Správě → Aplikace).
//  1) naskenovat QR poukazu a označit ho jako uplatněný,
//  2) objednávky (žádosti) z webu – tlačítko „Vyřešeno" (ručně vyřízeno, NEplánuje do kalendáře – online plánování
//     bude až ve fázi 2 od nového roku); vyřízené zůstanou vidět označené jako „vyřízeno" (lze vrátit),
//  3) poukazy objednané z webu k vyzvednutí – zapsat fyzické číslo předtištěného poukazu.

// Odemykací helpery (PRODEJNA_COOKIE, prodejna_token/odemceno/cookie) jsou v lib.php (sdílí je i API pro push).

/** Samostatná hlavička čtečky – BEZ navigace kartotéky (jen styl a flash). */
function prodejna_hlava(): void
{
    ?><!doctype html>
<html lang="cs"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Prodejna · Thai Most</title>
<link rel="stylesheet" href="/style.css?v=24">
<link rel="manifest" href="/prodejna-manifest.json">
<meta name="theme-color" content="#6b4a2b">
<style>body.prodejna-app{background:var(--bg,#f6f1ea)}body.prodejna-app > main{max-width:680px;margin:0 auto;padding:12px 14px 40px}</style>
</head><body class="prodejna-app">
<main>
<?php foreach (flash() as [$typ, $msg]): ?><div class="flash <?= h($typ) ?>"><?= h($msg) ?></div><?php endforeach ?>
<?php
}

function prodejna_pata(): void
{
    ?>
</main>
<script>if('serviceWorker' in navigator)navigator.serviceWorker.register('/sw-asistent.js').catch(function(){});</script>
</body></html><?php
}

function stranka_prodejna(): void
{
    session_start_safe();
    $pin = (string)nastaveni('prodejna_pin', '');
    $odemceno = prodejna_odemceno($pin);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        over_csrf();
        $akce = $_POST['akce'] ?? '';
        if ($akce === 'pin') {
            if ($pin === '') { flash('Prodejna zatím není zapnutá – admin musí nastavit PIN ve Správě → Aplikace.', 'chyba'); presmeruj('/?r=prodejna'); }
            if (!limit_ip('prodejna_pin', 15)) { http_response_code(429); exit('Příliš mnoho pokusů, zkuste to prosím za chvíli.'); }
            if (hash_equals($pin, trim((string)($_POST['pin'] ?? '')))) {
                $_SESSION['prodejna'] = 1;
                prodejna_cookie(prodejna_token($pin), time() + 400 * 86400);   // trvalé odemčení na tomto zařízení
                presmeruj('/?r=prodejna');
            }
            flash('Nesprávný PIN.', 'chyba');
            presmeruj('/?r=prodejna');
        }
        if (!$odemceno) { http_response_code(403); exit('Čtečka je zamčená.'); }
        if ($akce === 'odhlasit') {
            unset($_SESSION['prodejna']);
            prodejna_cookie('', time() - 3600);
            presmeruj('/?r=prodejna');
        } elseif ($akce === 'zapsat_cislo') {
            $id = (int)($_POST['poukaz_id'] ?? 0);
            $cislo = mb_substr(trim($_POST['cislo'] ?? ''), 0, 40);
            $p = q1('SELECT * FROM poukazy WHERE id=?', [$id]);
            if ($p && $cislo !== '' && in_array($p['stav'], ['zaplaceno', 'vydano'], true)) {
                q("UPDATE poukazy SET cislo_tisteny=?, stav='vydano' WHERE id=?", [$cislo, $id]);
                flash('Poukaz ' . $p['kod'] . ': číslo ' . $cislo . ' uloženo, označeno jako vydané.');
            }
            presmeruj('/?r=prodejna');
        } elseif ($akce === 'uplatnit') {
            $id = (int)($_POST['poukaz_id'] ?? 0);
            $p = q1('SELECT * FROM poukazy WHERE id=?', [$id]);
            if ($p && in_array($p['stav'], ['zaplaceno', 'vydano'], true)) {
                q("UPDATE poukazy SET stav='uplatneno', zustatek=0 WHERE id=?", [$id]);
                flash('Poukaz ' . $p['kod'] . ' označen jako uplatněný.');
            } elseif ($p) {
                flash('Poukaz ' . $p['kod'] . ' nelze uplatnit (stav: ' . (STAVY_POUKAZU[$p['stav']] ?? $p['stav']) . ').', 'chyba');
            }
            presmeruj('/?r=prodejna' . ($p ? '&kod=' . urlencode($p['kod']) : ''));
        } elseif ($akce === 'vyreseno') {
            $id = (int)($_POST['rez_id'] ?? 0);
            q("UPDATE rezervace SET stav='vyreseno' WHERE id=? AND stav='zadost'", [$id]);
            flash('Objednávka označena jako vyřízená.');
            presmeruj('/?r=prodejna');
        } elseif ($akce === 'vratit') {
            $id = (int)($_POST['rez_id'] ?? 0);
            q("UPDATE rezervace SET stav='zadost' WHERE id=? AND stav='vyreseno' AND zdroj='web'", [$id]);
            flash('Vráceno mezi nevyřízené.');
            presmeruj('/?r=prodejna');
        }
    }

    // --- zamčeno: zadání PINu ---
    if (!$odemceno) {
        prodejna_hlava();
        ?>
        <style>
        .pd-lock{max-width:360px;margin:8vh auto 0;text-align:center}
        .pd-lock h1{font-size:1.5rem;margin:.2em 0}
        .pd-lock .zamek{font-size:3rem;line-height:1}
        .pd-lock input{font-size:1.6rem;text-align:center;letter-spacing:.35em;width:100%;padding:12px;margin:14px 0}
        .pd-lock .btn{width:100%;font-size:1.1rem;padding:13px}
        </style>
        <div class="pd-lock">
          <div class="zamek">🔒</div>
          <h1>Thai Most – Prodejna</h1>
          <?php if ($pin === ''): ?>
            <p class="muted">Čtečka zatím není zapnutá. Administrátor ji zapne ve <b>Správě → Aplikace</b> nastavením PINu.</p>
          <?php else: ?>
            <p class="muted small">Zadejte PIN čtečky.</p>
            <form method="post"><?= csrf_pole() ?><input type="hidden" name="akce" value="pin">
              <input name="pin" inputmode="numeric" autocomplete="off" autofocus placeholder="PIN" aria-label="PIN">
              <button class="btn primary">Odemknout</button>
            </form>
          <?php endif ?>
        </div>
        <?php
        prodejna_pata();
        return;
    }

    // --- odemčeno: samotná Prodejna ---
    $zadosti = q("SELECT r.*, z.jmeno, z.prijmeni, z.telefon, s.nazev AS sluzba, v.popis AS var_popis
        FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id LEFT JOIN varianty v ON v.id=r.varianta_id
        LEFT JOIN sluzby s ON s.id=v.sluzba_id WHERE r.stav='zadost' AND r.zdroj='web' ORDER BY r.vytvoreno DESC")->fetchAll();
    $vyrizene = q("SELECT r.*, z.jmeno, z.prijmeni, z.telefon, s.nazev AS sluzba, v.popis AS var_popis
        FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id LEFT JOIN varianty v ON v.id=r.varianta_id
        LEFT JOIN sluzby s ON s.id=v.sluzba_id WHERE r.stav='vyreseno' AND r.zdroj='web' ORDER BY r.vytvoreno DESC LIMIT 30")->fetchAll();
    $pouk = q("SELECT * FROM poukazy WHERE zdroj='web' AND stav IN ('zaplaceno','vydano') AND TRIM(COALESCE(cislo_tisteny,''))=''
        ORDER BY vytvoreno DESC")->fetchAll();
    $skenKod = trim($_GET['kod'] ?? '');
    $skenP = $skenKod !== '' ? poukaz_podle_kodu($skenKod) : null;

    prodejna_hlava();
    ?>
    <style>
    .prodejna{max-width:640px;margin:0 auto}
    .pd-blok{background:var(--paper,#fff);border:1px solid var(--line,#e5ddd0);border-radius:16px;padding:16px;margin:14px 0}
    .pd-blok h2{margin:.1em 0 .6em;font-size:1.1rem;display:flex;align-items:center;gap:8px}
    .pd-radek{display:flex;gap:10px;align-items:flex-start;justify-content:space-between;border-top:1px solid var(--line,#eee);padding:10px 0}
    .pd-radek:first-of-type{border-top:0}
    .pd-info{flex:1;min-width:0;line-height:1.45}
    .pd-cislo{display:flex;gap:6px;align-items:center}
    .pd-cislo input{width:130px}
    .btn.big{font-size:1.05rem;padding:13px 18px;width:100%}
    .pd-pocet{display:inline-block;min-width:1.5em;text-align:center;background:#e9e2d5;border-radius:999px;padding:1px 9px;font-size:.82rem;font-weight:700}
    .pd-pocet.pd-upoz{background:#c0392b;color:#fff}
    .pd-poukaz,.pd-nenalezen{margin-top:12px;padding:12px;border:1px solid var(--line,#e5ddd0);border-radius:12px;background:var(--paper,#faf7f1)}
    .pd-vyr .btn{white-space:nowrap}
    .pd-hotovo{opacity:.62}
    .pd-hotovo .pd-stav{color:#3f7d53;font-weight:700}
    .pd-vyr-nadpis{margin:14px 0 2px;font-size:.92rem;color:var(--muted,#7a6a55);font-weight:700}
    .pd-paticka{text-align:center;margin:22px 0 4px}
    @media(max-width:520px){.pd-radek{flex-direction:column}.pd-cislo input{width:100%}.pd-cislo{width:100%}}
    </style>
    <div class="prodejna">
    <h1>🛍️ Prodejna</h1>
    <p class="muted small">Jednoduchý režim pro recepci. Online plánování masáží bude až od nového roku – teď objednávky jen vyřizujete (voláte zákazníkům).</p>

    <div id="pdPush" class="pd-blok" style="display:none;background:#f3ede4"></div>

    <section class="pd-blok">
      <h2>📷 Poukaz – uplatnit</h2>
      <button type="button" class="btn big" id="pdScan">📷 Naskenovat QR poukazu</button>
      <div class="field" style="margin-top:8px"><input id="pdKod" autocomplete="off" placeholder="nebo zadej kód ručně a stiskni Enter"></div>
      <?php if ($skenKod !== ''): ?>
        <?php if ($skenP): ?>
          <div class="pd-poukaz">
            <div><b><?= h($skenP['kod']) ?></b> — <?= h(STAVY_POUKAZU[$skenP['stav']] ?? $skenP['stav']) ?>, zůstatek <?= kc($skenP['zustatek']) ?><?= $skenP['platnost_do'] ? ', platí do ' . date('j. n. Y', strtotime((string)$skenP['platnost_do'])) : '' ?></div>
            <div class="muted small"><?= h((string)($skenP['sluzba_text'] ?? '')) ?><?= ($skenP['pro_koho'] ?? '') ? ' · pro ' . h($skenP['pro_koho']) : '' ?></div>
            <?php if (in_array($skenP['stav'], ['zaplaceno', 'vydano'], true)): ?>
              <form method="post" style="margin-top:10px"><?= csrf_pole() ?><input type="hidden" name="akce" value="uplatnit"><input type="hidden" name="poukaz_id" value="<?= (int)$skenP['id'] ?>"><button class="btn big">✅ Označit jako uplatněný</button></form>
            <?php else: ?>
              <p class="muted small" style="margin:8px 0 0">Tento poukaz nelze uplatnit (stav <?= h(STAVY_POUKAZU[$skenP['stav']] ?? $skenP['stav']) ?>).</p>
            <?php endif ?>
            <p class="small" style="margin:8px 0 0"><a href="/?r=prodejna">zavřít</a></p>
          </div>
        <?php else: ?>
          <p class="pd-nenalezen">Kód <b><?= h($skenKod) ?></b> nenalezen v kartotéce (starší papírová série?). <a href="/?r=prodejna">zavřít</a></p>
        <?php endif ?>
      <?php endif ?>
    </section>

    <section class="pd-blok">
      <h2>📥 Objednávky z webu <span class="pd-pocet<?= $zadosti ? ' pd-upoz' : '' ?>"><?= count($zadosti) ?></span></h2>
      <?php if (!$zadosti): ?><p class="muted">Žádné nevyřízené objednávky.</p><?php else: ?>
        <?php foreach ($zadosti as $z): ?>
          <div class="pd-radek">
            <div class="pd-info">
              <b><?= h(trim(($z['jmeno'] ?? '') . ' ' . ($z['prijmeni'] ?? ''))) ?></b> · <a href="tel:<?= h($z['telefon']) ?>"><?= h($z['telefon']) ?></a><br>
              <?= h((string)($z['sluzba'] ?? '')) ?><?= ($z['var_popis'] ?? '') ? ' – ' . h($z['var_popis']) : '' ?><br>
              <span class="muted small">kdy by se hodilo: <?= h((string)($z['preferovano'] ?: '—')) ?></span>
              <?php if (trim((string)($z['poznamka_zakaznik'] ?? '')) !== ''): ?><br><span class="small">pozn.: <?= h($z['poznamka_zakaznik']) ?></span><?php endif ?>
              <?php if (trim((string)($z['poukaz_kod'] ?? '')) !== ''): ?><br><span class="small">🎁 poukaz: <?= poukaz_kod_html($z['poukaz_kod']) ?></span><?php endif ?>
              <br><span class="muted small"><?= h(datum_cz($z['vytvoreno'])) ?></span>
            </div>
            <form method="post" class="pd-vyr"><?= csrf_pole() ?><input type="hidden" name="akce" value="vyreseno"><input type="hidden" name="rez_id" value="<?= (int)$z['id'] ?>"><button class="btn">✅ Vyřešeno</button></form>
          </div>
        <?php endforeach ?>
      <?php endif ?>
      <?php if ($vyrizene): ?>
        <p class="pd-vyr-nadpis">✓ Vyřízené</p>
        <?php foreach ($vyrizene as $z): ?>
          <div class="pd-radek pd-hotovo">
            <div class="pd-info">
              <span class="pd-stav">✓ vyřízeno</span> · <b><?= h(trim(($z['jmeno'] ?? '') . ' ' . ($z['prijmeni'] ?? ''))) ?></b> · <a href="tel:<?= h($z['telefon']) ?>"><?= h($z['telefon']) ?></a><br>
              <span class="muted small"><?= h((string)($z['sluzba'] ?? '')) ?><?= ($z['var_popis'] ?? '') ? ' – ' . h($z['var_popis']) : '' ?> · <?= h(datum_cz($z['vytvoreno'], false)) ?></span>
            </div>
            <form method="post" class="pd-vyr"><?= csrf_pole() ?><input type="hidden" name="akce" value="vratit"><input type="hidden" name="rez_id" value="<?= (int)$z['id'] ?>"><button class="btn" title="Vrátit mezi nevyřízené">↩ Vrátit</button></form>
          </div>
        <?php endforeach ?>
      <?php endif ?>
    </section>

    <section class="pd-blok">
      <h2>🎁 Poukazy z webu k vyzvednutí <span class="pd-pocet"><?= count($pouk) ?></span></h2>
      <p class="muted small" style="margin-top:-4px">Zaplacené online poukazy bez fyzického čísla – při vyzvednutí zapiš číslo z předtištěného poukazu.</p>
      <?php if (!$pouk): ?><p class="muted">Žádné čekající poukazy.</p><?php else: ?>
        <?php foreach ($pouk as $p): ?>
          <div class="pd-radek">
            <div class="pd-info"><b><?= h($p['kod']) ?></b> · <?= (int)$p['hodnota'] > 0 ? kc($p['hodnota']) : h((string)($p['sluzba_text'] ?? '')) ?><br>
              <span class="muted small"><?= ($p['pro_koho'] ?? '') ? 'pro ' . h($p['pro_koho']) . ' · ' : '' ?><?= ($p['objednal_jmeno'] ?? '') ? h($p['objednal_jmeno']) . ' ' : '' ?><?= h((string)($p['objednal_tel'] ?? '')) ?></span></div>
            <form method="post" class="pd-cislo"><?= csrf_pole() ?><input type="hidden" name="akce" value="zapsat_cislo"><input type="hidden" name="poukaz_id" value="<?= (int)$p['id'] ?>">
              <input name="cislo" inputmode="numeric" autocomplete="off" placeholder="číslo poukazu" required><button class="btn">Uložit</button></form>
          </div>
        <?php endforeach ?>
      <?php endif ?>
    </section>

    <?php if (!uzivatel()): ?>
    <p class="pd-paticka"><form method="post" style="display:inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="odhlasit"><button class="btn small" type="submit">🔒 Zamknout čtečku</button></form></p>
    <?php endif ?>
    </div>

    <script src="/skener.js?v=2"></script>
    <script>
    (function(){
      var b=document.getElementById('pdScan'), i=document.getElementById('pdKod');
      function go(code){ code=(code||'').trim(); if(code) location='/?r=prodejna&kod='+encodeURIComponent(code); }
      if(b) b.addEventListener('click', function(){ if(window.ThaiScan) ThaiScan.open(function(t){
        var m=String(t).match(/[?&]k=([A-Za-z0-9]{6,})/); go(m?m[1]:t);
      }); });
      if(i) i.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); go(i.value); } });
      // automatická obnova kvůli novým objednávkám (každých 60 s), když se needituje a není otevřený naskenovaný poukaz
      setInterval(function(){ var ae=document.activeElement; if(!/[?&]kod=/.test(location.search) && (!ae||ae.tagName!=='INPUT')) location.reload(); }, 60000);
    })();
    </script>
    <script>
    // Upozornění na nové objednávky i když je appka zavřená (Web Push). Natvrdo zapnuté:
    // při každém otevření se čtečka znovu přihlásí k odběru, takže upozornění běží dál, i kdyby je někdo omylem vypnul.
    (function(){
      if(!('serviceWorker'in navigator)||!('PushManager'in window)||!('Notification'in window))return;
      var box=document.getElementById('pdPush');
      function u8(s){var p='='.repeat((4-s.length%4)%4),b=(s+p).replace(/-/g,'+').replace(/_/g,'/'),r=atob(b),a=new Uint8Array(r.length);for(var i=0;i<r.length;i++)a[i]=r.charCodeAt(i);return a;}
      function post(u,d){return fetch(u,{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:d?JSON.stringify(d):''});}
      function hide(){ if(box){box.style.display='none';box.innerHTML='';} }
      function subscribe(reg){
        fetch('/api/push_klic').then(function(r){return r.json();}).then(function(o){
          reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:u8(o.klic)})
            .then(function(s){ post('/api/push_subscribe',s); }).catch(function(){});
        }).catch(function(){});
      }
      navigator.serviceWorker.register('/sw-asistent.js').catch(function(){});
      navigator.serviceWorker.ready.then(function(reg){
        if(Notification.permission==='granted'){ subscribe(reg); hide(); return; }   // už povoleno → tiše (znovu)přihlásit, box pryč
        if(Notification.permission==='denied'){ hide(); return; }                     // zablokováno v prohlížeči → nic neukazovat
        // poprvé: prohlížeč vyžaduje jedno klepnutí pro povolení notifikací
        if(box){
          box.style.display='block';
          box.innerHTML='🔔 <b>Zapnout upozornění na nové objednávky</b> – ať telefon zapípá, i když je appka zavřená. <button type="button" class="btn small primary" id="pdpon">Zapnout</button>';
          box.addEventListener('click',function(e){ if(e.target.id!=='pdpon')return;
            Notification.requestPermission().then(function(p){ if(p==='granted') subscribe(reg); hide(); });
          });
        }
      });
    })();
    </script>
    <?php
    prodejna_pata();
}
