<?php
// Stránky: přihlášení, Dnes, Kalendář, Rezervace.
require __DIR__ . '/stranky_zakaznici.php';
require __DIR__ . '/stranky_poukazy.php';
require __DIR__ . '/stranky_nastaveni.php';
require __DIR__ . '/stranky_tym.php';
require __DIR__ . '/stranky_vernost.php';
require __DIR__ . '/stranky_tablet.php';

function stranka_login(): void
{
    $chyba = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        over_csrf();
        if (!limit_ip('login', 20)) $chyba = 'Příliš mnoho pokusů. Zkuste to za hodinu.';
        else {
            $u = q1('SELECT * FROM uzivatele WHERE login=? AND aktivni=1', [trim($_POST['login'] ?? '')]);
            if ($u && password_verify($_POST['heslo'] ?? '', $u['heslo_hash'])) {
                session_regenerate_id(true);
                $_SESSION['uid'] = $u['id'];
                q('INSERT INTO log(kdo,akce) VALUES(?,?)', [$u['jmeno'], 'prihlaseni']);
                presmeruj('/?r=dnes');
            }
            $chyba = 'Špatné jméno nebo heslo.';
        }
    }
    $zadnyUzivatel = !qv('SELECT COUNT(*) FROM uzivatele');
    hlavicka('Přihlášení'); ?>
    <form method="post" class="card login">
      <h1>Kartotéka Thai Most</h1>
      <?= csrf_pole() ?>
      <?php if ($chyba): ?><div class="flash err"><?= h($chyba) ?></div><?php endif ?>
      <?php if ($zadnyUzivatel): ?><div class="flash warn">Zatím není založený žádný uživatel. Založte ho v terminálu kontejneru: <code>php bin/uzivatel.php</code> (návod v README).</div><?php endif ?>
      <label>Uživatel<input name="login" autocomplete="username" required autofocus></label>
      <label>Heslo<input name="heslo" type="password" autocomplete="current-password" required></label>
      <button class="btn primary">Přihlásit</button>
    </form>
    <?php paticka();
}

