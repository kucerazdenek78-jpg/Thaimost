<?php
// Společná kostra stránek kartotéky.

/** Stránka otevřená uvnitř plovoucího okna asistenta (iframe, ?okno=1): bez horní lišty a bez další plovoucí ikony. */
function je_okno(): bool { return isset($_GET['okno']) || je_app(); }
/** Asistent spuštěný jako nainstalovaná aplikace (?app=1): celá obrazovka, vlastní horní lišta. */
function je_app(): bool { return isset($_GET['app']); }

function hlavicka(string $titul, string $aktivni = ''): void
{
    $GLOBALS['__stranka_aktivni'] = $aktivni;
    $u = uzivatel();
    $zadosti = $u ? (int)qv("SELECT COUNT(*) FROM rezervace WHERE stav='zadost'") : 0;
    $poukazyCekaji = $u ? (int)qv("SELECT COUNT(*) FROM poukazy WHERE stav='objednano'") : 0;
    $menu = [
        'dnes' => 'Dnes' . ($zadosti ? " <b class=\"cnt\">$zadosti</b>" : ''),
        'kalendar' => '📅 Den',
        'zakaznici' => 'Zákazníci',
        'volno' => 'Volné termíny',
        'dostupnost' => 'Volna a přesčasy' . (($kn = $u ? count(konflikty_dostupnosti()) : 0) ? " <b class=\"cnt\">$kn</b>" : ''),
        'poukazy' => 'Poukazy' . ($poukazyCekaji ? " <b class=\"cnt\">$poukazyCekaji</b>" : ''),
        'karty' => 'Karty',
        'tym' => 'Tým',
        'smeny' => 'Směny',
        'nastaveni' => 'Nastavení',
    ];
    if ($u && ($u['role'] ?? '') === 'admin') $menu['uzivatele'] = 'Uživatelé';
    ?><!doctype html>
<html lang="cs"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($titul) ?> · Kartotéka Thai Most</title>
<link rel="stylesheet" href="/style.css?v=12">
<?php if (je_okno() && !je_app()): ?><base target="_top"><?php endif ?>
<link rel="manifest" href="/?r=manifest">
<meta name="theme-color" content="#6b4a2b">
<link rel="apple-touch-icon" href="/asistent-192.png">
</head><body<?= je_okno() ? ' class="okno' . (je_app() ? ' app' : '') . '"' : '' ?>>
<?php if ($u && !je_okno()): ?>
<header class="top">
  <a class="logo" href="/?r=dnes">Thai Most <span>kartotéka</span></a>
  <nav><?php foreach ($menu as $k => $t): ?><a href="/?r=<?= $k ?>"<?= $aktivni === $k ? ' class="on"' : '' ?>><?= $t ?></a><?php endforeach ?></nav>
  <div class="gs"><input id="gs" type="search" placeholder="🔍 příjmení, telefon, kód karty / poukazu" autocomplete="off"><button type="button" id="gscan" class="gscan" title="Skenovat QR kód karty fotoaparátem">📷</button><div id="gsr" class="gsr" hidden></div></div>
  <a class="btn primary" href="/?r=rez_nova">+ Rezervace</a>
  <span class="who"><?= h($u['jmeno']) ?> · <a href="/?r=logout">odhlásit</a></span>
</header>
<?php endif ?>
<main>
<?php if ($u && !je_okno() && function_exists('demo_pocet') && demo_pocet()): ?><div class="flash vzor">🧪 <b>VZOR</b> – v systému jsou vzorová data na zkoušení (<?= demo_pocet() ?> zákazníků se štítkem VZOR). Před ostrým provozem je smažte v <a href="/?r=nastaveni">Nastavení</a>.</div><?php endif ?>
<?php foreach (flash() as [$typ, $msg]): ?><div class="flash <?= h($typ) ?>"><?= h($msg) ?></div><?php endforeach ?>
<?php
}

