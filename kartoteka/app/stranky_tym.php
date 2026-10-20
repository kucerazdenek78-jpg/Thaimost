<?php
// Tým: masérky (profil, zaměření, výkonnost, spokojenost), recepční a jejich směny + kalendář (ICS).

const SMENA_STAVY = ['navrzeno' => 'Návrh', 'schvaleno' => 'Schváleno', 'odmitnuto' => 'Odmítnuto', 'zruseno' => 'Zrušeno'];
const ROLE = ['admin' => 'Admin', 'hlavni' => 'Hlavní pracovník', 'recepce' => 'Recepční'];
const DNY_CZ = ['po', 'út', 'st', 'čt', 'pá', 'so', 'ne'];

function tym_nav(string $aktivni): void
{
    $p = ['maserky' => 'Masérky', 'recepcni' => 'Recepční a kontakty'];
    echo '<p class="subnav">';
    foreach ($p as $k => $t) echo '<a href="/?r=' . $k . '"' . ($aktivni === $k ? ' class="on"' : '') . '>' . $t . '</a> ';
    echo '</p>';
}

function hvezdy(?float $x): string
{
    if ($x === null) return '<span class="muted">—</span>';
    $celych = (int)round($x);
    return '<span class="star" title="' . number_format($x, 2, ',', '') . ' z 5">' . str_repeat('★', $celych) . str_repeat('☆', 5 - $celych) . '</span> ' . number_format($x, 1, ',', '');
}

function zamereni_norm(string $t): string
{
    $a = preg_split('~[,;\n]+~u', $t) ?: [];
    $a = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 40), $a))));
    return implode(', ', $a);
}
function zamereni_html(string $z): string
{
    $o = '';
    foreach (array_filter(array_map('trim', explode(',', $z))) as $t) $o .= '<span class="tag">' . h($t) . '</span> ';
    return $o ?: '<span class="muted small">není vyplněno</span>';
}

// ---------- Výkonnost a spokojenost masérky ----------
function vykonnost_maserky(int $id, string $od, string $do): array
{
    $r = q("SELECT COUNT(*) AS pocet,
                   COALESCE(SUM((julianday(konec) - julianday(zacatek)) * 1440), 0) AS minuty,
                   COALESCE(SUM(cena), 0) AS trzby, COUNT(DISTINCT zakaznik_id) AS zakazniku
            FROM rezervace WHERE maserka_id=? AND stav='probehlo' AND zacatek>=? AND zacatek<?", [$id, $od, $do])->fetch();
    $r['neprisel'] = (int)qv("SELECT COUNT(*) FROM rezervace WHERE maserka_id=? AND stav='neprisel' AND zacatek>=? AND zacatek<?", [$id, $od, $do]);
    $r['vracejici'] = (int)qv("SELECT COUNT(*) FROM (SELECT 1 FROM rezervace WHERE maserka_id=? AND stav='probehlo' AND zacatek>=? AND zacatek<?
                               GROUP BY zakaznik_id HAVING COUNT(*)>=2)", [$id, $od, $do]);
    $h = q('SELECT AVG(znamka) AS prumer, COUNT(*) AS pocet FROM hodnoceni_maserek WHERE maserka_id=? AND cas>=? AND cas<?', [$id, $od, $do])->fetch();
    $r['hodnoceni'] = $h['pocet'] ? (float)$h['prumer'] : null;
    $r['hodnoceni_pocet'] = (int)$h['pocet'];
    $r['hodiny'] = round($r['minuty'] / 60, 1);
    $r['trzby'] = (int)$r['trzby'];
    $r['pocet'] = (int)$r['pocet'];
    return $r;
}

// ---------- MASÉRKY: přehled ----------
function stranka_maserky(array $u): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['akce'] ?? '') === 'nova') {
        vyzaduj_tym();
        $j = trim($_POST['jmeno'] ?? '');
        if ($j !== '') {
            q('INSERT INTO maserky(jmeno,poradi) VALUES(?, (SELECT COALESCE(MAX(poradi),0)+1 FROM maserky))', [$j]);
            $id = (int)db()->lastInsertId();
            zapis_log('maserka_nova', $j);
            presmeruj("/?r=maserka&id=$id");
        }
        presmeruj('/?r=maserky');
    }
    $dny = in_array((int)($_GET['dny'] ?? 30), [30, 90, 365], true) ? (int)($_GET['dny'] ?? 30) : 30;
    $od = date('Y-m-d 00:00:00', strtotime("-$dny days")); $do = '9999-12-31';
    $maserky = q('SELECT * FROM maserky ORDER BY aktivni DESC, poradi')->fetchAll();

    hlavicka('Masérky', 'tym'); ?>
    <h1>Tým</h1>
    <?php tym_nav('maserky'); ?>
    <div class="cal-nav">
      <b>Výkonnost za:</b>
      <?php foreach ([30 => '30 dní', 90 => '90 dní', 365 => 'rok'] as $d => $t): ?>
        <a class="btn<?= $d === $dny ? ' primary' : '' ?>" href="/?r=maserky&dny=<?= $d ?>"><?= $t ?></a>
      <?php endforeach ?>
    </div>
    <table class="tbl">
      <tr><th>Masérka</th><th>Zaměření</th><th>Masáží</th><th>Hodin</th><th>Tržby</th><th>Zákazníků (vracejících)</th><th>Nepřišli</th><th>Spokojenost</th></tr>
      <?php foreach ($maserky as $m): $v = vykonnost_maserky((int)$m['id'], $od, $do); ?>
        <tr onclick="location='/?r=maserka&id=<?= $m['id'] ?>'"<?= $m['aktivni'] ? '' : ' class="muted"' ?>>
          <td><span class="dot" style="background:<?= h($m['barva']) ?>"></span> <b><?= h($m['jmeno']) ?></b><?= $m['aktivni'] ? '' : ' <span class="tag">neaktivní</span>' ?></td>
          <td><?= zamereni_html($m['zamereni']) ?></td>
          <td><?= $v['pocet'] ?></td><td><?= $v['hodiny'] ?></td><td><?= kc($v['trzby']) ?></td>
          <td><?= $v['zakazniku'] ?> <span class="muted">(<?= $v['vracejici'] ?>)</span></td>
          <td><?= $v['neprisel'] ?></td>
          <td><?= hvezdy($v['hodnoceni']) ?> <span class="muted small">(<?= $v['hodnoceni_pocet'] ?>×)</span></td>
        </tr>
      <?php endforeach ?>
    </table>
    <p class="muted small">Počítají se proběhlé masáže s přiřazenou masérkou. Tržby jen z rezervací, kde je vyplněna cena. Klikněte na řádek pro detail.</p>
    <?php if (smi_upravit_tym($u)): ?>
      <form method="post" class="card inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="nova">
        <h2>Přidat masérku</h2><label>Jméno<input name="jmeno" required></label><button class="btn primary">Přidat</button></form>
    <?php endif;
    paticka();
}

