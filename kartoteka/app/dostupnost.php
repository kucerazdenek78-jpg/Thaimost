<?php
// Dostupnost masérek (volno / přesčas), státní svátky, kapacita masérn (lůžka, wellness).
//
// Pravidlo: masérka pracuje Po–Pá v otevírací době (Nastavení), kromě státních svátků.
// Volno = výjimka „nepracuje“ (celý den nebo část dne). Přesčas = výjimka „pracuje navíc“
// (víkend, svátek, nebo déle než otevírací doba). Volno má přednost před přesčasem.

const DOST_TYPY = ['volno' => 'Volno', 'prescas' => 'Přesčas'];

/** Státní svátky ČR pro daný rok: ['Y-m-d' => název]. */
function svatky_cz(int $rok): array
{
    static $cache = [];
    if (isset($cache[$rok])) return $cache[$rok];
    $velikonoce = easter_date_cz($rok);
    $pevne = ['01-01' => 'Nový rok, Den obnovy samostatného českého státu', '05-01' => 'Svátek práce', '05-08' => 'Den vítězství',
        '07-05' => 'Cyril a Metoděj', '07-06' => 'Den upálení mistra Jana Husa', '09-28' => 'Den české státnosti',
        '10-28' => 'Den vzniku samostatného československého státu', '11-17' => 'Den boje za svobodu a demokracii',
        '12-24' => 'Štědrý den', '12-25' => '1. svátek vánoční', '12-26' => '2. svátek vánoční'];
    $out = [];
    foreach ($pevne as $md => $n) $out["$rok-$md"] = $n;
    $out[date('Y-m-d', strtotime("$velikonoce -2 days"))] = 'Velký pátek';
    $out[date('Y-m-d', strtotime("$velikonoce +1 day"))] = 'Velikonoční pondělí';
    ksort($out);
    return $cache[$rok] = $out;
}

/** Velikonoční neděle (gregoriánský kalendář, bez rozšíření calendar). */
function easter_date_cz(int $y): string
{
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31); $den = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $y, $mes, $den);
}

function svatek(string $d): ?string { return svatky_cz((int)substr($d, 0, 4))[$d] ?? null; }
function je_pracovni_den(string $d): bool { return (int)date('N', strtotime($d)) <= 5 && !svatek($d); }

function min_z(string $hhmm): int { return (int)substr($hhmm, 0, 2) * 60 + (int)substr($hhmm, 3, 2); }
function hhmm(int $m): string { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }

/** Sjednocení intervalů [[od,do],...] v minutách. */
function intervaly_spoj(array $iv): array
{
    usort($iv, fn($a, $b) => $a[0] <=> $b[0]);
    $out = [];
    foreach ($iv as [$a, $b]) {
        if ($b <= $a) continue;
        if ($out && $a <= $out[count($out) - 1][1]) $out[count($out) - 1][1] = max($out[count($out) - 1][1], $b);
        else $out[] = [$a, $b];
    }
    return $out;
}
/** Odečtení intervalů $minus od $iv. */
function intervaly_minus(array $iv, array $minus): array
{
    foreach ($minus as [$x, $y]) {
        $nove = [];
        foreach ($iv as [$a, $b]) {
            if ($y <= $a || $x >= $b) { $nove[] = [$a, $b]; continue; }
            if ($x > $a) $nove[] = [$a, $x];
            if ($y < $b) $nove[] = [$y, $b];
        }
        $iv = $nove;
    }
    return $iv;
}

/** Výjimky (volno/přesčas) platné pro den – pro všechny masérky. [maserka_id => [záznamy]] */
function dostupnost_den(string $d): array
{
    static $cache = [];
    if (isset($cache[$d])) return $cache[$d];
    $out = [];
    foreach (q('SELECT * FROM maserky_volno WHERE datum_od <= ? AND datum_do >= ? ORDER BY typ, cas_od', [$d, $d])->fetchAll() as $x)
        $out[(int)$x['maserka_id']][] = $x;
    return $cache[$d] = $out;
}