// ---------------- DNES ----------------
function stranka_dnes(array $u): void
{
    $zadosti = q("SELECT r.*, z.jmeno, z.prijmeni, z.telefon, s.nazev AS sluzba, v.popis AS var_popis
        FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id LEFT JOIN varianty v ON v.id=r.varianta_id
        LEFT JOIN sluzby s ON s.id=v.sluzba_id WHERE r.stav='zadost' ORDER BY r.vytvoreno")->fetchAll();
    $dnes = date('Y-m-d');
    $plan = q("SELECT r.*, z.jmeno, z.prijmeni, z.telefon, z.pref_tlak, z.pref_text, z.zdravi_text, z.zdravi_text_en, z.zdravi_souhlas,
        s.nazev AS sluzba, v.popis AS var_popis, m.jmeno AS maserka
        FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id LEFT JOIN varianty v ON v.id=r.varianta_id
        LEFT JOIN sluzby s ON s.id=v.sluzba_id LEFT JOIN maserky m ON m.id=r.maserka_id
        WHERE r.zacatek >= ? AND r.zacatek < ? AND r.stav NOT IN ('zadost','odmitnuto')
        ORDER BY r.zacatek", [$dnes, date('Y-m-d', strtotime('+1 day'))])->fetchAll();
    $neodeslane = (int)qv("SELECT COUNT(*) FROM emaily WHERE stav<>'odeslano'");

    hlavicka('Dnes', 'dnes'); ?>
    <h1>Dnes <small><?= h(datum_cz($dnes, false)) ?></small></h1>

    <?php if ($neodeslane): ?>
      <div class="flash warn"><?= $neodeslane ?> e-mail(ů) čeká na odeslání – <a href="/?r=emaily">zobrazit</a>.</div>
    <?php endif ?>

    <section class="card">
      <h2>Žádosti z webu <span class="muted">(<?= count($zadosti) ?>)</span></h2>
      <?php if (!$zadosti): ?><p class="muted">Žádné nevyřízené žádosti.</p><?php endif ?>
      <?php foreach ($zadosti as $r): $z = q1('SELECT * FROM zakaznici WHERE id=?', [$r['zakaznik_id']]); ?>
        <div class="row-item">
          <div>
            <b><?= h(jmeno_z($r)) ?></b> · <a href="tel:<?= h($r['telefon']) ?>"><?= h(telefon_cz($r['telefon'])) ?></a>
            <?= stitky_html(zakaznik_stitky($z, zakaznik_statistika((int)$z['id']))) ?><br>
            <?= h($r['sluzba'] . ' – ' . $r['var_popis']) ?> · <b>hodí se:</b> <?= h($r['preferovano'] ?: '—') ?>
            <?php if ($r['poznamka_zakaznik']): ?><br><span class="muted">„<?= h($r['poznamka_zakaznik']) ?>“</span><?php endif ?>
            <br><span class="muted small">přišlo <?= h(datum_cz($r['vytvoreno'])) ?></span>
          </div>
          <a class="btn primary" href="/?r=rezervace&id=<?= $r['id'] ?>">Naplánovat</a>
        </div>
      <?php endforeach ?>
    </section>

    <section class="card">
      <h2>Dnešní plán <span class="muted">(<?= count($plan) ?>)</span></h2>
      <?php if (!$plan): ?><p class="muted">Na dnešek nic naplánováno. <a href="/?r=rez_nova">Přidat rezervaci</a></p><?php endif ?>
      <?php foreach ($plan as $r): ?>
        <a class="row-item link" href="/?r=rezervace&id=<?= $r['id'] ?>">
          <div class="time"><?= date('H:i', strtotime($r['zacatek'])) ?><br><span class="muted small"><?= date('H:i', strtotime($r['konec'])) ?></span></div>
          <div class="grow">
            <b><?= h(jmeno_z($r)) ?></b> <?= stav_html($r['stav']) ?><br>
            <?= h($r['sluzba'] . ' – ' . $r['var_popis']) ?> · <?= h($r['maserka'] ?? 'bez masérky') ?>
            <?php if ($r['platba']): ?> · <?= h(PLATBY[$r['platba']]) ?><?php endif ?>
            <?php $pref = trim(($r['pref_tlak'] ? 'tlak ' . TLAK[$r['pref_tlak']] . '. ' : '') . $r['pref_text']);
                  if ($pref): ?><br><span class="pref">💬 <?= h(mb_strimwidth($pref, 0, 140, '…')) ?></span><?php endif ?>
            <?php if ($r['zdravi_text'] && $r['zdravi_souhlas']): ?><br><span class="pref warn">⚕ <?= h(mb_strimwidth($r['zdravi_text'], 0, 120, '…')) ?></span><?php if (!$r['zdravi_text_en']): ?> <span class="pref warn" title="Masérka na tabletu uvidí zdraví jen česky">🇬🇧 chybí anglicky</span><?php endif ?><?php endif ?>
          </div>
        </a>
      <?php endforeach ?>
    </section>
    <?php paticka();
}

// ---------------- KALENDÁŘ ----------------
function stranka_kalendar(array $u): void
{
    $d = preg_match('~^\d{4}-\d{2}-\d{2}$~', $_GET['d'] ?? '') ? $_GET['d'] : date('Y-m-d');
    $od = nastaveni('oteviraci_od', '09:00'); $do = nastaveni('oteviraci_do', '20:00');
    $minOd = (int)substr($od, 0, 2) * 60 + (int)substr($od, 3, 2);
    $minDo = (int)substr($do, 0, 2) * 60 + (int)substr($do, 3, 2);
    $px = 1.4; // pixelů na minutu
    $maserky = q('SELECT * FROM maserky WHERE aktivni=1 ORDER BY poradi')->fetchAll();
    $sloupce = array_merge($maserky, [['id' => 0, 'jmeno' => 'Wellness / bez masérky', 'barva' => '#888']]);
    $rez = q("SELECT r.*, z.jmeno, z.prijmeni, s.nazev AS sluzba, v.popis AS var_popis
        FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id LEFT JOIN varianty v ON v.id=r.varianta_id
        LEFT JOIN sluzby s ON s.id=v.sluzba_id
        WHERE r.zacatek >= ? AND r.zacatek < ? AND r.stav NOT IN ('zadost','odmitnuto')",
        [$d, date('Y-m-d', strtotime($d . ' +1 day'))])->fetchAll();
    $poSloupcich = [];
    foreach ($rez as $r) $poSloupcich[(int)$r['maserka_id']][] = $r;

    hlavicka('Kalendář', 'kalendar'); ?>
    <div class="cal-nav">
      <a class="btn" href="/?r=kalendar&d=<?= date('Y-m-d', strtotime($d . ' -1 day')) ?>">‹</a>
      <form><input type="hidden" name="r" value="kalendar"><input type="date" name="d" value="<?= h($d) ?>" onchange="this.form.submit()"></form>
      <a class="btn" href="/?r=kalendar&d=<?= date('Y-m-d', strtotime($d . ' +1 day')) ?>">›</a>
      <a class="btn" href="/?r=kalendar">Dnes</a>
      <h1><?= h(datum_cz($d, false)) ?></h1>
    </div>
    <p class="muted small">Klepnutím do volného místa přidáte rezervaci na ten čas a masérku.</p>
    <div class="cal" style="--h:<?= ($minDo - $minOd) * $px ?>px">
      <div class="cal-hours">
        <?php for ($m = $minOd; $m < $minDo; $m += 60): ?><div style="top:<?= ($m - $minOd) * $px ?>px"><?= sprintf('%02d:%02d', intdiv($m, 60), $m % 60) ?></div><?php endfor ?>
      </div>
      <?php foreach ($sloupce as $s): ?>
      <div class="cal-col">
        <div class="cal-head" style="border-color:<?= h($s['barva']) ?>"><?= h($s['jmeno']) ?></div>
        <div class="cal-body">
          <?php for ($m = $minOd; $m < $minDo; $m += 30): ?>
            <a class="slot" style="top:<?= ($m - $minOd) * $px ?>px;height:<?= 30 * $px ?>px"
               href="/?r=rez_nova&d=<?= h($d) ?>&t=<?= sprintf('%02d:%02d', intdiv($m, 60), $m % 60) ?>&m=<?= (int)$s['id'] ?>"></a>
          <?php endfor ?>
          <?php $bloky = $poSloupcich[(int)$s['id']] ?? [];
          usort($bloky, fn($a, $b) => strcmp($a['zacatek'], $b['zacatek']));
          // překrývající se rezervace (kolize) zobrazit vedle sebe
          $drahy = [];
          foreach ($bloky as $i => $r) { $d = 0; foreach ($drahy as $j => $kon) if ($kon <= $r['zacatek']) { $d = $j; break; } else $d = count($drahy); $drahy[$d] = $r['konec']; $bloky[$i]['draha'] = $d; }
          $pocetDrah = max(1, count($drahy));
          foreach ($bloky as $r):
              $t1 = strtotime($r['zacatek']); $t2 = strtotime($r['konec']);
              $top = ((int)date('G', $t1) * 60 + (int)date('i', $t1) - $minOd) * $px;
              $hgt = max(26, ($t2 - $t1) / 60 * $px - 2);
              $w = 100 / $pocetDrah; $l = $r['draha'] * $w; ?>
            <a class="blk s-<?= h($r['stav']) ?>" href="/?r=rezervace&id=<?= $r['id'] ?>"
               style="top:<?= $top ?>px;height:<?= $hgt ?>px;left:calc(<?= $l ?>% + 3px);width:calc(<?= $w ?>% - 6px);border-color:<?= h($s['barva']) ?>">
              <b><?= date('H:i', $t1) ?>–<?= date('H:i', $t2) ?></b> <?= h(jmeno_z($r)) ?><br>
              <span><?= h($r['sluzba'] . ' ' . $r['var_popis']) ?></span>
            </a>
          <?php endforeach ?>
        </div>
      </div>
      <?php endforeach ?>
    </div>
    <?php paticka();
}

// ---------------- REZERVACE: formulář ----------------
/** Zpracuje odeslaný formulář rezervace (nová nebo úprava/naplánování). Vrací [chyba|null, kolize[]]. */
function rez_uloz(array $p, ?array $puvodni, bool $potvrdit): array
{
    $tel = telefon_norm($p['telefon'] ?? '');
    if (!$puvodni && $tel === '') return ['Zadejte platný telefon.', []];
    $var = q1('SELECT * FROM varianty WHERE id=?', [(int)($p['varianta_id'] ?? 0)]);
    if (!$var) return ['Vyberte masáž / službu.', []];
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $p['datum'] ?? '') || !preg_match('~^\d{2}:\d{2}$~', $p['cas'] ?? ''))
        return ['Zadejte datum a čas.', []];
    $delka = (int)($p['delka'] ?? 0) ?: ((int)$var['minuty'] ?: 60);
    $zac = $p['datum'] . ' ' . $p['cas'] . ':00';
    $kon = date('Y-m-d H:i:s', strtotime($zac) + $delka * 60);
    $mas = (int)($p['maserka_id'] ?? 0) ?: null;
    $platba = array_key_exists((string)($p['platba'] ?? ''), PLATBY) ? (string)$p['platba'] : '';
    $cena = ($p['cena'] ?? '') !== '' ? (int)$p['cena'] : (int)$var['cena'];

    $kol = $mas ? kolize($mas, $zac, $kon, (int)($puvodni['id'] ?? 0)) : [];
    if ($kol && empty($p['presto'])) return [null, $kol];

    if ($puvodni) {
        $zid = (int)$puvodni['zakaznik_id'];
    } else {
        $zid = zakaznik_najdi_nebo_zaloz($tel, trim($p['jmeno'] ?? ''), trim($p['prijmeni'] ?? ''), trim($p['email'] ?? ''));
    }
    if ($platba === 'bonus') {
        $z = q1('SELECT * FROM zakaznici WHERE id=?', [$zid]);
        $b = zakaznik_bonus($z);
        $jizBonus = $puvodni && $puvodni['platba'] === 'bonus';
        if ($b['zdarma'] < 1 && !$jizBonus && empty($p['presto'])) return ['Zákazník zatím nemá nárok na vstup zdarma (' . $b['razitka'] . '/' . $b['po'] . '). Zvolte jinou platbu.', []];
        $cena = 0;
    }

    $stav = $puvodni ? $puvodni['stav'] : 'potvrzeno';
    if ($potvrdit) $stav = 'potvrzeno';

    if ($puvodni) {
        q('UPDATE rezervace SET varianta_id=?, maserka_id=?, zacatek=?, konec=?, stav=?, platba=?, cena=?, zmeneno=?, zmenil=? WHERE id=?',
            [$var['id'], $mas, $zac, $kon, $stav, $platba, $cena, nyni(), kdo(), $puvodni['id']]);
        $rid = (int)$puvodni['id'];
        zapis_log('rezervace_uprava', "#$rid $zac");
    } else {
        q("INSERT INTO rezervace(zakaznik_id,varianta_id,maserka_id,zacatek,konec,stav,zdroj,platba,cena,poznamka_zakaznik,zmenil)
           VALUES(?,?,?,?,?,'potvrzeno',?,?,?,?,?)",
            [$zid, $var['id'], $mas, $zac, $kon, in_array($p['zdroj'] ?? '', ['telefon', 'osobne'], true) ? $p['zdroj'] : 'recepce',
             $platba, $cena, trim($p['poznamka'] ?? ''), kdo()]);
        $rid = (int)db()->lastInsertId();
        zapis_log('rezervace_nova', "#$rid $zac");
    }

    if ($stav === 'potvrzeno' && !empty($p['poslat_email'])) posli_potvrzeni($rid);
    return [null, [], $rid];
}

