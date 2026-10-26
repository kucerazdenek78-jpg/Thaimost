<?php
// Plán masérn: pevné přiřazení rezervací do masérn (přetažení myší / prstem na obrazovce Masérny), změna masérky
// s hlídáním kolizí a automatické posílání rezervací na tablety.
//
// Masérna je uložená u rezervace (sloupec rezervace.maserna = klíč masérny, viz masterny_den v prichody.php).
// Rezervace, která už je na tabletu, drží masérnu podle tabletu; při přesunu se sezení na tabletu zruší a znovu se pošle
// do nové masérny. Na tablet se rezervace posílají samy (tablet_auto) tolik minut před začátkem, kolik je v Nastavení.

const TABLET_POZDE_MIN = 30;   // za nedošlého se zákazník bere po tolika minutách od začátku; sám se už na tablet neposílá
const PLAN_AKCE = ['maserky_info', 'zmen_maserku', 'presun_masaz'];

/** Kolik minut před začátkem se rezervace sama pošle na tablet (Nastavení → tablet_predstih_min, výchozí 20). */
function tablet_predstih(): int { return max(0, min(180, (int)nastaveni('tablet_predstih_min', '20'))); }

function plan_json(array $d, int $kod = 200): void
{
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function plan_chyba(string $text): array { return ['ok' => false, 'chyba' => $text]; }

/** Název masérny pro hlášky: skupina, jinak název prvního lůžka. */
function plan_nazev(string $kl, array $skupiny): string
{
    $t = $skupiny[$kl][0] ?? null;
    return $t ? (trim((string)$t['skupina']) !== '' ? trim((string)$t['skupina']) : (string)$t['nazev']) : '?';
}

/** V které masérně rezervace v plánu dne je? (null = nikde / nevešla se) */
function plan_najdi(array $plan, int $rid): ?string
{
    foreach ($plan as $kl => $bloky) {
        if ((string)$kl === '') continue;
        foreach ($bloky as $b) if ((int)$b['id'] === $rid) return (string)$kl;
    }
    return null;
}

/** Poslední nezrušené sezení rezervace (cekani / bezi / konec / hotovo) nebo null. */
function plan_sezeni(int $rid): ?array
{
    return q1("SELECT * FROM sezeni WHERE rezervace_id=? AND stav<>'zruseno' ORDER BY id DESC LIMIT 1", [$rid]);
}

/** Dá se s rezervací hýbat? Vrací null, nebo důvod, proč ne. */
function plan_zamek(array $r): ?string
{
    if ($r['stav'] !== 'potvrzeno') return 'Rezervace už není potvrzená.';
    if (strtotime($r['konec']) <= time()) return 'Masáž už skončila.';
    $sz = plan_sezeni((int)$r['id']);
    if ($sz && $sz['stav'] === 'bezi') return 'Masáž právě běží.';
    if ($sz && in_array($sz['stav'], ['konec', 'hotovo'], true)) return 'Masáž už proběhla.';
    return null;
}

/** Sezení, které čeká na tabletu, se zruší, aby se rezervace poslala znovu (do nové masérny / k nové masérce). Výslovná akce recepce zároveň znovu zapne automatické posílání. */
function plan_znovu_na_tablet(int $rid): void
{
    q("UPDATE sezeni SET stav='zruseno', zavreno=?, ukonceno_duvod='presun' WHERE rezervace_id=? AND stav='cekani'", [nyni(), $rid]);
    q("UPDATE sezeni SET ukonceno_duvod='presun' WHERE rezervace_id=? AND stav='zruseno' AND ukonceno_duvod=''", [$rid]);
}

/** Vejde se rezervace do masérny? null = ano, jinak důvod. $r může být řádek z masterny_den nebo z rezervace_detail. */
function plan_vejde_se(array $r, string $kl, array $skupiny): ?string
{
    $t = $skupiny[$kl] ?? null;
    if (!$t) return 'Masérna neexistuje.';
    $vm = $r['var_maserky'] ?? null; $vm = $vm === null ? 1 : (int)$vm;
    if (($vm >= 2 || !empty($r['maserka2_id'])) && count($t) < 2) return 'Párová masáž potřebuje dvoulůžkovou masérnu.';
    if ((int)($r['w'] ?? $r['wellness_mistnost'] ?? 0) === 1) {
        $jeWell = fn(array $tt) => (bool)array_filter($tt, fn($x) => !empty($x['wellness']));
        $jsouWell = (bool)array_filter($skupiny, $jeWell);
        if ($jsouWell && !$jeWell($t)) return 'Whirlpool a sauna patří do wellness masérny.';
    }
    return null;
}

// ---------- Přesun mezi masérnami (s prohozením) ----------
/**
 * Přesune rezervaci do jiné masérny. Když tam ve stejný čas už někdo je, prohodí se: ten se přesune do původní masérny
 * rezervace. Nejde to, když by se prohozená masáž srazila s jinou, nevešla se (pár / wellness) nebo když některá z masáží
 * už běží či proběhla. Vrací ['ok' => bool, 'zprava' => text] nebo ['ok' => false, 'chyba' => text].
 */
function rez_presun_masernu(int $rid, string $cil): array
{
    $r = rezervace_detail($rid);
    if (!$r) return plan_chyba('Rezervace nenalezena.');
    if ($e = plan_zamek($r)) return plan_chyba($e . ' Nejde přesunout.');
    $skupiny = prichody_skupiny();
    if (!isset($skupiny[$cil])) return plan_chyba('Masérna neexistuje.');
    $den = substr($r['zacatek'], 0, 10);
    $plan = masterny_den($den, $skupiny, true);
    $zdroj = plan_najdi($plan, $rid);
    if ($zdroj === null) return plan_chyba('Rezervace se nevešla do žádné masérny – nejdřív uvolněte místo (např. v Kalendáři masérn).');
    if ($zdroj === $cil) return ['ok' => true, 'zmena' => false, 'zprava' => 'Rezervace už v téhle masérně je.'];
    if ($e = plan_vejde_se($r, $cil, $skupiny)) return plan_chyba($e);

    $a = strtotime($r['zacatek']); $b = strtotime($r['konec']);
    $stret = [];   // masáže v cílové masérně, které se s přesouvanou časově překrývají
    foreach ($plan[$cil] as $x) if (strtotime($x['zacatek']) < $b && strtotime($x['konec']) > $a) $stret[] = $x;

    $presun = [$rid => $cil];
    foreach ($stret as $x) {
        $nm = jmeno_z($x) . ' (' . date('H:i', strtotime($x['zacatek'])) . '–' . date('H:i', strtotime($x['konec'])) . ')';
        if ($e = plan_zamek($x)) return plan_chyba("V masérně „" . plan_nazev($cil, $skupiny) . "“ je v tu dobu $nm – $e Prohodit nejde.");
        if ($e = plan_vejde_se($x, $zdroj, $skupiny)) return plan_chyba("Prohodit s $nm nejde: $e");
        // po odchodu přesouvané rezervace musí být v původní masérně místo pro prohozenou
        foreach ($plan[$zdroj] as $y) {
            if ((int)$y['id'] === $rid) continue;
            if (strtotime($y['zacatek']) < strtotime($x['konec']) && strtotime($y['konec']) > strtotime($x['zacatek']))
                return plan_chyba("Prohodit s $nm nejde: v původní masérně „" . plan_nazev($zdroj, $skupiny) . "“ by se srazila s masáží " . jmeno_z($y)
                    . ' (' . date('H:i', strtotime($y['zacatek'])) . '–' . date('H:i', strtotime($y['konec'])) . ').');
        }
        $presun[(int)$x['id']] = $zdroj;
    }

    db()->beginTransaction();
    try {
        foreach ($presun as $id => $kl) q('UPDATE rezervace SET maserna=?, zmeneno=?, zmenil=? WHERE id=?', [$kl, nyni(), kdo(), $id]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    foreach (array_keys($presun) as $id) plan_znovu_na_tablet((int)$id);
    zapis_log('plan_presun', 'rez #' . $rid . ' ' . plan_nazev($zdroj, $skupiny) . ' → ' . plan_nazev($cil, $skupiny) . ($stret ? ' (prohozeno s #' . implode(', #', array_column($stret, 'id')) . ')' : ''));
    tablet_auto(true);

    $kdo = jmeno_z($r);
    $zprava = $stret
        ? "Prohozeno: $kdo → " . plan_nazev($cil, $skupiny) . ', ' . implode(' + ', array_map('jmeno_z', $stret)) . ' → ' . plan_nazev($zdroj, $skupiny) . '.'
        : "Přesunuto: $kdo → " . plan_nazev($cil, $skupiny) . '.';
    return ['ok' => true, 'zmena' => true, 'zprava' => $zprava];
}

// ---------- Změna masérky (s prohozením, když je vybraná masérka jinde) ----------
/** Seznam masérek pro okno: u každé stav pro čas rezervace – volna | obsazena (má v tu dobu jinou masáž) | nepracuje. */
function plan_maserky_info(int $rid): array
{
    $r = rezervace_detail($rid);
    if (!$r) return plan_chyba('Rezervace nenalezena.');
    $vm = $r['var_maserky'] === null ? 1 : (int)$r['var_maserky'];
    $zac = $r['zacatek']; $kon = rez_masaz_konec($r);
    $seznam = [];
    foreach (q('SELECT id, jmeno FROM maserky WHERE aktivni=1 ORDER BY poradi, id')->fetchAll() as $m) {
        $id = (int)$m['id'];
        $stav = !maserka_pracuje($id, $zac, $kon) ? 'nepracuje' : (kolize($id, $zac, $kon, $rid) ? 'obsazena' : 'volna');
        $seznam[] = ['id' => $id, 'jmeno' => $m['jmeno'], 'stav' => $stav];
    }
    return ['ok' => true, 'zamek' => plan_zamek($r), 'zakaznik' => jmeno_z($r), 'cas' => date('H:i', strtotime($r['zacatek'])) . '–' . date('H:i', strtotime($r['konec'])),
        'sluzba' => trim(($r['sluzba'] ?? '') . ' ' . ($r['var_popis'] ?? '')), 'bez_maserky' => $vm === 0, 'par' => $vm >= 2 || (bool)$r['maserka2_id'],
        'm1' => (int)$r['maserka_id'] ?: null, 'm2' => (int)$r['maserka2_id'] ?: null, 'maserky' => $seznam];
}

/**
 * Nastaví masérku (slot 1 = první, 2 = druhá u párové). Když je vybraná masérka v tu dobu u jiné masáže, nabídne prohození
 * (vrací 'potvrdit' => true a text); po souhlasu ($prohodit) se masérky mezi oběma masážemi prohodí.
 */
function rez_zmen_maserku(int $rid, int $slot, int $nova, bool $prohodit): array
{
    $r = rezervace_detail($rid);
    if (!$r) return plan_chyba('Rezervace nenalezena.');
    if ($e = plan_zamek($r)) return plan_chyba($e . ' Masérku nejde změnit.');
    $vm = $r['var_maserky'] === null ? 1 : (int)$r['var_maserky'];
    if ($vm === 0) return plan_chyba('Tahle služba je bez masérky.');
    $par = $vm >= 2 || $r['maserka2_id'];
    if ($slot < 1 || $slot > ($par ? 2 : 1)) return plan_chyba('Neplatná volba masérky.');
    $jm = q('SELECT id, jmeno FROM maserky WHERE aktivni=1')->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!isset($jm[$nova])) return plan_chyba('Masérka neexistuje nebo není aktivní.');
    $sl = $slot === 1 ? 'maserka_id' : 'maserka2_id'; $druhy = $slot === 1 ? 'maserka2_id' : 'maserka_id';
    $stara = (int)$r[$sl];
    if ($stara === $nova) return ['ok' => true, 'zmena' => false, 'zprava' => 'Masérka zůstala stejná.'];
    if ((int)$r[$druhy] === $nova) return plan_chyba($jm[$nova] . ' je u téhle masáže už jako druhá masérka.');
    $zac = $r['zacatek']; $kon = rez_masaz_konec($r); $kdo = jmeno_z($r);
    if (!maserka_pracuje($nova, $zac, $kon))
        return plan_chyba($jm[$nova] . ' v tu dobu nepracuje (volno, víkend nebo mimo směnu). Přesčas jí zapíšete ve Volna a přesčasy.');

    $kol = kolize($nova, $zac, $kon, $rid);
    $ulozit = function (array $zmeny) use ($rid) {
        db()->beginTransaction();
        try {
            foreach ($zmeny as [$id, $sloupec, $mid]) q("UPDATE rezervace SET $sloupec=?, zmeneno=?, zmenil=? WHERE id=?", [$mid ?: null, nyni(), kdo(), $id]);
            db()->commit();
        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    };
    if (!$kol) {
        $ulozit([[$rid, $sl, $nova]]);
        plan_znovu_na_tablet($rid);
        zapis_log('plan_maserka', "rez #$rid: " . ($jm[$stara] ?? '—') . ' → ' . $jm[$nova]);
        tablet_auto(true);
        return ['ok' => true, 'zmena' => true, 'zprava' => "$kdo: masérka " . $jm[$nova] . '.'];
    }

    // zaneprázdněná: popis a možnost prohození
    $popis = fn(array $x) => jmeno_z($x) . ' (' . date('H:i', strtotime($x['zacatek'])) . '–' . date('H:i', strtotime(rez_masaz_konec($x))) . ')';
    if (count($kol) > 1)
        return plan_chyba($jm[$nova] . ' je v tu dobu zaneprázdněná u víc masáží (' . implode(', ', array_map($popis, $kol)) . ') – prohodit nejde.');
    $bk = $kol[0];
    $prekryv = strtotime($bk['zacatek']) < strtotime($kon) && strtotime(rez_masaz_konec($bk)) > strtotime($zac);
    $duvod = $prekryv ? 'má v tu dobu masáž' : 'má masáž těsně před nebo po této (mezi masážemi je potřeba přestávka ' . prestavka() . ' min)';
    if (!$stara) return plan_chyba($jm[$nova] . " $duvod s " . $popis($bk) . '. Tahle masáž zatím žádnou masérku nemá, takže není s kým prohodit.');
    $b = rezervace_detail((int)$bk['id']);
    if (!$b) return plan_chyba('Souběžná rezervace nenalezena.');
    if ($e = plan_zamek($b)) return plan_chyba($jm[$nova] . " $duvod s " . $popis($bk) . " – $e Prohodit nejde.");
    $bsl = (int)$b['maserka_id'] === $nova ? 'maserka_id' : 'maserka2_id';
    $bdruhy = $bsl === 'maserka_id' ? 'maserka2_id' : 'maserka_id';
    $bz = $b['zacatek']; $bkon = rez_masaz_konec($b);
    if ((int)$b[$bdruhy] === $stara) return plan_chyba('Prohodit nejde: ' . $jm[$stara] . ' je u masáže ' . $popis($bk) . ' už jako druhá masérka.');
    if (!maserka_pracuje($stara, $bz, $bkon)) return plan_chyba($jm[$nova] . " $duvod s " . $popis($bk) . ', ale ' . $jm[$stara] . ' by ji nemohla převzít – v tu dobu nepracuje.');
    $dalsi = array_values(array_filter(kolize($stara, $bz, $bkon, (int)$b['id']), fn($x) => (int)$x['id'] !== $rid));
    if ($dalsi) return plan_chyba($jm[$nova] . " $duvod s " . $popis($bk) . ', ale ' . $jm[$stara] . ' by ji nemohla převzít – má v tu dobu ' . $popis($dalsi[0]) . '.');

    $skupiny = prichody_skupiny();
    $mist = plan_najdi(masterny_den(substr($b['zacatek'], 0, 10), $skupiny, false), (int)$b['id']);
    $kde = $mist !== null ? ' v masérně „' . plan_nazev($mist, $skupiny) . '“' : '';
    if (!$prohodit) {
        return ['ok' => false, 'potvrdit' => true,
            'zprava' => $jm[$nova] . " $duvod s " . $popis($bk) . $kde . '. Prohodit? ' . $jm[$nova] . ' půjde k ' . $kdo . ', ' . $jm[$stara] . ' převezme ' . jmeno_z($b) . '.'];
    }
    $ulozit([[$rid, $sl, $nova], [(int)$b['id'], $bsl, $stara]]);
    plan_znovu_na_tablet($rid); plan_znovu_na_tablet((int)$b['id']);
    zapis_log('plan_maserka', "rez #$rid: " . $jm[$stara] . ' → ' . $jm[$nova] . ' (prohozeno s #' . $b['id'] . ')');
    tablet_auto(true);
    return ['ok' => true, 'zmena' => true, 'zprava' => 'Prohozeno: ' . $jm[$nova] . ' → ' . $kdo . ', ' . $jm[$stara] . ' → ' . jmeno_z($b) . '.'];
}

// ---------- Automatické posílání na tablety ----------
/**
 * Pošle na tablety rezervace, kterým se blíží začátek (Nastavení: tablet_predstih_min před začátkem, nejpozději TABLET_POZDE_MIN po něm).
 * Masérna je ta z plánu (masterny_den), masérka ta z rezervace; chybí-li, doplní se návrh (stejným pravidlem jako dřív panel Příchody).
 * Tablet se zaplní, jen když v něm neběží ani se nehodnotí předchozí masáž – jinak se zkusí znovu při dalším volání.
 * Co už na tabletu bylo (i staženo ručně), se samo znovu neposílá. Volá se z dotazů tabletů a z obrazovky Masérny
 * (nejvýš jednou za 8 s; $force = hned, po přesunu a změně masérky). Vrací počet odeslaných rezervací.
 */
function tablet_auto(bool $force = false): int
{
    try { return tablet_auto_beh($force); }
    catch (Throwable $e) { error_log('tablet_auto: ' . $e); return 0; }
}

function tablet_auto_beh(bool $force): int
{
    $ted = time();
    if (!$force) {
        $p = qv("SELECT hodnota FROM nastaveni WHERE klic='tablet_auto_cas'");
        if ($p === null) q("INSERT OR IGNORE INTO nastaveni(klic,hodnota) VALUES('tablet_auto_cas','0')");
        elseif ((int)$p > $ted - 8) return 0;
        if (q("UPDATE nastaveni SET hodnota=? WHERE klic='tablet_auto_cas' AND CAST(hodnota AS INTEGER) <= ?", [(string)$ted, $ted - 8])->rowCount() < 1) return 0;
    }
    $skupiny = prichody_skupiny();
    if (!$skupiny) return 0;
    $od = date('Y-m-d H:i:s', $ted - TABLET_POZDE_MIN * 60);
    $do = date('Y-m-d H:i:s', $ted + tablet_predstih() * 60);
    $rows = q("SELECT r.*, z.jmeno, z.prijmeni, z.pref_maserka_id, COALESCE(v.maserky, 1) AS var_maserky
        FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id LEFT JOIN varianty v ON v.id=r.varianta_id
        WHERE r.stav='potvrzeno' AND COALESCE(v.maserky, 1) >= 1 AND r.zacatek >= ? AND r.zacatek <= ? AND r.konec > ?
          AND NOT EXISTS (SELECT 1 FROM sezeni s WHERE s.rezervace_id=r.id
              AND (s.stav IN ('cekani','bezi','konec','hotovo') OR (s.stav='zruseno' AND s.ukonceno_duvod<>'presun')))
        ORDER BY r.zacatek, r.id", [$od, $do, date('Y-m-d H:i:s', $ted)])->fetchAll();
    if (!$rows) return 0;

    $kdeJe = [];   // rezervace_id => klíč masérny (dny se počítají zvlášť, ať to přežije i půlnoc)
    foreach (array_unique(array_map(fn($r) => substr($r['zacatek'], 0, 10), $rows)) as $den)
        foreach (masterny_den($den, $skupiny, true) as $kl => $bloky) if ((string)$kl !== '') foreach ($bloky as $b) $kdeJe[(int)$b['id']] = (string)$kl;

    // masérky, které rezervace ještě nemá: návrh (volná, pracující, bez kolize; oblíbená zákazníka první)
    $bez = array_values(array_filter($rows, fn($r) => !(int)$r['maserka_id'] || ((int)$r['var_maserky'] >= 2 || $r['maserka2_id']) && !(int)$r['maserka2_id']));
    $navrh = $bez ? prichody_navrh($bez) : [];

    $odeslano = 0;
    foreach ($rows as $r) {
        $rid = (int)$r['id']; $kl = $kdeJe[$rid] ?? null;
        if ($kl === null) continue;                                   // nevešla se do žádné masérny
        $tablety = $skupiny[$kl];
        $par = (int)$r['var_maserky'] >= 2 || $r['maserka2_id'];
        if ($par && count($tablety) < 2) continue;
        $m1 = (int)$r['maserka_id'] ?: (int)($navrh[$rid]['maserka'] ?? 0);
        $m2 = $par ? ((int)$r['maserka2_id'] ?: (int)($navrh[$rid]['maserka2'] ?? 0)) : 0;
        if (!$m1 || ($par && (!$m2 || $m2 === $m1))) continue;       // bez masérky se nic neposílá (recepce ji vybere dvojklikem)
        $pot = $par ? [$tablety[0], $tablety[1]] : [$tablety[0]];
        $volne = true;
        foreach ($pot as $t) {
            $a = sezeni_aktivni((int)$t['id']);
            if (!$a) continue;
            if ($a['stav'] !== 'cekani') { $volne = false; break; }   // běží masáž nebo se hodnotí – tablet se uvolní sám
            // čeká tam někdo jiný: přepíše se jen když už je zjevně nedošlý
            $cizi = (int)$a['rezervace_id'] !== $rid ? q1('SELECT zacatek FROM rezervace WHERE id=?', [$a['rezervace_id']]) : null;
            if ($cizi && strtotime($cizi['zacatek']) > $ted - TABLET_POZDE_MIN * 60) { $volne = false; break; }
        }
        if (!$volne) continue;
        if ((int)$r['maserka_id'] !== $m1 || ($par && (int)$r['maserka2_id'] !== $m2))
            q('UPDATE rezervace SET maserka_id=?, maserka2_id=?, zmeneno=?, zmenil=? WHERE id=?', [$m1, $par ? $m2 : null, nyni(), 'automaticky', $rid]);
        $rd = rezervace_detail($rid);
        if (!$rd) continue;
        $e1 = tablet_poslat($rd, (int)$tablety[0]['id'], $m1, true);
        $e2 = (!$e1 && $par) ? tablet_poslat($rd, (int)$tablety[1]['id'], $m2, false) : null;
        if ($e1 || $e2) { error_log("tablet_auto: rez #$rid: " . ($e1 ?? $e2)); continue; }
        q("UPDATE sezeni SET poslal='automaticky' WHERE rezervace_id=? AND stav='cekani'", [$rid]);
        zapis_log('tablet_auto', "rez #$rid → " . plan_nazev($kl, $skupiny));
        $odeslano++;
    }
    return $odeslano;
}

// ---------- AJAX z obrazovky Masérny ----------
/** Obslouží POST akce z PLAN_AKCE a odpoví JSONem (končí skriptem). */
function plan_ajax(string $akce): void
{
    try {
        $rid = (int)($_POST['rez_id'] ?? 0);
        if ($akce === 'maserky_info') plan_json(plan_maserky_info($rid));
        if ($akce === 'zmen_maserku') plan_json(rez_zmen_maserku($rid, (int)($_POST['slot'] ?? 1), (int)($_POST['maserka_id'] ?? 0), !empty($_POST['prohodit'])));
        if ($akce === 'presun_masaz') plan_json(rez_presun_masernu($rid, (string)($_POST['maserna'] ?? '')));
        plan_json(plan_chyba('Neznámá akce.'), 400);
    } catch (Throwable $e) {
        error_log('plan_ajax: ' . $e);
        plan_json(plan_chyba('Chyba serveru, zkuste to prosím znovu.'), 500);
    }
}