// ---------- MASÉRKA: detail, úprava, výkonnost, hodnocení ----------
function stranka_maserka(array $u): void
{
    $id = (int)($_GET['id'] ?? 0);
    $m = q1('SELECT * FROM maserky WHERE id=?', [$id]);
    if (!$m) { flash('Masérka nenalezena.', 'err'); presmeruj('/?r=maserky'); }
    $smi = smi_upravit_tym($u);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = $_POST['akce'] ?? '';
        if ($akce === 'ulozit') {
            vyzaduj_tym();
            $jmeno = trim($_POST['jmeno'] ?? '');
            if ($jmeno === '') { flash('Jméno je povinné.', 'err'); presmeruj("/?r=maserka&id=$id"); }
            $nastup = preg_match('~^\d{4}-\d{2}-\d{2}$~', $_POST['nastup'] ?? '') ? $_POST['nastup'] : null;
            q('UPDATE maserky SET jmeno=?, telefon=?, email=?, zamereni=?, preference=?, poznamka_interni=?, nastup=?, barva=?, ucet=?, poradi=?, aktivni=? WHERE id=?',
                [$jmeno, trim($_POST['telefon'] ?? ''), trim($_POST['email'] ?? ''), zamereni_norm($_POST['zamereni'] ?? ''),
                 trim($_POST['preference'] ?? ''), trim($_POST['poznamka_interni'] ?? ''), $nastup,
                 preg_match('~^#[0-9a-fA-F]{6}$~', $_POST['barva'] ?? '') ? $_POST['barva'] : '#b07a3a',
                 trim($_POST['ucet'] ?? ''), (int)($_POST['poradi'] ?? 0), !empty($_POST['aktivni']) ? 1 : 0, $id]);
            zapis_log('maserka_uprava', "#$id $jmeno");
            flash('Masérka uložena.');
        } elseif ($akce === 'hodnoceni') {
            $zn = (int)($_POST['znamka'] ?? 0);
            $rez = (int)($_POST['rezervace_id'] ?? 0) ?: null;
            if ($rez && !q1('SELECT 1 FROM rezervace WHERE id=? AND maserka_id=?', [$rez, $id])) $rez = null;
            if ($zn >= 1 && $zn <= 5) {
                q('INSERT INTO hodnoceni_maserek(maserka_id,rezervace_id,znamka,komentar,zdroj,kdo) VALUES(?,?,?,?,?,?)',
                    [$id, $rez, $zn, trim($_POST['komentar'] ?? ''), 'recepce', kdo()]);
                flash('Hodnocení uloženo.');
            } else flash('Vyberte známku 1–5.', 'err');
        } elseif ($akce === 'smaz_hodnoceni') {
            vyzaduj_tym();
            q('DELETE FROM hodnoceni_maserek WHERE id=? AND maserka_id=?', [(int)($_POST['hid'] ?? 0), $id]);
            flash('Hodnocení smazáno.');
        }
        presmeruj("/?r=maserka&id=$id");
    }

    $obdobi = [30 => '30 dní', 90 => '90 dní', 365 => 'rok'];
    $v = [];
    foreach ($obdobi as $d => $_) $v[$d] = vykonnost_maserky($id, date('Y-m-d 00:00:00', strtotime("-$d days")), '9999-12-31');
    $mesice = [];
    for ($i = 0; $i < 6; $i++) {
        $z = date('Y-m-01', strtotime("first day of -$i month"));
        $k = date('Y-m-01', strtotime("$z +1 month"));
        $mesice[$z] = vykonnost_maserky($id, "$z 00:00:00", "$k 00:00:00");
    }
    $rozlozeni = array_fill(1, 5, 0);
    foreach (q('SELECT znamka, COUNT(*) c FROM hodnoceni_maserek WHERE maserka_id=? GROUP BY znamka', [$id])->fetchAll() as $r) $rozlozeni[(int)$r['znamka']] = (int)$r['c'];
    $celkemH = array_sum($rozlozeni);
    $hodnoceni = q('SELECT h.*, z.jmeno AS zj, z.prijmeni AS zp FROM hodnoceni_maserek h
        LEFT JOIN rezervace r ON r.id=h.rezervace_id LEFT JOIN zakaznici z ON z.id=r.zakaznik_id
        WHERE h.maserka_id=? ORDER BY h.cas DESC LIMIT 20', [$id])->fetchAll();
    $posledniRez = q("SELECT r.id, r.zacatek, z.jmeno, z.prijmeni FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id
        WHERE r.maserka_id=? AND r.stav='probehlo' ORDER BY r.zacatek DESC LIMIT 15", [$id])->fetchAll();
    $preferuji = (int)qv('SELECT COUNT(*) FROM zakaznici WHERE pref_maserka_id=?', [$id]);

    hlavicka($m['jmeno'], 'tym'); ?>
    <h1><span class="dot" style="background:<?= h($m['barva']) ?>"></span> <?= h($m['jmeno']) ?> <small><a href="/?r=maserky">← všechny masérky</a></small></h1>
    <div class="cols">
      <div>
        <form method="post" class="card"><?= csrf_pole() ?><input type="hidden" name="akce" value="ulozit">
          <h2>Profil a zaměření <?= $smi ? '' : '<span class="muted small">(úpravy smí admin nebo hlavní pracovník)</span>' ?></h2>
          <fieldset <?= $smi ? '' : 'disabled' ?> style="border:0;padding:0;margin:0">
          <div class="grid3">
            <label>Jméno *<input name="jmeno" value="<?= h($m['jmeno']) ?>" required></label>
            <label>Telefon<input name="telefon" value="<?= h($m['telefon']) ?>"></label>
            <label>E-mail<input type="email" name="email" value="<?= h($m['email']) ?>"></label>
          </div>
          <label>Zaměření <span class="muted small">(štítky oddělené čárkou, např. thajská, olejová, šíje a záda, sportovní, těhotenská)</span>
            <input name="zamereni" value="<?= h($m['zamereni']) ?>"></label>
          <label>Preference a styl práce <span class="muted small">(co dělá ráda, pro koho se hodí – silný/jemný tlak, co nedělá, jazyky…)</span>
            <textarea name="preference" rows="4"><?= h($m['preference']) ?></textarea></label>
          <?php if ($smi): ?>
          <label>Interní poznámka <span class="muted small">(vidí jen admin a hlavní pracovník)</span>
            <textarea name="poznamka_interni" rows="3"><?= h($m['poznamka_interni']) ?></textarea></label>
          <?php endif ?>
          <div class="grid3">
            <label>Nastoupila<input type="date" name="nastup" value="<?= h($m['nastup'] ?? '') ?>"></label>
            <label>Barva v kalendáři<input type="color" name="barva" value="<?= h($m['barva']) ?>"></label>
            <label>Pořadí<input type="number" name="poradi" value="<?= (int)$m['poradi'] ?>"></label>
          </div>
          <label>Účet na spropitné <span class="muted small">(jen se souhlasem masérky)</span><input name="ucet" value="<?= h($m['ucet']) ?>"></label>
          <label class="check"><input type="checkbox" name="aktivni" value="1" <?= $m['aktivni'] ? 'checked' : '' ?>> Aktivní (nabízí se v rezervacích)</label>
          </fieldset>
          <?php if ($smi): ?><button class="btn primary">Uložit</button><?php endif ?>
        </form>

        <section class="card">
          <h2>Výkonnost</h2>
          <table class="tbl plain">
            <tr><th></th><?php foreach ($obdobi as $t): ?><th><?= $t ?></th><?php endforeach ?></tr>
            <?php foreach ([['pocet', 'Proběhlých masáží'], ['hodiny', 'Odmasírováno hodin'], ['trzby', 'Tržby (kde je cena)'], ['zakazniku', 'Různých zákazníků'], ['vracejici', 'z toho se vrátili (2+ návštěvy)'], ['neprisel', 'Nepřišli']] as [$k, $t]): ?>
              <tr><td><?= $t ?></td><?php foreach ($obdobi as $d => $_): ?><td><?= $k === 'trzby' ? kc($v[$d][$k]) : $v[$d][$k] ?></td><?php endforeach ?></tr>
            <?php endforeach ?>
            <tr><td>Spokojenost</td><?php foreach ($obdobi as $d => $_): ?><td><?= hvezdy($v[$d]['hodnoceni']) ?></td><?php endforeach ?></tr>
          </table>
          <h3>Po měsících</h3>
          <table class="tbl plain">
            <tr><th>Měsíc</th><th>Masáží</th><th>Hodin</th><th>Tržby</th><th>Spokojenost</th></tr>
            <?php foreach ($mesice as $z => $x): ?>
              <tr><td><?= date('n/Y', strtotime($z)) ?></td><td><?= $x['pocet'] ?></td><td><?= $x['hodiny'] ?></td><td><?= kc($x['trzby']) ?></td><td><?= hvezdy($x['hodnoceni']) ?></td></tr>
            <?php endforeach ?>
          </table>
        </section>
      </div>
      <div>
        <section class="card side">
          <h3>Spokojenost zákazníků</h3>
          <?php if ($celkemH): ?>
            <?php foreach ([5, 4, 3, 2, 1] as $z): ?>
              <div class="bar"><span><?= $z ?> ★</span><i style="width:<?= round($rozlozeni[$z] / $celkemH * 100) ?>%"></i><b><?= $rozlozeni[$z] ?></b></div>
            <?php endforeach ?>
            <p class="small">Celkem <?= $celkemH ?> hodnocení.</p>
          <?php else: ?><p class="muted small">Zatím žádné hodnocení. Později je bude zadávat zákazník na tabletu v masérně, teď je může zapsat recepce.</p><?php endif ?>
          <p class="small">Žádá si ji jako preferovanou: <b><?= $preferuji ?></b> zákazníků</p>
          <form method="post"><?= csrf_pole() ?><input type="hidden" name="akce" value="hodnoceni">
            <label>Známka<select name="znamka" required><option value="">—</option><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= str_repeat('★', $i) ?> (<?= $i ?>)</option><?php endfor ?></select></label>
            <label>K masáži (nepovinné)<select name="rezervace_id"><option value="">— bez vazby —</option>
              <?php foreach ($posledniRez as $r): ?><option value="<?= $r['id'] ?>"><?= h(datum_cz($r['zacatek'])) ?> · <?= h(trim($r['jmeno'] . ' ' . $r['prijmeni'])) ?></option><?php endforeach ?></select></label>
            <label>Komentář<textarea name="komentar" rows="2"></textarea></label>
            <button class="btn">Přidat hodnocení</button>
          </form>
        </section>
        <section class="card side">
          <h3>Poslední hodnocení</h3>
          <?php foreach ($hodnoceni as $x): ?>
            <p class="small"><?= hvezdy((float)$x['znamka']) ?> · <?= h(datum_cz($x['cas'])) ?>
              <?= $x['zj'] !== null ? '· ' . h(trim($x['zj'] . ' ' . $x['zp'])) : '' ?> <span class="muted">(<?= h($x['zdroj']) ?>, <?= h($x['kdo']) ?>)</span><br>
              <?= h($x['komentar']) ?>
              <?php if ($smi): ?><form method="post" style="display:inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="smaz_hodnoceni"><input type="hidden" name="hid" value="<?= $x['id'] ?>"><button class="btn danger small" data-confirm="Smazat hodnocení?">smazat</button></form><?php endif ?></p>
          <?php endforeach ?>
        </section>
      </div>
    </div>
    <?php paticka();
}

// ---------- RECEPČNÍ (uživatelé kartotéky) ----------
function ical_token_pro(int $uid): string
{
    $t = (string)qv('SELECT ical_token FROM uzivatele WHERE id=?', [$uid]);
    if ($t === '') { $t = bin2hex(random_bytes(16)); q('UPDATE uzivatele SET ical_token=? WHERE id=?', [$t, $uid]); }
    return $t;
}
function ical_url(string $token, bool $webcal = false): string
{
    $u = rtrim(cfg()['url'] ?? '', '/') . '/?r=ical&t=' . $token;
    return $webcal ? preg_replace('~^https?://~', 'webcal://', $u) : $u;
}

function stranka_recepcni(array $u): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        vyzaduj_admina();
        $akce = $_POST['akce'] ?? '';
        if ($akce === 'ulozit') {
            $id = (int)($_POST['id'] ?? 0);
            $x = q1('SELECT * FROM uzivatele WHERE id=?', [$id]);
            $role = array_key_exists($_POST['role'] ?? '', ROLE) ? $_POST['role'] : 'recepce';
            $aktivni = !empty($_POST['aktivni']) ? 1 : 0;
            if (!$x) flash('Uživatel nenalezen.', 'err');
            elseif ($x['role'] === 'admin' && ($role !== 'admin' || !$aktivni)
                    && (int)qv("SELECT COUNT(*) FROM uzivatele WHERE role='admin' AND aktivni=1 AND id<>?", [$id]) === 0)
                flash('Musí zůstat aspoň jeden aktivní admin.', 'err');
            else {
                q('UPDATE uzivatele SET jmeno=?, role=?, telefon=?, email=?, poznamka=?, barva=?, aktivni=? WHERE id=?',
                    [trim($_POST['jmeno'] ?? '') ?: $x['login'], $role, trim($_POST['telefon'] ?? ''), trim($_POST['email'] ?? ''), trim($_POST['poznamka'] ?? ''),
                     preg_match('~^#[0-9a-fA-F]{6}$~', $_POST['barva'] ?? '') ? $_POST['barva'] : '#5b8a72', $aktivni, $id]);
                if (($_POST['heslo'] ?? '') !== '') {
                    if (strlen($_POST['heslo']) >= 8) q('UPDATE uzivatele SET heslo_hash=? WHERE id=?', [password_hash($_POST['heslo'], PASSWORD_DEFAULT), $id]);
                    else flash('Heslo musí mít aspoň 8 znaků – heslo nezměněno.', 'err');
                }
                zapis_log('uzivatel_uprava', $x['login']);
                flash('Uloženo.');
            }
        } elseif ($akce === 'novy') {
            $login = trim($_POST['login'] ?? '');
            if ($login === '' || strlen($_POST['heslo'] ?? '') < 8) flash('Login a heslo (aspoň 8 znaků) jsou povinné.', 'err');
            elseif (q1('SELECT 1 FROM uzivatele WHERE login=?', [$login])) flash('Tento login už existuje.', 'err');
            else {
                $role = array_key_exists($_POST['role'] ?? '', ROLE) ? $_POST['role'] : 'recepce';
                q('INSERT INTO uzivatele(login,jmeno,heslo_hash,role,telefon,email) VALUES(?,?,?,?,?,?)',
                    [$login, trim($_POST['jmeno'] ?? '') ?: $login, password_hash($_POST['heslo'], PASSWORD_DEFAULT), $role, trim($_POST['telefon'] ?? ''), trim($_POST['email'] ?? '')]);
                zapis_log('uzivatel_novy', $login);
                flash("Uživatel $login založen.");
            }
        }
        presmeruj('/?r=recepcni');
    }
    $lidi = q('SELECT * FROM uzivatele ORDER BY aktivni DESC, role, jmeno')->fetchAll();
    $od = date('Y-m-01'); $do = date('Y-m-t');
    $hodiny = [];
    foreach (q("SELECT uzivatel_id, od, do_ FROM smeny WHERE stav='schvaleno' AND datum BETWEEN ? AND ?", [$od, $do])->fetchAll() as $s)
        $hodiny[$s['uzivatel_id']] = ($hodiny[$s['uzivatel_id']] ?? 0) + (strtotime("1970-01-01 $s[do_]") - strtotime("1970-01-01 $s[od]")) / 3600;
    $admin = je_admin($u);

    hlavicka('Recepční', 'tym'); ?>
    <h1>Tým</h1>
    <?php tym_nav('recepcni'); ?>
    <table class="tbl">
      <tr><th>Jméno</th><th>Role</th><th>Telefon</th><th>E-mail</th><th>Hodin tento měsíc</th><th>Poznámka</th></tr>
      <?php foreach ($lidi as $x): ?>
        <tr<?= $x['aktivni'] ? '' : ' class="muted"' ?>>
          <td><span class="dot" style="background:<?= h($x['barva']) ?>"></span> <b><?= h($x['jmeno']) ?></b> <span class="muted small">(<?= h($x['login']) ?>)</span><?= $x['aktivni'] ? '' : ' <span class="tag">neaktivní</span>' ?></td>
          <td><?= h(ROLE[$x['role']] ?? $x['role']) ?></td>
          <td><?= h($x['telefon']) ?></td><td><?= h($x['email']) ?></td>
          <td><?= round($hodiny[$x['id']] ?? 0, 1) ?></td><td class="small"><?= h($x['poznamka']) ?></td>
        </tr>
        <?php if ($admin): ?>
        <tr><td colspan="6" style="border-top:0;padding-top:0"><details><summary class="muted small">upravit <?= h($x['jmeno']) ?></summary>
          <form method="post" class="inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="ulozit"><input type="hidden" name="id" value="<?= $x['id'] ?>">
            <div class="grid3">
              <label>Jméno<input name="jmeno" value="<?= h($x['jmeno']) ?>"></label>
              <label>Role<select name="role"><?= moznosti(ROLE, $x['role']) ?></select></label>
              <label>Barva ve směnách<input type="color" name="barva" value="<?= h($x['barva']) ?>"></label>
              <label>Telefon<input name="telefon" value="<?= h($x['telefon']) ?>"></label>
              <label>E-mail (sem chodí kalendář)<input type="email" name="email" value="<?= h($x['email']) ?>"></label>
              <label>Nové heslo (nechte prázdné = beze změny)<input type="password" name="heslo" minlength="8" autocomplete="new-password"></label>
            </div>
            <label>Poznámka (interní)<input name="poznamka" value="<?= h($x['poznamka']) ?>"></label>
            <label class="check"><input type="checkbox" name="aktivni" value="1" <?= $x['aktivni'] ? 'checked' : '' ?>> Aktivní (může se přihlásit)</label>
            <button class="btn primary">Uložit</button>
          </form></details></td></tr>
        <?php endif ?>
      <?php endforeach ?>
    </table>
    <?php if ($admin): ?>
    <form method="post" class="card"><?= csrf_pole() ?><input type="hidden" name="akce" value="novy">
      <h2>Přidat člena týmu</h2>
      <div class="grid3">
        <label>Login *<input name="login" required autocomplete="off"></label>
        <label>Jméno<input name="jmeno"></label>
        <label>Role<select name="role"><?= moznosti(ROLE, 'recepce') ?></select></label>
        <label>Telefon<input name="telefon"></label>
        <label>E-mail<input type="email" name="email"></label>
        <label>Heslo * (aspoň 8 znaků)<input type="password" name="heslo" minlength="8" required autocomplete="new-password"></label>
      </div>
      <button class="btn primary">Přidat</button>
    </form>
    <p class="muted small"><b>Role:</b> Admin – všechno včetně směn a uživatelů. Hlavní pracovník – upravuje masérky (zaměření, preference), vidí všechny směny. Recepční – rezervace, zákazníci, vlastní směny (může je navrhnout).</p>
    <?php endif;
    paticka();
}

