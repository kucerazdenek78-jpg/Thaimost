<?php
// Nastavení: masérky, ceník, prahy štítků, uživatelé, fronta e-mailů.

require_once __DIR__ . '/stranky_reklamy.php';
require_once __DIR__ . '/sluzby_web.php';   // texty služeb pro web (ceník)
// poukaz_pdf.php (motivy poukazu) načítá public/index.php – tady ho znovu nenačítat (index ho bere přes require)

function stranka_nastaveni(array $u): void
{
    if (($_GET['sekce'] ?? '') === 'reklamy') { stranka_reklamy($u); return; }
    if (($_GET['sekce'] ?? '') === 'posta') { stranka_posta($u); return; }
    if (($_GET['sekce'] ?? '') === 'platby') { stranka_platby($u); return; }
    if (($_GET['sekce'] ?? '') === 'cenik_tisk') { stranka_cenik_tisk(); return; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = $_POST['akce'] ?? '';
        if ($akce === 'poukaz_motivy') {
            // podklady poukazu (dárkový / vánoční) a vánoční období
            foreach (array_keys(POUKAZ_MOTIVY) as $m) {
                if (!empty($_POST["smazat_$m"])) { poukaz_motiv_smaz($m); flash('Nahraný podklad „' . POUKAZ_MOTIVY[$m] . '“ smazán.'); }
                if (!empty($_FILES["motiv_$m"]['name'])) {
                    $chyba = poukaz_motiv_nahraj($m, $_FILES["motiv_$m"]);
                    $chyba ? flash(POUKAZ_MOTIVY[$m] . ' poukaz: ' . $chyba, 'err') : flash(POUKAZ_MOTIVY[$m] . ' poukaz: podklad nahrán.');
                }
            }
            if (!empty($_POST['obnovit_vanocni'])) { q("DELETE FROM nastaveni WHERE klic IN ('vanocni_vychozi_ne','vanocni_pokus')"); poukaz_motiv_jpg('vanocni') ? flash('Připravený vánoční návrh je zpět.') : flash('Připravený vánoční návrh se nepodařilo stáhnout z GitHubu, zkuste to později.', 'err'); }
                        foreach (['vanoce_od', 'vanoce_do'] as $k) if (preg_match('~^\d{4}-(\d{2}-\d{2})$~', (string)($_POST[$k] ?? ''), $mm))
                q('INSERT INTO nastaveni(klic,hodnota) VALUES(?,?) ON CONFLICT(klic) DO UPDATE SET hodnota=excluded.hodnota', [$k, $mm[1]]);
            zapis_log('poukaz_motivy', '');
            presmeruj('/?r=nastaveni#motivy');
        }
        if ($akce === 'nastaveni') {
            foreach (['rezim_webu', 'tematicka_web', 'oteviraci_od', 'oteviraci_do', 'prestavka_min', 'tablet_predstih_min', 'prah_staly_navstev', 'prah_spici_dni', 'prah_nespolehlivy', 'bonus_po', 'bonus_pocita_poukaz', 'bonus_platnost_mesicu', 'hodnoceni_zapnuto'] as $k)
                if (isset($_POST[$k])) q('INSERT INTO nastaveni(klic,hodnota) VALUES(?,?) ON CONFLICT(klic) DO UPDATE SET hodnota=excluded.hodnota', [$k, trim($_POST[$k])]);
            // poukazy a platba převodem: číslo účtu (kontrola modulo 11), platnost poukazů v měsících
            if (isset($_POST['platba_ucet'])) {
                $uc = preg_replace('~\s+~', '', (string)$_POST['platba_ucet']);
                if ($uc !== '' && !ucet_rozloz($uc)) flash('Číslo účtu „' . $uc . '“ není platné (formát 123456789/0100 nebo 19-123456789/0800, kontrolní součet). Účet se neuložil.', 'err');
                else q('INSERT INTO nastaveni(klic,hodnota) VALUES(?,?) ON CONFLICT(klic) DO UPDATE SET hodnota=excluded.hodnota', ['platba_ucet', $uc]);
            }
            if (isset($_POST['poukaz_platnost_mes'])) q('INSERT INTO nastaveni(klic,hodnota) VALUES(?,?) ON CONFLICT(klic) DO UPDATE SET hodnota=excluded.hodnota', ['poukaz_platnost_mes', (string)max(1, min(36, (int)$_POST['poukaz_platnost_mes']))]);
            flash('Nastavení uloženo.');
        } elseif ($akce === 'maserky') {
            foreach ($_POST['m'] ?? [] as $id => $m) {
                if ((int)$id > 0) q('UPDATE maserky SET jmeno=?, aktivni=?, poradi=?, barva=?, ucet=? WHERE id=?',
                    [trim($m['jmeno']), !empty($m['aktivni']) ? 1 : 0, (int)$m['poradi'], $m['barva'] ?: '#b07a3a', trim($m['ucet'] ?? ''), (int)$id]);
            }
            if (trim($_POST['nova_maserka'] ?? '') !== '')
                q('INSERT INTO maserky(jmeno,poradi) VALUES(?, (SELECT COALESCE(MAX(poradi),0)+1 FROM maserky))', [trim($_POST['nova_maserka'])]);
            flash('Masérky uloženy.');
        } elseif ($akce === 'cenik') {
            // varianty: název varianty, minuty, cena, masérky…
            foreach ($_POST['v'] ?? [] as $id => $v) {
                $pop = trim((string)($v['popis'] ?? ''));
                q('UPDATE varianty SET cena=?, minuty=?, aktivni=?, maserky=?, maserky_min=?, wellness_mistnost=?' . ($pop !== '' ? ', popis=?' : '') . ' WHERE id=?',
                    array_merge([(int)$v['cena'], ($v['minuty'] ?? '') !== '' ? (int)$v['minuty'] : null, !empty($v['aktivni']) ? 1 : 0,
                    max(0, min(2, (int)($v['maserky'] ?? 1))), ($v['maserky_min'] ?? '') !== '' ? (int)$v['maserky_min'] : null, !empty($v['wellness_mistnost']) ? 1 : 0],
                    $pop !== '' ? [mb_substr($pop, 0, 120)] : [], [(int)$id]));
            }
            // texty služeb pro web (česky + překlady)
            $bezPrekladu = [];
            foreach ($_POST['s'] ?? [] as $id => $t) {
                $sl = q1('SELECT * FROM sluzby WHERE id=?', [(int)$id]);
                if (!$sl) continue;
                $nazev = trim((string)($t['nazev'] ?? '')) ?: $sl['nazev'];
                $popis = trim(preg_replace('~\s+~u', ' ', (string)($t['popis'] ?? '')));
                $pozn = trim(preg_replace('~\s+~u', ' ', (string)($t['pozn'] ?? '')));
                $stare = sluzba_preklady($sl); $pr = [];
                foreach (array_keys(SLUZBY_WEB_JAZYKY) as $jz) foreach (SLUZBY_WEB_POLE as $f) $pr[$jz][$f] = trim(preg_replace('~\s+~u', ' ', (string)($t[$jz][$f] ?? '')));
                // změnil se český text a překlad zůstal stejný → upozornit
                foreach (['nazev' => [$sl['nazev'], $nazev], 'popis' => [$sl['popis_web'], $popis], 'pozn' => [$sl['pozn_web'], $pozn]] as $f => [$a, $b])
                    if ($a !== $b) foreach (array_keys(SLUZBY_WEB_JAZYKY) as $jz) if ($b !== '' && $pr[$jz][$f] === $stare[$jz][$f]) $bezPrekladu[$nazev] = true;
                q('UPDATE sluzby SET nazev=?, popis_web=?, pozn_web=?, preklady=? WHERE id=?', [mb_substr($nazev, 0, 120), mb_substr($popis, 0, 1200), mb_substr($pozn, 0, 400), json_encode($pr, JSON_UNESCAPED_UNICODE), (int)$id]);
            }
            // nové položky u existujících služeb
            foreach ($_POST['nv'] ?? [] as $sid => $nv)
                if (sluzba_pridej_variantu((int)$sid, (string)($nv['popis'] ?? ''), $nv['minuty'] ?? '', $nv['cena'] ?? 0))
                    flash('Přidána položka ceníku „' . trim($nv['popis']) . '“ k „' . qv('SELECT nazev FROM sluzby WHERE id=?', [(int)$sid]) . '“.');
            // nová služba (masáž / wellness)
            $ns = $_POST['nova'] ?? [];
            if (trim((string)($ns['nazev'] ?? '')) !== '') {
                $nazev = mb_substr(trim($ns['nazev']), 0, 120);
                $polozky = array_filter($ns['v'] ?? [], fn($x) => trim((string)($x['popis'] ?? '')) !== '' && (int)($x['cena'] ?? 0) > 0);
                if (!$polozky) flash("Novou službu „{$nazev}“ jsem nepřidal – vyplňte aspoň jednu položku ceníku (např. „60 min“ a cenu).", 'err');
                else {
                    $kod = sluzba_novy_kod($nazev);
                    q("INSERT INTO sluzby(kod,nazev,kat,aktivni,poradi,popis_web,pozn_web,preklady) VALUES(?,?,?,1,(SELECT COALESCE(MAX(poradi),0)+1 FROM sluzby),?,?,'{}')",
                        [$kod, $nazev, ($ns['kat'] ?? '') === 'wellness' ? 'wellness' : 'masaze', mb_substr(trim(preg_replace('~\s+~u', ' ', (string)($ns['popis'] ?? ''))), 0, 1200),
                         mb_substr(trim((string)($ns['pozn'] ?? '')), 0, 400)]);
                    $sid = (int)db()->lastInsertId();
                    foreach ($polozky as $x) sluzba_pridej_variantu($sid, (string)$x['popis'], $x['minuty'] ?? '', $x['cena']);
                    zapis_log('sluzba_nova', "$kod $nazev");
                    flash("Přidána nová služba „{$nazev}“ – na webu se ukáže do minuty. Fotku a překlady doplníte u ní v ceníku.");
                }
            }
            // fotky služeb
            foreach (array_keys($_POST['smazat_foto'] ?? []) as $kod) { sluzba_foto_smaz((string)$kod); flash('Fotka smazána – na webu je zase ilustrační.'); }
            foreach ($_FILES['foto']['name'] ?? [] as $kod => $jm) {
                if ($jm === '' || $jm === null) continue;
                $f = ['name' => $jm, 'tmp_name' => $_FILES['foto']['tmp_name'][$kod], 'error' => $_FILES['foto']['error'][$kod], 'size' => $_FILES['foto']['size'][$kod]];
                $chyba = sluzba_foto_nahraj((string)$kod, $f);
                $nz = (string)qv('SELECT nazev FROM sluzby WHERE kod=?', [(string)$kod]);
                $chyba ? flash("$nz: $chyba", 'err') : flash("$nz: fotka nahrána.");
            }
            zapis_log('cenik', '');
            flash('Ceník uložen – na webu se změny ukážou do minuty.');
            if ($bezPrekladu) flash('Změnili jste český text u: ' . implode(', ', array_keys($bezPrekladu)) . '. Upravte i anglický a německý překlad (rozbalte „Překlady“), jinak web v angličtině a němčině ukáže starý text.', 'err');
            presmeruj('/?r=nastaveni&c=1#cenik');
        } elseif ($akce === 'uzivatel' && $u['role'] === 'admin') {
            $login = trim($_POST['login'] ?? ''); $heslo = $_POST['heslo'] ?? '';
            if ($login !== '' && strlen($heslo) >= 8) {
                q('INSERT INTO uzivatele(login,jmeno,heslo_hash,role) VALUES(?,?,?,?) ON CONFLICT(login) DO UPDATE SET heslo_hash=excluded.heslo_hash, jmeno=excluded.jmeno, role=excluded.role',
                    [$login, trim($_POST['jmeno'] ?? '') ?: $login, password_hash($heslo, PASSWORD_DEFAULT), array_key_exists($_POST['role'] ?? '', ROLE) ? $_POST['role'] : 'recepce']);
                flash("Uživatel $login uložen.");
            } else flash('Login a heslo (aspoň 8 znaků).', 'err');
        } elseif ($akce === 'demo_nahrat' && $u['role'] === 'admin') {
            try { flash(demo_nahrat()); } catch (Throwable $e) { flash('Vzorová data se nenahrála: ' . $e->getMessage(), 'err'); }
        } elseif ($akce === 'demo_smazat' && $u['role'] === 'admin') {
            if (($_POST['potvrzeni'] ?? '') !== 'SMAZAT') flash('Pro smazání napište SMAZAT.', 'err');
            else { try { flash(demo_smazat()); } catch (Throwable $e) { flash('Smazání selhalo: ' . $e->getMessage(), 'err'); } }
        } elseif ($akce === 'zkusebni_smazat' && $u['role'] === 'admin') {
            if (($_POST['potvrzeni'] ?? '') !== 'SMAZAT') flash('Pro smazání napište SMAZAT.', 'err');
            else { try { $m = zkusebni_smazat($_POST); $m ? flash($m) : flash('Nic nebylo vybráno.', 'err'); } catch (Throwable $e) { flash('Smazání selhalo: ' . $e->getMessage(), 'err'); } }
            presmeruj('/?r=nastaveni#zkusebni');
        } elseif ($akce === 'odeslat_emaily') {
            odesli_frontu(); flash('Fronta e-mailů zpracována.');
        }
        presmeruj('/?r=nastaveni');
    }
    $n = q('SELECT klic,hodnota FROM nastaveni')->fetchAll(PDO::FETCH_KEY_PAIR);
    $maserky = q('SELECT * FROM maserky ORDER BY poradi')->fetchAll();
    $cenik = q('SELECT v.*, s.nazev FROM varianty v JOIN sluzby s ON s.id=v.sluzba_id ORDER BY s.poradi, v.poradi')->fetchAll();
    $uziv = q('SELECT id,login,jmeno,role,aktivni FROM uzivatele ORDER BY login')->fetchAll();

    hlavicka('Nastavení', 'nastaveni'); ?>
    <h1>Nastavení</h1>
    <?php aplikace_karta(); ?>
    <?php if (je_admin($u)): ?>
    <section class="card"><h2>📇 Zákazníci v telefonu (Google kontakty)</h2>
      <p style="margin-top:0">Zákazníci z kartotéky se sami objeví v kontaktech telefonu i se značkami (⭐ stálý, 🎁 poukaz, 🎉 masáž zdarma, oblíbená masérka), takže při hovoru hned víte, kdo volá.
        <?php $gkp = gk_pripojeno(); ?><?= $gkp ? '<span class="tag zdarma">✅ Google účet připojený</span>' : '<span class="tag">zatím nepřipojeno</span>' ?></p>
      <a class="btn primary" href="/?r=google">Nastavit Google kontakty</a>
    </section>
    <?php endif ?>
    <div class="cols">
      <div>
        <form method="post" class="card">
          <?= csrf_pole() ?><input type="hidden" name="akce" value="nastaveni">
          <h2>Web a provoz</h2>
          <label>Rezervační formulář na webu<select name="rezim_webu"><?= moznosti(['zajem' => 'Zájem – zákazník napíše, kdy by se hodilo, recepce domluví', 'terminy' => 'Volné termíny – zákazník vidí volné časy (zatím nepoužívat)'], $n['rezim_webu']) ?></select></label>
          <label>✨ Tematická masérna na webu<select name="tematicka_web"><?= moznosti(['1' => 'Zapnuto – web ukazuje sekci s atmosférami a výběr atmosféry v objednávce', '0' => 'Vypnuto – na webu se o tematické masérně nic nezobrazí (v kartotéce zůstává)'], (string)($n['tematicka_web'] ?? '1')) ?></select>
            <span class="muted small">Projeví se do pár vteřin bez zásahu do webu (web se ptá kartotéky). Platí pro češtinu, angličtinu i němčinu.</span></label>
          <div class="grid3">
            <label>Otevřeno od<input type="time" name="oteviraci_od" value="<?= h($n['oteviraci_od']) ?>"></label>
            <label>do<input type="time" name="oteviraci_do" value="<?= h($n['oteviraci_do']) ?>"></label>
            <label>Přestávka mezi masážemi (min)<input type="number" name="prestavka_min" min="0" max="60" step="5" value="<?= h($n['prestavka_min'] ?? '10') ?>"></label>
          </div>
          <div class="grid3">
            <label>Na tablet se pošle (min před začátkem)<input type="number" name="tablet_predstih_min" min="0" max="180" step="5" value="<?= h($n['tablet_predstih_min'] ?? '20') ?>"></label>
          </div>
          <p class="muted small">Rezervace se na tablet v masérně posílají samy tolik minut před začátkem (jakmile je tablet volný a rezervace má masérku). Ruční posílání už není potřeba.</p>
          <h2 id="poukazy-platba">🎁 Poukazy a platba převodem</h2>
          <div class="grid3">
            <label>Číslo účtu salonu<input name="platba_ucet" value="<?= h(salon_ucet()) ?>" placeholder="123456789/0100" inputmode="numeric" autocomplete="off"></label>
            <label>Platnost poukazu (měsíce od nákupu)<input type="number" name="poukaz_platnost_mes" min="1" max="36" value="<?= h((string)poukaz_platnost_mes()) ?>"></label>
          </div>
          <p class="muted small">S číslem účtu nabízí web u objednávky poukazu <b>platbu převodem s QR kódem</b> (variabilní symbol = číslo poukazu) a platba se pak sama spáruje v Platbách. Bez účtu jen platbu v salonu.
            <?= salon_ucet() !== '' ? 'IBAN: <b>' . h(chunk_split(ucet_iban(salon_ucet()), 4, ' ')) . '</b>.' : '' ?>
            Platnost se poukazu nastaví při nákupu a je na něm vytištěná – do toho data se musí zákazník objednat.</p>
          <h2>Štítky zákazníků</h2>
          <div class="grid3">
            <label>⭐ Stálý: návštěv za 6 měs.<input type="number" name="prah_staly_navstev" value="<?= h($n['prah_staly_navstev']) ?>"></label>
            <label>💤 Spící: dní bez návštěvy<input type="number" name="prah_spici_dni" value="<?= h($n['prah_spici_dni']) ?>"></label>
            <label>⚠️ Nespolehlivý: nepřišel ×<input type="number" name="prah_nespolehlivy" value="<?= h($n['prah_nespolehlivy']) ?>"></label>
          </div>
          <h2>Bonus</h2>
          <div class="grid3">
            <label>Zdarma po kolika zaplacených<input type="number" name="bonus_po" value="<?= h($n['bonus_po']) ?>"></label>
            <label>Masáž na poukaz se počítá<select name="bonus_pocita_poukaz"><?= moznosti(['0' => 'ne', '1' => 'ano'], $n['bonus_pocita_poukaz']) ?></select><span class="muted small">Razítko dostane kupující poukazu – jedno za každý zaplacený poukaz.</span></label>
            <label>Platnost karty (měsíců)<input type="number" name="bonus_platnost_mesicu" min="0" max="60" value="<?= h($n['bonus_platnost_mesicu'] ?? '12') ?>"><span class="muted small">Karta platí tolik měsíců od poslední zapsané masáže; bez další návštěvy razítka propadají. 0 = bez expirace.</span></label>
            <label>Hodnocení po masáži e-mailem<select name="hodnoceni_zapnuto"><?= moznosti(['1' => 'posílat', '0' => 'neposílat'], $n['hodnoceni_zapnuto'] ?? '1') ?></select><span class="muted small">Po proběhlé masáži pošleme zákazníkovi (s e-mailem) odkaz na ohodnocení spokojenosti; výsledek se uloží k zákazníkovi do karty.</span></label>
          </div>
          <button class="btn primary">Uložit</button>
        </form>

        <form method="post" class="card" id="motivy" enctype="multipart/form-data">
          <?= csrf_pole() ?><input type="hidden" name="akce" value="poukaz_motivy">
          <h2>🎄 Motivy poukazu</h2>
          <p class="muted small" style="margin-top:0">Podklad = celý poukaz bez vyplněných údajů jako obrázek JPEG 210 × 100 mm (ideálně 2381 × 1134 px). Políčka Jméno / Procedura / Platnost, kód a QR musí být na stejných místech jako ve výchozím dárkovém návrhu – kartotéka do nich jen dopisuje.</p>
          <div class="motivy-grid">
          <?php foreach (POUKAZ_MOTIVY as $m => $nazev): $vl = poukaz_motiv_jpg($m); ?>
            <div class="motiv">
              <b><?= h($nazev) ?> poukaz</b>
              <?php if (poukaz_motiv_dostupny($m)): ?><img src="<?= h(poukaz_motiv_url($m)) ?>" alt="<?= h($nazev) ?> poukaz" loading="lazy"><?php else: ?><div class="motiv-prazdny">zatím nenahraný – motiv nejde vybrat</div><?php if ($m === 'vanocni'): ?><label class="check"><input type="checkbox" name="obnovit_vanocni" value="1"> použít připravený vánoční návrh</label><?php endif ?><?php endif ?>
              <span class="muted small"><?= $vl ? 'podklad z ' . h(date('j. n. Y', filemtime($vl))) : ($m === 'darkovy' ? 'výchozí návrh' : '') ?></span>
              <label>Nahrát nový podklad (.jpg)<input type="file" name="motiv_<?= $m ?>" accept="image/jpeg"></label>
              <?php if ($vl): ?><label class="check"><input type="checkbox" name="smazat_<?= $m ?>" value="1"> smazat<?= $m === 'darkovy' ? ' (vrátí se výchozí návrh)' : ' (vánoční motiv nepůjde vybrat)' ?></label><?php endif ?>
            </div>
          <?php endforeach ?>
          </div>
          <div class="grid3">
            <label>Vánoční motiv přednastavit od<input type="date" name="vanoce_od" value="<?= date('Y') . '-' . h((string)nastaveni('vanoce_od', '11-01')) ?>"></label>
            <label>do<input type="date" name="vanoce_do" value="<?= date('Y') . '-' . h((string)nastaveni('vanoce_do', '12-24')) ?>"></label>
          </div>
          <p class="muted small">V tomto období se na webu i v kartotéce předvybere vánoční poukaz (pokud je nahraný); vždy jde přepnout na dárkový. Teď: <b><?= h(POUKAZ_MOTIVY[poukaz_motiv_vychozi()]) ?></b>.</p>
          <button class="btn primary">Uložit</button>
        </form>

        <section class="card" id="reklamy"><h2>📣 Reklamní materiály</h2>
          <p style="margin-top:0">Hotové reklamy na Facebook a Instagram: obrázky, reely a texty ke stažení a zkopírování.</p>
          <p><a class="btn primary" href="/?r=nastaveni&amp;sekce=reklamy">Otevřít reklamní materiály</a></p>
        </section>
        <section class="card" id="tym"><h2>Tým</h2>
          <p class="muted small">Správa lidí a provozu na jednom místě.</p>
          <p><a class="btn primary" href="/?r=tym">👥 Tým – masérky</a> <a class="btn" href="/?r=recepcni">🧾 Recepční</a> <a class="btn" href="/?r=smeny">🗓 Směny</a>
             <a class="btn" href="/?r=masterny">🚪 Masérny a tablety</a> <a class="btn" href="/?r=dostupnost">🌴 Volna a přesčasy</a><?php if (($u['role'] ?? '') === 'admin'): ?> <a class="btn" href="/?r=uzivatele">🔑 Uživatelé</a><?php endif ?></p>
        </section>

        <?php
        $sluzbyC = q('SELECT * FROM sluzby ORDER BY poradi, id')->fetchAll();
        $varC = []; foreach ($cenik as $c) $varC[$c['sluzba_id']][] = $c;
        $vlastnich = 0; foreach ($sluzbyC as $sc) if ($sc['kod'] && sluzba_foto_soubor($sc['kod'])) $vlastnich++;
        ?>
        <form method="post" class="card" id="cenik" enctype="multipart/form-data">
          <?= csrf_pole() ?><input type="hidden" name="akce" value="cenik">
          <details class="rozbal"<?= ($_GET['c'] ?? '') === '1' ? ' open' : '' ?>><summary><h2>Ceník a služby na webu <span class="muted small">(<?= count($sluzbyC) ?> služeb, <?= count($cenik) ?> položek · vlastních fotek <?= $vlastnich ?> – klepnutím rozbalíte)</span></h2></summary>
          <p><a class="btn" href="/?r=nastaveni&amp;sekce=cenik_tisk" target="_blank">🖨 Ceník A4 k tisku (PDF)</a> <span class="muted small">česky, anglicky i německy – z uložených cen a textů</span></p>
          <p class="muted small">U každé služby upravíte název, popis a poznámku na webu (i anglicky a německy), fotku a jednotlivé položky ceníku. Změny se na webu ukážou do minuty po uložení.<br>
            Masérek: 0 = bez masérky (whirlpool, sauna), 2 = párová. „Masérky min“ = jak dlouho jsou masérky potřeba (balíček párová + whirlpool: 60, zbytek jsou zákazníci sami). „Celá wellness“ = zabere obě lůžka dvoulůžkové masérny.</p>
          <?php foreach ($sluzbyC as $sc): $sid = (int)$sc['id']; $pr = sluzba_preklady($sc); $furl = $sc['kod'] ? sluzba_foto_url($sc['kod']) : null;
                $ceny = array_map(fn($c) => kc($c['cena']), array_filter($varC[$sid] ?? [], fn($c) => $c['aktivni'])); ?>
            <details class="cs-blok">
              <summary><b><?= h($sc['nazev']) ?></b> <span class="muted small"><?= h(implode(' · ', $ceny)) ?></span><?= $furl ? ' <span class="tag zdarma">📷 vlastní fotka</span>' : '' ?></summary>
              <div class="cs-hlava">
                <?php if ($sc['kod']): ?>
                <div class="sf">
                  <div class="sf-img"><img src="<?= h($furl ?: REKLAMY_WEB . 'assets/img/ai/' . $sc['kod'] . '.jpg') ?>" alt="" loading="lazy" onerror="this.style.visibility='hidden'"><span class="<?= $furl ? 'sf-ok' : 'sf-ilu' ?>"><?= $furl ? '✓ vlastní' : 'ilustrační' ?></span></div>
                  <input type="file" name="foto[<?= h($sc['kod']) ?>]" accept="image/*" class="sf-in"><span class="muted small sf-st"></span>
                  <?php if ($furl): ?><label class="check small"><input type="checkbox" name="smazat_foto[<?= h($sc['kod']) ?>]" value="1"> smazat fotku (zpět ilustrační)</label><?php endif ?>
                </div>
                <?php endif ?>
                <div class="cs-texty">
                  <label>Název<input name="s[<?= $sid ?>][nazev]" value="<?= h($sc['nazev']) ?>" maxlength="120" required></label>
                  <label>Popis na webu<textarea name="s[<?= $sid ?>][popis]" rows="3" maxlength="1200"><?= h($sc['popis_web']) ?></textarea></label>
                  <label>Poznámka <span class="muted small">(nepovinné, např. doporučené oblečení)</span><input name="s[<?= $sid ?>][pozn]" value="<?= h($sc['pozn_web']) ?>" maxlength="400"></label>
                  <details class="cs-preklad"><summary class="small">🇬🇧 🇩🇪 Překlady pro anglickou a německou verzi webu</summary>
                    <?php foreach (SLUZBY_WEB_JAZYKY as $jz => $jn): ?>
                      <p class="small" style="margin:8px 0 2px"><b><?= h($jn) ?></b></p>
                      <label class="small">Název<input name="s[<?= $sid ?>][<?= $jz ?>][nazev]" value="<?= h($pr[$jz]['nazev']) ?>" lang="<?= $jz ?>"></label>
                      <label class="small">Popis<textarea name="s[<?= $sid ?>][<?= $jz ?>][popis]" rows="3" lang="<?= $jz ?>"><?= h($pr[$jz]['popis']) ?></textarea></label>
                      <label class="small">Poznámka<input name="s[<?= $sid ?>][<?= $jz ?>][pozn]" value="<?= h($pr[$jz]['pozn']) ?>" lang="<?= $jz ?>"></label>
                    <?php endforeach ?>
                    <p class="muted small">Prázdný překlad = web v tom jazyce ukáže český text.</p>
                  </details>
                </div>
              </div>
              <table class="tbl plain cs-var">
                <tr><th>Položka ceníku</th><th>Minut</th><th>Kč</th><th title="Kolik masérek služba potřebuje">Masérek</th><th title="Jak dlouho od začátku jsou masérky potřeba – prázdné = celou dobu">Masérky min</th><th title="Zabírá celou dvoulůžkovou / wellness masérnu">Celá wellness</th><th>Aktivní</th></tr>
                <?php foreach ($varC[$sid] ?? [] as $c): ?>
                  <tr><td><input name="v[<?= $c['id'] ?>][popis]" value="<?= h($c['popis']) ?>" maxlength="120" style="min-width:9em"></td>
                      <td><input type="number" name="v[<?= $c['id'] ?>][minuty]" value="<?= h($c['minuty'] ?? '') ?>" style="width:4.5em"></td>
                      <td><input type="number" name="v[<?= $c['id'] ?>][cena]" value="<?= $c['cena'] ?>" style="width:5.5em"></td>
                      <td><select name="v[<?= $c['id'] ?>][maserky]"><?= moznosti([0 => '0', 1 => '1', 2 => '2'], (int)$c['maserky']) ?></select></td>
                      <td><input type="number" name="v[<?= $c['id'] ?>][maserky_min]" value="<?= h($c['maserky_min'] ?? '') ?>" style="width:4.5em" placeholder="celou"></td>
                      <td><input type="checkbox" name="v[<?= $c['id'] ?>][wellness_mistnost]" value="1" <?= $c['wellness_mistnost'] ? 'checked' : '' ?>></td>
                      <td><input type="checkbox" name="v[<?= $c['id'] ?>][aktivni]" value="1" <?= $c['aktivni'] ? 'checked' : '' ?>></td></tr>
                <?php endforeach ?>
                <tr class="cs-nova"><td><input name="nv[<?= $sid ?>][popis]" placeholder="+ nová položka, např. 90 min" maxlength="120" style="min-width:9em"></td>
                    <td><input type="number" name="nv[<?= $sid ?>][minuty]" placeholder="min" style="width:4.5em"></td>
                    <td><input type="number" name="nv[<?= $sid ?>][cena]" placeholder="Kč" style="width:5.5em"></td><td colspan="4" class="muted small">vyplňte a uložte ceník</td></tr>
              </table>
            </details>
          <?php endforeach ?>
          <details class="cs-blok cs-nova-sl"<?= ($_GET['nova'] ?? '') === '1' ? ' open' : '' ?>>
            <summary><b>➕ Přidat novou masáž / službu</b></summary>
            <div style="padding:12px">
              <div class="grid3">
                <label>Název *<input name="nova[nazev]" maxlength="120" placeholder="např. Thajská masáž s horkými kameny"></label>
                <label>Kategorie<select name="nova[kat]"><option value="masaze">Masáže</option><option value="wellness">Wellness a rituály</option></select></label>
              </div>
              <label>Popis na webu<textarea name="nova[popis]" rows="3" maxlength="1200" placeholder="co masáž obnáší, pro koho je vhodná…"></textarea></label>
              <label>Poznámka <span class="muted small">(nepovinné)</span><input name="nova[pozn]" maxlength="400"></label>
              <table class="tbl plain cs-var" style="margin:6px 0">
                <tr><th>Položka ceníku</th><th>Minut</th><th>Kč</th></tr>
                <?php for ($i = 0; $i < 3; $i++): ?>
                  <tr><td><input name="nova[v][<?= $i ?>][popis]" placeholder="<?= ['60 min', '90 min', '120 min'][$i] ?>" maxlength="120"></td>
                      <td><input type="number" name="nova[v][<?= $i ?>][minuty]" style="width:4.5em"></td>
                      <td><input type="number" name="nova[v][<?= $i ?>][cena]" style="width:5.5em"></td></tr>
                <?php endfor ?>
              </table>
              <p class="muted small">Stačí vyplnit jednu položku. Po uložení se služba objeví v ceníku – tam pak doplníte fotku, překlady a počet masérek. Na webu bude zařazená na konec své kategorie; dokud nemá fotku, ukáže se místo ní lotos.</p>
            </div>
          </details>
          <p><button class="btn primary">Uložit ceník</button></p>
          </details>
        </form>
        <script>
        // zmenšení fotky v prohlížeči (max. 1600 px, JPEG) – fotka z telefonu má jinak i 5 MB a server ji nemusí přijmout
        document.querySelectorAll('.sf-in').forEach(function (inp) {
          inp.addEventListener('change', function () {
            var f = inp.files && inp.files[0]; if (!f || !window.DataTransfer) return;
            var st = inp.parentElement.querySelector('.sf-st'), img = new Image(), url = URL.createObjectURL(f);
            st.textContent = 'Připravuji fotku…';
            img.onload = function () {
              var k = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight)), c = document.createElement('canvas');
              c.width = Math.round(img.naturalWidth * k); c.height = Math.round(img.naturalHeight * k);
              c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(url);
              c.toBlob(function (b) {
                if (!b) { st.textContent = ''; return; }
                var dt = new DataTransfer(); dt.items.add(new File([b], f.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }));
                inp.files = dt.files;
                inp.parentElement.querySelector('img').src = URL.createObjectURL(b);
                st.textContent = 'Připraveno (' + Math.round(b.size / 1024) + ' kB) – nahraje se tlačítkem „Uložit ceník“.';
              }, 'image/jpeg', 0.85);
            };
            img.onerror = function () { URL.revokeObjectURL(url); st.textContent = 'Fotku se nepodařilo načíst – zkuste JPG.'; };
            img.src = url;
          });
        });
        </script>
      </div>
      <div>
        <?php demo_karta($u) ?>
        <?php zkusebni_karta($u) ?>
        <?php [$postaProblem, $postaTxt] = posta_souhrn(); ?>
        <section class="card side">
          <h3>✉️ Pošta <?= $postaProblem ? '<b class="cnt">' . $postaProblem . '</b>' : '' ?></h3>
          <p class="small"><?php foreach ($postaTxt as $t): ?><?= h($t) ?><br><?php endforeach ?>
          Upozornění chodí na <b><?= h(implode(', ', posta_upozorneni()) ?: '— nenastaveno —') ?></b>.</p>
          <p><a class="btn primary" href="/?r=nastaveni&amp;sekce=posta">Nastavit a hlídat poštu</a> <a class="btn" href="/?r=emaily">Fronta</a></p>
        </section>
        <?php $nPlateb = platby_ke_sparovani(); ?>
        <section class="card side">
          <h3>💳 Platby <?= $nPlateb ? '<b class="cnt">' . $nPlateb . '</b>' : '' ?></h3>
          <p class="small"><?= platby_nastaveno() ? 'Avíza z banky se čtou ze schránky <b>' . h(platby_ucet()['user']) . '</b> a párují se s objednanými poukazy podle VS a částky.' : '<b class="warn">Schránka pro platby není nastavená.</b> Nastavíte ji v Pošta → 💳 Platby.' ?></p>
          <p><a class="btn primary" href="/?r=nastaveni&amp;sekce=platby">Párování plateb</a></p>
        </section>
        <section class="card side">
          <h3>Uživatelé <a class="small" href="/?r=uzivatele">správa →</a></h3>
          <?php foreach ($uziv as $x): ?><p><a href="/?r=uzivatele&id=<?= (int)($x['id'] ?? 0) ?>"><?= h($x['jmeno']) ?></a> <span class="muted small">(<?= h($x['login']) ?>, <?= h($x['role']) ?>)</span></p><?php endforeach ?>
          <?php if ($u['role'] === 'admin'): ?>
          <form method="post"><?= csrf_pole() ?><input type="hidden" name="akce" value="uzivatel">
            <label>Login<input name="login" required></label>
            <label>Jméno<input name="jmeno"></label>
            <label>Heslo (nové / změna)<input name="heslo" type="password" minlength="8" required></label>
            <label>Role<select name="role"><?= moznosti(ROLE, 'recepce') ?></select></label>
            <button class="btn">Uložit uživatele</button>
          </form>
          <?php endif ?>
        </section>
      </div>
    </div>
    <?php paticka();
}

