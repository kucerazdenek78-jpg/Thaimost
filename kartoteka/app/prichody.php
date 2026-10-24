<?php
// Příchody: zákazníci chodí ~10 min před masáží, často po vlnách pro všechny masérny najednou.
// Panel na stránce Dnes navrhne rozdělení do masérn a masérek (recepce jen přečte, kam kdo jde),
// jedním klepnutím se všechno pošle na tablety; jednotlivé řádky jdou před odesláním přehodit.
// Také: ukončení masáže z recepce s důvodem a upozornění vedoucí.

/** Dnešní potvrzené rezervace kolem teď: začínají nejpozději za 45 min, začaly maximálně před 30 min a ještě neskončily. */
function prichody_okno(): array
{
    $od = date('Y-m-d H:i:s', time() - 30 * 60);
    $do = date('Y-m-d H:i:s', time() + 45 * 60);
    return q("SELECT r.*, z.jmeno, z.prijmeni, z.telefon, z.pref_maserka_id, z.pref_tlak, z.pref_text,
            s.nazev AS sluzba, v.popis AS var_popis, COALESCE(v.maserky, 1) AS var_maserky, COALESCE(v.wellness_mistnost, 0) AS wellness_mistnost,
            m.jmeno AS maserka, m2.jmeno AS maserka2
        FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id LEFT JOIN varianty v ON v.id=r.varianta_id
        LEFT JOIN sluzby s ON s.id=v.sluzba_id LEFT JOIN maserky m ON m.id=r.maserka_id LEFT JOIN maserky m2 ON m2.id=r.maserka2_id
        WHERE r.stav='potvrzeno' AND r.zacatek >= ? AND r.zacatek <= ? AND r.konec > ? ORDER BY r.zacatek, r.id",
        [$od, $do, nyni()])->fetchAll();
}

/** Skupiny lůžek (tabletů) podle masérny: [klíč => [tablety…]] */
function prichody_skupiny(): array
{
    $sk = [];
    foreach (q('SELECT * FROM mistnosti WHERE aktivni=1 ORDER BY poradi, id')->fetchAll() as $t) $sk[maserna_klic($t)][] = $t;
    return $sk;
}

/**
 * Navrhne rozdělení do masérn a masérek. Vrací [rezervace_id => [
 *   'stav' => 'odeslano'|'navrh'|'bez_tabletu', 'skupina' => klíč masérny|null, 'maserka' => id|null, 'maserka2' => id|null, 'pozn' => text ]].
 * Pravidla: masérna se po tu dobu blokuje celá; jednotlivce raději do jednolůžkové, páry do dvoulůžkové; zadanou masérku nechá,
 * chybějící doplní volnou (oblíbená zákazníka první, pak ta s nejmenším počtem masáží).
 */