// ---------- SMĚNY recepčních ----------
function smeny_hodin(array $s): float { return (strtotime("1970-01-01 $s[do_]") - strtotime("1970-01-01 $s[od]")) / 3600; }

function smena_kolize(int $uid, string $datum, string $od, string $do, int $krome = 0): bool
{
    return (bool)q1("SELECT 1 FROM smeny WHERE uzivatel_id=? AND datum=? AND id<>? AND stav IN ('navrzeno','schvaleno') AND od<? AND do_>?", [$uid, $datum, $krome, $do, $od]);
}

/** ICS kalendář ze směn (UTC časy, SEQUENCE podle verze, zrušené jako STATUS:CANCELLED). */
function ics_smeny(array $smeny, string $nazev, bool $sJmenem = false, string $method = 'PUBLISH'): string
{
    $esc = fn(string $t) => str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $t);
    $fold = function (string $l): string {
        $o = '';
        while (strlen($l) > 73) { $c = mb_strcut($l, 0, 73, 'UTF-8'); $o .= $c . "\r\n "; $l = substr($l, strlen($c)); }
        return $o . $l;
    };
    $utc = fn(string $d, string $t) => (new DateTime("$d $t", new DateTimeZone(cfg()['casova_zona'] ?? 'Europe/Prague')))
        ->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    $L = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Thai Most//Kartoteka//CS', 'CALSCALE:GREGORIAN', "METHOD:$method",
          'X-WR-CALNAME:' . $esc($nazev), 'X-WR-TIMEZONE:Europe/Prague', 'REFRESH-INTERVAL;VALUE=DURATION:PT1H'];
    $stamp = gmdate('Ymd\THis\Z');
    foreach ($smeny as $s) {
        $zrus = $s['stav'] === 'zruseno';
        $L[] = 'BEGIN:VEVENT';
        $L[] = "UID:smena-$s[id]@masage.dvere.app";
        $L[] = "DTSTAMP:$stamp";
        $L[] = 'DTSTART:' . $utc($s['datum'], $s['od']);
        $L[] = 'DTEND:' . $utc($s['datum'], $s['do_']);
        $L[] = 'SUMMARY:' . $esc(($sJmenem ? $s['jmeno'] . ' – ' : '') . 'Thai Most – recepce');
        if (trim($s['poznamka']) !== '') $L[] = 'DESCRIPTION:' . $esc($s['poznamka']);
        $L[] = 'LOCATION:' . $esc('Thai Most, SNP 2654/26, Most');
        $L[] = 'SEQUENCE:' . (int)$s['verze'];
        $L[] = 'STATUS:' . ($zrus ? 'CANCELLED' : 'CONFIRMED');
        $L[] = 'END:VEVENT';
    }
    $L[] = 'END:VCALENDAR';
    return implode("\r\n", array_map($fold, $L)) . "\r\n";
}

