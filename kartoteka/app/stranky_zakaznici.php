<?php
// Zákazníci: seznam, karta, hledání podle telefonu (pro formulář rezervace).

function stranka_zakaznici(array $u): void
{
    $hl = trim($_GET['q'] ?? '');
    $filtr = $_GET['f'] ?? '';
    $sql = "SELECT z.*, (SELECT COUNT(*) FROM rezervace r WHERE r.zakaznik_id=z.id AND r.stav='probehlo') AS navstev,
            (SELECT MAX(zacatek) FROM rezervace r WHERE r.zakaznik_id=z.id AND r.stav='probehlo') AS posledni
            FROM zakaznici z";
    if ($hl !== '') {
        $ids = array_column(zakaznici_hledej($hl, 1000), 'id');
        $sql .= ' WHERE z.id IN (' . ($ids ? implode(',', array_map('intval', $ids)) : '0') . ')';
    }
    $sql .= ' ORDER BY posledni DESC NULLS LAST, z.prijmeni, z.jmeno LIMIT 300';
    $rows = q($sql)->fetchAll();

    hlavicka('Zákazníci', 'zakaznici'); ?>
    <div class="cal-nav">
      <h1>Zákazníci</h1>
      <form class="grow"><input type="hidden" name="r" value="zakaznici"><input name="q" value="<?= h($hl) ?>" placeholder="příjmení, jméno, telefon, e-mail, kód karty" autofocus></form>
      <a class="btn primary" href="/?r=zakaznik&novy=1">+ Nový zákazník</a>
      <a class="btn" href="/?r=rez_nova">+ Rezervace</a>
      <a class="btn" href="/?r=import">⬆ Import z Google kontaktů</a>
      <?php if (smi_upravit_tym($u)): ?><a class="btn" href="/?r=uklid_zakaznici">🧹 Úklid – bez masáží</a><?php endif ?>
    </div>
    <p class="muted small">Filtr: <?php foreach (['' => 'vše', 'staly' => '⭐ stálí', 'spici' => '💤 spící', 'nesp' => '⚠️ nespolehliví', 'volny' => '⏱ vezmou volný termín', 'nove' => '🆕 noví', 'pap' => '📇 papírová kartička'] as $k => $t): ?>
      <a href="/?r=zakaznici&f=<?= $k ?>&q=<?= urlencode($hl) ?>"<?= $filtr === $k ? ' class="on"' : '' ?>><?= $t ?></a> · <?php endforeach ?></p>
    <table class="tbl">
      <tr><th>Jméno</th><th>Telefon</th><th>Štítky</th><th>Návštěv</th><th>Naposledy</th><th>Bonus</th></tr>
      <?php foreach ($rows as $z):
          $st = zakaznik_statistika((int)$z['id']); $stitky = zakaznik_stitky($z, $st);
          if ($filtr && !array_filter($stitky, fn($s) => $s[1] === $filtr)) continue; ?>
        <tr onclick="location='/?r=zakaznik&id=<?= $z['id'] ?>'">
          <td><a href="/?r=zakaznik&id=<?= $z['id'] ?>"><?= h(jmeno_z($z)) ?></a></td>
          <td><?= h(telefon_cz($z['telefon'])) ?></td>
          <td><?= stitky_html($stitky) ?></td>
          <td><?= $st['pocet'] ?></td>
          <td><?= $st['posledni'] ? h(datum_cz($st['posledni']['zacatek'], false)) : '—' ?></td>
          <td><?= bonus_html(zakaznik_bonus($z)) ?></td>
        </tr>
      <?php endforeach ?>
    </table>
    <?php paticka();
}