function prichody_navrh(array $rez): array
{
    $skupiny = prichody_skupiny();
    $nazvy = array_column($rez, null, 'id');
    $konecSk = [];            // klíč skupiny => čas, do kdy je obsazená
    $odeslano = [];           // rezervace_id => skupina (už čeká / běží na tabletu)
    foreach ($skupiny as $kl => $tablety) {
        $konecSk[$kl] = 0;
        foreach ($tablety as $t) {
            $sz = sezeni_aktivni((int)$t['id']);
            if (!$sz) continue;
            $vlastni = isset($nazvy[(int)$sz['rezervace_id']]);
            $rr = q1('SELECT zacatek, konec FROM rezervace WHERE id=?', [$sz['rezervace_id']]);
            $do = $sz['plan_konec'] ? strtotime((string)$sz['plan_konec']) : strtotime((string)($rr['konec'] ?? nyni()));
            $konecSk[$kl] = max($konecSk[$kl], $do);
            if ($vlastni) $odeslano[(int)$sz['rezervace_id']] = $kl;
        }
    }
    $maserky = q('SELECT id, jmeno FROM maserky WHERE aktivni=1 ORDER BY poradi, id')->fetchAll();
    $zatizeni = array_fill_keys(array_column($maserky, 'id'), 0);
    $davka = [];              // masérka => [[od, do], …] přidělené v tomto kole
    $out = [];

    $volnaMaserka = function (array $r, array $krome) use ($maserky, &$zatizeni, &$davka): ?int {
        $zac = $r['zacatek']; $mk = rez_masaz_konec($r);
        $kand = $maserky;
        usort($kand, fn($a, $b) => (((int)$b['id'] === (int)$r['pref_maserka_id']) <=> ((int)$a['id'] === (int)$r['pref_maserka_id'])) ?: ($zatizeni[$a['id']] <=> $zatizeni[$b['id']]));
        foreach ($kand as $m) {
            $id = (int)$m['id'];
            if (in_array($id, $krome, true) || !maserka_pracuje($id, $zac, $mk) || kolize($id, $zac, $mk, (int)$r['id'])) continue;
            foreach ($davka[$id] ?? [] as [$a, $b]) if ($a < strtotime($mk) && $b > strtotime($zac)) continue 2;
            return $id;
        }
        return null;
    };

    foreach ($rez as $r) {
        $rid = (int)$r['id'];
        if (isset($odeslano[$rid])) { $out[$rid] = ['stav' => 'odeslano', 'skupina' => $odeslano[$rid], 'maserka' => (int)$r['maserka_id'] ?: null, 'maserka2' => (int)$r['maserka2_id'] ?: null, 'pozn' => '']; continue; }
        if ((int)$r['var_maserky'] === 0) { $out[$rid] = ['stav' => 'bez_tabletu', 'skupina' => null, 'maserka' => null, 'maserka2' => null, 'pozn' => 'bez masérky (wellness) – tablet se nepoužije']; continue; }
        $par = (int)$r['var_maserky'] >= 2 || $r['maserka2_id'];
        $zac = strtotime($r['zacatek']); $kon = strtotime($r['konec']);
        // vhodné masérny: volné v čase začátku; pár potřebuje dvoulůžkovou, jednotlivce raději jednolůžkovou
        $kand = [];
        foreach ($skupiny as $kl => $tablety) {
            if ($konecSk[$kl] > $zac) continue;
            if ($par && count($tablety) < 2) continue;
            $kand[] = $kl;
        }
        usort($kand, fn($a, $b) => $par ? 0 : (count($skupiny[$a]) <=> count($skupiny[$b])));
        $kl = $kand[0] ?? null;
        $m1 = (int)$r['maserka_id'] ?: null; $m2 = (int)$r['maserka2_id'] ?: null;
        $pozn = '';
        if ($kl !== null) $konecSk[$kl] = $kon;
        else $pozn = 'všechny vhodné masérny jsou obsazené';
        if (!$m1) $m1 = $volnaMaserka($r, array_filter([$m2]));
        if ($par && !$m2) $m2 = $volnaMaserka($r, array_filter([$m1]));
        if (!$m1) $pozn = trim($pozn . ' · žádná volná masérka');
        if ($par && !$m2) $pozn = trim($pozn . ' · chybí druhá masérka');
        foreach (array_filter([$m1, $m2]) as $mid) { $davka[$mid][] = [$zac, strtotime(rez_masaz_konec($r))]; $zatizeni[$mid]++; }
        $out[$rid] = ['stav' => 'navrh', 'skupina' => $kl, 'maserka' => $m1, 'maserka2' => $par ? $m2 : null, 'pozn' => trim($pozn, ' ·')];
    }
    return $out;
}

/** Odešle vybrané rezervace na tablety podle voleb z formuláře. Vrací [ok-řádky, chyby]. */
function prichody_rozeslat(array $post): array
{
    $ok = []; $err = [];
    $skupiny = prichody_skupiny();
    foreach (array_unique(array_map('intval', (array)($post['ids'] ?? []))) as $rid) {
        $r = rezervace_detail($rid);
        if (!$r || $r['stav'] !== 'potvrzeno') continue;
        $jm = trim($r['prijmeni'] ?: $r['jmeno']);
        $kl = (string)($post['mist'][$rid] ?? '');
        $m1 = (int)($post['mas1'][$rid] ?? 0); $m2 = (int)($post['mas2'][$rid] ?? 0);
        $par = (int)($r['var_maserky'] ?? 1) >= 2 || $m2 > 0 || $r['maserka2_id'];
        $tablety = $skupiny[$kl] ?? [];
        if (!$tablety) { $err[] = "$jm: vyberte masérnu."; continue; }
        if (!$m1) { $err[] = "$jm: vyberte masérku."; continue; }
        if ($par && (!$m2 || $m2 === $m1 || count($tablety) < 2)) { $err[] = "$jm: párová potřebuje dvoulůžkovou masérnu a dvě různé masérky."; continue; }
        q('UPDATE rezervace SET maserka_id=?, maserka2_id=?, zmeneno=?, zmenil=? WHERE id=?', [$m1, $par ? $m2 : null, nyni(), kdo(), $rid]);
        $r = rezervace_detail($rid);
        $e1 = tablet_poslat($r, (int)$tablety[0]['id'], $m1, true);
        $e2 = (!$e1 && $par) ? tablet_poslat($r, (int)$tablety[1]['id'], $m2, false) : null;
        if ($e1 || $e2) { $err[] = "$jm: " . ($e1 ?? $e2); continue; }
        $ok[] = $jm . ' → ' . ($skupiny[$kl][0]['skupina'] ?: $tablety[0]['nazev']) . ' · ' . $r['maserka'] . ($r['maserka2'] ? ' + ' . $r['maserka2'] : '');
    }
    return [$ok, $err];
}

