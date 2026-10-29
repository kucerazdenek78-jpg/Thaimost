<?php
// Dárkové poukazy: seznam, detail, nový z recepce, uplatnění.

function stranka_poukazy(array $u): void
{
    $f = $_GET['f'] ?? 'aktivni';
    $kde = ['aktivni' => "stav IN ('objednano','zaplaceno','vydano')", 'objednano' => "stav='objednano'", 'vse' => '1=1',
            'uplatnene' => "stav IN ('uplatneno','propadle','zruseno')"][$f] ?? "stav IN ('objednano','zaplaceno','vydano')";
    $hl = trim($_GET['q'] ?? '');
    $p = [];
    if ($hl !== '') { $kde .= ' AND (kod LIKE ? OR objednal_jmeno LIKE ? OR pro_koho LIKE ? OR objednal_tel LIKE ?)'; $p = ["%$hl%", "%$hl%", "%$hl%", '%' . preg_replace('~\D~', '', $hl) . '%']; }
    $rows = q("SELECT * FROM poukazy WHERE $kde ORDER BY vytvoreno DESC LIMIT 300", $p)->fetchAll();
    // propadlé označit
    q("UPDATE poukazy SET stav='propadle' WHERE stav IN ('zaplaceno','vydano') AND platnost_do IS NOT NULL AND platnost_do < ?", [date('Y-m-d')]);

    hlavicka('Poukazy', 'poukazy'); ?>
    <div class="cal-nav">
      <h1>Dárkové poukazy</h1>
      <form class="grow"><input type="hidden" name="r" value="poukazy"><input type="hidden" name="f" value="<?= h($f) ?>"><input name="q" value="<?= h($hl) ?>" placeholder="kód, jméno, telefon"></form>
      <a class="btn primary" href="/?r=poukaz_novy">+ Vystavit poukaz</a>
    </div>
    <p class="muted small"><?php foreach (['aktivni' => 'aktivní', 'objednano' => 'čekají na platbu', 'uplatnene' => 'uplatněné / propadlé', 'vse' => 'vše'] as $k => $t): ?>
      <a href="/?r=poukazy&f=<?= $k ?>"<?= $f === $k ? ' class="on"' : '' ?>><?= $t ?></a> · <?php endforeach ?></p>
    <table class="tbl">
      <tr><th>Kód</th><th>Stav</th><th>Hodnota</th><th>Zůstatek</th><th>Pro</th><th>Objednal</th><th>Platnost</th><th>Vytvořeno</th></tr>
      <?php foreach ($rows as $pk): ?>
        <tr onclick="location='/?r=poukaz&id=<?= $pk['id'] ?>'">
          <td><a href="/?r=poukaz&id=<?= $pk['id'] ?>"><?= h($pk['kod']) ?></a></td>
          <td><span class="stav p-<?= h($pk['stav']) ?>"><?= h(STAVY_POUKAZU[$pk['stav']]) ?></span></td>
          <td><?= h($pk['sluzba_text'] ?: kc($pk['hodnota'])) ?></td>
          <td><?= kc($pk['zustatek']) ?></td>
          <td><?= h($pk['pro_koho']) ?></td>
          <td><?= h($pk['objednal_jmeno']) ?> <span class="muted small"><?= h(telefon_cz($pk['objednal_tel'])) ?></span></td>
          <td><?= $pk['platnost_do'] ? date('j. n. Y', strtotime($pk['platnost_do'])) : '—' ?></td>
          <td class="small"><?= h(datum_cz($pk['vytvoreno'], false)) ?> <span class="muted"><?= h($pk['zdroj']) ?></span></td>
        </tr>
      <?php endforeach ?>
    </table>
    <?php paticka();
}