/** Úklid: hromadné mazání zákazníků, kteří nikdy nebyli na masáži a jsou v kartotéce déle než X měsíců. Jen admin / hlavní pracovník. */
function stranka_uklid_zakaznici(array $u): void
{
    vyzaduj_tym();
    $mes = max(0, (int)($_GET['mes'] ?? $_POST['mes'] ?? 12));
    $kandidati = fn() => q("SELECT z.*,
            (SELECT COUNT(*) FROM rezervace r WHERE r.zakaznik_id=z.id) AS rez_vse
        FROM zakaznici z
        WHERE z.zdroj<>'vzor'
          AND NOT EXISTS (SELECT 1 FROM rezervace r WHERE r.zakaznik_id=z.id AND r.stav='probehlo')
          AND NOT EXISTS (SELECT 1 FROM rezervace r WHERE r.zakaznik_id=z.id AND r.zacatek IS NOT NULL AND r.zacatek >= datetime('now','localtime'))
          AND NOT EXISTS (SELECT 1 FROM poukazy p WHERE (p.zakaznik_id=z.id OR p.razitko_zak_id=z.id) AND p.stav IN ('objednano','zaplaceno','vydano'))
          AND z.vytvoreno <= datetime('now','localtime','-' || ? || ' months')
        ORDER BY z.vytvoreno", [$mes])->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['akce'] ?? '') === 'smaz') {
        if (($_POST['potvrzeni'] ?? '') !== 'SMAZAT') { flash('Pro smazání napište SMAZAT.', 'err'); presmeruj("/?r=uklid_zakaznici&mes=$mes"); }
        $vyber = array_map('intval', (array)($_POST['z'] ?? []));
        $moznosti = array_map(fn($r) => (int)$r['id'], $kandidati());   // smazat jde jen to, co je teď mezi kandidáty
        $smazat = array_values(array_intersect($vyber, $moznosti));
        if (!$smazat) { flash('Nikdo nebyl vybrán.', 'err'); presmeruj("/?r=uklid_zakaznici&mes=$mes"); }
        try { $n = zakaznici_smaz($smazat); flash("Smazáno $n zákazníků bez masáží."); }
        catch (Throwable $e) { flash('Smazání selhalo: ' . $e->getMessage(), 'err'); }
        presmeruj("/?r=uklid_zakaznici&mes=$mes");
    }

    $rows = $kandidati();
    hlavicka('Úklid zákazníků', 'zakaznici'); ?>
    <div class="cal-nav"><h1>🧹 Zákazníci bez masáží</h1><a class="btn" href="/?r=zakaznici">‹ Zpět na zákazníky</a></div>
    <p class="muted small">Zákazníci, kteří u vás nikdy nebyli na masáži, nemají objednanou budoucí rezervaci ani aktivní poukaz a jsou v kartotéce déle než zvolenou dobu (typicky importované kontakty, které se nikdy neobjednaly). Vzoroví zákazníci se sem nepočítají.</p>
    <p class="muted small">V kartotéce déle než:
      <?php foreach ([6 => '6 měsíců', 12 => '1 rok', 24 => '2 roky', 0 => 'bez omezení'] as $k => $t): ?>
        <a href="/?r=uklid_zakaznici&mes=<?= $k ?>"<?= $mes === $k ? ' class="on"' : '' ?>><?= $t ?></a> · <?php endforeach ?></p>

    <?php if (!$rows): ?>
      <p class="flash ok">Nikdo takový tu není. ✓</p>
    <?php else: ?>
      <form method="post" action="/?r=uklid_zakaznici&mes=<?= $mes ?>"><?= csrf_pole() ?><input type="hidden" name="akce" value="smaz"><input type="hidden" name="mes" value="<?= $mes ?>">
      <p><label><input type="checkbox" onclick="document.querySelectorAll('.uz').forEach(c=>c.checked=this.checked)"> <b>Vybrat všechny (<?= count($rows) ?>)</b></label></p>
      <table class="tbl">
        <tr><th></th><th>Jméno</th><th>Telefon</th><th>E-mail</th><th>Zdroj</th><th>V kartotéce od</th><th>Žádostí</th></tr>
        <?php foreach ($rows as $z): ?>
          <tr>
            <td><input type="checkbox" class="uz" name="z[]" value="<?= (int)$z['id'] ?>"></td>
            <td><a href="/?r=zakaznik&id=<?= (int)$z['id'] ?>" target="_blank"><?= h(jmeno_z($z)) ?></a></td>
            <td><?= h(telefon_cz($z['telefon'])) ?></td>
            <td class="small"><?= h($z['email']) ?></td>
            <td class="small"><?= h($z['zdroj']) ?></td>
            <td><?= h(datum_cz($z['vytvoreno'], false)) ?></td>
            <td><?= (int)$z['rez_vse'] ?></td>
          </tr>
        <?php endforeach ?>
      </table>
      <p class="muted small" style="margin-top:12px">Smazání je natrvalo a nelze ho vrátit. Poukazy (pokud nějaké byly) zůstanou, jen se u nich odpojí kupující.</p>
      <label class="small">Pro potvrzení napište <b>SMAZAT</b> <input name="potvrzeni" autocomplete="off" required pattern="SMAZAT" style="max-width:140px"></label>
      <button class="btn danger">Smazat vybrané</button>
      </form>
    <?php endif ?>
    <?php paticka();
}