function posli_potvrzeni(int $rid): void
{
    $r = rezervace_detail($rid);
    if (!$r || !$r['email']) return;
    posli_email($r['email'], 'Thai Most – potvrzení termínu ' . date('j. n. H:i', strtotime($r['zacatek'])),
        "Dobrý den,\n\npotvrzujeme vám termín:\n\n" . datum_cz($r['zacatek']) . "\n$r[sluzba] – $r[var_popis]"
        . ($r['maserka'] ? "\nMasérka: $r[maserka]" : '') . "\n\nAdresa: Slovenského národního povstání 2654/26, Most\n"
        . "Když nebudete moct přijít, dejte nám prosím vědět co nejdřív na +420 778 429 329.\n\nTěšíme se na vás,\nThajské masáže Most\n");
}

function rez_formular(array $v, bool $novy, string $tlacitko): void
{
    $minuty = $v['delka'] ?? ''; ?>
    <?php if ($novy): ?>
    <fieldset><legend>Zákazník</legend>
      <label>🔍 Najít stávajícího zákazníka <span class="muted">(příjmení, jméno nebo telefon – klepnutím se vyplní údaje)</span>
        <input id="zhledej" type="search" autocomplete="off" placeholder="např. Novák, Jiří Novák, 777123"></label>
      <div id="zvysl" class="zvysl" hidden></div>
      <div class="grid3">
        <label>Telefon *<input name="telefon" id="tel" type="tel" required value="<?= h($v['telefon'] ?? '') ?>" placeholder="777 123 456" autocomplete="off"></label>
        <label>Jméno<input name="jmeno" id="jmeno" value="<?= h($v['jmeno'] ?? '') ?>"></label>
        <label>Příjmení<input name="prijmeni" id="prijmeni" value="<?= h($v['prijmeni'] ?? '') ?>"></label>
      </div>
      <label>E-mail <span class="muted">(nepovinný, pro potvrzení)</span><input name="email" id="email" type="email" value="<?= h($v['email'] ?? '') ?>"></label>
      <div id="zinfo" class="zinfo"></div>
      <label>Objednal se<select name="zdroj"><?= moznosti(['telefon' => 'telefonicky', 'osobne' => 'osobně na recepci', 'recepce' => 'jinak'], $v['zdroj'] ?? 'telefon') ?></select></label>
    </fieldset>
    <?php endif ?>
    <fieldset><legend>Termín</legend>
      <label>Masáž / služba *<select name="varianta_id" id="var" required><?= varianty_select(isset($v['varianta_id']) ? (int)$v['varianta_id'] : null) ?></select></label>
      <div class="grid3">
        <label>Datum *<input type="date" name="datum" required value="<?= h($v['datum'] ?? date('Y-m-d')) ?>"></label>
        <label>Čas *<input type="time" name="cas" required step="300" value="<?= h($v['cas'] ?? '') ?>"></label>
        <label>Délka (min)<input type="number" name="delka" id="delka" min="10" max="300" step="5" value="<?= h($minuty) ?>" placeholder="podle služby"></label>
      </div>
      <div class="grid3">
        <label>Masérka<select name="maserka_id"><?= maserky_select(isset($v['maserka_id']) ? (int)$v['maserka_id'] : null) ?></select></label>
        <label>Platba<select name="platba"><?= moznosti(PLATBY, $v['platba'] ?? '') ?></select></label>
        <label>Cena (Kč)<input type="number" name="cena" value="<?= h($v['cena'] ?? '') ?>" placeholder="podle ceníku"></label>
      </div>
      <?php if ($novy): ?><label>Poznámka<input name="poznamka" value="<?= h($v['poznamka'] ?? '') ?>"></label><?php endif ?>
      <label class="check"><input type="checkbox" name="poslat_email" value="1" <?= !empty($v['poslat_email']) ? 'checked' : '' ?>> Poslat zákazníkovi potvrzení e-mailem (když má e-mail)</label>
    </fieldset>
    <button class="btn primary"><?= h($tlacitko) ?></button>
    <script>
    // délka podle zvolené služby + rychlé dohledání zákazníka podle telefonu
    const varMin = <?= json_encode(q('SELECT id, minuty FROM varianty')->fetchAll(PDO::FETCH_KEY_PAIR)) ?>;
    const sel = document.getElementById('var'), del = document.getElementById('delka');
    sel && sel.addEventListener('change', () => { del.placeholder = varMin[sel.value] ? varMin[sel.value] + ' (podle služby)' : 'zadejte délku'; });
    sel && sel.dispatchEvent(new Event('change'));
    const zh = document.getElementById('zhledej'), zv = document.getElementById('zvysl');
    if (zh) {
      let zt, zs = 0;
      zh.addEventListener('input', () => { clearTimeout(zt); zt = setTimeout(async () => {
        const q = zh.value.trim(); if (q.length < 2) { zv.hidden = true; return; }
        const my = ++zs; const r = await fetch('/?r=hledej_zak&q=' + encodeURIComponent(q)); const d = (await r.json()).filter(x => x.typ === 'zak');
        if (my !== zs) return; zv.textContent = '';
        if (!d.length) { const n = document.createElement('div'); n.className = 'muted small'; n.style.padding = '6px 10px'; n.textContent = 'Nikdo takový – vyplňte údaje níže (založí se nový zákazník).'; zv.appendChild(n); }
        d.forEach(x => { const a = document.createElement('a'); a.href = '#'; a.className = 'zv';
          const b = document.createElement('b'); b.textContent = x.nazev + ' · ' + x.tel; const i = document.createElement('span'); i.textContent = x.info; a.append(b, i);
          a.addEventListener('click', e => { e.preventDefault(); document.getElementById('tel').value = x.telefon; document.getElementById('jmeno').value = x.jmeno; document.getElementById('prijmeni').value = x.prijmeni; document.getElementById('email').value = x.email || '';
            if (x.maserka_id) { const ms = document.querySelector('select[name=maserka_id]'); if (ms && !ms.value) ms.value = x.maserka_id; }
            zv.hidden = true; zh.value = ''; document.getElementById('tel').dispatchEvent(new Event('input')); });
          zv.appendChild(a); });
        zv.hidden = false; }, 200); });
    }
    const tel = document.getElementById('tel');
    if (tel) {
      let t; tel.addEventListener('input', () => { clearTimeout(t); t = setTimeout(async () => {
        const v = tel.value.replace(/\D/g, ''); const box = document.getElementById('zinfo');
        if (v.length < 9) { box.innerHTML = ''; return; }
        const r = await fetch('/?r=hledat&tel=' + encodeURIComponent(tel.value)); const d = await r.json();
        if (!d.ok) { box.innerHTML = '<span class="muted">Nový zákazník – vyplňte jméno a příjmení.</span>'; return; }
        ['jmeno','prijmeni','email'].forEach(k => { const el = document.getElementById(k); if (el && !el.value) el.value = d[k] || ''; });
        box.innerHTML = d.html;
      }, 350); });
      if (tel.value) tel.dispatchEvent(new Event('input'));
    }
    </script>
    <?php
}

