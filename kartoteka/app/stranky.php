<?php
// Stránky: přihlášení, Dnes, Kalendář, Rezervace.
require __DIR__ . '/stranky_zakaznici.php';
require __DIR__ . '/stranky_poukazy.php';
require __DIR__ . '/stranky_nastaveni.php';
require __DIR__ . '/stranky_tym.php';
require __DIR__ . '/stranky_vernost.php';
require __DIR__ . '/stranky_import.php';
require __DIR__ . '/stranky_tablet.php';
require __DIR__ . '/demo.php';
require __DIR__ . '/dostupnost.php';
require __DIR__ . '/prichody.php';
require __DIR__ . '/stranky_ai.php';

function stranka_login(): void
{
    $chyba = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        over_csrf();
        if (!limit_ip('login', 20)) $chyba = 'Příliš mnoho pokusů. Zkuste to za hodinu.';
        else {
            $u = q1('SELECT * FROM uzivatele WHERE login=? AND aktivni=1', [trim($_POST['login'] ?? '')]);
            if ($u && password_verify($_POST['heslo'] ?? '', $u['heslo_hash'])) {
                $kam = $_SESSION['po_prihlaseni'] ?? '';
                session_regenerate_id(true);
                $_SESSION['uid'] = $u['id'];
                unset($_SESSION['po_prihlaseni']);
                if (!empty($_POST['trvale'])) trvale_prihlaseni_vytvor((int)$u['id']);
                q('INSERT INTO log(kdo,akce) VALUES(?,?)', [$u['jmeno'], 'prihlaseni' . (!empty($_POST['trvale']) ? ' (trvale)' : '')]);
                presmeruj(preg_match('~^/\?r=[a-z_]+[^\s]*$~', $kam) ? $kam : '/?r=dnes');
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
      <label class="check"><input type="checkbox" name="trvale" value="1"<?= str_contains((string)($_SESSION['po_prihlaseni'] ?? ''), 'app=1') ? ' checked' : '' ?>> Zůstat přihlášen na tomto zařízení (<?= TRVALE_DNI ?> dní) – jen na vlastním telefonu, ne na sdíleném počítači</label>
      <button class="btn primary">Přihlásit</button>
    </form>
    <?php paticka();
}

// ---------------- DNES ----------------
function stranka_dnes(array $u): void
{
    // recepce posílá masáž na tablet až ve chvíli, kdy klient přijde: zvolí masérnu (a případně jinou masérku)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = $_POST['akce'] ?? '';
        $r = rezervace_detail((int)($_POST['rez_id'] ?? 0));
        if ($r && $akce === 'tablet_dnes') {
            $mid = (int)($_POST['maserka_id'] ?? 0);
            if ($mid && $mid !== (int)$r['maserka_id']) {
                $kol = kolize($mid, $r['zacatek'], $r['konec'], (int)$r['id']);
                q('UPDATE rezervace SET maserka_id=?, zmeneno=?, zmenil=? WHERE id=?', [$mid, nyni(), kdo(), $r['id']]);
                zapis_log('rezervace_maserka', "#{$r['id']} → masérka $mid");
                if ($kol) flash('Pozor: ' . qv('SELECT jmeno FROM maserky WHERE id=?', [$mid]) . ' má v tu dobu podle plánu i ' . jmeno_z($kol[0]) . ' (' . date('H:i', strtotime($kol[0]['zacatek'])) . ') – zkontrolujte kalendář.', 'warn');
                $r = rezervace_detail((int)$r['id']);
            }
            $err = tablet_poslat($r, (int)($_POST['mistnost_id'] ?? 0));
            $mn = qv('SELECT nazev FROM mistnosti WHERE id=?', [(int)($_POST['mistnost_id'] ?? 0)]);
            flash($err ?? jmeno_z($r) . ' → ' . $mn . ' (' . ($r['maserka'] ?? '') . ')', $err ? 'err' : 'ok');
        } elseif ($r && $akce === 'tablet_par') {
            // párová: obě lůžka jedné masérny – první lůžko 1. masérka, druhé 2. masérka
            $kl = (string)($_POST['skupina'] ?? '');
            $luzka = array_values(array_filter(q('SELECT * FROM mistnosti WHERE aktivni=1 ORDER BY poradi, id')->fetchAll(), fn($t) => maserna_klic($t) === $kl));
            $mas = array_values(array_filter([(int)$r['maserka_id'], (int)$r['maserka2_id']]));
            if (count($luzka) < 2 || count($mas) < 2) flash('Párová potřebuje masérnu se dvěma lůžky a dvě vybrané masérky.', 'err');
            else {
                $e1 = tablet_poslat($r, (int)$luzka[0]['id'], $mas[0], true);
                $e2 = $e1 ? null : tablet_poslat($r, (int)$luzka[1]['id'], $mas[1], false);
                flash(($e1 ?? $e2) ?? jmeno_z($r) . ' → ' . $luzka[0]['nazev'] . ' (' . $r['maserka'] . ') + ' . $luzka[1]['nazev'] . ' (' . $r['maserka2'] . ')', ($e1 ?? $e2) ? 'err' : 'ok');
            }
        } elseif ($akce === 'prichody_poslat') {
            [$ok, $err] = prichody_rozeslat($_POST);
            if ($ok) flash('Odesláno na tablety: ' . implode(' · ', $ok), 'ok');
            foreach ($err as $e) flash($e, 'err');
            if (!$ok && !$err) flash('Nic nebylo vybráno.', 'warn');
        } elseif ($akce === 'ukoncit') {
            $e = masaz_ukoncit_recepce((int)($_POST['rez_id'] ?? 0), (string)($_POST['duvod'] ?? ''), !empty($_POST['pocitat']));
            flash($e ?? 'Masáž ukončena, vedoucí dostane upozornění.', $e ? 'err' : 'ok');
        } elseif ($akce === 'hromadne_stav' && in_array($_POST['stav'] ?? '', ['probehlo', 'neprisel', 'zruseno_pozde'], true)) {
            // hromadně: všechny zaškrtnuté rezervace ke kontrole dostanou stejný stav
            $novy = $_POST['stav']; $n = 0;
            foreach (array_unique(array_map('intval', (array)($_POST['ids'] ?? []))) as $rid) {
                if (!q1("SELECT 1 FROM rezervace WHERE id=? AND stav='potvrzeno'", [$rid])) continue;
                q('UPDATE rezervace SET stav=?, zmeneno=?, zmenil=? WHERE id=?', [$novy, nyni(), kdo(), $rid]);
                zapis_log('rezervace_stav', "#$rid $novy (hromadně)");
                if ($novy !== 'probehlo') q("UPDATE sezeni SET stav='zruseno', zavreno=? WHERE rezervace_id=? AND stav='cekani'", [nyni(), $rid]);
                $n++;
            }
            flash($n ? "Označeno $n rezervací: " . STAVY[$novy] . '.' : 'Nic nebylo zaškrtnuté.', $n ? 'ok' : 'warn');
        } elseif ($r && $akce === 'tablet_stahnout') {
            q("UPDATE sezeni SET stav='zruseno', zavreno=? WHERE rezervace_id=? AND stav='cekani'", [nyni(), $r['id']]);
            zapis_log('tablet_zruseno', "rez #{$r['id']}");
            flash(jmeno_z($r) . ': staženo z tabletu.');
        }
        presmeruj('/?r=dnes');
    }
    $zadosti = q("SELECT r.*, z.jmeno, z.prijmeni, z.telefon, s.nazev AS sluzba, v.popis AS var_popis
        FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id LEFT JOIN varianty v ON v.id=r.varianta_id
        LEFT JOIN sluzby s ON s.id=v.sluzba_id WHERE r.stav='zadost' ORDER BY r.vytvoreno")->fetchAll();
    $dnes = date('Y-m-d');
    $plan = q("SELECT r.*, z.jmeno, z.prijmeni, z.telefon, z.pref_tlak, z.pref_text, z.zdravi_text, z.zdravi_text_en, z.zdravi_souhlas,
        s.nazev AS sluzba, v.popis AS var_popis, m.jmeno AS maserka, m2.jmeno AS maserka2
        FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id LEFT JOIN varianty v ON v.id=r.varianta_id
        LEFT JOIN sluzby s ON s.id=v.sluzba_id LEFT JOIN maserky m ON m.id=r.maserka_id LEFT JOIN maserky m2 ON m2.id=r.maserka2_id
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

    <?php $konf = konflikty_dostupnosti(); if ($konf): ?>
      <div class="flash warn">⚠ <b><?= count($konf) ?> masáž<?= count($konf) === 1 ? '' : (count($konf) < 5 ? 'e' : 'í') ?> u masérky, která v tu dobu nepracuje</b> (volno / víkend / svátek) – nejbližší <?= h(datum_cz($konf[0]['zacatek'])) ?> <?= h(jmeno_z($konf[0])) ?>. <a href="/?r=dostupnost">Vyřešit se zákazníky →</a></div>
    <?php endif ?>
    <?php $kontrola = ke_kontrole(); if ($kontrola): ?>
    <section class="card kontrola">
      <h2>Přišel klient? <span class="muted">(<?= count($kontrola) ?> ke kontrole)</span></h2>
      <p class="muted small">Termín už skončil a masáž nebyla spuštěná na tabletu. Označte, jak to dopadlo – „Nepřišel“ a „Zrušeno pozdě“ se počítají do štítku ⚠️ Nespolehlivý, do bonusu se počítá jen „Proběhlo“.</p>
      <form id="hromadne" method="post" class="row-item" style="align-items:center;gap:8px;flex-wrap:wrap">
        <?= csrf_pole() ?><input type="hidden" name="akce" value="hromadne_stav">
        <label class="check"><input type="checkbox" id="vsechny" onchange="document.querySelectorAll('.kont-cb').forEach(c=>c.checked=this.checked)"> Vybrat všechny</label>
        <button name="stav" value="probehlo" class="btn primary" data-confirm="Označit všechny zaškrtnuté jako Proběhlo (dorazili)?">✓ Zaškrtnutí dorazili</button>
        <button name="stav" value="neprisel" class="btn danger" data-confirm="Označit všechny zaškrtnuté jako Nepřišel?">Zaškrtnutí nepřišli</button>
      </form>
      <?php foreach ($kontrola as $r): ?>
        <form method="post" action="/?r=rezervace&id=<?= $r['id'] ?>" class="row-item">
          <?= csrf_pole() ?><input type="hidden" name="akce" value="stav"><input type="hidden" name="zpet" value="dnes">
          <input type="checkbox" class="kont-cb" name="ids[]" value="<?= $r['id'] ?>" form="hromadne" aria-label="vybrat" style="width:22px;height:22px">
          <div class="time"><?= date('H:i', strtotime($r['zacatek'])) ?><br><span class="muted small"><?= date('j. n.', strtotime($r['zacatek'])) ?></span></div>
          <div class="grow"><a href="/?r=rezervace&id=<?= $r['id'] ?>"><b><?= h(jmeno_z($r)) ?></b></a> · <a href="tel:<?= h($r['telefon']) ?>"><?= h(telefon_cz($r['telefon'])) ?></a><br>
            <?= h($r['sluzba'] . ' – ' . $r['var_popis']) ?> · <?= h(($r['maserka'] ?? 'bez masérky') . (!empty($r['maserka2']) ? ' + ' . $r['maserka2'] : '')) ?></div>
          <button name="stav" value="probehlo" class="btn primary">✓ Proběhlo</button>
          <button name="stav" value="neprisel" class="btn danger">Nepřišel</button>
          <button name="stav" value="zruseno_pozde" class="btn">Zrušeno pozdě</button>
        </form>
      <?php endforeach ?>
    </section>
    <?php endif ?>

    <?php ukoncene_dnes_banner(); prichody_panel(); ?>
    <?php
    $mistnosti = q('SELECT * FROM mistnosti WHERE aktivni=1 ORDER BY poradi, nazev')->fetchAll();
    $stavMist = [];   // id masérny => sezení (s příjmením) nebo null
    foreach ($mistnosti as $m) {
        $sz = sezeni_aktivni((int)$m['id']);
        if ($sz) $sz += q1('SELECT z.prijmeni, z.jmeno, ma.jmeno AS maserka FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id LEFT JOIN maserky ma ON ma.id=? WHERE r.id=?', [$sz['maserka_id'], $sz['rezervace_id']]) ?: [];
        $stavMist[(int)$m['id']] = $sz;
    }
    $sezRez = [];     // rezervace_id => aktivní sezení
    foreach ($stavMist as $mid => $sz) if ($sz) $sezRez[(int)$sz['rezervace_id']] = $sz + ['mistnost' => $mid];
    $maserkyAkt = q('SELECT id, jmeno FROM maserky WHERE aktivni=1 ORDER BY poradi, id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $nazMist = array_column($mistnosti, 'nazev', 'id');
    ?>
    <?php if ($mistnosti): ?>
    <section class="card mistnosti-ted">
      <h2>Masérny teď</h2>
      <div class="mist-grid">
      <?php foreach ($mistnosti as $m): $sz = $stavMist[(int)$m['id']]; ?>
        <div class="mist <?= $sz ? 'm-' . h($sz['stav']) : 'm-volno' ?>"><b><?= h($m['nazev']) ?></b>
          <span><?php if (!$sz): ?>volná<?php else: ?><?= h(SEZENI_STAVY[$sz['stav']] ?? $sz['stav']) ?> · <?= h(trim($sz['prijmeni'] ?: $sz['jmeno'])) ?><?= $sz['maserka'] ? ' · ' . h($sz['maserka']) : '' ?><?= $sz['stav'] === 'bezi' && $sz['plan_konec'] ? ' · do ' . h(substr($sz['plan_konec'], 11, 5)) : '' ?><?php endif ?></span></div>
      <?php endforeach ?>
      </div>
    </section>
    <?php endif ?>

    <section class="card">
      <h2>Dnešní plán <span class="muted">(<?= count($plan) ?>)</span></h2>
      <?php if (!$plan): ?><p class="muted">Na dnešek nic naplánováno. <a href="/?r=rez_nova">Přidat rezervaci</a></p><?php endif ?>
      <?php foreach ($plan as $r): ?>
        <div class="row-item plan-row">
          <a class="time" href="/?r=rezervace&id=<?= $r['id'] ?>"><?= date('H:i', strtotime($r['zacatek'])) ?><br><span class="muted small"><?= date('H:i', strtotime($r['konec'])) ?></span></a>
          <div class="grow">
            <a href="/?r=rezervace&id=<?= $r['id'] ?>"><b><?= h(jmeno_z($r)) ?></b></a> <?= stav_html($r['stav']) ?><br>
            <?= h($r['sluzba'] . ' – ' . $r['var_popis']) ?> · <?= h($r['maserka'] ?? 'bez masérky') ?>
            <?php if ($r['platba']): ?> · <?= h(PLATBY[$r['platba']]) ?><?php endif ?>
            <?php $pref = trim(($r['pref_tlak'] ? 'tlak ' . TLAK[$r['pref_tlak']] . '. ' : '') . $r['pref_text']);
                  if ($pref): ?><br><span class="pref">💬 <?= h(mb_strimwidth($pref, 0, 140, '…')) ?></span><?php endif ?>
            <?php if ($r['zdravi_text'] && $r['zdravi_souhlas']): ?><br><span class="pref warn">⚕ <?= h(mb_strimwidth($r['zdravi_text'], 0, 120, '…')) ?></span><?php if (!$r['zdravi_text_en']): ?> <span class="pref warn" title="Masérka na tabletu uvidí zdraví jen česky">🇬🇧 chybí anglicky</span><?php endif ?><?php endif ?>
          </div>
          <?php $sz = $sezRez[(int)$r['id']] ?? null;
          if ($mistnosti && $r['stav'] === 'potvrzeno' && strtotime($r['konec']) > time() && ($r['maserka_id'] || $r['maserka2_id'])): ?>
          <div class="tab-send">
            <?php if ($r['maserka2_id'] && (!$sz || $sz['stav'] === 'cekani')): foreach (kapacita()['masery'] as $kl => $mk): if ($mk['luzka'] < 2) continue; ?>
              <form method="post" class="inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="tablet_par"><input type="hidden" name="rez_id" value="<?= $r['id'] ?>"><input type="hidden" name="skupina" value="<?= h($kl) ?>">
                <button class="btn small primary">📲👥 <?= h($mk['nazev']) ?> – obě lůžka</button></form>
            <?php endforeach; endif ?>
            <?php if ($sz && $sz['stav'] !== 'cekani'): ?>
              <span class="tab-st bezi">▶ <?= h($nazMist[$sz['mistnost']] ?? '') ?> · <?= h(SEZENI_STAVY[$sz['stav']] ?? '') ?></span>
              <?php if ($sz['stav'] === 'bezi') ukoncit_formular($r) ?>
            <?php else: ?>
            <form method="post">
              <?= csrf_pole() ?><input type="hidden" name="akce" value="tablet_dnes"><input type="hidden" name="rez_id" value="<?= $r['id'] ?>">
              <?php if ($sz): ?><span class="tab-st cek">📲 čeká v <?= h($nazMist[$sz['mistnost']] ?? '') ?></span><?php endif ?>
              <select name="maserka_id" title="Masérka – jde změnit těsně před masáží"><?php foreach ($maserkyAkt as $mid => $mj): ?><option value="<?= $mid ?>" <?= (int)$r['maserka_id'] === (int)$mid ? 'selected' : '' ?>><?= h($mj) ?></option><?php endforeach ?></select>
              <?php foreach ($mistnosti as $m): $obs = $stavMist[(int)$m['id']]; $tady = $sz && (int)$sz['mistnost'] === (int)$m['id'];
                    $zabrana = $obs && in_array($obs['stav'], ['bezi', 'konec'], true); ?>
                <button class="btn small<?= $tady ? ' on' : '' ?>" name="mistnost_id" value="<?= $m['id'] ?>" <?= $zabrana ? 'disabled' : '' ?>
                  title="<?= $zabrana ? 'Masérna je obsazená' : ($tady ? 'Poslat znovu (např. po změně masérky)' : '') . ($obs && !$tady ? 'Na tabletu čeká ' . h(trim($obs['prijmeni'] ?: $obs['jmeno'])) . ' – přepíše se' : ($tady ? '' : 'Poslat sem')) ?>"><?= $sz ? '↪ ' : '📲 ' ?><?= h($m['nazev']) ?><?= $zabrana ? ' ⛔' : ($obs && !$tady ? ' ⏳' : '') ?></button>
              <?php endforeach ?>
              <?php if ($sz): ?><button class="btn small" name="akce" value="tablet_stahnout">Stáhnout</button><?php endif ?>
            </form>
            <?php endif ?>
          </div>
          <?php endif ?>
        </div>
      <?php endforeach ?>
    </section>
    <?php paticka();
}

// ---------------- KALENDÁŘ ----------------
function stranka_kalendar(array $u): void
{
    $d = preg_match('~^\d{4}-\d{2}-\d{2}$~', $_GET['d'] ?? '') ? $_GET['d'] : date('Y-m-d');
    $minOd = min_z(nastaveni('oteviraci_od', '09:00')); $minDo = min_z(nastaveni('oteviraci_do', '20:00'));
    $maserky = q('SELECT * FROM maserky WHERE aktivni=1 ORDER BY poradi')->fetchAll();
    $okna = [];
    foreach ($maserky as $m) {
        $okna[(int)$m['id']] = maserka_okna((int)$m['id'], $d);
        foreach ($okna[(int)$m['id']] as [$x, $y]) { $minOd = min($minOd, $x); $minDo = max($minDo, $y); }
    }
    $px = 1.4; // pixelů na minutu
    $sloupce = array_merge($maserky, [['id' => 0, 'jmeno' => 'Wellness masérna / bez masérky', 'barva' => '#888']]);
    $rez = q("SELECT r.*, z.jmeno, z.prijmeni, s.nazev AS sluzba, v.popis AS var_popis, COALESCE(v.wellness_mistnost, 0) AS w
        FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id LEFT JOIN varianty v ON v.id=r.varianta_id
        LEFT JOIN sluzby s ON s.id=v.sluzba_id
        WHERE r.zacatek >= ? AND r.zacatek < ? AND r.stav NOT IN ('zadost','odmitnuto')",
        [$d, date('Y-m-d', strtotime($d . ' +1 day'))])->fetchAll();
    $poSloupcich = [];
    foreach ($rez as $r) {
        // u masérek jen část s masérkami (párová 60 min), ve sloupci wellness masérny celá doba
        $rm = $r; $rm['konec_zobr'] = rez_masaz_konec($r);
        if ($r['maserka_id']) $poSloupcich[(int)$r['maserka_id']][] = $rm;
        if ($r['maserka2_id']) $poSloupcich[(int)$r['maserka2_id']][] = $rm;
        if ((int)$r['w'] === 1 || (!$r['maserka_id'] && !$r['maserka2_id'])) $poSloupcich[0][] = $r + ['konec_zobr' => $r['konec']];
        $minDo = max($minDo, $minz_ = (int)date('G', strtotime($r['konec'])) * 60 + (int)date('i', strtotime($r['konec'])));   // wellness po zavírací době
    }
    $minDo = (int)(ceil($minDo / 30) * 30);
    $prev = date('Y-m-d', strtotime("$d -1 day")); $next = date('Y-m-d', strtotime("$d +1 day"));
    $sv = svatek($d);
    $minz = fn($dt) => (int)date('G', strtotime($dt)) * 60 + (int)date('i', strtotime($dt));

    hlavicka('Kalendář', 'kalendar'); ?>
    <div class="cal-nav">
      <a class="btn" href="/?r=kalendar&d=<?= $prev ?>" title="předchozí den (←)">‹</a>
      <form><input type="hidden" name="r" value="kalendar"><input type="date" name="d" value="<?= h($d) ?>" onchange="this.form.submit()"></form>
      <a class="btn" href="/?r=kalendar&d=<?= $next ?>" title="další den (→)">›</a>
      <a class="btn" href="/?r=kalendar">Dnes</a>
      <h1><?= h(datum_cz($d, false)) ?><?= $sv ? ' <small class="tag nesp">🎌 ' . h($sv) . '</small>' : '' ?></h1>
      <a class="btn small" href="/?r=dostupnost&d=<?= h($d) ?>">🌴 Volno / ⏱ přesčas</a>
    </div>
    <p class="muted small">Šrafovaně = masérka nepracuje. Zeleně čárkovaně = volné okno k zaplnění (klepnutím přidáte rezervaci). Šipky ← → nebo přejetí prstem = posun po dnech.</p>
    <div class="cal" id="cal" style="--h:<?= ($minDo - $minOd) * $px ?>px">
      <div class="cal-hours">
        <?php for ($m = (int)ceil($minOd / 60) * 60; $m < $minDo; $m += 60): ?><div style="top:<?= ($m - $minOd) * $px ?>px"><?= hhmm($m) ?></div><?php endfor ?>
      </div>
      <?php foreach ($sloupce as $s):
        $mid = (int)$s['id'];
        $bloky = $poSloupcich[$mid] ?? [];
        usort($bloky, fn($a, $b) => strcmp($a['zacatek'], $b['zacatek']));
        $aktivni = array_values(array_filter($bloky, fn($r) => in_array($r['stav'], ['potvrzeno', 'probehlo'], true)));
        $ok = $mid ? $okna[$mid] : [[$minOd, $minDo]];
        // volná okna = pracovní doba minus rezervace
        $volna = $mid ? intervaly_minus($ok, array_map(fn($r) => [$minz($r['zacatek']), $minz($r['konec_zobr'])], $aktivni)) : [];
        $volna = array_values(array_filter($volna, fn($x) => $x[1] - $x[0] >= 30 && ($d > date('Y-m-d') || ($d === date('Y-m-d') && $x[1] > (int)date('G') * 60 + (int)date('i')))));
        $volnoMin = array_sum(array_map(fn($x) => $x[1] - $x[0], $volna));
        $popis = $mid ? maserka_den_popis($mid, $d) : []; ?>
      <div class="cal-col">
        <div class="cal-head" style="border-color:<?= h($s['barva']) ?>"><?= h($s['jmeno']) ?>
          <?php foreach ($popis as [$typ, $t]): ?><small class="<?= h($typ) ?>"><?= h($t) ?></small><?php endforeach ?>
          <?php if ($mid && $ok): ?><small><?= $volnoMin ? 'volno ' . h(rtrim(rtrim(number_format($volnoMin / 60, 1, ',', ''), '0'), ',')) . ' h' : 'plno ✓' ?></small><?php endif ?>
        </div>
        <div class="cal-body">
          <?php if ($mid): foreach (intervaly_minus([[$minOd, $minDo]], $ok) as [$x, $y]): ?>
            <div class="mimo-sm" style="top:<?= ($x - $minOd) * $px ?>px;height:<?= ($y - $x) * $px ?>px"></div>
          <?php endforeach; endif ?>
          <?php for ($m = $minOd; $m < $minDo; $m += 30): ?>
            <a class="slot" style="top:<?= ($m - $minOd) * $px ?>px;height:<?= 30 * $px ?>px"
               href="/?r=rez_nova&d=<?= h($d) ?>&t=<?= hhmm($m) ?>&m=<?= $mid ?>"></a>
          <?php endfor ?>
          <?php foreach ($volna as [$x, $y]): ?>
            <a class="gap" style="top:<?= ($x - $minOd) * $px + 1 ?>px;height:<?= max(18, ($y - $x) * $px - 2) ?>px" href="/?r=rez_nova&d=<?= h($d) ?>&t=<?= hhmm($x) ?>&m=<?= $mid ?>">+ <?= hhmm($x) ?> · <?= $y - $x ?> min</a>
          <?php endforeach ?>
          <?php
          // překrývající se rezervace (kolize) zobrazit vedle sebe
          $drahy = [];
          foreach ($bloky as $i => $r) { $dr = 0; foreach ($drahy as $j => $kon) if ($kon <= $r['zacatek']) { $dr = $j; break; } else $dr = count($drahy); $drahy[$dr] = $r['konec_zobr']; $bloky[$i]['draha'] = $dr; }
          $pocetDrah = max(1, count($drahy));
          foreach ($bloky as $r):
              $t1 = strtotime($r['zacatek']); $t2 = strtotime($r['konec_zobr']);
              $top = ($minz($r['zacatek']) - $minOd) * $px;
              $hgt = max(26, ($t2 - $t1) / 60 * $px - 2);
              $w = 100 / $pocetDrah; $l = $r['draha'] * $w;
              $mimoSm = $mid && in_array($r['stav'], ['potvrzeno'], true) && !maserka_pracuje($mid, $r['zacatek'], $r['konec_zobr']); ?>
            <a class="blk s-<?= h($r['stav']) ?><?= $mimoSm ? ' konfl' : '' ?>" href="/?r=rezervace&id=<?= $r['id'] ?>"
               style="top:<?= $top ?>px;height:<?= $hgt ?>px;left:calc(<?= $l ?>% + 3px);width:calc(<?= $w ?>% - 6px);border-color:<?= h($s['barva']) ?>"
               <?= $mimoSm ? 'title="Masérka v tu dobu nepracuje – vyřešit"' : '' ?>>
              <b><?= $mimoSm ? '⚠ ' : '' ?><?= date('H:i', $t1) ?>–<?= date('H:i', $t2) ?></b> <?= h(jmeno_z($r)) ?><?= $r['maserka2_id'] ? ' 👥' : '' ?><br>
              <span><?= h($r['sluzba'] . ' ' . $r['var_popis']) ?></span>
            </a>
          <?php endforeach ?>
        </div>
      </div>
      <?php endforeach ?>
    </div>
    <script>
    (function () {
      const go = d => location.href = '/?r=kalendar&d=' + d;
      document.addEventListener('keydown', e => { if (e.target.closest('input,select,textarea')) return;
        if (e.key === 'ArrowLeft') go('<?= $prev ?>'); if (e.key === 'ArrowRight') go('<?= $next ?>'); });
      let x0 = null, y0 = 0; const c = document.getElementById('cal');
      c.addEventListener('touchstart', e => { x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; }, { passive: true });
      c.addEventListener('touchend', e => { if (x0 === null) return; const dx = e.changedTouches[0].clientX - x0, dy = e.changedTouches[0].clientY - y0;
        // jen výrazné vodorovné přejetí a jen když kalendář není širší než obrazovka (jinak se posouvá do strany)
        if (Math.abs(dx) > 90 && Math.abs(dx) > Math.abs(dy) * 2 && c.scrollWidth <= c.clientWidth + 4) go(dx < 0 ? '<?= $next ?>' : '<?= $prev ?>'); x0 = null; });
    })();
    </script>
    <?php paticka();
}

// ---------------- REZERVACE: formulář ----------------
/** Zpracuje odeslaný formulář rezervace (nová nebo úprava/naplánování). Vrací [chyba|null, kolize[]]. */
function rez_uloz(array $p, ?array $puvodni, bool $potvrdit): array
{
    $tel = telefon_norm($p['telefon'] ?? '');
    if (!$puvodni && $tel === '') return ['Zadejte platný telefon.', []];
    $var = q1('SELECT v.*, s.kat FROM varianty v JOIN sluzby s ON s.id=v.sluzba_id WHERE v.id=?', [(int)($p['varianta_id'] ?? 0)]);
    if (!$var) return ['Vyberte masáž / službu.', []];
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $p['datum'] ?? '') || !preg_match('~^\d{2}:\d{2}$~', $p['cas'] ?? ''))
        return ['Zadejte datum a čas.', []];
    $delka = (int)($p['delka'] ?? 0) ?: ((int)$var['minuty'] ?: 60);
    $zac = $p['datum'] . ' ' . $p['cas'] . ':00';
    $kon = date('Y-m-d H:i:s', strtotime($zac) + $delka * 60);
    $pocet = (int)$var['maserky'];          // 0 = bez masérky (whirlpool), 1, 2 = párová
    $mas = $pocet >= 1 ? ((int)($p['maserka_id'] ?? 0) ?: null) : null;
    $mas2 = $pocet >= 2 ? ((int)($p['maserka2_id'] ?? 0) ?: null) : null;
    if ($mas2 && $mas2 === $mas) return ['U párové vyberte dvě různé masérky.', []];
    // masérky jsou potřeba jen první část (např. párová: 60 min masáž, pak whirlpool a sauna bez masérek)
    $mKon = $var['maserky_min'] && (int)$var['maserky_min'] < $delka ? date('Y-m-d H:i:s', strtotime($zac) + (int)$var['maserky_min'] * 60) : null;
    $mKonec = $mKon ?? $kon;
    $platba = array_key_exists((string)($p['platba'] ?? ''), PLATBY) ? (string)$p['platba'] : '';
    $cena = ($p['cena'] ?? '') !== '' ? (int)$p['cena'] : (int)$var['cena'];

    if ($pocet >= 2 && (!$mas || !$mas2) && empty($p['presto'])) return [$var['popis'] . ' potřebuje dvě masérky – vyberte obě (nebo zaškrtněte „Přesto uložit“).', []];
    $kol = [];
    foreach (array_filter([$mas, $mas2]) as $m) $kol = array_merge($kol, kolize($m, $zac, $mKonec, (int)($puvodni['id'] ?? 0)));
    if ($kol && empty($p['presto'])) return [null, $kol];
    foreach (array_filter([$mas, $mas2]) as $m) if (empty($p['presto']) && !maserka_pracuje($m, $zac, $mKonec)) {
        $pop = array_column(maserka_den_popis($m, $p['datum']), 1);
        return [qv('SELECT jmeno FROM maserky WHERE id=?', [$m]) . ' v tu dobu nepracuje' . ($pop ? ' (' . implode(', ', $pop) . ')' : '') . '. Vyberte jinou masérku nebo čas, zapište jí přesčas (Volna a přesčasy), nebo zaškrtněte „Přesto uložit“.', []];
    }
    if (empty($p['presto']) && ($kp = kapacita_problem($zac, $kon, (int)$var['wellness_mistnost'] === 1, (int)($puvodni['id'] ?? 0))))
        return [$kp . ' Zvolte jiný čas, nebo zaškrtněte „Přesto uložit“.', []];
    if (!$mas && $pocet >= 1 && empty($p['presto'])) {
        $aktivni = (int)qv('SELECT COUNT(*) FROM maserky WHERE aktivni=1');
        $obsazene = (int)qv("SELECT COUNT(DISTINCT maserka_id) FROM rezervace WHERE maserka_id IS NOT NULL AND stav IN ('potvrzeno','probehlo') AND id<>? AND zacatek < ? AND konec > ?",
            [(int)($puvodni['id'] ?? 0), $kon, $zac]);
        if ($aktivni && $obsazene >= $aktivni) return ['V tu dobu jsou obsazené všechny masérky. Zvolte jiný čas (přehled obsazenosti je pod formulářem), nebo zaškrtněte „Přesto uložit“.', []];
    }
    // stejný zákazník nemůže mít ve stejnou dobu dvě masáže
    if (empty($p['presto'])) {
        $zidKontrola = $puvodni ? (int)$puvodni['zakaznik_id'] : (int)(qv('SELECT id FROM zakaznici WHERE telefon=?', [$tel]) ?? 0);
        if ($zidKontrola && qv("SELECT 1 FROM rezervace WHERE zakaznik_id=? AND id<>? AND stav IN ('potvrzeno','probehlo') AND zacatek < ? AND konec > ?", [$zidKontrola, (int)($puvodni['id'] ?? 0), $kon, $zac]))
            return ['Zákazník už má ve stejnou dobu jinou masáž. Zkontrolujte, jestli nejde o dvojí zápis (nebo zaškrtněte „Přesto uložit“).', []];
    }

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
        q('UPDATE rezervace SET varianta_id=?, maserka_id=?, maserka2_id=?, masaz_konec=?, zacatek=?, konec=?, stav=?, platba=?, cena=?, zmeneno=?, zmenil=? WHERE id=?',
            [$var['id'], $mas, $mas2, $mKon, $zac, $kon, $stav, $platba, $cena, nyni(), kdo(), $puvodni['id']]);
        $rid = (int)$puvodni['id'];
        zapis_log('rezervace_uprava', "#$rid $zac");
    } else {
        q("INSERT INTO rezervace(zakaznik_id,varianta_id,maserka_id,maserka2_id,masaz_konec,zacatek,konec,stav,zdroj,platba,cena,poznamka_zakaznik,zmenil)
           VALUES(?,?,?,?,?,?,?,'potvrzeno',?,?,?,?,?)",
            [$zid, $var['id'], $mas, $mas2, $mKon, $zac, $kon, in_array($p['zdroj'] ?? '', ['telefon', 'osobne'], true) ? $p['zdroj'] : 'recepce',
             $platba, $cena, trim($p['poznamka'] ?? ''), kdo()]);
        $rid = (int)db()->lastInsertId();
        zapis_log('rezervace_nova', "#$rid $zac");
    }

    if ($stav === 'potvrzeno' && !empty($p['poslat_email'])) posli_potvrzeni($rid);
    return [null, [], $rid];
}

/** Změna stavu rezervace (proběhlo, nepřišel, zrušeno, odmítnuto…) – ruční i z asistenta. */
function rez_zmen_stav(array $r, string $novy, bool $poslatEmail = false, string $duvod = ''): void
{
    $id = (int)$r['id'];
    q('UPDATE rezervace SET stav=?, zmeneno=?, zmenil=? WHERE id=?', [$novy, nyni(), kdo(), $id]);
    zapis_log('rezervace_stav', "#$id $novy");
    // klient nepřišel / zrušil: stáhnout masáž z tabletu, pokud tam čeká
    if (in_array($novy, ['neprisel', 'zruseno_vcas', 'zruseno_pozde', 'odmitnuto'], true))
        q("UPDATE sezeni SET stav='zruseno', zavreno=? WHERE rezervace_id=? AND stav='cekani'", [nyni(), $id]);
    if ($novy === 'odmitnuto' && ($r['email'] ?? '') && $poslatEmail)
        posli_email($r['email'], 'Thai Most – k vaší žádosti o termín',
            "Dobrý den,\n\nomlouváme se, ale požadovaný termín ($r[preferovano]) už nemáme volný."
            . ($duvod !== '' ? "\n" . $duvod : '')
            . "\n\nZavolejte nám prosím na +420 778 429 329, rádi najdeme jiný čas.\n\nThajské masáže Most\n");
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

function rez_formular(array $v, bool $novy, string $tlacitko, int $rezId = 0): void
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
    <fieldset id="termin" data-mimo="<?= $rezId ?>"><legend>Termín</legend>
      <label>Masáž / služba *<select name="varianta_id" id="var" required><?= varianty_select(isset($v['varianta_id']) ? (int)$v['varianta_id'] : null) ?></select></label>
      <div class="grid3">
        <label>Datum *<input type="date" name="datum" required value="<?= h($v['datum'] ?? date('Y-m-d')) ?>"></label>
        <label>Čas *<input type="time" name="cas" required step="300" value="<?= h($v['cas'] ?? '') ?>"></label>
        <label>Délka (min)<input type="number" name="delka" id="delka" min="10" max="300" step="5" value="<?= h($minuty) ?>" placeholder="podle služby"></label>
      </div>
      <div class="grid3">
        <label id="lmas">Masérka<select name="maserka_id"><?= maserky_select(isset($v['maserka_id']) ? (int)$v['maserka_id'] : null) ?></select></label>
        <label id="lmas2" hidden>2. masérka (párová)<select name="maserka2_id"><?= maserky_select(isset($v['maserka2_id']) ? (int)$v['maserka2_id'] : null, true, '— vyberte —') ?></select></label>
        <label>Platba<select name="platba"><?= moznosti(PLATBY, $v['platba'] ?? '') ?></select></label>
        <label>Cena (Kč)<input type="number" name="cena" value="<?= h($v['cena'] ?? '') ?>" placeholder="podle ceníku"></label>
      </div>
      <div id="obs" class="obs" aria-live="polite"></div>
      <?php if ($novy): ?><label>Poznámka<input name="poznamka" value="<?= h($v['poznamka'] ?? '') ?>"></label><?php endif ?>
      <label class="check"><input type="checkbox" name="poslat_email" value="1" <?= !empty($v['poslat_email']) ? 'checked' : '' ?>> Poslat zákazníkovi potvrzení e-mailem (když má e-mail)</label>
    </fieldset>
    <button class="btn primary"><?= h($tlacitko) ?></button>
    <script>
    // délka podle zvolené služby + rychlé dohledání zákazníka podle telefonu
    const varMin = <?= json_encode(q('SELECT id, minuty FROM varianty')->fetchAll(PDO::FETCH_KEY_PAIR)) ?>;
    const varWell = <?= json_encode(q("SELECT id, wellness_mistnost FROM varianty")->fetchAll(PDO::FETCH_KEY_PAIR)) ?>;
    const varMas = <?= json_encode(q("SELECT id, maserky FROM varianty")->fetchAll(PDO::FETCH_KEY_PAIR)) ?>;
    const varMasMin = <?= json_encode(q("SELECT id, maserky_min FROM varianty WHERE maserky_min IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR)) ?>;
    const sel = document.getElementById('var'), del = document.getElementById('delka');
    sel && sel.addEventListener('change', () => { del.placeholder = varMin[sel.value] ? varMin[sel.value] + ' (podle služby)' : 'zadejte délku'; });
    sel && sel.dispatchEvent(new Event('change'));
    // ---- živá kontrola obsazenosti: kdo je v ten den volný, kolize u zvolené masérky, přepnutí na jinou ----
    (function () {
      const fs = document.getElementById('termin'); if (!fs) return;
      const f = n => fs.querySelector('[name=' + n + ']'), box = document.getElementById('obs');
      const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
      const m2 = s => { const [h, m] = s.split(':').map(Number); return h * 60 + m; }, hm = n => String(Math.floor(n / 60)).padStart(2, '0') + ':' + String(n % 60).padStart(2, '0');
      let t, posl = 0, data = null;
      function delka() { return parseInt(f('delka').value) || varMin[f('varianta_id').value] || 0; }
      function mdelka() { const v = f('varianta_id').value, m = parseInt(varMasMin[v]) || 0, d = delka(); return m && m < d ? m : d; }
      function pocetMas() { const v = f('varianta_id').value; return v in varMas ? parseInt(varMas[v]) : 1; }
      function prepniMas() {
        const n = pocetMas(), l1 = document.getElementById('lmas'), l2 = document.getElementById('lmas2');
        l1.hidden = n === 0; l2.hidden = n < 2;
        if (n === 0) f('maserka_id').value = '';
        if (n < 2) f('maserka2_id').value = '';
      }
      async function nacti() {
        const d = f('datum').value, dl = delka();
        if (!d || !dl) { box.innerHTML = ''; return; }
        const my = ++posl;
        const r = await fetch('/?r=den_obs&d=' + d + '&delka=' + dl + '&mdelka=' + mdelka() + '&mimo=' + fs.dataset.mimo + '&w=' + (parseInt(varWell[f('varianta_id').value]) ? 1 : 0));
        if (my !== posl) return; data = await r.json(); kresli();
      }
      // výběr masérky: u párové se doplní nejdřív první, pak druhá (klepnutí na už vybranou ji zruší)
      function nastav(cas, mid) {
        if (cas) f('cas').value = cas;
        if (mid !== undefined && pocetMas() > 0) {
          const a = f('maserka_id'), b = f('maserka2_id'); mid = String(mid);
          if (pocetMas() < 2) a.value = mid;
          else if (a.value === mid) a.value = '';
          else if (b.value === mid) b.value = '';
          else if (!a.value) a.value = mid; else b.value = mid;
        }
        kresli();
      }
      function kresli() {
        if (!data || !data.ok) { box.innerHTML = ''; return; }
        const cas = f('cas').value, dl = mdelka(), cel = delka(), n = pocetMas();
        const vybrane = [f('maserka_id').value, f('maserka2_id').value].map(x => parseInt(x) || 0).filter(x => x);
        const mid = vybrane[0] || 0;
        const od = m2(data.od), do_ = m2(data.do), sir = do_ - od;
        let h = '';
        if (n >= 2) { const kon = m2(data.zavreno) - dl; if (kon >= m2(data.od)) h += '<div class="obs-st">👥 Párová na konec směny: <button type="button" class="chip" data-t="' + hm(kon) + '">' + hm(kon) + '</button> (masáž do ' + data.zavreno + (cel > dl ? ', pak whirlpool a sauna do ' + hm(m2(data.zavreno) + cel - dl) + ' s recepční' : '') + ')</div>'; if (vybrane.length < 2) h += '<div class="obs-st bad">Vyberte dvě masérky – klepněte na jména v přehledu níže.</div>'; }
        // stav vybraného termínu
        if (cas) {
          const s = m2(cas), e = s + dl, vSmene = m => !m.mimo.some(b => m2(b.od) < e && m2(b.do) > s);
          const plno = data.plno.includes(cas);
          const volniTed = plno ? [] : data.maserky.filter(m => vSmene(m) && !m.bloky.some(b => m2(b.od) < e && m2(b.do) > s));
          if (data.ted >= 0 && s < data.ted) h += '<div class="obs-st bad">⏱ ' + cas + ' už uplynulo – zadáváte masáž zpětně.</div>';
          if (plno) h += '<div class="obs-st bad">⚠ V ' + cas + ' se masáž nevejde do masérn – lůžka jsou obsazená.</div>';
          const alt = volniTed.filter(m => !vybrane.includes(m.id)).map(m => '<button type="button" class="chip" data-m="' + m.id + '">' + esc(m.jmeno) + '</button>').join(' ');
          if (n === 0) {
            h += plno ? '' : '<div class="obs-st ok">✓ Wellness masérna je v ' + cas + '–' + hm(s + cel) + ' volná (bez masérky).</div>';
          }
          if (cel > dl || n === 0) { if (s + cel > m2(data.zavreno)) h += '<div class="obs-st ok">ℹ Whirlpool a sauna po zavírací době (' + data.zavreno + ') do ' + hm(s + cel) + ' – zákazníci zůstávají s recepční.</div>'; }
          vybrane.forEach(mid => {
            const m = data.maserky.find(x => x.id === mid), kol = m ? m.bloky.filter(b => m2(b.od) < e && m2(b.do) > s) : [];
            if (n === 1 && s + dl > m2(data.zavreno)) h += '<div class="obs-st bad">⚠ Masáž by skončila po zavírací době (' + data.zavreno + ').</div>';
            if (m && !vSmene(m)) {
              const dalsi = (m.volne || []).slice(0, 6).map(x => '<button type="button" class="chip" data-t="' + x + '">' + x + '</button>').join(' ');
              h += '<div class="obs-st bad">⚠ <b>' + esc(m.jmeno) + ' v tu dobu nepracuje</b>' + (m.popis ? ' (' + esc(m.popis) + ')' : '') + '.'
                + (alt ? '<br>V ' + cas + ' jsou volné: ' + alt : '') + (dalsi ? '<br>' + esc(m.jmeno) + ' má ten den volno v: ' + dalsi : '') + '</div>';
            } else if (kol.length) {
              const dalsi = (m.volne || []).filter(x => m2(x) >= s).slice(0, 6).map(x => '<button type="button" class="chip" data-t="' + x + '">' + x + '</button>').join(' ');
              h += '<div class="obs-st bad">⚠ <b>' + esc(m.jmeno) + ' je obsazená</b>: ' + kol.map(b => b.od + '–' + b.do + ' ' + esc(b.kdo)).join(', ') + '.'
                + (alt ? '<br>V ' + cas + ' jsou volné: ' + alt : '<br>V ' + cas + ' není volná žádná masérka.')
                + (dalsi ? '<br>' + esc(m.jmeno) + ' má volno v: ' + dalsi : '') + '</div>';
            } else if (s + dl <= do_) h += '<div class="obs-st ok">✓ ' + esc(m ? m.jmeno : '') + ' je v ' + cas + '–' + hm(e) + ' volná.' + (alt ? ' Volné jsou i: ' + alt : '') + '</div>';
          });
          if (!vybrane.length && n > 0) {
            h += '<div class="obs-st ' + (volniTed.length ? 'ok' : 'bad') + '">' + (volniTed.length ? 'V ' + cas + '–' + hm(e) + ' jsou volné: ' + alt : '⚠ V ' + cas + ' není volná žádná masérka.') + '</div>';
          }
        }
        // přehled dne: řádek na masérku, šedé = obsazeno, zlatá = vybraný termín; klepnutím do řádku se vybere čas a masérka
        h += '<div class="obs-den"><div class="obs-hl"><span>Obsazenost ' + esc(data.datum) + (data.svatek ? ' · 🎌 ' + esc(data.svatek) : '') + '</span><span class="muted small">klepněte do volného místa</span></div>';
        data.maserky.forEach(m => {
          let bar = (data.ted > od ? '<i class="p" style="left:0;width:' + (Math.min(data.ted, do_) - od) / sir * 100 + '%" title="už uplynulo"></i>' : '') + (m.mimo || []).map(b => '<i class="x" style="left:' + ((m2(b.od) - od) / sir * 100) + '%;width:' + ((m2(b.do) - m2(b.od)) / sir * 100) + '%" title="nepracuje"></i>').join('') + m.bloky.map(b => '<i class="b" style="left:' + ((m2(b.od) - od) / sir * 100) + '%;width:' + ((m2(b.do) - m2(b.od)) / sir * 100) + '%" title="' + esc(b.od + '–' + b.do + ' ' + b.kdo) + '"></i>').join('');
          if (cas && vybrane.includes(m.id)) { const s = m2(cas); bar += '<i class="v" style="left:' + ((s - od) / sir * 100) + '%;width:' + (dl / sir * 100) + '%"></i>'; }
          h += '<div class="obs-r' + (vybrane.includes(m.id) ? ' on' : '') + '"><button type="button" class="obs-n" data-m="' + m.id + '" style="border-color:' + esc(m.barva) + '">' + esc(m.jmeno) + '<small>' + (m.popis ? esc(m.popis) : 'volno ' + m.volne.length + '×') + '</small></button>'
            + '<div class="obs-bar" data-m="' + m.id + '">' + bar + '</div></div>';
        });
        let osa = ''; for (let x = Math.ceil(od / 60) * 60; x <= do_; x += 60) osa += '<span style="left:' + ((x - od) / sir * 100) + '%">' + (x / 60) + '</span>';
        h += '<div class="obs-osa">' + osa + '</div></div>';
        box.innerHTML = h;
      }
      box.addEventListener('click', e => {
        const b = e.target.closest('button');
        if (b) { e.preventDefault(); if (b.dataset.t) nastav(b.dataset.t); else if (b.dataset.m) nastav(null, b.dataset.m); return; }
        const bar = e.target.closest('.obs-bar'); if (!bar || !data) return;
        const r = bar.getBoundingClientRect(), od = m2(data.od), sir = m2(data.do) - od;
        let min = od + Math.round(((e.clientX - r.left) / r.width * sir) / 15) * 15;
        const m = data.maserky.find(x => x.id === parseInt(bar.dataset.m));
        // posunout na nejbližší volný začátek u té masérky
        const vol = (m.volne || []).map(m2);
        if (vol.length) min = vol.reduce((a, c) => Math.abs(c - min) < Math.abs(a - min) ? c : a, vol[0]);
        else min = Math.max(od, Math.min(min, m2(data.do) - mdelka()));   // žádný volný začátek (např. už po směně): vyber klepnutý čas a hláška dole řekne proč to nejde
        nastav(hm(min), m.id);
      });
      ['datum', 'delka', 'varianta_id'].forEach(n => f(n).addEventListener('change', () => { prepniMas(); clearTimeout(t); t = setTimeout(nacti, 150); }));
      f('maserka2_id').addEventListener('change', kresli);
      prepniMas();
      f('delka').addEventListener('input', () => { clearTimeout(t); t = setTimeout(nacti, 400); });
      ['cas', 'maserka_id'].forEach(n => { f(n).addEventListener('change', kresli); f(n).addEventListener('input', kresli); });
      nacti();
    })();
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

/** Chyba formuláře rezervace; u varování, která jde vědomě přeskočit, přidá „Přesto uložit“. */
function chyba_html(?string $chyba): string
{
    if (!$chyba) return '';
    $o = '<div class="flash err">' . h($chyba);
    if (str_contains($chyba, 'Přesto uložit')) $o .= '<label class="check"><input type="checkbox" name="presto" value="1"> Přesto uložit</label>';
    return $o . '</div>';
}

function kolize_html(array $kol): string
{
    $o = '<div class="flash warn"><b>Masérka má v tu dobu jinou rezervaci:</b><ul>';
    foreach ($kol as $k) $o .= '<li>' . date('H:i', strtotime($k['zacatek'])) . '–' . date('H:i', strtotime($k['konec'])) . ' ' . h(jmeno_z($k)) . '</li>';
    return $o . '</ul><label class="check"><input type="checkbox" name="presto" value="1"> Přesto uložit</label></div>';
}

function stranka_rez_nova(array $u): void
{
    $v = ['datum' => $_GET['d'] ?? date('Y-m-d'), 'cas' => $_GET['t'] ?? '', 'maserka_id' => $_GET['m'] ?? null, 'maserka2_id' => $_GET['m2'] ?? null, 'poslat_email' => 1,
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
        if (!$chyba && !$kol) {
            // rezervace z návrhu asistenta: návrh je tím přijatý
            if (!empty($_GET['ai'])) q("UPDATE ai_navrhy SET stav='prijato', rezervace_id=?, rozhodl=?, rozhodnuto=? WHERE id=? AND stav IN ('ceka','otevreno')", [$res[2], kdo(), nyni(), (int)$_GET['ai']]);
            flash('Rezervace uložena.'); presmeruj('/?r=rezervace&id=' . $res[2]);
        }
    }
    hlavicka('Nová rezervace', ''); ?>
    <h1>Nová rezervace</h1>
    <form method="post" class="card">
      <?= csrf_pole() ?>
      <?= chyba_html($chyba) ?>
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
            rez_zmen_stav($r, $novy, !empty($_POST['poslat_email']), trim($_POST['duvod'] ?? ''));
            flash(jmeno_z($r) . ': ' . STAVY[$novy]);
            if (($_POST['zpet'] ?? '') === 'dnes') presmeruj('/?r=dnes');
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
        'varianta_id' => $r['varianta_id'], 'maserka_id' => $r['maserka_id'] ?: $z['pref_maserka_id'], 'maserka2_id' => $r['maserka2_id'],
        'datum' => $r['zacatek'] ? substr($r['zacatek'], 0, 10) : date('Y-m-d'),
        'cas' => $r['zacatek'] ? substr($r['zacatek'], 11, 5) : '',
        'delka' => $r['zacatek'] && $r['konec'] ? (strtotime($r['konec']) - strtotime($r['zacatek'])) / 60 : '',
        'platba' => $r['platba'], 'cena' => $r['cena'], 'poslat_email' => $zadost ? 1 : 0,
    ];

    // návrh nového termínu z „Volné termíny“ (přesun existující rezervace): předvyplní formulář, uloží se až tlačítkem
    $navrhPresun = $_SERVER['REQUEST_METHOD'] !== 'POST' && preg_match('~^\d{4}-\d{2}-\d{2}$~', $_GET['d'] ?? '') && preg_match('~^\d{2}:\d{2}$~', $_GET['t'] ?? '');
    if ($navrhPresun) {
        $formData['datum'] = $_GET['d']; $formData['cas'] = $_GET['t'];
        if (($_GET['delka'] ?? '') !== '') $formData['delka'] = (int)$_GET['delka'];
        if (!empty($_GET['v'])) $formData['varianta_id'] = (int)$_GET['v'];
        if (($_GET['m'] ?? '') !== '') $formData['maserka_id'] = (int)$_GET['m'];
        if (($_GET['m2'] ?? '') !== '') $formData['maserka2_id'] = (int)$_GET['m2'];
    }

    hlavicka('Rezervace', ''); ?>
    <?php if (!empty($navrhPresun)): ?><div class="flash warn">↪ <b>Návrh nového termínu:</b> <?= h(datum_cz($formData['datum'] . ' ' . $formData['cas'] . ':00')) ?>. Zatím je uložený původní termín (<?= h(datum_cz($r['zacatek'])) ?>) – změnu potvrďte tlačítkem „Uložit změny“ ve formuláři níže.</div><?php endif ?>
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
            <dt>Masérka</dt><dd><?= h(($r['maserka'] ?? '—') . ($r['maserka2'] ? ' + ' . $r['maserka2'] : '')) ?><?= $r['masaz_konec'] ? ' <span class="muted small">(masáž do ' . h(date('H:i', strtotime($r['masaz_konec']))) . ', pak bez masérek)</span>' : '' ?></dd>
            <dt>Platba</dt><dd><?= h(PLATBY[$r['platba']] ?? '') ?> · <?= kc($r['cena']) ?></dd>
            <dt>Zdroj</dt><dd><?= h($r['zdroj']) ?> · vytvořeno <?= h(datum_cz($r['vytvoreno'])) ?><?= $r['zmenil'] ? ' · naposledy ' . h($r['zmenil']) : '' ?></dd>
          </dl>
        </section>

        <form method="post" class="card">
          <?= csrf_pole() ?>
          <input type="hidden" name="akce" value="<?= $zadost ? 'naplanovat' : 'upravit' ?>">
          <h2><?= $zadost ? 'Naplánovat a potvrdit' : 'Upravit termín' ?> <?php if (!$zadost): ?><a class="btn small" href="/?r=volno&z=<?= (int)$r['zakaznik_id'] ?>&v=<?= (int)$r['varianta_id'] ?>&od=<?= h(substr((string)$r['zacatek'], 0, 10)) ?>&presun=<?= (int)$r['id'] ?>">Najít jiný termín…</a><?php endif ?></h2>
          <?php if ($zadost): ?><p class="muted">Domluvte termín se zákazníkem (zavolejte na <a href="tel:<?= h($r['telefon']) ?>"><?= h(telefon_cz($r['telefon'])) ?></a>) a uložte ho. Tím se žádost potvrdí.</p><?php endif ?>
          <?= chyba_html($chyba) ?>
          <?php if ($kol) echo kolize_html($kol) ?>
          <?php rez_formular($formData, false, $zadost ? 'Potvrdit termín' : 'Uložit změny', (int)$r['id']) ?>
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

        <?php if (!$zadost && !in_array($r['stav'], ['neprisel', 'zruseno_vcas', 'zruseno_pozde', 'odmitnuto'], true)) tablet_karta($r); ?>

        <?php if (in_array($r['stav'], ['neprisel', 'zruseno_vcas', 'zruseno_pozde'], true) && $r['konec'] && $r['konec'] > nyni()) uvolneny_termin($r); ?>

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


/** Termín se uvolnil (storno / nepřišel a ještě neskončil): komu zavolat. */
function uvolneny_termin(array $r): void
{
    $zajemci = q("SELECT z.* FROM zakaznici z WHERE z.volny_termin=1 AND z.blokovany=0 AND z.id<>?
        AND NOT EXISTS (SELECT 1 FROM rezervace x WHERE x.zakaznik_id=z.id AND x.stav='potvrzeno' AND x.zacatek < ? AND x.konec > ?)
        ORDER BY (SELECT MAX(zacatek) FROM rezervace y WHERE y.zakaznik_id=z.id AND y.stav='probehlo') DESC LIMIT 12",
        [$r['zakaznik_id'], $r['konec'], max($r['zacatek'], nyni())])->fetchAll(); ?>
    <section class="card volno-box">
      <h2>⏱ Uvolněný termín <?= h(date('H:i', strtotime(max($r['zacatek'], nyni())))) ?>–<?= h(date('H:i', strtotime($r['konec']))) ?><?= $r['maserka'] ? ' · ' . h($r['maserka']) : '' ?></h2>
      <?php if (!$zajemci): ?><p class="muted">Nikdo nemá zaškrtnuto „Vezme i uvolněný termín“. Nastavíte to v kartě zákazníka.</p>
      <?php else: ?><p class="muted small">Zákazníci, kteří vezmou volný termín na poslední chvíli – zavolejte a pak jim termín zapište přes „Další termín“:</p>
        <?php foreach ($zajemci as $z): ?>
          <p><a href="tel:<?= h($z['telefon']) ?>" class="btn">📞 <?= h(telefon_cz($z['telefon'])) ?></a> <a href="/?r=zakaznik&id=<?= $z['id'] ?>"><b><?= h(jmeno_z($z)) ?></b></a>
            <?= $z['volny_kdy'] ? '<span class="muted small">– ' . h($z['volny_kdy']) . '</span>' : '' ?>
            <a class="small" href="/?r=rez_nova&z=<?= $z['id'] ?>">zapsat →</a></p>
        <?php endforeach ?>
      <?php endif ?>
    </section>
<?php }


/** JSON: obsazenost masérek v daný den (pro živou kontrolu ve formuláři rezervace). */
function stranka_den_obs(array $u): void
{
    header('Content-Type: application/json; charset=utf-8');
    $d = preg_match('~^\d{4}-\d{2}-\d{2}$~', $_GET['d'] ?? '') ? $_GET['d'] : date('Y-m-d');
    $delka = max(5, min(300, (int)($_GET['delka'] ?? 60)));
    $mdelka = max(5, min($delka, (int)($_GET['mdelka'] ?? $delka)));   // jak dlouho jsou potřeba masérky (párová: 60 z 120)
    $mimo = (int)($_GET['mimo'] ?? 0);
    $od = nastaveni('oteviraci_od', '09:00'); $do = nastaveni('oteviraci_do', '20:00');
    $minOd = (int)substr($od, 0, 2) * 60 + (int)substr($od, 3, 2); $minDo = (int)substr($do, 0, 2) * 60 + (int)substr($do, 3, 2);
    $wellness = ($_GET['w'] ?? '') === '1';
    $okna = [];
    foreach (q('SELECT id FROM maserky WHERE aktivni=1')->fetchAll(PDO::FETCH_COLUMN) as $mid) {
        $okna[(int)$mid] = maserka_okna((int)$mid, $d);
        foreach ($okna[(int)$mid] as [$a, $b]) { $minOd = min($minOd, $a); $minDo = max($minDo, $b); }   // přesčas mimo otevírací dobu
    }
    $zavreno = $minDo;   // zavírací doba (bez wellness po ní)
    $rez = q("SELECT r.id, r.maserka_id, r.maserka2_id, r.zacatek, r.konec, COALESCE(r.masaz_konec, r.konec) AS m_konec, z.jmeno, z.prijmeni FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id
        WHERE (r.maserka_id IS NOT NULL OR r.maserka2_id IS NOT NULL) AND r.stav IN ('potvrzeno','probehlo') AND r.id<>? AND r.zacatek < ? AND r.konec > ? ORDER BY r.zacatek",
        [$mimo, date('Y-m-d', strtotime("$d +1 day")), $d])->fetchAll();
    $bloky = [];
    foreach ($rez as $r) {
        $a = max($minOd, (int)date('G', strtotime($r['zacatek'])) * 60 + (int)date('i', strtotime($r['zacatek'])));
        $b = (int)date('G', strtotime($r['m_konec'])) * 60 + (int)date('i', strtotime($r['m_konec']));
        foreach (array_filter([$r['maserka_id'], $r['maserka2_id']]) as $mid) $bloky[(int)$mid][] = [$a, $b, trim($r['prijmeni'] ?: $r['jmeno'])];
    }
    // wellness po zavírací době (zákazníci zůstávají s recepční): osa se prodlouží
    $poslKonec = (int)qv("SELECT MAX(CAST(strftime('%H', konec) AS INTEGER) * 60 + CAST(strftime('%M', konec) AS INTEGER)) FROM rezervace WHERE stav IN ('potvrzeno','probehlo') AND date(zacatek)=? AND date(konec)=?", [$d, $d]);
    $minDoOsa = max($minDo, $poslKonec, $wellness ? $minDo + ($delka - $mdelka) : 0);
    $od = hhmm($minOd); $do = hhmm($minDoOsa);
    $ted = $d === date('Y-m-d') ? (int)date('G') * 60 + (int)date('i') : ($d < date('Y-m-d') ? 99999 : -1);
    $hm = fn($n) => sprintf('%02d:%02d', intdiv($n, 60), $n % 60);
    // časy, kdy se masáž nevejde do masérn (plná lůžka / wellness masérna)
    $plno = [];
    $soubDen = q("SELECT r.id, r.zacatek, r.konec, s.kat FROM rezervace r LEFT JOIN varianty v ON v.id=r.varianta_id LEFT JOIN sluzby s ON s.id=v.sluzba_id
        WHERE r.stav IN ('potvrzeno','probehlo') AND r.id<>? AND r.zacatek < ? AND r.konec > ?", [$mimo, date('Y-m-d', strtotime("$d +1 day")), $d])->fetchAll();
    for ($t = $minOd; $t + $mdelka <= $minDo; $t += 15) {
        $zac = "$d " . $hm($t) . ':00'; $kon = date('Y-m-d H:i:s', strtotime($zac) + $delka * 60);
        $tu = array_values(array_filter($soubDen, fn($r) => $r['zacatek'] < $kon && $r['konec'] > $zac));
        if (kapacita_problem($zac, $kon, $wellness, $mimo, $tu)) $plno[$hm($t)] = true;
    }
    $out = [];
    foreach (q('SELECT id, jmeno, barva FROM maserky WHERE aktivni=1 ORDER BY poradi, id')->fetchAll() as $m) {
        $bl = $bloky[(int)$m['id']] ?? [];
        $ok_ = $okna[(int)$m['id']] ?? [];
        $volne = [];
        for ($t = $minOd; $t + $mdelka <= $minDo; $t += 15) {
            if ($t < $ted || isset($plno[$hm($t)])) continue;
            $vSmene = false;
            foreach ($ok_ as [$a, $b]) if ($t >= $a && $t + $mdelka <= $b) { $vSmene = true; break; }
            if (!$vSmene) continue;
            $ok = true;
            foreach ($bl as [$a, $b]) if ($a < $t + $mdelka && $b > $t) { $ok = false; break; }
            if ($ok) $volne[] = $hm($t);
        }
        $mimoSm = intervaly_minus([[$minOd, $minDoOsa]], $ok_);
        $out[] = ['id' => (int)$m['id'], 'jmeno' => $m['jmeno'], 'barva' => $m['barva'],
                  'bloky' => array_map(fn($x) => ['od' => $hm($x[0]), 'do' => $hm($x[1]), 'kdo' => $x[2]], $bl), 'volne' => $volne,
                  'mimo' => array_map(fn($x) => ['od' => $hm($x[0]), 'do' => $hm($x[1])], $mimoSm),
                  'popis' => implode(', ', array_column(maserka_den_popis((int)$m['id'], $d), 1))];
    }
    echo json_encode(['ok' => true, 'datum' => date('j. n. Y', strtotime($d)), 'od' => $od, 'do' => $do, 'maserky' => $out,
        'plno' => array_keys($plno), 'svatek' => svatek($d), 'zavreno' => hhmm($zavreno), 'ted' => $ted], JSON_UNESCAPED_UNICODE);
    exit;
}
