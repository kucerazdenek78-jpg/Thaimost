<?php
// Import kontaktů (Google Kontakty: CSV nebo vCard) do zákazníků. Dvoukrokově: náhled → potvrzení.

function import_tel(string $t): string
{
    $n = telefon_norm($t);
    if ($n !== '') return $n;
    $d = preg_replace('~\D~', '', $t);
    if (preg_match('~^420\d{9}$~', $d)) return '+' . $d;
    return '';
}

/** Rozloží obsah souboru (CSV z Google nebo vCard) na kontakty [jmeno, prijmeni, tel, email, dalsi_tel]. */
function import_parsuj(string $obsah): array
{
    if (str_starts_with($obsah, "\xFF\xFE") || str_starts_with($obsah, "\xFE\xFF")) $obsah = mb_convert_encoding($obsah, 'UTF-8', 'UTF-16');
    $obsah = preg_replace('~^\xEF\xBB\xBF~', '', $obsah);
    return stripos($obsah, 'BEGIN:VCARD') !== false ? import_vcf($obsah) : import_csv($obsah);
}

function import_kontakt(string $jmeno, string $prijmeni, string $nazev, array $tel, array $mail): array
{
    $jmeno = trim($jmeno); $prijmeni = trim($prijmeni);
    if ($jmeno === '' && $prijmeni === '' && trim($nazev) !== '') { $c = preg_split('~\s+~', trim($nazev), 2); $jmeno = $c[0]; $prijmeni = $c[1] ?? ''; }
    $telOk = []; foreach ($tel as $t) { $n = import_tel($t); if ($n !== '' && !in_array($n, $telOk, true)) $telOk[] = $n; }
    $mail = array_values(array_filter(array_map('trim', $mail)));
    return ['jmeno' => $jmeno, 'prijmeni' => $prijmeni, 'tel' => $telOk[0] ?? '', 'dalsi' => array_slice($telOk, 1), 'email' => $mail[0] ?? ''];
}

function import_csv(string $obsah): array
{
    $f = fopen('php://temp', 'r+'); fwrite($f, $obsah); rewind($f);
    $hlav = fgetcsv($f, 0, ',', '"', '');
    if (!$hlav) return [];
    $h = array_map(fn($x) => mb_strtolower(trim((string)$x)), $hlav);
    $idx = fn(array $nazvy) => array_values(array_filter(array_keys($h), fn($i) => in_array($h[$i], $nazvy, true)));
    $iJm = $idx(['first name', 'given name']); $iPr = $idx(['last name', 'family name']); $iNaz = $idx(['name', 'display name', 'file as']);
    $iTel = array_values(array_filter(array_keys($h), fn($i) => preg_match('~^phone( \d+)? - value$|^(primary|mobile|home|work) phone$|^phone$~', $h[$i])));
    $iMail = array_values(array_filter(array_keys($h), fn($i) => preg_match('~^e-?mail( \d+)? - value$|^e-?mail( address)?$|^e-?mail \d+ value$~', $h[$i])));
    $out = [];
    while (($r = fgetcsv($f, 0, ',', '"', '')) !== false) {
        if (!array_filter($r, fn($x) => trim((string)$x) !== '')) continue;
        $get = fn(array $ii) => array_map(fn($i) => (string)($r[$i] ?? ''), $ii);
        $tel = []; foreach ($get($iTel) as $v) foreach (explode(':::', $v) as $p) $tel[] = $p;
        $mail = []; foreach ($get($iMail) as $v) foreach (explode(':::', $v) as $p) $mail[] = $p;
        $out[] = import_kontakt($get($iJm)[0] ?? '', $get($iPr)[0] ?? '', $get($iNaz)[0] ?? '', $tel, $mail);
    }
    fclose($f);
    return $out;
}