function kolize_html(array $kol): string
{
    $o = '<div class="flash warn"><b>Masérka má v tu dobu jinou rezervaci:</b><ul>';
    foreach ($kol as $k) $o .= '<li>' . date('H:i', strtotime($k['zacatek'])) . '–' . date('H:i', strtotime($k['konec'])) . ' ' . h(jmeno_z($k)) . '</li>';
    return $o . '</ul><label class="check"><input type="checkbox" name="presto" value="1"> Přesto uložit</label></div>';
}

function stranka_rez_nova(array $u): void
{
    $v = ['datum' => $_GET['d'] ?? date('Y-m-d'), 'cas' => $_GET['t'] ?? '', 'maserka_id' => $_GET['m'] ?? null, 'poslat_email' => 1,
          'varianta_id' => ($_GET['v'] ?? '') !== '' ? (int)$_GET['v'] : null, 'delka' => $_GET['delka'] ?? ''];
    if (!empty($_GET['z'])) {
        $z = q1('SELECT * FROM zakaznici WHERE id=?', [(int)$_GET['z']]);
        if ($z) $v += ['telefon' => telefon_cz($z['telefon']), 'jmeno' => $z['jmeno'], 'prijmeni' => $z['prijmeni'], 'email' => $z['email'],
                       'maserka_id' => $v['maserka_id'] ?: $z['pref_maserka_id']];
        if ($z && !$v['varianta_id']) $v['varianta_id'] = (int)qv("SELECT varianta_id FROM rezervace WHERE zakaznik_id=? AND stav='probehlo' AND varianta_id IS NOT NULL ORDER BY zacatek DESC LIMIT 1", [$z['id']]) ?: null;
    }
    $chyba = null; $kol = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $v = $_POST;
        $res = rez_uloz($_POST, null, true);
        [$chyba, $kol] = $res;
        if (!$chyba && !$kol) { flash('Rezervace uložena.'); presmeruj('/?r=rezervace&id=' . $res[2]); }
    }
    hlavicka('Nová rezervace', ''); ?>
    <h1>Nová rezervace</h1>
    <form method="post" class="card">
      <?= csrf_pole() ?>
      <?php if ($chyba): ?><div class="flash err"><?= h($chyba) ?></div><?php endif ?>
      <?php if ($kol) echo kolize_html($kol) ?>
      <?php rez_formular($v, true, 'Uložit rezervaci') ?>
    </form>
    <?php paticka();
}