/** JSON pro formulář rezervace: existuje zákazník s tímto telefonem? */
function stranka_hledat(array $u): void
{
    header('Content-Type: application/json; charset=utf-8');
    $tel = telefon_norm($_GET['tel'] ?? '');
    $z = $tel ? q1('SELECT * FROM zakaznici WHERE telefon=?', [$tel]) : null;
    if (!$z) { echo json_encode(['ok' => false]); exit; }
    $st = zakaznik_statistika((int)$z['id']); $b = zakaznik_bonus($z);
    $html = '<b>Známý zákazník:</b> ' . stitky_html(zakaznik_stitky($z, $st)) . ' ' . bonus_html($b)
        . ' · ' . $st['pocet'] . ' návštěv' . ($st['posledni'] ? ', naposledy ' . h(datum_cz($st['posledni']['zacatek'], false)) : '')
        . ($z['pref_text'] || $z['pref_tlak'] ? '<br>💬 ' . h(trim(($z['pref_tlak'] ? 'tlak ' . TLAK[$z['pref_tlak']] . '. ' : '') . mb_strimwidth($z['pref_text'], 0, 160, '…'))) : '')
        . ($z['blokovany'] ? '<br><b class="warn">⛔ Blokovaný – rozhodne vedoucí.</b>' : '')
        . ' <a href="/?r=zakaznik&id=' . $z['id'] . '" target="_blank">karta</a>';
    echo json_encode(['ok' => true, 'jmeno' => $z['jmeno'], 'prijmeni' => $z['prijmeni'], 'email' => $z['email'],
        'maserka_id' => $z['pref_maserka_id'], 'html' => $html], JSON_UNESCAPED_UNICODE);
    exit;
}