function stranka_emaily(array $u): void
{
    $uc = isset(POSTA_UCTY[$_GET['ucet'] ?? '']) ? $_GET['ucet'] : '';
    $rows = $uc ? q('SELECT * FROM emaily WHERE ucet=? ORDER BY id DESC LIMIT 100', [$uc])->fetchAll()
                : q('SELECT * FROM emaily ORDER BY id DESC LIMIT 100')->fetchAll();
    $stavy = ['ceka' => '⏳ čeká', 'odeslano' => '✅ odesláno', 'chyba' => '❌ chyba'];
    hlavicka('E-maily', 'posta'); ?>
    <p class="muted small"><a href="/?r=nastaveni&amp;sekce=posta">← Pošta</a></p>
    <h1>Fronta e-mailů</h1>
    <p><a class="chip<?= $uc === '' ? ' on' : '' ?>" href="/?r=emaily">Vše</a>
      <?php foreach (POSTA_UCTY as $k => $x): ?><a class="chip<?= $uc === $k ? ' on' : '' ?>" href="/?r=emaily&amp;ucet=<?= h($k) ?>"><?= h($x[0]) ?></a> <?php endforeach ?></p>
    <table class="tbl">
      <tr><th>Kdy</th><th>Účet</th><th>Komu</th><th>Předmět</th><th>Stav</th></tr>
      <?php foreach ($rows as $e): ?>
        <tr><td class="small"><?= h(datum_cz($e['vytvoreno'])) ?></td><td class="small"><?= h(POSTA_UCTY[$e['ucet']][0] ?? $e['ucet']) ?></td><td><?= h($e['komu']) ?></td><td><?= h($e['predmet']) ?><details><summary class="muted small">text</summary><pre><?= h($e['text']) ?></pre></details></td>
            <td><?= h($stavy[$e['stav']] ?? $e['stav']) ?><?= $e['odeslano'] ? '<br><span class="muted small">' . h(datum_cz($e['odeslano'])) . '</span>' : '' ?><?= $e['chyba'] ? '<br><span class="warn small">' . h($e['chyba']) . '</span>' : '' ?></td></tr>
      <?php endforeach ?>
    </table>
    <?php paticka();
}