/** Veřejný kalendářový odkaz (bez přihlášení, chráněný dlouhým tokenem) – pro odběr v Google/Apple/Outlook kalendáři. */
function stranka_ical(): void
{
    $t = (string)($_GET['t'] ?? '');
    $od = date('Y-m-d', strtotime('-60 days'));
    $smeny = null; $nazev = 'Thai Most – směny';
    if (preg_match('~^[0-9a-f]{32}$~', $t)) {
        if (hash_equals((string)nastaveni('ical_vse_token', '-'), $t)) {
            $smeny = q("SELECT s.*, u.jmeno FROM smeny s JOIN uzivatele u ON u.id=s.uzivatel_id WHERE s.datum>=? AND s.stav IN ('schvaleno','zruseno') ORDER BY s.datum, s.od", [$od])->fetchAll();
            $sJmenem = true;
        } elseif ($x = q1('SELECT id, jmeno FROM uzivatele WHERE ical_token=? AND aktivni=1', [$t])) {
            $smeny = q("SELECT s.*, u.jmeno FROM smeny s JOIN uzivatele u ON u.id=s.uzivatel_id WHERE s.uzivatel_id=? AND s.datum>=? AND s.stav IN ('schvaleno','zruseno') ORDER BY s.datum, s.od", [$x['id'], $od])->fetchAll();
            $sJmenem = false; $nazev = 'Thai Most – moje směny (' . $x['jmeno'] . ')';
        }
    }
    if ($smeny === null) { http_response_code(404); exit('Nenalezeno'); }
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="smeny.ics"');
    header('Cache-Control: no-store');
    echo ics_smeny($smeny, $nazev, $sJmenem);
    exit;
}

