<?php
// Společná kostra stránek kartotéky.

function hlavicka(string $titul, string $aktivni = ''): void
{
    $u = uzivatel();
    $zadosti = $u ? (int)qv("SELECT COUNT(*) FROM rezervace WHERE stav='zadost'") : 0;
    $poukazyCekaji = $u ? (int)qv("SELECT COUNT(*) FROM poukazy WHERE stav='objednano'") : 0;
    $menu = [
        'dnes' => 'Dnes' . ($zadosti ? " <b class=\"cnt\">$zadosti</b>" : ''),
        'kalendar' => 'Kalendář',
        'zakaznici' => 'Zákazníci',
        'poukazy' => 'Poukazy' . ($poukazyCekaji ? " <b class=\"cnt\">$poukazyCekaji</b>" : ''),
        'tym' => 'Tým',
        'smeny' => 'Směny',
        'nastaveni' => 'Nastavení',
    ];
    ?><!doctype html>
<html lang="cs"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($titul) ?> · Kartotéka Thai Most</title>
<link rel="stylesheet" href="/style.css?v=2">
</head><body>
<?php if ($u): ?>
<header class="top">
  <a class="logo" href="/?r=dnes">Thai Most <span>kartotéka</span></a>
  <nav><?php foreach ($menu as $k => $t): ?><a href="/?r=<?= $k ?>"<?= $aktivni === $k ? ' class="on"' : '' ?>><?= $t ?></a><?php endforeach ?></nav>
  <a class="btn primary" href="/?r=rez_nova">+ Rezervace</a>
  <span class="who"><?= h($u['jmeno']) ?> · <a href="/?r=logout">odhlásit</a></span>
</header>
<?php endif ?>
<main>
<?php foreach (flash() as [$typ, $msg]): ?><div class="flash <?= h($typ) ?>"><?= h($msg) ?></div><?php endforeach ?>
<?php
}

function paticka(): void
{
    ?></main>
<script>
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