// ---------------- REZERVACE: detail ----------------
function stranka_rezervace(array $u): void
{
    $id = (int)($_GET['id'] ?? 0);
    $r = rezervace_detail($id);
    if (!$r) { flash('Rezervace nenalezena.', 'err'); presmeruj('/?r=dnes'); }
    $chyba = null; $kol = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = $_POST['akce'] ?? '';
        if ($akce === 'naplanovat' || $akce === 'upravit') {
            $res = rez_uloz($_POST, $r, $akce === 'naplanovat');
            [$chyba, $kol] = $res;
            if (!$chyba && !$kol) { flash($akce === 'naplanovat' ? 'Termín potvrzen.' : 'Uloženo.'); presmeruj("/?r=rezervace&id=$id"); }
        } elseif ($akce === 'stav' && isset(STAVY[$_POST['stav'] ?? ''])) {
            $novy = $_POST['stav'];
            q('UPDATE rezervace SET stav=?, zmeneno=?, zmenil=? WHERE id=?', [$novy, nyni(), kdo(), $id]);
            zapis_log('rezervace_stav', "#$id $novy");
            if ($novy === 'odmitnuto' && $r['email'] && !empty($_POST['poslat_email']))
                posli_email($r['email'], 'Thai Most – k vaší žádosti o termín',
                    "Dobrý den,\n\nomlouváme se, ale požadovaný termín ($r[preferovano]) už nemáme volný."
                    . (trim($_POST['duvod'] ?? '') !== '' ? "\n" . trim($_POST['duvod']) : '')
                    . "\n\nZavolejte nám prosím na +420 778 429 329, rádi najdeme jiný čas.\n\nThajské masáže Most\n");
            flash('Stav změněn: ' . STAVY[$novy]);
            presmeruj("/?r=rezervace&id=$id");
        } elseif ($akce === 'poznamka') {
            q('UPDATE rezervace SET poznamka_masaz=?, poznamka_en=?, zmeneno=?, zmenil=? WHERE id=?', [trim($_POST['poznamka_masaz'] ?? ''), trim($_POST['poznamka_en'] ?? ''), nyni(), kdo(), $id]);
            if (!empty($_POST['do_preferenci']) && trim($_POST['poznamka_masaz'] ?? '') !== '') {
                $z = q1('SELECT pref_text FROM zakaznici WHERE id=?', [$r['zakaznik_id']]);
                $novy = trim(trim($z['pref_text']) . "\n" . date('j. n. Y') . ': ' . trim($_POST['poznamka_masaz']));
                q('UPDATE zakaznici SET pref_text=? WHERE id=?', [$novy, $r['zakaznik_id']]);
            }
            flash('Poznámka uložena.');
            presmeruj("/?r=rezervace&id=$id");
        } elseif ($akce === 'tablet_poslat') {
            $err = tablet_poslat($r, (int)($_POST['mistnost_id'] ?? 0));
            flash($err ?? 'Rezervace odeslána na tablet.', $err ? 'err' : 'ok');
            presmeruj("/?r=rezervace&id=$id");
        } elseif ($akce === 'tablet_zrusit') {
            q("UPDATE sezeni SET stav='zruseno', zavreno=? WHERE rezervace_id=? AND stav='cekani'", [nyni(), $id]);
            zapis_log('tablet_zruseno', "rez #$id");
            flash('Rezervace stažena z tabletu.');
            presmeruj("/?r=rezervace&id=$id");
        } elseif ($akce === 'poukaz') {
            $chyba = uplatni_poukaz($r, strtoupper(trim($_POST['kod'] ?? '')), (int)($_POST['castka'] ?? 0));
            if (!$chyba) { flash('Poukaz uplatněn.'); presmeruj("/?r=rezervace&id=$id"); }
        }
    }

    $z = q1('SELECT * FROM zakaznici WHERE id=?', [$r['zakaznik_id']]);
    $st = zakaznik_statistika((int)$z['id']);
    $bonus = zakaznik_bonus($z);
    $poukazy = zakaznik_poukazy((int)$z['id']);
    $zadost = $r['stav'] === 'zadost';
    $formData = $_SERVER['REQUEST_METHOD'] === 'POST' && ($chyba || $kol) ? $_POST : [
        'varianta_id' => $r['varianta_id'], 'maserka_id' => $r['maserka_id'] ?: $z['pref_maserka_id'],
        'datum' => $r['zacatek'] ? substr($r['zacatek'], 0, 10) : date('Y-m-d'),
        'cas' => $r['zacatek'] ? substr($r['zacatek'], 11, 5) : '',
        'delka' => $r['zacatek'] && $r['konec'] ? (strtotime($r['konec']) - strtotime($r['zacatek'])) / 60 : '',
        'platba' => $r['platba'], 'cena' => $r['cena'], 'poslat_email' => $zadost ? 1 : 0,
    ];

    hlavicka('Rezervace', ''); ?>
    <h1><?= h(jmeno_z($r)) ?> <?= stav_html($r['stav']) ?></h1>
    <div class="cols">
      <div>
        <section class="card">
          <h2><?= $zadost ? 'Žádost z webu' : 'Termín' ?></h2>
          <dl>
            <dt>Služba</dt><dd><?= h(($r['sluzba'] ?? '—') . ' – ' . ($r['var_popis'] ?? '')) ?></dd>
            <?php if ($r['zacatek']): ?><dt>Kdy</dt><dd><?= h(datum_cz($r['zacatek'])) ?>–<?= date('H:i', strtotime($r['konec'])) ?></dd><?php endif ?>
            <?php if ($r['preferovano']): ?><dt>Hodí se mu</dt><dd><?= h($r['preferovano']) ?></dd><?php endif ?>
            <?php if ($r['poznamka_zakaznik']): ?><dt>Poznámka</dt><dd><?= nl2br(h($r['poznamka_zakaznik'])) ?></dd><?php endif ?>
            <dt>Masérka</dt><dd><?= h($r['maserka'] ?? '—') ?></dd>
            <dt>Platba</dt><dd><?= h(PLATBY[$r['platba']] ?? '') ?> · <?= kc($r['cena']) ?></dd>
            <dt>Zdroj</dt><dd><?= h($r['zdroj']) ?> · vytvořeno <?= h(datum_cz($r['vytvoreno'])) ?><?= $r['zmenil'] ? ' · naposledy ' . h($r['zmenil']) : '' ?></dd>
          </dl>
        </section>

        <form method="post" class="card">
          <?= csrf_pole() ?>
          <input type="hidden" name="akce" value="<?= $zadost ? 'naplanovat' : 'upravit' ?>">
          <h2><?= $zadost ? 'Naplánovat a potvrdit' : 'Upravit termín' ?></h2>
          <?php if ($zadost): ?><p class="muted">Domluvte termín se zákazníkem (zavolejte na <a href="tel:<?= h($r['telefon']) ?>"><?= h(telefon_cz($r['telefon'])) ?></a>) a uložte ho. Tím se žádost potvrdí.</p><?php endif ?>
          <?php if ($chyba): ?><div class="flash err"><?= h($chyba) ?></div><?php endif ?>
          <?php if ($kol) echo kolize_html($kol) ?>
          <?php rez_formular($formData, false, $zadost ? 'Potvrdit termín' : 'Uložit změny') ?>
        </form>

        <?php if (!$zadost): ?>
        <form method="post" class="card">
          <?= csrf_pole() ?><input type="hidden" name="akce" value="poznamka">
          <h2>Poznámka k masáži</h2>
          <p class="muted small">Co se dělalo, jak to klient hodnotil, na co si příště dát pozor.</p>
          <textarea name="poznamka_masaz" rows="3"><?= h($r['poznamka_masaz']) ?></textarea>
          <label>🇬🇧 English note for the therapist (tablet) <span class="muted small">– nahradí na tabletu všechny poznámky (zákazníka, k masáži, interní)</span><textarea name="poznamka_en" rows="2" lang="en"><?= h($r['poznamka_en']) ?></textarea></label>
          <label class="check"><input type="checkbox" name="do_preferenci" value="1"> Uložit i do trvalých preferencí zákazníka</label>
          <button class="btn">Uložit poznámku</button>
        </form>
        <?php endif ?>

        <?php if (!$zadost) tablet_karta($r); ?>

        <section class="card">
          <h2>Stav</h2>
          <form method="post" class="stav-btns">
            <?= csrf_pole() ?><input type="hidden" name="akce" value="stav">
            <?php if ($zadost): ?>
              <input name="duvod" placeholder="Důvod / jiný návrh (do e-mailu)" class="grow">
              <label class="check"><input type="checkbox" name="poslat_email" value="1" checked> poslat e-mail</label>
              <button name="stav" value="odmitnuto" class="btn danger" data-confirm="Odmítnout žádost?">Odmítnout</button>
            <?php else: ?>
              <?php foreach (['potvrzeno', 'probehlo', 'neprisel', 'zruseno_vcas', 'zruseno_pozde'] as $s): if ($s === $r['stav']) continue; ?>
                <button name="stav" value="<?= $s ?>" class="btn<?= in_array($s, ['neprisel', 'zruseno_pozde']) ? ' danger' : '' ?>"><?= h(STAVY[$s]) ?></button>
              <?php endforeach ?>
            <?php endif ?>
          </form>
        </section>
      </div>

      <div><?php zakaznik_panel($z, $st, $bonus, $poukazy, $r) ?></div>
    </div>
    <?php paticka();
}