function stranka_poukaz_novy(array $u): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $p = $_POST;
        $hodnota = (int)($p['hodnota'] ?? 0);
        if ($hodnota < 1) { flash('Zadejte hodnotu poukazu.', 'err'); presmeruj('/?r=poukaz_novy'); }
        $kod = poukaz_kod();
        $platnost = preg_match('~^\d{4}-\d{2}-\d{2}$~', $p['platnost_do'] ?? '') ? $p['platnost_do'] : null;
        $stav = !empty($p['zaplaceno']) ? 'vydano' : 'objednano';
        q("INSERT INTO poukazy(kod,hodnota,zustatek,sluzba_text,platnost_do,stav,objednal_jmeno,objednal_tel,objednal_email,pro_koho,od_koho,venovani,prevzeti,platba,poznamka,zdroj)
           VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'osobně',?,?,'recepce')",
            [$kod, $hodnota, $hodnota, trim($p['sluzba_text'] ?? ''), $platnost, $stav, trim($p['objednal_jmeno'] ?? ''), telefon_norm($p['objednal_tel'] ?? ''),
             trim($p['objednal_email'] ?? ''), trim($p['pro_koho'] ?? ''), trim($p['od_koho'] ?? ''), trim($p['venovani'] ?? ''),
             $p['platba'] ?? '', trim($p['poznamka'] ?? '')]);
        $id = (int)db()->lastInsertId();
        // poukaz se vede pod kupujícím (podle telefonu); může ho darovat – držitele pak lze změnit na kartě poukazu
        $tel = telefon_norm($p['objednal_tel'] ?? '');
        if ($tel !== '') {
            $jm = trim($p['objednal_jmeno'] ?? ''); $parts = preg_split('~\s+~', $jm, 2);
            q('UPDATE poukazy SET zakaznik_id=? WHERE id=?', [zakaznik_najdi_nebo_zaloz($tel, $parts[0] ?? '', $parts[1] ?? '', trim($p['objednal_email'] ?? '')), $id]);
        }
        zapis_log('poukaz_novy', $kod);
        flash("Poukaz $kod vystaven.");
        presmeruj("/?r=poukaz&id=$id");
    }
    hlavicka('Nový poukaz', 'poukazy'); ?>
    <h1>Vystavit poukaz</h1>
    <form method="post" class="card">
      <?= csrf_pole() ?>
      <div class="grid3">
        <label>Hodnota (Kč) *<input type="number" name="hodnota" min="1" required></label>
        <label>Na konkrétní službu <span class="muted">(nepovinné)</span><input name="sluzba_text" placeholder="např. Tradiční thajská 90 min"></label>
        <label>Platnost do<input type="date" name="platnost_do" value="<?= date('Y-m-d', strtotime('+12 months')) ?>"></label>
      </div>
      <div class="grid3">
        <label>Pro koho<input name="pro_koho"></label>
        <label>Od koho<input name="od_koho"></label>
        <label>Platba<select name="platba"><?= moznosti(['hotove' => 'Hotově', 'karta' => 'Kartou', 'prevod' => 'Převodem'], 'hotove') ?></select></label>
      </div>
      <label>Věnování<input name="venovani" maxlength="120"></label>
      <div class="grid3">
        <label>Kupující – jméno<input name="objednal_jmeno"></label>
        <label>Telefon<input name="objednal_tel"></label>
        <label>E-mail<input name="objednal_email" type="email"></label>
      </div>
      <label>Poznámka<input name="poznamka"></label>
      <label class="check"><input type="checkbox" name="zaplaceno" value="1" checked> Zaplaceno a předáno teď</label>
      <button class="btn primary">Vystavit</button>
    </form>
    <?php paticka();
}