function stranka_zakaznik(array $u): void
{
    $id = (int)($_GET['id'] ?? 0);
    $novy = $id === 0 && isset($_GET['novy']);
    $z = $novy ? ['id' => 0, 'telefon' => '', 'jmeno' => '', 'prijmeni' => '', 'email' => '', 'poznamka_interni' => '', 'pref_tlak' => '', 'pref_maserka_id' => null,
                  'pref_text' => '', 'pref_text_en' => '', 'zdravi_text' => '', 'zdravi_text_en' => '', 'zdravi_souhlas' => null, 'volny_termin' => 0, 'volny_kdy' => '',
                  'blokovany' => 0, 'pocatecni_razitka' => 0, 'gdpr_souhlas' => null, 'zdroj' => 'recepce', 'vytvoreno' => nyni()]
                : q1('SELECT * FROM zakaznici WHERE id=?', [$id]);
    if ($novy && !empty($_GET['q'])) {   // předvyplnění z hledání
        $q = trim($_GET['q']);
        if (preg_match('~^[\d\s+()-]{6,}$~', $q)) $z['telefon'] = $q;
        else { $c = preg_split('~\s+~', $q, 2); $z['prijmeni'] = $c[0]; $z['jmeno'] = $c[1] ?? ''; }
    }
    if (!$z) { flash('Zákazník nenalezen.', 'err'); presmeruj('/?r=zakaznici'); }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['akce'] ?? '') === 'smaz_zakaznika' && !$novy) {
        vyzaduj_tym();   // smí jen admin nebo hlavní pracovník
        $jm = jmeno_z($z);
        try { zakaznici_smaz([$id]); flash("Zákazník $jm byl smazán."); presmeruj('/?r=zakaznici'); }
        catch (Throwable $e) { flash('Smazání selhalo: ' . $e->getMessage(), 'err'); presmeruj("/?r=zakaznik&id=$id"); }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$novy) pap_post("/?r=zakaznik&id=$id");   // papírová kartička (akce pap_*)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $p = $_POST;
        $tel = telefon_norm($p['telefon'] ?? '');
        $zpet = $novy ? '/?r=zakaznik&novy=1' : "/?r=zakaznik&id=$id";
        if ($tel === '') { flash('Neplatný telefon.', 'err'); presmeruj($zpet); }
        $dup = qv('SELECT id FROM zakaznici WHERE telefon=? AND id<>?', [$tel, $id]);
        if ($dup) { flash($novy ? 'Zákazník s tímto telefonem už existuje – otevřena jeho karta.' : 'Tento telefon už má jiný zákazník.', 'err'); presmeruj($novy ? "/?r=zakaznik&id=$dup" : $zpet); }
        if ($novy) {
            q("INSERT INTO zakaznici(telefon,jmeno,prijmeni,zdroj,gdpr_souhlas) VALUES(?,?,?,'recepce',NULL)", [$tel, trim($p['jmeno'] ?? ''), trim($p['prijmeni'] ?? '')]);
            $id = (int)db()->lastInsertId();
            $z = q1('SELECT * FROM zakaznici WHERE id=?', [$id]);
            zapis_log('zakaznik_novy', "#$id");
        }
        $zdraviSouhlas = !empty($p['zdravi_souhlas']) ? ($z['zdravi_souhlas'] ?: nyni()) : null;
        $zdravi = $zdraviSouhlas ? trim($p['zdravi_text'] ?? '') : '';
        q('UPDATE zakaznici SET telefon=?, jmeno=?, prijmeni=?, email=?, poznamka_interni=?, pref_tlak=?, pref_maserka_id=?, pref_text=?, pref_text_en=?,
            zdravi_text=?, zdravi_text_en=?, zdravi_souhlas=?, volny_termin=?, volny_kdy=?, blokovany=?, pocatecni_razitka=? WHERE id=?',
            [$tel, trim($p['jmeno'] ?? ''), trim($p['prijmeni'] ?? ''), trim($p['email'] ?? ''), trim($p['poznamka_interni'] ?? ''),
             array_key_exists($p['pref_tlak'] ?? '', TLAK) ? $p['pref_tlak'] : '', (int)($p['pref_maserka_id'] ?? 0) ?: null, trim($p['pref_text'] ?? ''), trim($p['pref_text_en'] ?? ''),
             $zdravi, $zdraviSouhlas ? trim($p['zdravi_text_en'] ?? '') : '', $zdraviSouhlas, !empty($p['volny_termin']) ? 1 : 0, trim($p['volny_kdy'] ?? ''), !empty($p['blokovany']) ? 1 : 0,
             max(0, (int)($p['pocatecni_razitka'] ?? 0)), $id]);
        zapis_log('zakaznik_uprava', "#$id");
        if ($zdravi !== '' && trim($p['zdravi_text_en'] ?? '') === '') flash('Uloženo. ⚠ Doplňte anglickou verzi zdravotního upozornění – masérka na tabletu jinak uvidí jen češtinu.', 'err');
        else flash($novy ? 'Zákazník založen.' : 'Uloženo.');
        presmeruj("/?r=zakaznik&id=$id");
    }

    if ($novy) {
        hlavicka('Nový zákazník', 'zakaznici'); ?>
        <h1>Nový zákazník <small>bez rezervace</small></h1>
        <p class="muted small">Založí zákazníka do kartotéky i bez rezervace – s preferencemi a upozorněními. Rezervaci mu pak vytvoříte z jeho karty.</p>
        <div class="cols"><div>
        <?php zakaznik_formular($z, true) ?>
        </div></div>
        <?php paticka(); return;
    }
    $st = zakaznik_statistika($id);
    $bonus = zakaznik_bonus($z);
    $poukazy = zakaznik_poukazy($id);
    $vse = q("SELECT r.*, v.popis AS var_popis, s.nazev AS sluzba, m.jmeno AS maserka FROM rezervace r
        LEFT JOIN varianty v ON v.id=r.varianta_id LEFT JOIN sluzby s ON s.id=v.sluzba_id LEFT JOIN maserky m ON m.id=r.maserka_id
        WHERE r.zakaznik_id=? ORDER BY COALESCE(r.zacatek, r.vytvoreno) DESC LIMIT 100", [$id])->fetchAll();

    hlavicka(jmeno_z($z), 'zakaznici'); ?>
    <h1><?= h(jmeno_z($z)) ?> <?= stitky_html(zakaznik_stitky($z, $st)) ?></h1>
    <div class="cols">
      <div>
        <?php zakaznik_formular($z, false) ?>

        <section class="card">
          <h2>Historie <span class="muted">(<?= count($vse) ?>)</span></h2>
          <table class="tbl">
            <tr><th>Kdy</th><th>Služba</th><th>Masérka</th><th>Stav</th><th>Platba</th><th>Poznámka</th></tr>
            <?php foreach ($vse as $r): ?>
              <tr onclick="location='/?r=rezervace&id=<?= $r['id'] ?>'">
                <td><?= h($r['zacatek'] ? datum_cz($r['zacatek']) : 'žádost ' . datum_cz($r['vytvoreno'], false)) ?></td>
                <td><?= h(($r['sluzba'] ?? '') . ' ' . ($r['var_popis'] ?? '')) ?></td>
                <td><?= h($r['maserka'] ?? '—') ?></td>
                <td><?= stav_html($r['stav']) ?></td>
                <td><?= h(PLATBY[$r['platba']] ?? '') ?></td>
                <td class="small"><?= h(mb_strimwidth($r['poznamka_masaz'], 0, 60, '…')) ?></td>
              </tr>
            <?php endforeach ?>
          </table>
        </section>
      </div>
      <div>
        <?php zakaznik_panel($z, $st, $bonus, $poukazy) ?>
        <section class="card side">
          <h3>Statistika</h3>
          <dl>
            <dt>Návštěv celkem</dt><dd><?= $st['pocet'] ?> (za 6 měs.: <?= $st['za6m'] ?>)</dd>
            <dt>Nepřišel / pozdní storno</dt><dd><?= $st['neprisel'] ?>× za 12 měs.</dd>
            <dt>Nejčastější služba</dt><dd><?= h($st['nejSluzba'] ?? '—') ?></dd>
            <dt>Nejčastější masérka</dt><dd><?= h($st['nejMaserka'] ?? '—') ?></dd>
            <dt>Zdroj</dt><dd><?= h($z['zdroj']) ?> · od <?= h(datum_cz($z['vytvoreno'], false)) ?></dd>
            <dt>Souhlas GDPR</dt><dd><?= $z['gdpr_souhlas'] ? h(datum_cz($z['gdpr_souhlas'])) : 'není zaznamenán' ?></dd>
          </dl>
        </section>
        <?php if (smi_upravit_tym($u)): $pocetPoukazu = count($poukazy);
            $co = []; if ($vse) $co[] = count($vse) . ' rezervací/žádostí'; if ($pocetPoukazu) $co[] = $pocetPoukazu . ' poukaz' . ($pocetPoukazu === 1 ? '' : ($pocetPoukazu < 5 ? 'y' : 'ů'));
            $potvrd = 'Opravdu natrvalo smazat zákazníka ' . jmeno_z($z) . '?' . ($vse ? ' Smaže se i jeho historie (' . count($vse) . ' rezervací/žádostí).' : '') . ($pocetPoukazu ? ' Poukazy zůstanou, jen se u nich odpojí kupující.' : '') . ' Nelze vrátit zpět.'; ?>
        <section class="card side">
          <h3>🗑 Smazat zákazníka</h3>
          <p class="muted small">Natrvalo odebere zákazníka z kartotéky<?= $vse ? ' i s jeho historií (' . count($vse) . ' rezervací/žádostí)' : '' ?>. Poukazy zůstanou. Nelze vrátit zpět.</p>
          <form method="post" action="/?r=zakaznik&id=<?= (int)$z['id'] ?>"><?= csrf_pole() ?><input type="hidden" name="akce" value="smaz_zakaznika">
            <button class="btn danger" data-confirm="<?= h($potvrd) ?>">Smazat zákazníka</button>
          </form>
        </section>
        <?php endif ?>
        <?php $hod = function_exists('hodnoceni_zakaznika') ? hodnoceni_zakaznika($id) : []; if ($hod): ?>
        <section class="card">
          <h3>Hodnocení zákazníka <span class="muted small">(zpětná vazba z e-mailu po masáži)</span></h3>
          <?php $prum = round(array_sum(array_map(fn($x) => (int)$x['hvezdy'], $hod)) / count($hod), 1); ?>
          <p class="muted small">Průměr <?= $prum ?> ★ z <?= count($hod) ?> hodnocení</p>
          <?php foreach ($hod as $x): ?>
            <div style="border-top:1px solid #efe7da;padding:8px 0">
              <b style="color:#c69c5d"><?php for ($i = 1; $i <= 5; $i++) echo $i <= (int)$x['hvezdy'] ? '★' : '☆'; ?></b>
              <span class="muted small"><?= h(datum_cz($x['hodnoceno'], false)) ?><?= $x['sluzba'] ? ' · ' . h($x['sluzba']) : '' ?></span>
              <?php if (trim((string)$x['komentar']) !== ''): ?><div class="small" style="margin-top:2px">„<?= h($x['komentar']) ?>“</div><?php endif ?>
            </div>
          <?php endforeach ?>
        </section>
        <?php endif ?>
      </div>
    </div>
    <?php paticka();
}