/** Pracovní okna masérky v daný den v minutách od půlnoci: [[od,do],...]. */
function maserka_okna(int $mid, string $d): array
{
    $ot = [min_z(nastaveni('oteviraci_od', '09:00')), min_z(nastaveni('oteviraci_do', '20:00'))];
    $iv = je_pracovni_den($d) ? [$ot] : [];
    $vyj = dostupnost_den($d)[$mid] ?? [];
    foreach ($vyj as $x) if ($x['typ'] === 'prescas') $iv[] = [$x['cas_od'] ? min_z($x['cas_od']) : $ot[0], $x['cas_do'] ? min_z($x['cas_do']) : $ot[1]];
    $iv = intervaly_spoj($iv);
    $minus = [];
    foreach ($vyj as $x) if ($x['typ'] === 'volno') $minus[] = [$x['cas_od'] ? min_z($x['cas_od']) : 0, $x['cas_do'] ? min_z($x['cas_do']) : 24 * 60];
    return intervaly_minus($iv, $minus);
}

/** Pracuje masérka celou dobu [zac, kon)? (stejný den) */
function maserka_pracuje(int $mid, string $zac, string $kon): bool
{
    $d = substr($zac, 0, 10);
    $a = min_z(substr($zac, 11, 5)); $b = substr($kon, 0, 10) === $d ? min_z(substr($kon, 11, 5)) : 24 * 60;
    foreach (maserka_okna($mid, $d) as [$x, $y]) if ($a >= $x && $b <= $y) return true;
    return false;
}

/** Krátký popis dne masérky do hlavičky kalendáře: volno / přesčas / nepracuje. */
function maserka_den_popis(int $mid, string $d): array
{
    $vyj = dostupnost_den($d)[$mid] ?? [];
    $o = [];
    foreach ($vyj as $x) {
        $cas = $x['cas_od'] ? $x['cas_od'] . '–' . $x['cas_do'] : 'celý den';
        $o[] = [$x['typ'], ($x['typ'] === 'volno' ? '🌴 volno ' : '⏱ přesčas ') . $cas . ($x['poznamka'] ? ' – ' . $x['poznamka'] : '')];
    }
    if (!$vyj && !je_pracovni_den($d)) $o[] = ['nepracuje', svatek($d) ? '🎌 ' . svatek($d) : 'víkend – bez přesčasu'];
    return $o;
}

// ---------- Kapacita masérn ----------
/** Masérna, do které tablet/lůžko patří (prázdná skupina = samostatná jednolůžková masérna). */
function maserna_klic(array $t): string { return trim($t['skupina']) !== '' ? mb_strtolower(trim($t['skupina'])) : 'id' . $t['id']; }

/** Kapacita: lůžko = aktivní tablet. Wellness zabere celou masérnu (všechna její lůžka).
 *  [luzka, wellness_mist => počet masérn pro wellness, luzka_wellness => lůžek ve wellness masérně, masérny => [klíč => [nazev, luzka, wellness]], nastaveno] */
function kapacita(): array
{
    static $k = null;
    if ($k) return $k;
    $mas = [];
    foreach (q('SELECT * FROM mistnosti WHERE aktivni=1 ORDER BY poradi, id')->fetchAll() as $t) {
        $kl = maserna_klic($t);
        $mas[$kl] = $mas[$kl] ?? ['nazev' => trim($t['skupina']) ?: $t['nazev'], 'luzka' => 0, 'wellness' => false];
        $mas[$kl]['luzka']++;
        if ((int)$t['wellness'] === 1) $mas[$kl]['wellness'] = true;
    }
    $well = array_filter($mas, fn($m) => $m['wellness']);
    return $k = ['luzka' => array_sum(array_column($mas, 'luzka')), 'wellness_mist' => count($well),
        'luzka_wellness' => $well ? max(array_column($well, 'luzka')) : 0, 'masery' => $mas,
        'nastaveno' => nastaveni('mistnosti_nastaveny') === '1' && $mas];
}