function paticka(): void
{
    ?></main>
<?php if (uzivatel() && !je_okno() && ($GLOBALS['__stranka_aktivni'] ?? '') !== 'ai') asistent_ikona(); ?>
<?php if (uzivatel() && !je_okno()): ?>
<div id="poplach" hidden></div>
<script>
// HLASITÝ POPLACH: masérka na tabletu stiskla „To nejsem já“ → červená obrazovka + siréna na každé stránce, dokud to recepce nevyřeší
(function(){
var box=document.getElementById('poplach'),ctx=null,ticho={},zpet=null,tit=document.title,pip=null;
function zvuk(){try{ctx=ctx||new (window.AudioContext||window.webkitAudioContext)();if(ctx.state==='suspended')ctx.resume()}catch(e){}}
['click','touchstart','keydown'].forEach(function(e){document.addEventListener(e,zvuk,{passive:true})});
function pisk(){if(!ctx||ctx.state!=='running')return;var t=ctx.currentTime;
 for(var i=0;i<4;i++){var o=ctx.createOscillator(),g=ctx.createGain();o.type='square';o.frequency.value=i%2?1250:850;o.connect(g);g.connect(ctx.destination);
  g.gain.setValueAtTime(0.0001,t+i*.22);g.gain.exponentialRampToValueAtTime(0.7,t+i*.22+.02);g.gain.exponentialRampToValueAtTime(0.0001,t+i*.22+.2);o.start(t+i*.22);o.stop(t+i*.22+.21)}
 if(navigator.vibrate)navigator.vibrate([300,100,300,100,300])}
function kresli(a){
 var nove=a.filter(function(x){return !ticho[x.id]});
 if(!a.length){box.hidden=true;box.innerHTML='';document.title=tit;if(pip){clearInterval(pip);pip=null}return}
 var h='';a.forEach(function(x){h+='<div class="pl-r"><b>'+x.mistnost+'</b>: masérka'+(x.maserka?' '+x.maserka:'')+' hlásí, že to <u>NENÍ ONA</u> – zákazník <b>'+x.zakaznik+'</b> ('+x.cas+') <a class="btn primary" href="/?r=rezervace&id='+x.rez+'#tablet">Vyřešit – poslat znovu</a></div>'});
 var velky=nove.length>0;
 box.className=velky?'pl-velky':'pl-pruh';
 box.innerHTML='<div class="pl-in"><div class="pl-h">⚠ POZOR – TABLET HLÁSÍ NESROVNALOST</div>'+h+(velky?'<button type="button" class="btn" id="pl-ticho">🔇 Ztlumit zvuk (poplach zůstane nahoře)</button><div class="pl-t">Klepněte kamkoli, aby se zapnul zvuk.</div>':'')+'</div>';
 box.hidden=false;
 var bt=box.querySelector('#pl-ticho');if(bt)bt.onclick=function(){a.forEach(function(x){ticho[x.id]=1});kresli(a)};
 if(velky){pisk();if(!pip)pip=setInterval(function(){document.title=document.title===tit?'🚨 TABLET HLÁSÍ CHYBU':tit;if(Object.keys(ticho).length===0||a.some(function(x){return !ticho[x.id]}))pisk()},2500)}
 else if(pip){clearInterval(pip);pip=null;document.title=tit}}
async function tik(){try{var r=await fetch('/?r=poplach',{credentials:'same-origin'});if(r.ok)kresli(await r.json())}catch(e){}}
tik();setInterval(tik,4000);
})();
</script>
<?php endif ?>
<script>
// rychlé hledání zákazníků a poukazů (příjmení bez diakritiky, telefon, kód karty / poukazu)
(function(){var i=document.getElementById('gs'),b=document.getElementById('gsr');if(!i)return;var t,seq=0,res=[];
function mk(tag,cls,txt){var e=document.createElement(tag);if(cls)e.className=cls;if(txt!==undefined)e.textContent=txt;return e}
async function hledej(){var q=i.value.trim();if(q.length<2){b.hidden=true;return}var my=++seq;
 try{var r=await fetch('/?r=hledej_zak&q='+encodeURIComponent(q),{credentials:'same-origin'});res=await r.json()}catch(e){return}
 if(my!==seq)return;b.textContent='';
 if(!res.length){b.appendChild(mk('div','gsn','Nic nenalezeno'));var a=mk('a','gsa','+ Založit nového zákazníka („'+q+'“)');a.href='/?r=zakaznik&novy=1&q='+encodeURIComponent(q);b.appendChild(a);var a2=mk('a','gsa','+ Nová rezervace');a2.href='/?r=rez_nova';b.appendChild(a2)}
 res.forEach(function(x){var row=mk('div','gsi');var a=mk('a','gsm');a.href=x.url;a.appendChild(mk('b','',x.nazev+(x.tel?' · '+x.tel:'')));a.appendChild(mk('span','gsd',x.info));row.appendChild(a);
  if(x.rez){var l=mk('a','gsb','Termín');l.href=x.volno;row.appendChild(l);var l2=mk('a','gsb','Rezervace');l2.href=x.rez;row.appendChild(l2)}b.appendChild(row)});
 b.hidden=false}
i.addEventListener('input',function(){clearTimeout(t);t=setTimeout(hledej,200)});
i.addEventListener('keydown',function(e){if(e.key==='Enter'&&res.length){e.preventDefault();location=res[0].url}if(e.key==='Escape'){b.hidden=true}});
document.addEventListener('click',function(e){if(!e.target.closest('.gs'))b.hidden=true});
document.addEventListener('keydown',function(e){if(e.key==='/'&&!/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)){e.preventDefault();i.focus()}});
})();
// skener QR (věrnostní karta → přímo na kartu; jiný text → do hledání)
(function(){var b=document.getElementById('gscan'),i=document.getElementById('gs');if(!b)return;var sc=document.createElement('script');sc.src='/skener.js?v=2';document.head.appendChild(sc);
b.addEventListener('click',function(){if(!window.ThaiScan)return;ThaiScan.open(function(t){var m=t.match(/[?&]k=([A-Za-z0-9]{10})/)||t.match(/^\s*([A-HJ-NP-Z2-9]{10})\s*$/i);if(m){location='/?r=karta&k='+m[1].toUpperCase()}else{i.value=t.trim();i.dispatchEvent(new Event('input'));i.focus()}})})})();
// potvrzení u nevratných akcí
document.querySelectorAll('[data-confirm]').forEach(el => el.addEventListener('click', e => { if (!confirm(el.dataset.confirm)) e.preventDefault(); }));
</script>
</body></html><?php
}