/** Formulář zákazníka (údaje, preference, zdraví, pro recepci) – pro novou i existující kartu. */
function zakaznik_formular(array $z, bool $novy): void
{ ?>
        <form method="post" class="card" action="<?= $novy ? '/?r=zakaznik&novy=1' : '/?r=zakaznik&id=' . (int)$z['id'] ?>">
          <?= csrf_pole() ?>
          <h2>Údaje</h2>
          <div class="grid3">
            <label>Telefon *<input name="telefon" required value="<?= h(telefon_cz($z['telefon'])) ?>"></label>
            <label>Jméno<input name="jmeno" value="<?= h($z['jmeno']) ?>"></label>
            <label>Příjmení<input name="prijmeni" value="<?= h($z['prijmeni']) ?>"></label>
          </div>
          <label>E-mail<input name="email" type="email" value="<?= h($z['email']) ?>"></label>

          <h2>Preference <span class="muted small">(recepce je probere se zákazníkem a předá masérce)</span></h2>
          <div class="grid3">
            <label>Síla tlaku<select name="pref_tlak"><?= moznosti(TLAK, $z['pref_tlak']) ?></select></label>
            <label>Oblíbená masérka<select name="pref_maserka_id"><?= maserky_select($z['pref_maserka_id'] ? (int)$z['pref_maserka_id'] : null, true, '— žádná —') ?></select></label>
            <label title="Razítka, která zákazník měl už před evidencí v kartotéce (převod ze staré kartičky). Další razítka počítá kartotéka sama.">Razítka převedená z dřívější kartičky<input type="number" name="pocatecni_razitka" min="0" value="<?= (int)$z['pocatecni_razitka'] ?>"></label>
          </div>
          <label>Co má rád / na co se zaměřit / čemu se vyhnout<textarea name="pref_text" rows="3" placeholder="např. na konci strečink, víc šíje, nemá rád šlapání po zádech, olej kokos, raději ticho"><?= h($z['pref_text']) ?></textarea></label>
          <label>🇬🇧 English version for the therapist (tablet) <span class="muted small">– napište ručně anglicky; na tabletu se ukáže místo češtiny</span><textarea name="pref_text_en" rows="2" lang="en" placeholder="e.g. extra stretching at the end, more neck work, no walking on the back, coconut oil, prefers quiet"><?= h($z['pref_text_en']) ?></textarea></label>

          <h2>Zdravotní upozornění <span class="muted small">(jen se souhlasem zákazníka)</span></h2>
          <label class="check"><input type="checkbox" name="zdravi_souhlas" value="1" <?= $z['zdravi_souhlas'] ? 'checked' : '' ?>> Zákazník souhlasí, že si zdravotní informaci zapíšeme<?= $z['zdravi_souhlas'] ? ' (od ' . h(datum_cz($z['zdravi_souhlas'], false)) . ')' : '' ?></label>
          <label><textarea name="zdravi_text" rows="2" placeholder="např. operované koleno – nešlapat, těhotná, vysoký tlak"><?= h($z['zdravi_text']) ?></textarea></label>
          <label>🇬🇧 English version for the therapist (tablet) <span class="muted small">– důležité, ať je správně</span><textarea name="zdravi_text_en" rows="2" lang="en" placeholder="e.g. operated knee – do not walk on it, pregnant, high blood pressure"><?= h($z['zdravi_text_en']) ?></textarea></label>
          <?php if ($z['zdravi_text'] && !$z['zdravi_text_en']): ?><p class="warn small">⚠ Chybí anglická verze – masérka na tabletu uvidí zdravotní upozornění jen česky.</p><?php endif ?>

          <h2>Pro recepci</h2>
          <label class="check"><input type="checkbox" name="volny_termin" value="1" <?= $z['volny_termin'] ? 'checked' : '' ?>> ⏱ Vezme i uvolněný termín na poslední chvíli</label>
          <label>Kdy může<input name="volny_kdy" value="<?= h($z['volny_kdy']) ?>" placeholder="např. všední dny dopoledne"></label>
          <label class="check"><input type="checkbox" name="blokovany" value="1" <?= $z['blokovany'] ? 'checked' : '' ?>> ⛔ Blokovaný (online jen žádost, rozhodne vedoucí)</label>
          <label>Interní poznámka <span class="muted small">(zákazník nikdy nevidí)</span><textarea name="poznamka_interni" rows="2"><?= h($z['poznamka_interni']) ?></textarea></label>
          <button class="btn primary"><?= $novy ? 'Založit zákazníka' : 'Uložit' ?></button>
        </form>
<?php }