/** Rezervace, které se v čase [zac, kon) překrývají (aktivní), s druhem služby. */
function soubezne(string $zac, string $kon, int $mimoId = 0): array
{
    return q("SELECT r.id, r.zacatek, r.konec, COALESCE(v.wellness_mistnost, 0) AS w FROM rezervace r LEFT JOIN varianty v ON v.id=r.varianta_id
        WHERE r.stav IN ('potvrzeno','probehlo') AND r.id<>? AND r.zacatek < ? AND r.konec > ?", [$mimoId, $kon, $zac])->fetchAll();
}

/** Vejde se masáž/wellness do masérn? Vrací null, nebo text problému. */
function kapacita_problem(string $zac, string $kon, bool $wellness, int $mimoId = 0, ?array $soub = null): ?string
{
    $k = kapacita();
    if (!$k['nastaveno']) return null;
    $soub = $soub ?? soubezne($zac, $kon, $mimoId);
    // kontrolní body: začátek nové rezervace a začátky překrývajících se
    $body = [strtotime($zac)];
    foreach ($soub as $r) { $t = strtotime($r['zacatek']); if ($t > strtotime($zac) && $t < strtotime($kon)) $body[] = $t; }
    foreach ($body as $t) {
        $w = $wellness ? 1 : 0; $mas = $wellness ? 0 : 1;
        foreach ($soub as $r) if (strtotime($r['zacatek']) <= $t && strtotime($r['konec']) > $t) { if ((int)($r['w'] ?? 0) === 1) $w++; else $mas++; }
        if ($w > $k['wellness_mist']) return $k['wellness_mist'] ? 'Wellness masérna je v tu dobu obsazená.' : 'Žádná masérna není nastavená pro wellness (Tým → Masérny).';
        $volnaLuzka = $k['luzka'] - $w * $k['luzka_wellness'];
        if ($mas > $volnaLuzka) return 'V ' . date('H:i', $t) . ' jsou obsazená všechna lůžka (' . $k['luzka'] . ($w ? ', z toho wellness masérna' : '') . ').';
    }
    return null;
}

// ---------- Konflikty: rezervace u masérky, která v tu dobu nepracuje ----------
function konflikty_dostupnosti(?string $od = null, ?string $do = null): array
{
    $od = $od ?? nyni(); $do = $do ?? date('Y-m-d', strtotime('+120 days'));
    $rows = q("SELECT r.*, z.jmeno, z.prijmeni, z.telefon, s.nazev AS sluzba, s.kat, v.popis AS var_popis, m.jmeno AS maserka, m2.jmeno AS maserka2
        FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id LEFT JOIN varianty v ON v.id=r.varianta_id
        LEFT JOIN sluzby s ON s.id=v.sluzba_id LEFT JOIN maserky m ON m.id=r.maserka_id LEFT JOIN maserky m2 ON m2.id=r.maserka2_id
        WHERE r.stav='potvrzeno' AND (r.maserka_id IS NOT NULL OR r.maserka2_id IS NOT NULL) AND r.konec > ? AND r.zacatek < ? ORDER BY r.zacatek", [$od, $do])->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        // u párové může nepracovat jen jedna z obou – do konfliktu jde ta (slot = 1 nebo 2)
        foreach ([[1, 'maserka_id', 'maserka'], [2, 'maserka2_id', 'maserka2']] as [$slot, $col, $jm])
            if ($r[$col] && !maserka_pracuje((int)$r[$col], $r['zacatek'], rez_masaz_konec($r))) $out[] = $r + ['slot' => $slot, 'konf_mid' => (int)$r[$col], 'konf_jmeno' => $r[$jm]];
    }
    return $out;
}

/** Zapíše volno / přesčas masérky (ručně i z asistenta). Vrací ['chyba' => ?string, 'konflikty' => počet masáží, které tím přestanou sedět]. */
function volno_pridej(int $mid, string $typ, string $dOd, string $dDo, string $cOd, string $cDo, string $poznamka = ''): array
{
    $typ = array_key_exists($typ, DOST_TYPY) ? $typ : 'volno';
    $dDo = $dDo ?: $dOd;
    $ok = $mid && qv('SELECT 1 FROM maserky WHERE id=?', [$mid]) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $dOd) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $dDo) && $dDo >= $dOd
        && (($cOd === '' && $cDo === '') || (preg_match('~^\d{2}:\d{2}$~', $cOd) && preg_match('~^\d{2}:\d{2}$~', $cDo) && $cDo > $cOd));
    if (!$ok) return ['chyba' => 'Zkontrolujte masérku, datum a čas (čas buď obojí, nebo nic = celý den).', 'konflikty' => 0];
    q('INSERT INTO maserky_volno(maserka_id,typ,datum_od,datum_do,cas_od,cas_do,poznamka,kdo) VALUES(?,?,?,?,?,?,?,?)',
        [$mid, $typ, $dOd, $dDo, $cOd ?: null, $cDo ?: null, $poznamka, kdo()]);
    zapis_log('dostupnost', "$typ masérka $mid $dOd–$dDo $cOd–$cDo");
    $kon = konflikty_dostupnosti(max(nyni(), "$dOd 00:00:00"), date('Y-m-d', strtotime("$dDo +1 day")));
    return ['chyba' => null, 'konflikty' => count(array_filter($kon, fn($r) => (int)$r['konf_mid'] === $mid))];
}

