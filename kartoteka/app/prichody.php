<?php
// Masérny a tablety: návrh masérek a rozdělení rezervací do masérn, ukončení masáže z recepce, kalendář masérn.
// Na tablety se rezervace posílají samy (viz plan.php → tablet_auto); recepce rezervace jen přetahuje mezi masérnami
// a v případě potřeby mění masérku přímo na obrazovce Masérny (prehled.php).

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

/** Ruční posílání na tablety je zrušené (rezervace se posílají samy, viz plan.php). Funkce zůstává jen kvůli staré POST větvi ve starém přehledu. */
function prichody_rozeslat(array $post): array
{
    return [[], ['Ruční posílání na tablety je zrušené – rezervace se posílají samy. Masérnu a masérku změníte na obrazovce Masérny.']];
}

/** Panel „Příchody“ už neexistuje (nahradilo ho automatické posílání). Prázdná funkce zůstává kvůli starému přehledu /?r=dnes_stary. */
function prichody_panel(): void {}

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

// ---------- Kalendář: obsazenost MASÉRN (vedle obsazenosti masérek) ----------
/**
 * Rozdělení rezervací dne do masérn. Pořadí rozhodování:
 *  1. co už je na tabletu (nebo proběhlo) – masérna podle tabletu ('zdroj' = tablet),
 *  2. pevně zvolená masérna u rezervace (sloupec maserna; mění se přetažením na obrazovce Masérny; 'zdroj' = pevna),
 *  3. zbytek podle pravidla: pár do dvoulůžkové, wellness do wellness masérny, jednotlivec raději do jednolůžkové ('zdroj' = navrh).
 * S $fixuj = true se návrh rovnou uloží k rezervaci (a uložená volba, která zanikla nebo se srazila, se vyčistí),
 * takže bloky během dne neskáčou, když přibude nová rezervace.
 * Vrací [klíč masérny => [bloky], '' => [nevešlo se]]; blok = rezervace + 'zdroj'.
 */
function masterny_den(string $d, array $skupiny, bool $fixuj = false): array
{
    $dalsi = date('Y-m-d', strtotime("$d +1 day"));
    $rez = q("SELECT r.*, z.jmeno, z.prijmeni, z.pref_maserka_id, s.nazev AS sluzba, v.popis AS var_popis, COALESCE(v.maserky, 1) AS var_maserky, COALESCE(v.wellness_mistnost, 0) AS w,
            m.jmeno AS maserka, m2.jmeno AS maserka2
        FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id LEFT JOIN varianty v ON v.id=r.varianta_id LEFT JOIN sluzby s ON s.id=v.sluzba_id
        LEFT JOIN maserky m ON m.id=r.maserka_id LEFT JOIN maserky m2 ON m2.id=r.maserka2_id
        WHERE r.zacatek >= ? AND r.zacatek < ? AND r.stav IN ('potvrzeno','probehlo') ORDER BY r.zacatek, r.id",
        [$d, $dalsi])->fetchAll();
    $tabletSkup = [];   // id tabletu => klíč masérny
    $wellSk = [];
    foreach ($skupiny as $kl => $tablety) foreach ($tablety as $t) { $tabletSkup[(int)$t['id']] = $kl; if ($t['wellness']) $wellSk[$kl] = true; }
    $fix = [];          // rezervace_id => klíč masérny (z tabletu)
    foreach (q("SELECT rezervace_id, mistnost_id FROM sezeni WHERE stav<>'zruseno' AND rezervace_id IN (SELECT id FROM rezervace WHERE zacatek >= ? AND zacatek < ?) ORDER BY id", [$d, $dalsi])->fetchAll() as $x)
        if (isset($tabletSkup[(int)$x['mistnost_id']])) $fix[(int)$x['rezervace_id']] = $tabletSkup[(int)$x['mistnost_id']];
    $out = array_fill_keys(array_keys($skupiny), []); $out[''] = [];
    $obs = array_fill_keys(array_keys($skupiny), []);
    $zaber = function (string $kl, array $r, string $zdroj) use (&$obs, &$out) {
        $obs[$kl][] = [strtotime($r['zacatek']), strtotime($r['konec'])];
        $out[$kl][] = $r + ['zdroj' => $zdroj];
    };
    $volno = function (string $kl, int $a, int $b) use (&$obs): bool {
        foreach ($obs[$kl] as [$x, $y]) if ($x < $b && $y > $a) return false;
        return true;
    };
    $hotovo = [];       // rezervace_id => už umístěná

    // 1) tablet je skutečnost: masérna podle něj (a uložená volba se s ním srovná)
    foreach ($rez as $r) {
        $id = (int)$r['id']; if (!isset($fix[$id])) continue;
        $zaber($fix[$id], $r, 'tablet'); $hotovo[$id] = true;
        if ($fixuj && (string)$r['maserna'] !== $fix[$id]) q('UPDATE rezervace SET maserna=? WHERE id=?', [$fix[$id], $id]);
    }
    // 2) pevně zvolená masérna (pokud pořád existuje a nic se tam nesráží)
    foreach ($rez as $r) {
        $id = (int)$r['id']; $kl = (string)$r['maserna'];
        if (isset($hotovo[$id]) || $kl === '') continue;
        if (isset($skupiny[$kl]) && $volno($kl, strtotime($r['zacatek']), strtotime($r['konec']))) { $zaber($kl, $r, 'pevna'); $hotovo[$id] = true; }
        elseif ($fixuj) q("UPDATE rezervace SET maserna='' WHERE id=?", [$id]);
    }
    // 3) zbytek: návrh
    foreach ($rez as $r) {
        $id = (int)$r['id']; if (isset($hotovo[$id])) continue;
        $a = strtotime($r['zacatek']); $b = strtotime($r['konec']);
        $par = (int)$r['var_maserky'] >= 2 || $r['maserka2_id'];
        $kand = array_keys($skupiny);
        if ($r['w']) { $w = array_values(array_filter($kand, fn($k) => isset($wellSk[$k]))); if ($w) $kand = $w; }
        if ($par) $kand = array_values(array_filter($kand, fn($k) => count($skupiny[$k]) >= 2));
        usort($kand, fn($x, $y) => ($par || $r['w']) ? 0 : count($skupiny[$x]) <=> count($skupiny[$y]));
        $vybrana = null;
        foreach ($kand as $kl) if ($volno($kl, $a, $b)) { $vybrana = $kl; break; }
        if ($vybrana === null) { $out[''][] = $r + ['zdroj' => 'nevesel']; continue; }
        $zaber($vybrana, $r, 'navrh');
        if ($fixuj) q('UPDATE rezervace SET maserna=? WHERE id=?', [$vybrana, $id]);
    }
    return $out;
}