/** Sekce „Aplikace Asistent“: instalace do telefonu (Android/Chrome), QR kód pro otevření v telefonu. */
function aplikace_karta(): void
{
    $url = rtrim(cfg()['url'] ?? '', '/') . '/?r=ai&app=1'; ?>
    <section class="card aplikace">
      <h2>📲 Aplikace Asistent do telefonu</h2>
      <div class="aplikace-in">
        <img src="/asistent-192.png" alt="" width="72" height="72">
        <div class="grow">
          <p style="margin-top:0">Asistent jako samostatná aplikace s ikonou na ploše: ptáte se hlasem nebo psaním, asistent připraví rezervace, přesuny, potvrzení žádostí i volna masérek a vy je jen odklepnete „Provést“.</p>
          <p><button type="button" class="btn primary" id="appInstal" hidden>⬇ Nainstalovat aplikaci</button>
             <span id="appHotovo" class="tag zdarma" hidden>✅ Aplikace je nainstalovaná</span>
             <a class="btn" href="/?r=ai&amp;app=1">Otevřít aplikaci</a></p>
          <ol class="small" id="appNavod">
            <li>V telefonu s Androidem otevřete v <b>Chromu</b> odkaz níže (nebo naskenujte QR kód fotoaparátem).</li>
            <li>Přihlaste se a zaškrtněte <b>Zůstat přihlášen</b>.</li>
            <li>V Chromu klepněte na <b>⋮ → Nainstalovat aplikaci</b> (případně „Přidat na plochu“). Ikona 🤖 Asistent se objeví na ploše.</li>
            <li>Napoprvé u 🎤 povolte mikrofon.</li>
          </ol>
          <p class="small">Odkaz: <code><?= h($url) ?></code></p>
        </div>
        <div class="aplikace-qr" id="appQr" data-qr="<?= h($url) ?>" title="Naskenujte telefonem"></div>
      </div>
    </section>
    <script src="/qrcode.js"></script>
    <script>
    (function(){
      var e=document.getElementById('appQr');try{var q=qrcode(0,'M');q.addData(e.dataset.qr);q.make();e.innerHTML=q.createSvgTag({cellSize:4,margin:2,scalable:true})}catch(x){e.textContent=e.dataset.qr}
      if('serviceWorker' in navigator)navigator.serviceWorker.register('/sw-asistent.js').catch(function(){});
      var b=document.getElementById('appInstal'),ok=document.getElementById('appHotovo'),ev=null;
      if(matchMedia('(display-mode: standalone)').matches){ok.hidden=false}
      window.addEventListener('beforeinstallprompt',function(x){x.preventDefault();ev=x;b.hidden=false});
      window.addEventListener('appinstalled',function(){b.hidden=true;ok.hidden=false});
      b.addEventListener('click',function(){if(!ev)return;ev.prompt();ev.userChoice.then(function(){ev=null;b.hidden=true})});
    })();
    </script>
<?php
}