// ---------- Stránka Volna a přesčasy ----------
function stranka_dostupnost(array $u): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = $_POST['akce'] ?? '';
        if ($akce === 'pridat') {
            $typ = array_key_exists($_POST['typ'] ?? '', DOST_TYPY) ? $_POST['typ'] : 'volno';
            $res = volno_pridej((int)($_POST['maserka_id'] ?? 0), $typ, $_POST['datum_od'] ?? '', $_POST['datum_do'] ?? '',
                trim($_POST['cas_od'] ?? ''), trim($_POST['cas_do'] ?? ''), trim($_POST['poznamka'] ?? ''));
            if ($res['chyba']) flash($res['chyba'], 'err');
            else flash(DOST_TYPY[$typ] . ' uloženo.' . ($res['konflikty'] ? ' ⚠ V tu dobu má masérka ' . $res['konflikty'] . ' naplánovaných masáží – vyřešte je níže se zákazníky.' : ''), $res['konflikty'] ? 'warn' : 'ok');
        } elseif ($akce === 'smazat') {
            q('DELETE FROM maserky_volno WHERE id=?', [(int)($_POST['id'] ?? 0)]);
            flash('Smazáno.');
        } elseif ($akce === 'preradit') {
            $r = rezervace_detail((int)($_POST['rez_id'] ?? 0));
            $nova = (int)($_POST['maserka_id'] ?? 0);
            $col = ($_POST['slot'] ?? '1') === '2' ? 'maserka2_id' : 'maserka_id';
            if ($r && $nova) {
                q("UPDATE rezervace SET $col=?, zmeneno=?, zmenil=? WHERE id=?", [$nova, nyni(), kdo(), $r['id']]);
                q("UPDATE sezeni SET stav='zruseno', zavreno=? WHERE rezervace_id=? AND stav='cekani'", [nyni(), $r['id']]);
                $pozn = trim($_POST['poznamka'] ?? '');
                if ($pozn !== '') q('UPDATE rezervace SET poznamka_masaz=TRIM(poznamka_masaz || ?) WHERE id=?', ["\n" . date('j. n.') . ': ' . $pozn, $r['id']]);
                zapis_log('rezervace_preradit', "#{$r['id']} {$r['maserka_id']} → $nova");
                flash(jmeno_z($r) . ' přeřazen(a) na ' . qv('SELECT jmeno FROM maserky WHERE id=?', [$nova]) . '.');
            }
        }
        presmeruj('/?r=dostupnost' . (!empty($_POST['zpet_d']) ? '&d=' . urlencode($_POST['zpet_d']) : ''));
    }

    $maserky = q('SELECT * FROM maserky WHERE aktivni=1 ORDER BY poradi, id')->fetchAll();
    $jmena = array_column($maserky, 'jmeno', 'id');
    $zaznamy = q("SELECT * FROM maserky_volno WHERE datum_do >= ? ORDER BY datum_od, maserka_id", [date('Y-m-d', strtotime('-7 days'))])->fetchAll();
    $konflikty = konflikty_dostupnosti();
    $rok = (int)date('Y');
    $svatky = array_filter(svatky_cz($rok) + svatky_cz($rok + 1), fn($d) => $d >= date('Y-m-d') && $d <= date('Y-m-d', strtotime('+120 days')), ARRAY_FILTER_USE_KEY);

    hlavicka('Volna a přesčasy', 'dostupnost'); ?>
    <h1>Volna a přesčasy masérek</h1>
    <p class="muted small">Masérka pracuje Po–Pá <?= h(nastaveni('oteviraci_od', '09:00')) ?>–<?= h(nastaveni('oteviraci_do', '20:00')) ?>, ve státní svátky ne. Sem zapište, kdy má <b>volno</b> (nepracuje) nebo kdy bere <b>přesčas</b> (víkend, svátek, déle). Kalendář, hledání volných termínů i kontrola při rezervaci s tím počítají.</p>

    <?php if ($konflikty): ?>
    <section class="card konflikty">
      <h2>⚠ K vyřešení se zákazníkem (<?= count($konflikty) ?>)</h2>
      <p class="muted small">Masáže naplánované u masérky, která v tu dobu nepracuje. Zavolejte zákazníkovi a domluvte jinou masérku nebo jiný termín. U každé masérky je vidět, jestli je v tu dobu volná (✓), obsazená (přeplnit ⚠) nebo nepracuje.</p>
      <?php foreach ($konflikty as $r):
          $soub = soubezne($r['zacatek'], $r['konec'], (int)$r['id']); ?>
        <form method="post" class="row-item konf">
          <?= csrf_pole() ?><input type="hidden" name="akce" value="preradit"><input type="hidden" name="rez_id" value="<?= $r['id'] ?>"><input type="hidden" name="slot" value="<?= (int)$r['slot'] ?>">
          <div class="time"><?= h(date('j. n.', strtotime($r['zacatek']))) ?><br><b><?= date('H:i', strtotime($r['zacatek'])) ?></b>–<?= date('H:i', strtotime(rez_masaz_konec($r))) ?></div>
          <div class="grow"><a href="/?r=rezervace&id=<?= $r['id'] ?>"><b><?= h(jmeno_z($r)) ?></b></a> · <a href="tel:<?= h($r['telefon']) ?>">📞 <?= h(telefon_cz($r['telefon'])) ?></a><br>
            <?= h($r['sluzba'] . ' – ' . $r['var_popis']) ?> · u <b><?= h($r['konf_jmeno']) ?></b> <span class="tag nesp">nepracuje</span><?= $r['maserka2_id'] ? ' <span class="muted small">(párová: ' . h($r['maserka'] . ' + ' . $r['maserka2']) . ')</span>' : '' ?>
            <input name="poznamka" placeholder="poznámka (např. domluveno se zákazníkem)" class="small-in">
          </div>
          <div class="konf-btn">
          <?php foreach ($maserky as $m): if (in_array((int)$m['id'], [(int)$r['maserka_id'], (int)$r['maserka2_id']], true)) continue;
              $prac = maserka_pracuje((int)$m['id'], $r['zacatek'], rez_masaz_konec($r));
              $kol = $prac ? kolize((int)$m['id'], $r['zacatek'], rez_masaz_konec($r), (int)$r['id']) : []; ?>
            <button class="btn small <?= !$prac ? 'off' : ($kol ? 'warn' : 'primary') ?>" name="maserka_id" value="<?= $m['id'] ?>"
              <?= !$prac ? 'disabled title="nepracuje"' : ($kol ? 'title="obsazená: ' . h(jmeno_z($kol[0])) . ' ' . date('H:i', strtotime($kol[0]['zacatek'])) . ' – přeplnit" data-confirm="' . h($m['jmeno']) . ' má v tu dobu ' . h(jmeno_z($kol[0])) . '. Opravdu přeplnit?"' : 'title="volná"') ?>>
              <?= !$prac ? '' : ($kol ? '⚠ ' : '✓ ') ?><?= h($m['jmeno']) ?></button>
          <?php endforeach ?>
            <a class="btn small" href="/?r=volno&z=<?= (int)$r['zakaznik_id'] ?>&v=<?= (int)$r['varianta_id'] ?>&od=<?= h(substr($r['zacatek'], 0, 10)) ?>&presun=<?= (int)$r['id'] ?>">Jiný termín…</a>
          </div>
        </form>
      <?php endforeach ?>
    </section>
    <?php endif ?>

    <div class="cols">
      <div>
        <form method="post" class="card">
          <?= csrf_pole() ?><input type="hidden" name="akce" value="pridat">
          <h2>Zapsat volno nebo přesčas</h2>
          <div class="grid3">
            <label>Masérka<select name="maserka_id" required><?php foreach ($maserky as $m): ?><option value="<?= $m['id'] ?>"><?= h($m['jmeno']) ?></option><?php endforeach ?></select></label>
            <label>Co<select name="typ"><option value="volno">🌴 Volno – nepracuje</option><option value="prescas">⏱ Přesčas – pracuje navíc</option></select></label>
            <label>Poznámka<input name="poznamka" placeholder="dovolená, lékař, sobota…"></label>
          </div>
          <div class="grid3">
            <label>Od dne<input type="date" name="datum_od" required value="<?= h($_GET['d'] ?? date('Y-m-d')) ?>"></label>
            <label>Do dne <span class="muted small">(víc dní)</span><input type="date" name="datum_do"></label>
            <label>Čas <span class="muted small">(prázdné = celý den)</span><span style="display:flex;gap:6px"><input type="time" name="cas_od" step="900"><input type="time" name="cas_do" step="900"></span></label>
          </div>
          <p class="muted small">Přesčas o víkendu/svátku bez času = otevírací doba. Přesčas ve všední den zadejte s časem (např. 20:00–21:30).</p>
          <button class="btn primary">Uložit</button>
        </form>

        <section class="card">
          <h2>Zapsaná volna a přesčasy</h2>
          <?php if (!$zaznamy): ?><p class="muted">Zatím nic.</p><?php endif ?>
          <table class="tbl plain">
          <?php foreach ($zaznamy as $x):
              $vyuziti = '';
              if ($x['typ'] === 'prescas') {   // kolik z přesčasu je už naplánováno
                  $celkem = 0; $obs = 0;
                  for ($d = $x['datum_od']; $d <= $x['datum_do']; $d = date('Y-m-d', strtotime("$d +1 day"))) {
                      $a = $x['cas_od'] ?: nastaveni('oteviraci_od', '09:00'); $b = $x['cas_do'] ?: nastaveni('oteviraci_do', '20:00');
                      $celkem += min_z($b) - min_z($a);
                      $obs += (int)qv("SELECT COALESCE(SUM((julianday(MIN(konec, ?)) - julianday(MAX(zacatek, ?))) * 1440), 0) FROM rezervace
                          WHERE maserka_id=? AND stav IN ('potvrzeno','probehlo') AND zacatek < ? AND konec > ?", ["$d $b:00", "$d $a:00", $x['maserka_id'], "$d $b:00", "$d $a:00"]);
                  }
                  $vyuziti = ' · naplánováno ' . round($obs / 60, 1) . ' z ' . round($celkem / 60, 1) . ' h';
              } ?>
            <tr><td><span class="tag <?= $x['typ'] === 'volno' ? 'nesp' : 'volny' ?>"><?= $x['typ'] === 'volno' ? '🌴 volno' : '⏱ přesčas' ?></span></td>
              <td><b><?= h($jmena[$x['maserka_id']] ?? '?') ?></b></td>
              <td><?= h(date('j. n.', strtotime($x['datum_od']))) ?><?= $x['datum_do'] !== $x['datum_od'] ? '–' . h(date('j. n. Y', strtotime($x['datum_do']))) : ' ' . date('Y', strtotime($x['datum_od'])) ?>
                · <?= $x['cas_od'] ? h($x['cas_od'] . '–' . $x['cas_do']) : 'celý den' ?><?= h($vyuziti) ?>
                <?= $x['poznamka'] ? '<br><span class="muted small">' . h($x['poznamka']) . '</span>' : '' ?>
                <?php if ($x['typ'] === 'prescas'): ?><br><a class="small" href="/?r=kalendar&d=<?= h(max($x['datum_od'], date('Y-m-d'))) ?>">naplánovat v kalendáři →</a><?php endif ?></td>
              <td><form method="post" class="inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="smazat"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button class="btn small" data-confirm="Smazat záznam?">Smazat</button></form></td></tr>
          <?php endforeach ?>
          </table>
        </section>
      </div>
      <div>
        <section class="card side">
          <h3>Státní svátky (příštích 120 dní)</h3>
          <?php if (!$svatky): ?><p class="muted small">Žádné.</p><?php endif ?>
          <?php foreach ($svatky as $d => $n): ?><p class="small"><b><?= h(datum_cz($d, false)) ?></b> – <?= h($n) ?></p><?php endforeach ?>
          <p class="muted small">Ve svátek masérky nepracují, pokud nemají zapsaný přesčas.</p>
        </section>
      </div>
    </div>
    <?php paticka();
}