function stranka_poukaz(array $u): void
{
    $id = (int)($_GET['id'] ?? 0);
    $p = q1('SELECT * FROM poukazy WHERE id=?', [$id]);
    if (!$p) { flash('Poukaz nenalezen.', 'err'); presmeruj('/?r=poukazy'); }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = $_POST['akce'] ?? '';
        if ($akce === 'stav' && isset(STAVY_POUKAZU[$_POST['stav'] ?? ''])) {
            q('UPDATE poukazy SET stav=? WHERE id=?', [$_POST['stav'], $id]);
            zapis_log('poukaz_stav', "$p[kod] $_POST[stav]");
            flash('Stav změněn.');
        } elseif ($akce === 'uprava') {
            $platnost = preg_match('~^\d{4}-\d{2}-\d{2}$~', $_POST['platnost_do'] ?? '') ? $_POST['platnost_do'] : null;
            $zid = telefon_norm($_POST['zakaznik_tel'] ?? '') ? zakaznik_najdi_nebo_zaloz(telefon_norm($_POST['zakaznik_tel']), '', '') : null;
            q('UPDATE poukazy SET platnost_do=?, poznamka=?, zakaznik_id=COALESCE(?, zakaznik_id), pro_koho=?, od_koho=?, venovani=? WHERE id=?',
                [$platnost, trim($_POST['poznamka'] ?? ''), $zid, trim($_POST['pro_koho'] ?? ''), trim($_POST['od_koho'] ?? ''), mb_substr(trim($_POST['venovani'] ?? ''), 0, 200), $id]);
            flash('Uloženo.');
        } elseif ($akce === 'email') {
            $komu = trim($_POST['email'] ?? '');
            if (!poukaz_lze_poslat($p)) flash('Poukaz se dá poslat až po zaplacení (stav Zaplaceno nebo Vydáno).', 'err');
            elseif (!filter_var($komu, FILTER_VALIDATE_EMAIL)) flash('Zadejte platnou e-mailovou adresu.', 'err');
            else { [$zprava, $typ] = poukaz_posli_emailem($p, $komu); flash($zprava, $typ); }
        } elseif ($akce === 'cerpani') {
            $c = (int)($_POST['castka'] ?? 0);
            if ($c > 0 && $c <= $p['zustatek']) {
                q('INSERT INTO poukaz_pohyby(poukaz_id,castka,poznamka,kdo) VALUES(?,?,?,?)', [$id, -$c, trim($_POST['pozn'] ?? ''), kdo()]);
                $nz = $p['zustatek'] - $c;
                q('UPDATE poukazy SET zustatek=?, stav=? WHERE id=?', [$nz, $nz > 0 ? 'vydano' : 'uplatneno', $id]);
                flash('Čerpání zapsáno.');
            } else flash('Neplatná částka.', 'err');
        }
        presmeruj("/?r=poukaz&id=$id");
    }
    $pohyby = q('SELECT * FROM poukaz_pohyby WHERE poukaz_id=? ORDER BY cas DESC', [$id])->fetchAll();
    $zak = $p['zakaznik_id'] ? q1('SELECT * FROM zakaznici WHERE id=?', [$p['zakaznik_id']]) : null;

    hlavicka('Poukaz ' . $p['kod'], 'poukazy'); ?>
    <h1>Poukaz <?= h($p['kod']) ?> <span class="stav p-<?= h($p['stav']) ?>"><?= h(STAVY_POUKAZU[$p['stav']]) ?></span></h1>
    <div class="cols">
      <div>
        <section class="card">
          <?= poukaz_nahled($p) ?>
          <p>
            <a class="btn primary" href="/?r=poukaz_tisk&id=<?= $p['id'] ?>" target="_blank">🖨 Tisk poukazu</a>
            <a class="btn" href="/?r=poukaz_pdf&id=<?= $p['id'] ?>&stahnout=1">⬇ Stáhnout PDF</a>
          </p>
          <p class="muted small">Na originální návrh se vyplní Jméno (Pro koho), Procedura (služba, jinak hodnota) a Platnost, dole kód s QR. Co chybí, zůstane prázdné k dopsání tužkou. Údaje „Od koho“ a věnování se na poukaz netisknou, zůstávají jen v kartotéce.</p>
        </section>
        <form method="post" class="card">
          <?= csrf_pole() ?><input type="hidden" name="akce" value="email">
          <h2>Poslat zákazníkovi e-mailem</h2>
          <label>E-mail<input type="email" name="email" value="<?= h($p['objednal_email']) ?>" required placeholder="zakaznik@example.cz"></label>
          <button class="btn primary"<?= poukaz_lze_poslat($p) ? '' : ' disabled' ?>>✉ Poslat poukaz jako PDF</button>
          <?php if (!poukaz_lze_poslat($p)): ?><p class="muted small">Poslat jde až po zaplacení: nastavte stav Zaplaceno nebo Vydáno.</p><?php endif ?>
        </form>
        <section class="card">
          <dl>
            <dt>Hodnota</dt><dd><?= kc($p['hodnota']) ?><?= $p['sluzba_text'] ? ' · ' . h($p['sluzba_text']) : '' ?></dd>
            <dt>Zůstatek</dt><dd><b><?= kc($p['zustatek']) ?></b></dd>
            <dt>Pro / od</dt><dd><?= h($p['pro_koho'] ?: '—') ?> / <?= h($p['od_koho'] ?: '—') ?></dd>
            <?php if ($p['venovani']): ?><dt>Věnování</dt><dd>„<?= h($p['venovani']) ?>“</dd><?php endif ?>
            <dt>Objednal</dt><dd><?= h($p['objednal_jmeno']) ?> · <a href="tel:<?= h($p['objednal_tel']) ?>"><?= h(telefon_cz($p['objednal_tel'])) ?></a><?= $p['objednal_email'] ? ' · ' . h($p['objednal_email']) : '' ?></dd>
            <dt>Převzetí / platba</dt><dd><?= h($p['prevzeti'] ?: '—') ?> / <?= h($p['platba'] ?: '—') ?></dd>
            <dt>Vytvořeno</dt><dd><?= h(datum_cz($p['vytvoreno'])) ?> (<?= h($p['zdroj']) ?>)</dd>
            <?php if ($zak): ?><dt>Držitel</dt><dd><a href="/?r=zakaznik&id=<?= $zak['id'] ?>"><?= h(jmeno_z($zak)) ?></a></dd><?php endif ?>
          </dl>
        </section>
        <form method="post" class="card">
          <?= csrf_pole() ?><input type="hidden" name="akce" value="uprava">
          <h2>Upravit</h2>
          <div class="grid3">
            <label>Platnost do<input type="date" name="platnost_do" value="<?= h($p['platnost_do'] ?? '') ?>"></label>
            <label>Držitel – komu poukaz patří (telefon)<input name="zakaznik_tel" value="<?= h($zak ? telefon_cz($zak['telefon']) : '') ?>"></label>
          </div>
          <div class="grid3">
            <label>Věnováno (pro koho)<input name="pro_koho" value="<?= h($p['pro_koho']) ?>"></label>
            <label>Od koho<input name="od_koho" value="<?= h($p['od_koho']) ?>"></label>
          </div>
          <label>Vzkaz / věnování na poukazu<textarea name="venovani" rows="2" maxlength="200"><?= h($p['venovani']) ?></textarea></label>
          <label>Poznámka (interní)<textarea name="poznamka" rows="2"><?= h($p['poznamka']) ?></textarea></label>
          <button class="btn">Uložit</button>
        </form>
        <section class="card">
          <h2>Pohyby</h2>
          <?php if (!$pohyby): ?><p class="muted">Zatím nic nečerpáno.</p><?php endif ?>
          <?php foreach ($pohyby as $m): ?><p><?= h(datum_cz($m['cas'])) ?> · <b><?= kc($m['castka']) ?></b> · <?= h($m['poznamka']) ?> <span class="muted small"><?= h($m['kdo']) ?></span>
            <?= $m['rezervace_id'] ? '· <a href="/?r=rezervace&id=' . $m['rezervace_id'] . '">rezervace</a>' : '' ?></p><?php endforeach ?>
        </section>
      </div>
      <div>
        <section class="card side">
          <h3>Stav</h3>
          <form method="post" class="stav-btns">
            <?= csrf_pole() ?><input type="hidden" name="akce" value="stav">
            <?php foreach (['zaplaceno', 'vydano', 'uplatneno', 'zruseno'] as $s): if ($s === $p['stav']) continue; ?>
              <button name="stav" value="<?= $s ?>" class="btn<?= $s === 'zruseno' ? ' danger' : '' ?>"><?= h(STAVY_POUKAZU[$s]) ?></button>
            <?php endforeach ?>
          </form>
          <?php if ($p['zustatek'] > 0 && in_array($p['stav'], ['zaplaceno', 'vydano'])): ?>
          <h3>Čerpat bez rezervace</h3>
          <form method="post" class="inline">
            <?= csrf_pole() ?><input type="hidden" name="akce" value="cerpani">
            <input type="number" name="castka" placeholder="částka Kč" max="<?= (int)$p['zustatek'] ?>"><input name="pozn" placeholder="poznámka">
            <button class="btn">Zapsat</button>
          </form>
          <?php endif ?>
          <p class="muted small">Uplatnění za konkrétní masáž se dělá u rezervace (panel vpravo → Uplatnit poukaz).</p>
        </section>
      </div>
    </div>
    <?php paticka();
}