/** Boční panel se zákazníkem – recepce vidí vše podstatné u rezervace. */
function zakaznik_panel(array $z, array $st, array $bonus, array $poukazy, ?array $r = null): void
{ ?>
    <section class="card side">
      <h2><a href="/?r=zakaznik&id=<?= $z['id'] ?>"><?= h(jmeno_z($z)) ?></a></h2>
      <p><a href="tel:<?= h($z['telefon']) ?>"><?= h(telefon_cz($z['telefon'])) ?></a><?= $z['email'] ? ' · ' . h($z['email']) : '' ?></p>
      <p><?= stitky_html(zakaznik_stitky($z, $st)) ?></p>
      <p><b>Bonus:</b> <?= bonus_html($bonus) ?></p>
      <?php foreach ($poukazy as $p): ?>
        <p class="tag poukaz">🎁 <a href="/?r=poukaz&id=<?= $p['id'] ?>"><?= h($p['kod']) ?></a> · zbývá <?= kc($p['zustatek']) ?><?= $p['platnost_do'] ? ' · do ' . h(date('j. n. Y', strtotime($p['platnost_do']))) : '' ?></p>
      <?php endforeach ?>
      <p class="muted small"><?= $st['pocet'] ?> návštěv<?= $st['posledni'] ? ' · naposledy ' . h(datum_cz($st['posledni']['zacatek'], false)) . ' (' . h($st['posledni']['sluzba'] . ' ' . $st['posledni']['var_popis']) . ', ' . h($st['posledni']['maserka'] ?? '—') . ')' : '' ?>
        <?= $st['interval'] ? " · chodí cca po $st[interval] dnech" : '' ?></p>
      <?php if ($st['posledni'] && trim((string)$st['posledni']['poznamka_masaz']) !== ''): ?><p class="small">Minule poznámka: „<?= h(mb_strimwidth($st['posledni']['poznamka_masaz'], 0, 140, '…')) ?>“</p><?php endif ?>
      <?php if ($z['pref_tlak'] || $z['pref_text'] || $z['pref_maserka_id']): ?>
        <h3>Preference</h3>
        <p class="pref"><?= $z['pref_tlak'] ? 'Tlak: <b>' . h(TLAK[$z['pref_tlak']]) . '</b><br>' : '' ?>
          <?= $z['pref_maserka_id'] ? 'Oblíbená masérka: <b>' . h(qv('SELECT jmeno FROM maserky WHERE id=?', [$z['pref_maserka_id']])) . '</b><br>' : '' ?>
          <?= nl2br(h($z['pref_text'])) ?></p>
      <?php endif ?>
      <?php if ($z['zdravi_text'] && $z['zdravi_souhlas']): ?><h3>Zdraví – pozor</h3><p class="pref warn"><?= nl2br(h($z['zdravi_text'])) ?></p><?php endif ?>
      <?php if ($z['poznamka_interni']): ?><h3>Interní poznámka</h3><p class="pref"><?= nl2br(h($z['poznamka_interni'])) ?></p><?php endif ?>
      <p><a class="btn" href="/?r=zakaznik&id=<?= $z['id'] ?>">Karta zákazníka</a>
         <a class="btn" href="/?r=rez_nova&z=<?= $z['id'] ?>">Další termín</a>
         <a class="btn primary" href="/?r=volno&z=<?= $z['id'] ?>">Najít volný termín</a></p>
      <?php $kt = karta_zakaznika((int)$z['id']); ?>
      <p class="small"><?= $kt ? '💳 Věrnostní karta <a href="/?r=karta&k=' . h($kt['kod']) . '">' . h($kt['kod']) . '</a>' : '<span class="muted">💳 Bez věrnostní karty – naskenujte QR nové karty a přiřaďte ji.</span> <a href="/?r=karty">karty</a>' ?></p>
      <?php if ($r && $r['stav'] !== 'zadost' && !$r['poukaz_id']): ?>
      <form method="post" class="inline">
        <?= csrf_pole() ?><input type="hidden" name="akce" value="poukaz">
        <h3>Uplatnit poukaz</h3>
        <input name="kod" placeholder="TM-26-XXXXX" value="<?= h($poukazy[0]['kod'] ?? '') ?>">
        <input name="castka" type="number" placeholder="částka" value="<?= h($r['cena'] ?? '') ?>">
        <button class="btn">Uplatnit</button>
      </form>
      <?php endif ?>
    </section>
<?php }