function import_vcf(string $obsah): array
{
    $obsah = preg_replace("~\r?\n[ \t]~", '', $obsah);   // rozbalení zalomených řádků
    $out = [];
    foreach (preg_split('~BEGIN:VCARD~i', $obsah) as $blok) {
        if (stripos($blok, 'END:VCARD') === false) continue;
        $jm = $pr = $fn = ''; $tel = []; $mail = [];
        foreach (preg_split('~\r?\n~', $blok) as $l) {
            if (!preg_match('~^([A-Za-z.]+)(?:;[^:]*)?:(.*)$~', $l, $m)) continue;
            $k = strtoupper(preg_replace('~^.*\.~', '', $m[1])); $v = trim($m[2]);
            if ($k === 'N') { $p = explode(';', $v); $pr = $p[0] ?? ''; $jm = $p[1] ?? ''; }
            elseif ($k === 'FN') $fn = $v;
            elseif ($k === 'TEL') $tel[] = $v;
            elseif ($k === 'EMAIL') $mail[] = $v;
        }
        $out[] = import_kontakt($jm, $pr, $fn, $tel, $mail);
    }
    return $out;
}

function stranka_import(array $u): void
{
    session_start_safe();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = $_POST['akce'] ?? '';
        if ($akce === 'nahraj') {
            $fl = $_FILES['soubor'] ?? null;
            if (!$fl || $fl['error'] !== UPLOAD_ERR_OK || $fl['size'] > 8 * 1024 * 1024) { flash('Soubor se nepodařilo nahrát (max. 8 MB).', 'err'); presmeruj('/?r=import'); }
            $kontakty = import_parsuj((string)file_get_contents($fl['tmp_name']));
            if (!$kontakty) { flash('V souboru nejsou žádné kontakty. Použijte export z Google Kontaktů ve formátu Google CSV nebo vCard.', 'err'); presmeruj('/?r=import'); }
            $_SESSION['import'] = $kontakty;
            presmeruj('/?r=import');
        } elseif ($akce === 'potvrd' && !empty($_SESSION['import'])) {
            $vybrane = array_map('intval', (array)($_POST['vyber'] ?? []));
            $nove = 0; $doplnene = 0; $pozn = !empty($_POST['pozn_dalsi']);
            foreach ($_SESSION['import'] as $i => $k) {
                if (!in_array($i, $vybrane, true) || $k['tel'] === '') continue;
                $ex = q1('SELECT id FROM zakaznici WHERE telefon=?', [$k['tel']]);
                $id = zakaznik_najdi_nebo_zaloz($k['tel'], $k['jmeno'], $k['prijmeni'], $k['email'], 'google');
                if ($ex) $doplnene++; else $nove++;
                if ($pozn && $k['dalsi']) q("UPDATE zakaznici SET poznamka_interni = TRIM(poznamka_interni || CASE WHEN poznamka_interni='' THEN '' ELSE char(10) END || ?) WHERE id=? AND instr(poznamka_interni, ?) = 0",
                    ['Další telefon: ' . implode(', ', array_map('telefon_cz', $k['dalsi'])), $id, implode(', ', array_map('telefon_cz', $k['dalsi']))]);
            }
            unset($_SESSION['import']);
            zapis_log('import_kontaktu', "nových $nove, doplněných $doplnene");
            flash("Import hotov: $nove nových zákazníků, $doplnene už existovalo (doplněny chybějící údaje, nic se nepřepsalo).");
            presmeruj('/?r=zakaznici');
        } elseif ($akce === 'zrusit') { unset($_SESSION['import']); presmeruj('/?r=import'); }
        presmeruj('/?r=import');
    }

    $kont = $_SESSION['import'] ?? null;
    hlavicka('Import kontaktů', 'zakaznici'); ?>
    <div class="cal-nav"><h1>Import kontaktů z Google</h1><a class="btn" href="/?r=zakaznici">‹ Zákazníci</a></div>
    <?php if (!$kont): ?>
      <div class="cols"><div>
        <form method="post" enctype="multipart/form-data" class="card"><?= csrf_pole() ?><input type="hidden" name="akce" value="nahraj">
          <h2>1. Nahrajte exportovaný soubor</h2>
          <label>Soubor z Google Kontaktů (.csv nebo .vcf)<input type="file" name="soubor" accept=".csv,.vcf,text/csv,text/vcard" required></label>
          <button class="btn primary">Nahrát a ukázat náhled</button>
        </form>
      </div><div>
        <section class="card side"><h3>Jak soubor získat</h3>
          <ol class="small">
            <li>Otevřete <b>contacts.google.com</b> (pod účtem, kde máte kontakty).</li>
            <li>Vlevo <b>Exportovat</b> (případně nejdřív zaškrtněte jen vybrané kontakty / štítek).</li>
            <li>Formát <b>Google CSV</b> (nebo vCard) → Exportovat.</li>
            <li>Stažený soubor nahrajte vlevo.</li>
          </ol>
          <p class="muted small">Před uložením uvidíte náhled a vyberete, koho přidat. Existující zákazníci (stejný telefon) se nepřepisují, jen se doplní prázdné jméno / e-mail. Kontakty bez telefonu se přeskočí.</p>
        </section>
      </div></div>
    <?php else:
      $tels = array_column($kont, 'tel'); $vSouboru = array_count_values(array_filter($tels));
      $videno = []; ?>
      <form method="post"><?= csrf_pole() ?><input type="hidden" name="akce" value="potvrd">
        <p><b><?= count($kont) ?></b> kontaktů v souboru. Zaškrtnuté se importují.
          <a href="#" onclick="document.querySelectorAll('.imp').forEach(c=>{if(!c.disabled)c.checked=true});return false">vybrat vše</a> ·
          <a href="#" onclick="document.querySelectorAll('.imp').forEach(c=>c.checked=false);return false">nic</a> ·
          <a href="#" onclick="document.querySelectorAll('.imp').forEach(c=>{c.checked=!c.disabled&&c.dataset.nova==='1'});return false">jen nové</a></p>
        <table class="tbl">
          <tr><th></th><th>Příjmení</th><th>Jméno</th><th>Telefon</th><th>E-mail</th><th>Stav</th></tr>
          <?php foreach ($kont as $i => $k):
            $ex = $k['tel'] ? q1('SELECT id, jmeno, prijmeni FROM zakaznici WHERE telefon=?', [$k['tel']]) : null;
            $dup = $k['tel'] && isset($videno[$k['tel']]); if ($k['tel']) $videno[$k['tel']] = true;
            $bez = $k['tel'] === ''; ?>
            <tr<?= $bez || $dup ? ' class="muted"' : '' ?>>
              <td><input type="checkbox" class="imp" name="vyber[]" value="<?= $i ?>" data-nova="<?= !$ex ? 1 : 0 ?>" style="width:auto;margin:0" <?= $bez || $dup ? 'disabled' : (!$ex ? 'checked' : '') ?>></td>
              <td><?= h($k['prijmeni']) ?></td><td><?= h($k['jmeno']) ?></td>
              <td><?= h(telefon_cz($k['tel'])) ?><?= $k['dalsi'] ? ' <span class="muted small">+' . count($k['dalsi']) . ' další</span>' : '' ?></td>
              <td class="small"><?= h($k['email']) ?></td>
              <td class="small"><?= $bez ? 'bez telefonu – přeskočí se' : ($dup ? 'duplicita v souboru' : ($ex ? 'už existuje: <a href="/?r=zakaznik&id=' . $ex['id'] . '">' . h(trim($ex['jmeno'] . ' ' . $ex['prijmeni'])) . '</a>' : '🆕 nový')) ?></td>
            </tr>
          <?php endforeach ?>
        </table>
        <label class="check" style="margin-top:10px"><input type="checkbox" name="pozn_dalsi" value="1" checked> Další telefonní čísla kontaktu zapsat do interní poznámky</label>
        <button class="btn primary">Importovat vybrané</button>
        <button class="btn" name="akce" value="zrusit">Zrušit</button>
      </form>
    <?php endif;
    paticka();
}
