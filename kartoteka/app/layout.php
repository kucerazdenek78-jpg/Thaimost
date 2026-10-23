<?php
// Společná kostra stránek kartotéky.

function hlavicka(string $titul, string $aktivni = ''): void
{
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
        'ai' => '🤖 Asistent' . (($an = $u ? (int)qv("SELECT COUNT(*) FROM ai_navrhy WHERE stav IN ('ceka','otevreno') AND zacatek > ?", [nyni()]) : 0) ? " <b class=\"cnt\">$an</b>" : ''),
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
<link rel="stylesheet" href="/style.css?v=8">
</head><body>
<?php if ($u): ?>
<header class="top">
  <a class="logo" href="/?r=dnes">Thai Most <span>kartotéka</span></a>
  <nav><?php foreach ($menu as $k => $t): ?><a href="/?r=<?= $k ?>"<?= $aktivni === $k ? ' class="on"' : '' ?>><?= $t ?></a><?php endforeach ?></nav>
  <div class="gs"><input id="gs" type="search" placeholder="🔍 příjmení, telefon, kód karty / poukazu" autocomplete="off"><button type="button" id="gscan" class="gscan" title="Skenovat QR kód karty fotoaparátem">📷</button><div id="gsr" class="gsr" hidden></div></div>
  <a class="btn primary" href="/?r=rez_nova">+ Rezervace</a>
  <span class="who"><?= h($u['jmeno']) ?> · <a href="/?r=logout">odhlásit</a></span>
</header>
<?php endif ?>
<main>
<?php if ($u && function_exists('demo_pocet') && demo_pocet()): ?><div class="flash vzor">🧪 <b>VZOR</b> – v systému jsou vzorová data na zkoušení (<?= demo_pocet() ?> zákazníků se štítkem VZOR). Před ostrým provozem je smažte v <a href="/?r=nastaveni">Nastavení</a>.</div><?php endif ?>
<?php foreach (flash() as [$typ, $msg]): ?><div class="flash <?= h($typ) ?>"><?= h($msg) ?></div><?php endforeach ?>
<?php
}

function paticka(): void
{
    ?></main>
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