/** Uplatnění poukazu na rezervaci. Vrací text chyby nebo null. */
function uplatni_poukaz(array $r, string $kod, int $castka): ?string
{
    $p = q1('SELECT * FROM poukazy WHERE kod=?', [$kod]);
    if (!$p) return "Poukaz $kod neexistuje.";
    if (!in_array($p['stav'], ['zaplaceno', 'vydano'])) return 'Poukaz není platný (' . STAVY_POUKAZU[$p['stav']] . ').';
    if ($p['platnost_do'] && $p['platnost_do'] < date('Y-m-d')) return 'Poukaz propadl ' . date('j. n. Y', strtotime($p['platnost_do'])) . '.';
    if ($castka < 1) $castka = (int)$r['cena'];
    $castka = min($castka, (int)$p['zustatek']);
    if ($castka < 1) return 'Na poukazu nic nezbývá.';
    q('INSERT INTO poukaz_pohyby(poukaz_id,castka,rezervace_id,poznamka,kdo) VALUES(?,?,?,?,?)', [$p['id'], -$castka, $r['id'], 'rezervace #' . $r['id'], kdo()]);
    $nz = $p['zustatek'] - $castka;
    q('UPDATE poukazy SET zustatek=?, stav=?, zakaznik_id=COALESCE(zakaznik_id, ?) WHERE id=?', [$nz, $nz > 0 ? 'vydano' : 'uplatneno', $r['zakaznik_id'], $p['id']]);
    q("UPDATE rezervace SET poukaz_id=?, platba='poukaz', zmeneno=?, zmenil=? WHERE id=?", [$p['id'], nyni(), kdo(), $r['id']]);
    zapis_log('poukaz_uplatneni', "$kod −$castka na #$r[id]");
    return null;
}