function kalendar_masterny(string $d): void
{
    $skupiny = prichody_skupiny();
    $minOd = min_z(nastaveni('oteviraci_od', '09:00')); $minDo = min_z(nastaveni('oteviraci_do', '20:00'));
    $plan = $skupiny ? masterny_den($d, $skupiny) : [];
    // návrh masérek tam, kde ještě nejsou přidělené (stejné pravidlo jako panel Příchody)
    $navrhMas = []; $jmMas = q('SELECT id, jmeno FROM maserky')->fetchAll(PDO::FETCH_KEY_PAIR);
    $kNavrhu = [];
    foreach ($plan as $bl) foreach ($bl as $r) if ($r['stav'] === 'potvrzeno' && (int)$r['var_maserky'] > 0) $kNavrhu[] = $r;
    usort($kNavrhu, fn($a, $b) => strcmp($a['zacatek'], $b['zacatek']) ?: $a['id'] <=> $b['id']);
    if ($kNavrhu) $navrhMas = prichody_navrh($kNavrhu);
    foreach ($plan as $bl) foreach ($bl as $r) $minDo = max($minDo, (int)date('G', strtotime($r['konec'])) * 60 + (int)date('i', strtotime($r['konec'])), $minOd);
    $minDo = (int)(ceil($minDo / 30) * 30);
    $px = 1.4;
    $minz = fn($dt) => (int)date('G', strtotime($dt)) * 60 + (int)date('i', strtotime($dt));
    $prev = date('Y-m-d', strtotime("$d -1 day")); $next = date('Y-m-d', strtotime("$d +1 day"));
    $sloupce = [];
    foreach ($skupiny as $kl => $t) $sloupce[$kl] = (trim($t[0]['skupina']) !== '' ? $t[0]['skupina'] : $t[0]['nazev']) . (count($t) > 1 ? ' (duo)' : '') . (isset($t[0]['wellness']) && $t[0]['wellness'] ? ' ♨' : '');
    if (!empty($plan['']) ) $sloupce[''] = '⚠ Nevešlo se';
    hlavicka('Kalendář masérn', 'kalendar'); ?>
    <div class="cal-nav">
      <a class="btn" href="/?r=kalendar&pohled=masterny&d=<?= $prev ?>">‹</a>
      <form><input type="hidden" name="r" value="kalendar"><input type="hidden" name="pohled" value="masterny"><input type="date" name="d" value="<?= h($d) ?>" onchange="this.form.submit()"></form>
      <a class="btn" href="/?r=kalendar&pohled=masterny&d=<?= $next ?>">›</a>
      <a class="btn" href="/?r=kalendar&pohled=masterny">Dnes</a>
      <a class="btn" href="/?r=kalendar&d=<?= h($d) ?>">Obsazenost masérek</a>
      <a class="btn primary" href="/?r=kalendar&pohled=masterny&d=<?= h($d) ?>">Obsazenost masérn</a>
      <a class="btn" href="/?r=volno" title="Hledání volných termínů na několik dní dopředu">🔎 Volné termíny</a>
      <h1><?= h(datum_cz($d, false)) ?></h1>
    </div>
    <?php if (!$skupiny): ?><p class="flash warn">Nejsou nastavené masérny. Přidejte je v Tým → Masérny.</p><?php else: ?>
    <p class="muted small">Která masérna kdy obsazená. 📲 = už je na tabletu v téhle masérně; plná čára = masérna je přiřazená (přetažením na obrazovce Masérny ji změníte); čárkovaně = zatím jen návrh (dny dopředu). Masérna se blokuje celá. Klepnutím do volného místa přidáte rezervaci.</p>
    <div class="cal" style="--h:<?= ($minDo - $minOd) * $px ?>px">
      <div class="cal-hours"><?php for ($m = (int)ceil($minOd / 60) * 60; $m < $minDo; $m += 60): ?><div style="top:<?= ($m - $minOd) * $px ?>px"><?= hhmm($m) ?></div><?php endfor ?></div>
      <?php foreach ($sloupce as $kl => $nazev):
          $bloky = $plan[$kl] ?? [];
          $aktivni = array_map(fn($r) => [$minz($r['zacatek']), $minz($r['konec'])], $bloky);
          $volna = $kl === '' ? [] : intervaly_minus([[$minOd, $minDo]], $aktivni);
          $volna = array_values(array_filter($volna, fn($x) => $x[1] - $x[0] >= 30 && ($d > date('Y-m-d') || ($d === date('Y-m-d') && $x[1] > (int)date('G') * 60 + (int)date('i')))));
          $volnoMin = array_sum(array_map(fn($x) => $x[1] - $x[0], $volna)); ?>
      <div class="cal-col">
        <div class="cal-head" style="border-color:<?= $kl === '' ? '#c0605a' : '#7a5ea8' ?>"><?= h($nazev) ?>
          <?php if ($kl !== ''): ?><small><?= $volnoMin ? 'volno ' . h(rtrim(rtrim(number_format($volnoMin / 60, 1, ',', ''), '0'), ',')) . ' h' : 'plno ✓' ?></small><?php endif ?></div>
        <div class="cal-body">
          <?php if ($kl !== ''): for ($m = $minOd; $m < $minDo; $m += 30): ?>
            <a class="slot" style="top:<?= ($m - $minOd) * $px ?>px;height:<?= 30 * $px ?>px" href="/?r=rez_nova&d=<?= h($d) ?>&t=<?= hhmm($m) ?>"></a>
          <?php endfor; foreach ($volna as [$x, $y]): ?>
            <a class="gap" style="top:<?= ($x - $minOd) * $px + 1 ?>px;height:<?= max(18, ($y - $x) * $px - 2) ?>px" href="/?r=rez_nova&d=<?= h($d) ?>&t=<?= hhmm($x) ?>">+ <?= hhmm($x) ?> · <?= $y - $x ?> min</a>
          <?php endforeach; endif ?>
          <?php foreach ($bloky as $r):
              $top = ($minz($r['zacatek']) - $minOd) * $px; $hgt = max(26, (strtotime($r['konec']) - strtotime($r['zacatek'])) / 60 * $px - 2);
              $mas = trim(($r['maserka'] ?? '') . ($r['maserka2'] ? ' + ' . $r['maserka2'] : '')); $masNavrh = false;
              if ($mas === '' && (int)$r['var_maserky'] > 0 && !empty($navrhMas[(int)$r['id']])) {
                  $nv = $navrhMas[(int)$r['id']];
                  $mas = trim(($jmMas[$nv['maserka']] ?? '') . (!empty($nv['maserka2']) ? ' + ' . ($jmMas[$nv['maserka2']] ?? '') : '')); $masNavrh = $mas !== '';
              } ?>
            <a class="blk s-<?= h($r['stav']) ?><?= $r['zdroj'] === 'nevesel' ? ' konfl' : '' ?>" href="/?r=rezervace&id=<?= $r['id'] ?>"
               style="top:<?= $top ?>px;height:<?= $hgt ?>px;left:3px;width:calc(100% - 6px);border-color:#7a5ea8;<?= $r['zdroj'] === 'navrh' ? 'opacity:.75;border-style:dashed' : '' ?>">
              <b><?= $r['zdroj'] === 'tablet' ? '📲 ' : '' ?><?= date('H:i', strtotime($r['zacatek'])) ?>–<?= date('H:i', strtotime($r['konec'])) ?></b> <?= h(jmeno_z($r)) ?><br>
              <span><?= h($r['sluzba'] . ' ' . $r['var_popis']) ?></span><br>
              <span><?= $mas !== '' ? '👤 <b>' . h($mas) . '</b>' . ($masNavrh ? ' <i>(návrh)</i>' : '') : ((int)$r['var_maserky'] === 0 ? '<i>bez masérky (wellness)</i>' : '<i>masérka zatím nepřidělena</i>') ?></span>
            </a>
          <?php endforeach ?>
        </div>
      </div>
      <?php endforeach ?>
    </div>
    <?php endif;
    paticka();
}