/** Panel „Příchody“ na stránce Dnes. */
function prichody_panel(): void
{
    $rez = prichody_okno();
    $skupiny = prichody_skupiny();
    if (!$rez || !$skupiny) return;
    $navrh = prichody_navrh($rez);
    $maserky = q('SELECT id, jmeno FROM maserky WHERE aktivni=1 ORDER BY poradi, id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $nazMas = [];
    foreach ($skupiny as $kl => $t) $nazMas[$kl] = trim($t[0]['skupina']) !== '' ? $t[0]['skupina'] : $t[0]['nazev'];
    $kSend = array_filter($navrh, fn($n) => $n['stav'] === 'navrh');
    ?>
    <section class="card prichody">
      <h2>Příchody <span class="muted">(<?= count($rez) ?> v nejbližší době)</span></h2>
      <p class="muted small">Návrh rozdělení do masérn a masérek. Přečtěte zákazníkům, kam mají jít, případně v řádku cokoli přehoďte a pak jedním klepnutím pošlete všechny na tablety.</p>
      <form method="post">
        <?= csrf_pole() ?><input type="hidden" name="akce" value="prichody_poslat">
        <?php foreach ($rez as $r): $n = $navrh[(int)$r['id']]; $rid = (int)$r['id'];
              $par = (int)$r['var_maserky'] >= 2 || $r['maserka2_id']; ?>
          <div class="row-item prichod<?= $n['stav'] === 'odeslano' ? ' hotovo' : '' ?>">
            <?php if ($n['stav'] === 'navrh'): ?><input type="checkbox" name="ids[]" value="<?= $rid ?>" checked style="width:22px;height:22px" aria-label="poslat"><?php else: ?><span style="width:22px"></span><?php endif ?>
            <div class="time"><?= date('H:i', strtotime($r['zacatek'])) ?></div>
            <div class="grow">
              <a href="/?r=rezervace&id=<?= $rid ?>"><b><?= h(jmeno_z($r)) ?></b></a> · <?= h($r['sluzba'] . ' ' . $r['var_popis']) ?>
              <?php if ($n['stav'] === 'odeslano'): ?>
                <br><span class="tab-st cek">📲 na tabletu: <?= h($nazMas[$n['skupina']] ?? '') ?> · <?= h(($r['maserka'] ?? '') . ($r['maserka2'] ? ' + ' . $r['maserka2'] : '')) ?></span>
              <?php elseif ($n['stav'] === 'bez_tabletu'): ?>
                <br><span class="muted small"><?= h($n['pozn']) ?></span>
              <?php else: ?>
                <br>
                <select name="mist[<?= $rid ?>]" title="Masérna"><option value="">— masérna —</option>
                  <?php foreach ($skupiny as $kl => $t): ?><option value="<?= h($kl) ?>" <?= $n['skupina'] === $kl ? 'selected' : '' ?>><?= h($nazMas[$kl]) ?><?= count($t) > 1 ? ' (duo)' : '' ?></option><?php endforeach ?></select>
                <select name="mas1[<?= $rid ?>]" title="Masérka"><option value="">— masérka —</option>
                  <?php foreach ($maserky as $mid => $mj): ?><option value="<?= $mid ?>" <?= (int)$n['maserka'] === (int)$mid ? 'selected' : '' ?>><?= h($mj) ?></option><?php endforeach ?></select>
                <?php if ($par): ?><select name="mas2[<?= $rid ?>]" title="2. masérka"><option value="">— 2. masérka —</option>
                  <?php foreach ($maserky as $mid => $mj): ?><option value="<?= $mid ?>" <?= (int)$n['maserka2'] === (int)$mid ? 'selected' : '' ?>><?= h($mj) ?></option><?php endforeach ?></select><?php endif ?>
                <?php if ($n['pozn']): ?><span class="warn small">⚠ <?= h($n['pozn']) ?></span><?php endif ?>
              <?php endif ?>
            </div>
          </div>
        <?php endforeach ?>
        <?php if ($kSend): ?><button class="btn primary">📲 Poslat vybrané na tablety</button><?php endif ?>
      </form>
    </section>
    <?php
}

// ---------- Ukončení masáže z recepce ----------
/** Komu jde upozornění o předčasně ukončené masáži: aktivní „hlavní pracovníci“ s e-mailem + e-mail salonu. */
function vedouci_emaily(): array
{
    $e = q("SELECT email FROM uzivatele WHERE role='hlavni' AND aktivni=1 AND email<>''")->fetchAll(PDO::FETCH_COLUMN);
    $e[] = (string)(cfg()['email_salon'] ?? '');
    return array_values(array_unique(array_filter($e)));
}

/** Ukončí masáž (všechna aktivní sezení rezervace) z recepce. Vrací chybu, nebo null. */
function masaz_ukoncit_recepce(int $rezId, string $duvod, bool $pocitat): ?string
{
    $duvod = trim($duvod);
    if ($duvod === '') return 'Napište důvod ukončení (dostane ho vedoucí).';
    $r = rezervace_detail($rezId);
    if (!$r) return 'Rezervace nenalezena.';
    $sez = q("SELECT s.*, mi.nazev AS tablet FROM sezeni s JOIN mistnosti mi ON mi.id=s.mistnost_id WHERE s.rezervace_id=? AND s.stav IN ('cekani','bezi','konec')", [$rezId])->fetchAll();
    $ted = nyni();
    foreach ($sez as $s) q("UPDATE sezeni SET stav='hotovo', konec=COALESCE(?, konec), zavreno=?, ukonceno=?, ukonceno_duvod=?, ukonceno_kdo=? WHERE id=?",
        [$ted, $ted, $ted, $duvod, kdo(), $s['id']]);
    $novy = $pocitat ? 'probehlo' : 'zruseno_vcas';
    q('UPDATE rezervace SET stav=?, poznamka_masaz=TRIM(poznamka_masaz || ?), zmeneno=?, zmenil=? WHERE id=?',
        [$novy, "\n" . date('j. n. H:i') . ' – masáž předčasně ukončena z recepce: ' . $duvod, $ted, kdo(), $rezId]);
    zapis_log('masaz_ukoncena', "rez #$rezId: $duvod");
    $kde = $sez ? implode(', ', array_unique(array_column($sez, 'tablet'))) : '—';
    $text = "Masáž byla předčasně ukončena z recepce.\n\nZákazník: " . jmeno_z($r) . ' (' . telefon_cz($r['telefon']) . ")\nMasáž: " . trim(($r['sluzba'] ?? '') . ' ' . ($r['var_popis'] ?? ''))
        . "\nTermín: " . datum_cz($r['zacatek']) . "\nMasérna / tablet: $kde\nMasérka: " . ($r['maserka'] ?? '—') . ($r['maserka2'] ? ' + ' . $r['maserka2'] : '')
        . "\nUkončil(a): " . kdo() . "\n\nDůvod: $duvod\n\n" . ($pocitat ? 'Počítá se jako proběhlá.' : 'Nepočítá se jako proběhlá (do razítek ani statistik).') . "\n";
    foreach (vedouci_emaily() as $adr) posli_email($adr, 'Thai Most – předčasně ukončená masáž (' . jmeno_z($r) . ')', $text);
    return null;
}

/** Karta s tlačítkem „Ukončit masáž“ (v detailu rezervace i na Dnes). */
function ukoncit_formular(array $r): void
{ ?>
    <details class="ukoncit">
      <summary class="btn small danger">⛔ Ukončit masáž</summary>
      <form method="post" action="/?r=dnes" class="card" style="margin-top:6px">
        <?= csrf_pole() ?><input type="hidden" name="akce" value="ukoncit"><input type="hidden" name="rez_id" value="<?= (int)$r['id'] ?>">
        <label>Důvod ukončení * <span class="muted small">(dostane ho vedoucí e-mailem)</span><textarea name="duvod" rows="2" required placeholder="např. klientovi se udělalo špatně"></textarea></label>
        <label class="check"><input type="checkbox" name="pocitat" value="1"> I tak počítat jako proběhlou masáž (razítka)</label>
        <button class="btn danger" data-confirm="Opravdu ukončit masáž? Tablet se uvolní a vedoucí dostane upozornění.">Ukončit masáž a informovat vedoucí</button>
      </form>
    </details>
<?php }

/** Upozornění na Dnes: dnes předčasně ukončené masáže. */
function ukoncene_dnes_banner(): void
{
    $rows = q("SELECT DISTINCT s.rezervace_id, s.ukonceno, s.ukonceno_duvod, s.ukonceno_kdo FROM sezeni s WHERE s.ukonceno >= ? ORDER BY s.ukonceno DESC", [date('Y-m-d')])->fetchAll();
    $videno = [];
    foreach ($rows as $x) {
        if (isset($videno[$x['rezervace_id']])) continue; $videno[$x['rezervace_id']] = 1;
        $r = rezervace_detail((int)$x['rezervace_id']); if (!$r) continue; ?>
      <div class="flash warn">⛔ <b><?= h(jmeno_z($r)) ?></b> – masáž ukončena v <?= h(substr($x['ukonceno'], 11, 5)) ?> (<?= h($x['ukonceno_kdo']) ?>): <?= h($x['ukonceno_duvod']) ?></div>
<?php }
}