function smeny_posli_uzivateli(array $x, string $od, string $do): string
{
    if (!filter_var($x['email'], FILTER_VALIDATE_EMAIL)) return "{$x['jmeno']}: chybí e-mail";
    $rows = q("SELECT s.*, u.jmeno FROM smeny s JOIN uzivatele u ON u.id=s.uzivatel_id
               WHERE s.uzivatel_id=? AND s.datum BETWEEN ? AND ? AND (s.stav='schvaleno' OR (s.stav='zruseno' AND s.odeslano IS NOT NULL)) ORDER BY s.datum, s.od", [$x['id'], $od, $do])->fetchAll();
    if (!$rows) return '';
    $txt = "Dobrý den, {$x['jmeno']},\n\nvaše směny na recepci Thai Most (" . date('j. n.', strtotime($od)) . ' – ' . date('j. n. Y', strtotime($do)) . "):\n\n";
    foreach ($rows as $s)
        $txt .= ($s['stav'] === 'zruseno' ? '✗ ZRUŠENO: ' : '• ') . datum_cz($s['datum'], false) . ' ' . $s['od'] . '–' . $s['do_'] . ($s['poznamka'] ? " ($s[poznamka])" : '') . "\n";
    $txt .= "\nV příloze je kalendář (.ics) – otevřením se směny přidají do vašeho kalendáře, změněné se přepíšou.\n"
          . "Trvalé napojení kalendáře (aktualizuje se samo): " . ical_url(ical_token_pro((int)$x['id'])) . "\n\nThai Most\n";
    posli_email($x['email'], 'Thai Most – směny ' . date('j. n.', strtotime($od)) . '–' . date('j. n.', strtotime($do)), $txt,
        ['nazev' => 'smeny.ics', 'mime' => 'text/calendar; method=PUBLISH', 'obsah' => ics_smeny($rows, 'Thai Most – směny', false)]);
    q("UPDATE smeny SET odeslano=? WHERE uzivatel_id=? AND datum BETWEEN ? AND ? AND stav IN ('schvaleno','zruseno')", [nyni(), $x['id'], $od, $do]);
    return '';
}

function stranka_smeny(array $u): void
{
    $admin = je_admin($u);
    $tyden = date('Y-m-d', strtotime('monday this week', strtotime($_GET['tyden'] ?? 'today') ?: time()));
    $nedele = date('Y-m-d', strtotime("$tyden +6 days"));
    $zpet = "/?r=smeny&tyden=$tyden";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = $_POST['akce'] ?? '';
        $cas = fn($k) => preg_match('~^\d{2}:\d{2}$~', $_POST[$k] ?? '') ? $_POST[$k] : null;
        $dat = preg_match('~^\d{4}-\d{2}-\d{2}$~', $_POST['datum'] ?? '') ? $_POST['datum'] : null;
        if ($akce === 'pridat') {
            $uid = $admin ? (int)($_POST['uzivatel_id'] ?? 0) : (int)$u['id'];
            $od = $cas('od'); $do = $cas('do');
            if (!$dat || !$od || !$do || $od >= $do || !q1('SELECT 1 FROM uzivatele WHERE id=? AND aktivni=1', [$uid])) flash('Vyplňte datum a čas (konec po začátku).', 'err');
            elseif (smena_kolize($uid, $dat, $od, $do)) flash('Tento člověk už má ten den překrývající se směnu.', 'err');
            else {
                $stav = $admin && ($_POST['stav'] ?? '') === 'schvaleno' ? 'schvaleno' : 'navrzeno';
                q('INSERT INTO smeny(uzivatel_id,datum,od,do_,stav,poznamka,navrhl,schvalil) VALUES(?,?,?,?,?,?,?,?)',
                    [$uid, $dat, $od, $do, $stav, trim($_POST['poznamka'] ?? ''), kdo(), $stav === 'schvaleno' ? kdo() : '']);
                zapis_log('smena_nova', "$uid $dat $od-$do $stav");
                flash($stav === 'schvaleno' ? 'Směna schválena.' : 'Návrh směny uložen, čeká na schválení adminem.');
            }
            $zpet = '/?r=smeny&tyden=' . date('Y-m-d', strtotime('monday this week', strtotime($dat ?: $tyden)));
        } elseif ($admin && in_array($akce, ['upravit', 'stav'], true)) {
            $id = (int)($_POST['id'] ?? 0);
            $s = q1('SELECT * FROM smeny WHERE id=?', [$id]);
            if ($s && $akce === 'stav' && isset(SMENA_STAVY[$_POST['stav'] ?? ''])) {
                q('UPDATE smeny SET stav=?, schvalil=?, verze=verze+1, zmeneno=? WHERE id=?', [$_POST['stav'], kdo(), nyni(), $id]);
                flash('Stav změněn: ' . SMENA_STAVY[$_POST['stav']]);
            } elseif ($s && $akce === 'upravit') {
                $od = $cas('od'); $do = $cas('do');
                if (!$dat || !$od || !$do || $od >= $do) flash('Neplatné datum nebo čas.', 'err');
                elseif (smena_kolize((int)$s['uzivatel_id'], $dat, $od, $do, $id)) flash('Překrývá se s jinou směnou.', 'err');
                else {
                    q("UPDATE smeny SET datum=?, od=?, do_=?, poznamka=?, verze=verze+1, zmeneno=? WHERE id=?", [$dat, $od, $do, trim($_POST['poznamka'] ?? ''), nyni(), $id]);
                    flash('Směna upravena' . ($s['stav'] === 'schvaleno' ? ' – pošlete změnu do kalendáře.' : '.'));
                }
            }
        } elseif ($admin && $akce === 'schvalit_tyden') {
            $n = q("UPDATE smeny SET stav='schvaleno', schvalil=?, verze=verze+1, zmeneno=? WHERE stav='navrzeno' AND datum BETWEEN ? AND ?", [kdo(), nyni(), $tyden, $nedele])->rowCount();
            flash("Schváleno návrhů: $n.");
        } elseif ($admin && $akce === 'kopie') {
            $minuly = date('Y-m-d', strtotime("$tyden -7 days")); $n = 0;
            foreach (q("SELECT * FROM smeny WHERE stav IN ('schvaleno','navrzeno') AND datum BETWEEN ? AND ?", [$minuly, date('Y-m-d', strtotime("$tyden -1 day"))])->fetchAll() as $s) {
                $d = date('Y-m-d', strtotime("$s[datum] +7 days"));
                if (smena_kolize((int)$s['uzivatel_id'], $d, $s['od'], $s['do_'])) continue;
                q("INSERT INTO smeny(uzivatel_id,datum,od,do_,stav,poznamka,navrhl) VALUES(?,?,?,?, 'navrzeno', ?, ?)", [$s['uzivatel_id'], $d, $s['od'], $s['do_'], $s['poznamka'], kdo()]); $n++;
            }
            flash("Zkopírováno z minulého týdne jako návrhy: $n.");
        } elseif ($admin && $akce === 'poslat') {
            $uid = $_POST['uzivatel_id'] ?? 'vse';
            $lidi = $uid === 'vse' ? q('SELECT * FROM uzivatele WHERE aktivni=1')->fetchAll() : q('SELECT * FROM uzivatele WHERE id=? AND aktivni=1', [(int)$uid])->fetchAll();
            $problemy = []; $ok = 0;
            foreach ($lidi as $x) { $p = smeny_posli_uzivateli($x, $tyden, $nedele); if ($p !== '') $problemy[] = $p; else $ok++; }
            flash("Kalendář odeslán/zařazen do fronty e-mailů: $ok." . (!empty(cfg()['smtp']['host']) ? '' : ' (SMTP není nastaveno – e-maily zatím čekají ve frontě, kalendářové odkazy fungují hned.)'));
            if ($problemy) flash('Nepodařilo se: ' . implode('; ', $problemy), 'warn');
        } elseif ($akce === 'token' && ($admin || (int)($_POST['uzivatel_id'] ?? 0) === (int)$u['id'])) {
            q('UPDATE uzivatele SET ical_token=? WHERE id=?', [bin2hex(random_bytes(16)), (int)$_POST['uzivatel_id']]);
            flash('Nový kalendářový odkaz vytvořen – starý přestal fungovat.');
        }
        presmeruj($zpet);
    }

    // data týdne
    $vse = $admin || ($u['role'] === 'hlavni');
    $rows = q("SELECT s.*, u.jmeno FROM smeny s JOIN uzivatele u ON u.id=s.uzivatel_id WHERE s.datum BETWEEN ? AND ? AND s.stav<>'zruseno' ORDER BY s.datum, s.od", [$tyden, $nedele])->fetchAll();
    $bunky = []; $souhrn = [];
    foreach ($rows as $s) {
        $vidi = $vse || (int)$s['uzivatel_id'] === (int)$u['id'] || $s['stav'] === 'schvaleno';
        if (!$vidi) continue;
        $bunky[$s['uzivatel_id']][$s['datum']][] = $s;
        if ($s['stav'] === 'schvaleno') $souhrn[$s['uzivatel_id']] = ($souhrn[$s['uzivatel_id']] ?? 0) + smeny_hodin($s);
    }
    $lidi = q('SELECT * FROM uzivatele WHERE aktivni=1 ORDER BY role, jmeno')->fetchAll();
    $radky = array_values(array_filter($lidi, fn($x) => in_array($x['role'], ['recepce', 'hlavni'], true) || isset($bunky[$x['id']])));
    $cekaji = count(array_filter($rows, fn($s) => $s['stav'] === 'navrzeno'));
    $dny = []; for ($i = 0; $i < 7; $i++) $dny[] = date('Y-m-d', strtotime("$tyden +$i days"));
    $vseToken = (string)nastaveni('ical_vse_token', '');

    hlavicka('Směny', 'smeny'); ?>
    <h1>Směny recepčních <small>týden <?= date('j. n.', strtotime($tyden)) ?> – <?= date('j. n. Y', strtotime($nedele)) ?></small></h1>
    <div class="cal-nav">
      <a class="btn" href="/?r=smeny&tyden=<?= date('Y-m-d', strtotime("$tyden -7 days")) ?>">← předchozí</a>
      <a class="btn" href="/?r=smeny">tento týden</a>
      <a class="btn" href="/?r=smeny&tyden=<?= date('Y-m-d', strtotime("$tyden +7 days")) ?>">další →</a>
      <?php if ($admin): ?>
        <form method="post" class="inline" style="display:inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="schvalit_tyden"><button class="btn"<?= $cekaji ? '' : ' disabled' ?>>✔ Schválit všechny návrhy (<?= $cekaji ?>)</button></form>
        <form method="post" class="inline" style="display:inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="kopie"><button class="btn" data-confirm="Zkopírovat směny z minulého týdne jako návrhy?">⧉ Kopie z minulého týdne</button></form>
        <form method="post" class="inline" style="display:inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="poslat"><input type="hidden" name="uzivatel_id" value="vse"><button class="btn primary" data-confirm="Poslat všem recepčním kalendář na tento týden e-mailem?">📅 Poslat týden do kalendářů</button></form>
      <?php endif ?>
    </div>
    <div style="overflow-x:auto">
    <table class="tbl smeny">
      <tr><th>Recepční</th><?php foreach ($dny as $i => $d): ?><th class="<?= $d === date('Y-m-d') ? 'dnes' : '' ?>"><?= DNY_CZ[$i] ?> <?= date('j. n.', strtotime($d)) ?></th><?php endforeach ?><th>Hodin</th></tr>
      <?php foreach ($radky as $x): ?>
        <tr><td><span class="dot" style="background:<?= h($x['barva']) ?>"></span> <b><?= h($x['jmeno']) ?></b></td>
        <?php foreach ($dny as $d): ?><td>
          <?php foreach ($bunky[$x['id']][$d] ?? [] as $s): ?>
            <div class="sm sm-<?= h($s['stav']) ?>" title="<?= h(SMENA_STAVY[$s['stav']]) ?><?= $s['poznamka'] ? ': ' . h($s['poznamka']) : '' ?>">
              <b><?= h($s['od']) ?>–<?= h($s['do_']) ?></b> <span class="small"><?= $s['stav'] === 'navrzeno' ? 'návrh' : '' ?></span>
              <?php if ($s['poznamka']): ?><div class="small muted"><?= h($s['poznamka']) ?></div><?php endif ?>
              <?php if ($admin): ?>
                <div class="sm-akce">
                <?php foreach (($s['stav'] === 'navrzeno' ? ['schvaleno' => '✔', 'odmitnuto' => '✖'] : []) + ['zruseno' => '🗑'] as $st => $ik): ?>
                  <form method="post"><?= csrf_pole() ?><input type="hidden" name="akce" value="stav"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="stav" value="<?= $st ?>"><button title="<?= h(SMENA_STAVY[$st]) ?>"><?= $ik ?></button></form>
                <?php endforeach ?>
                <details><summary>✎</summary>
                  <form method="post" class="sm-edit"><?= csrf_pole() ?><input type="hidden" name="akce" value="upravit"><input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <input type="date" name="datum" value="<?= h($s['datum']) ?>"><input type="time" name="od" value="<?= h($s['od']) ?>"><input type="time" name="do" value="<?= h($s['do_']) ?>">
                    <input name="poznamka" value="<?= h($s['poznamka']) ?>" placeholder="poznámka"><button class="btn">Uložit</button></form></details>
                </div>
              <?php endif ?>
            </div>
          <?php endforeach ?>
        </td><?php endforeach ?>
        <td><?= round($souhrn[$x['id']] ?? 0, 1) ?></td></tr>
      <?php endforeach ?>
    </table></div>
    <p class="small muted">Barvy: <span class="sm sm-schvaleno">schváleno</span> <span class="sm sm-navrzeno">návrh (čeká na schválení)</span> <span class="sm sm-odmitnuto">odmítnuto</span>. Do kalendářů se posílají jen schválené směny.
      <?= $vse ? '' : 'Vidíte svoje směny a schválené směny kolegů.' ?></p>

    <div class="cols">
      <form method="post" class="card"><?= csrf_pole() ?><input type="hidden" name="akce" value="pridat">
        <h2><?= $admin ? 'Přidat směnu' : 'Navrhnout směnu' ?></h2>
        <?php if ($admin): ?>
          <label>Kdo<select name="uzivatel_id"><?php foreach ($lidi as $x): ?><option value="<?= $x['id'] ?>"><?= h($x['jmeno']) ?></option><?php endforeach ?></select></label>
        <?php endif ?>
        <div class="grid3">
          <label>Datum<input type="date" name="datum" value="<?= h($tyden) ?>" required></label>
          <label>Od<input type="time" name="od" value="09:00" required></label>
          <label>Do<input type="time" name="do" value="17:00" required></label>
        </div>
        <label>Poznámka<input name="poznamka" placeholder="např. zástup, kratší směna"></label>
        <?php if ($admin): ?><label>Stav<select name="stav"><?= moznosti(['schvaleno' => 'rovnou schváleno', 'navrzeno' => 'jen návrh'], 'schvaleno') ?></select></label><?php endif ?>
        <button class="btn primary"><?= $admin ? 'Uložit směnu' : 'Odeslat návrh adminovi' ?></button>
      </form>
      <section class="card side">
        <h3>Kalendář do telefonu</h3>
        <?php $mojeUrl = ical_url(ical_token_pro((int)$u['id'])); ?>
        <p class="small">Moje směny (aktualizuje se samo): <input readonly value="<?= h($mojeUrl) ?>" onclick="this.select()"></p>
        <p class="small">V Google Kalendáři: <i>Další kalendáře → Z adresy URL</i>. V iPhonu: <i>Kalendáře → Přidat → Přidat odebíraný kalendář</i>. V Outlooku: <i>Přidat kalendář → Ze stránky Web</i>.
          Odkaz nikomu nesdílejte – kdo ho má, vidí vaše směny.</p>
        <form method="post"><?= csrf_pole() ?><input type="hidden" name="akce" value="token"><input type="hidden" name="uzivatel_id" value="<?= $u['id'] ?>"><button class="btn small" data-confirm="Vytvořit nový odkaz? Starý přestane fungovat.">vytvořit nový odkaz</button></form>
        <?php if ($admin): ?>
          <h3>Celý tým (admin)</h3>
          <p class="small"><input readonly value="<?= h(ical_url($vseToken)) ?>" onclick="this.select()"></p>
          <h3>Poslat e-mailem jednomu</h3>
          <form method="post"><?= csrf_pole() ?><input type="hidden" name="akce" value="poslat">
            <label><select name="uzivatel_id"><?php foreach ($radky as $x): ?><option value="<?= $x['id'] ?>"><?= h($x['jmeno']) ?><?= filter_var($x['email'], FILTER_VALIDATE_EMAIL) ? '' : ' (chybí e-mail)' ?></option><?php endforeach ?></select></label>
            <button class="btn">📅 Poslat týden</button></form>
        <?php endif ?>
      </section>
    </div>
    <?php paticka();
}