function stitky_html(array $stitky): string
{
    $o = '';
    foreach ($stitky as [$t, $cls, $title]) $o .= '<span class="tag ' . h($cls) . '" title="' . h($title) . '">' . h($t) . '</span> ';
    return $o;
}

function stav_html(string $stav): string { return '<span class="stav s-' . h($stav) . '">' . h(STAVY[$stav] ?? $stav) . '</span>'; }

function bonus_html(array $b): string
{
    $o = '<span class="bonus" title="' . $b['celkem'] . ' zaplacených celkem">';
    for ($i = 0; $i < $b['po']; $i++) $o .= '<i class="' . ($i < $b['razitka'] ? 'on' : '') . '"></i>';
    $o .= ' ' . $b['razitka'] . '/' . $b['po'] . '</span>';
    if ($b['zdarma'] > 0) $o .= ' <span class="tag zdarma">🎉 ' . ($b['zdarma'] > 1 ? $b['zdarma'] . '× ' : '') . 'vstup zdarma</span>';
    return $o;
}

function maserky_select(?int $vybrana, bool $prazdna = true, string $prazdnaText = '— bez masérky (wellness) —'): string
{
    $o = $prazdna ? '<option value="">' . h($prazdnaText) . '</option>' : '';
    foreach (q('SELECT id, jmeno FROM maserky WHERE aktivni=1 ORDER BY poradi')->fetchAll() as $m)
        $o .= '<option value="' . $m['id'] . '"' . ($vybrana == $m['id'] ? ' selected' : '') . '>' . h($m['jmeno']) . '</option>';
    return $o;
}

function moznosti(array $pole, $vybrana): string
{
    $o = '';
    foreach ($pole as $k => $t) $o .= '<option value="' . h($k) . '"' . ((string)$vybrana === (string)$k ? ' selected' : '') . '>' . h($t) . '</option>';
    return $o;
}

/** Plovoucí ikona asistenta vpravo dole na každé stránce + okno s chatem (/?r=ai&okno=1 v iframe).
 *  Konverzace je v session na serveru, takže po přechodu na jinou stránku zůstane. Otevřené okno se po přechodu
 *  otevře znovu (jen na počítači; na mobilu je okno přes celou obrazovku). */
function asistent_ikona(): void
{
    $n = 0;
    try { $n = count(ai_cekajici()); } catch (Throwable $e) {}
    ?>
<button type="button" class="aipom-btn" id="aipom-btn" aria-label="Asistent – zeptat se" title="Asistent – zeptat se (Alt+A)">🤖<?= $n ? '<b class="aipom-n" title="Návrhy čekající na recepci">' . $n . '</b>' : '' ?></button>
<div class="aipom-okno" id="aipom-okno" role="dialog" aria-label="Asistent">
  <div class="aipom-hl"><span>🤖 Asistent</span><a href="/?r=ai" title="Otevřít na celou stránku" aria-label="Otevřít na celou stránku">⤢</a><button type="button" id="aipom-zavrit" title="Zavřít (Esc)" aria-label="Zavřít">✕</button></div>
</div>
<script>
(function () {
  var b = document.getElementById('aipom-btn'), o = document.getElementById('aipom-okno'), f = null;
  var maly = function () { return window.innerWidth <= 600; };
  function otevri() {
    if (!f) { f = document.createElement('iframe'); f.src = '/?r=ai&okno=1'; f.title = 'Asistent'; f.setAttribute('allow', 'microphone'); o.appendChild(f); }
    o.classList.add('on'); if (maly()) b.style.display = 'none';
    try { sessionStorage.setItem('aipom-otevreno', '1'); } catch (e) {}
  }
  function zavri() {
    o.classList.remove('on'); b.style.display = '';
    try { sessionStorage.removeItem('aipom-otevreno'); window.speechSynthesis && speechSynthesis.cancel(); } catch (e) {}
  }
  b.addEventListener('click', function () { o.classList.contains('on') ? zavri() : otevri(); });
  document.getElementById('aipom-zavrit').addEventListener('click', zavri);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && o.classList.contains('on')) zavri();
    if (e.altKey && (e.key === 'a' || e.key === 'A' || e.code === 'KeyA')) { e.preventDefault(); o.classList.contains('on') ? zavri() : otevri(); }
  });
  try { if (sessionStorage.getItem('aipom-otevreno') === '1' && !maly()) otevri(); } catch (e) {}
})();
</script>
<?php
}