/** Poukaz se zákazníkovi posílá až po zaplacení (dřív by šel uplatnit bez platby). */
function poukaz_lze_poslat(array $p): bool { return in_array($p['stav'], ['zaplaceno', 'vydano'], true); }

/** Pošle poukaz jako PDF v příloze. Vrací [text hlášky, 'ok'|'err'] podle toho, co se opravdu stalo. */
function poukaz_posli_emailem(array $p, string $komu): array
{
    try { $t = poukaz_texty($p); } catch (Throwable $e) { return ['Poukaz se nepodařilo připravit: ' . $e->getMessage(), 'err']; }
    $radky = ["Dobrý den,", '', 'v příloze posíláme dárkový poukaz Thajské masáže Most.', '', 'Kód poukazu: ' . $p['kod']];
    if (!empty($t['jmeno'])) $radky[] = 'Pro: ' . $t['jmeno']['text'];
    if (!empty($t['procedura'])) $radky[] = 'Poukaz: ' . $t['procedura']['text'];
    if (!empty($t['platnost'])) $radky[] = 'Platnost: ' . $t['platnost']['text'];
    array_push($radky, '',
        'Poukaz si můžete vytisknout. Má rozměr 210 × 100 mm, na papíru A4 zabere horní třetinu a stačí ho vystřihnout.',
        'Termín masáže si můžete domluvit telefonicky na 778 429 329 nebo na www.thai-most.cz.',
        'STORNO: objednanou masáž lze zrušit nejpozději 24 hodin předem, jinak propadá bez náhrady.',
        '', 'Děkujeme a těšíme se na vás.', 'Thajské masáže Most', 'SNP 2654/26, 434 01 Most', 'www.thai-most.cz');
    try { $pdf = poukaz_pdf($p); } catch (Throwable $e) { return ['PDF poukazu se nepodařilo vytvořit: ' . $e->getMessage(), 'err']; }
    $id = posli_email($komu, 'Thai Most – dárkový poukaz ' . $p['kod'], implode("\n", $radky) . "\n",
        ['nazev' => poukaz_soubor($p), 'mime' => 'application/pdf', 'obsah' => $pdf]);
    if ($id === 0) return ['Neplatná e-mailová adresa.', 'err'];
    zapis_log('poukaz_email', $p['kod'] . ' → ' . $komu);
    $r = q1('SELECT stav, chyba FROM emaily WHERE id=?', [$id]);
    if (($r['stav'] ?? '') === 'odeslano') return ["Poukaz $p[kod] odeslán na $komu.", 'ok'];
    if (($r['stav'] ?? '') === 'chyba') return ['Odeslání se nepovedlo: ' . ($r['chyba'] ?: 'neznámá chyba') . ' E-mail zůstává ve frontě.', 'err'];
    return ['E-mail je připravený ve frontě, ale neodešel: v config.php zatím není nastavený SMTP.', 'err'];
}
