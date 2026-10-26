<?php
// Úvodní obrazovka recepce „Masérny“: co se děje v masérnách teď, kdy jsou v plánu díry, kdo právě přichází a co je k vyřízení.
// Cíl: masérny plné lidí. Recepční si termín vybírá sama – systém jen ukazuje volná místa (odkaz „+ rezervace“ otevře formulář s předvyplněným časem).
// Bloky masáží se dají přetahovat mezi masérnami (když tam ve stejný čas někdo je, prohodí se), dvojklikem se mění masérka
// (plan.php). Na tablety se rezervace posílají samy (tablet_auto). Čára „teď“ ukazuje aktuální čas v každém sloupci.
// Ostatní POST akce (ukončení masáže, hromadné stavy) obsluhuje dál stranka_dnes(); starý podrobný přehled je na /?r=dnes_stary.

/**
 * Volná okna jedné masérny. $bloky = [[od, do], …] v minutách od půlnoci.
 * Vrací [[start, nejdelší_masáž_v_min], …]: start je po přestávce za předchozí masáží (a ne dřív než teď), zaokrouhlený nahoru na 5 min;
 * nejdelší masáž už počítá přestávku před další masáží. Okna, do kterých se nevejde ani nejkratší masáž, se vynechají.
 */
function prehled_okna(array $bloky, int $od, int $do, int $nejkratsi, int $pm, int $odTed): array
{
    usort($bloky, fn($a, $b) => $a[0] <=> $b[0]);
    $okna = [];
    $pridej = function (int $z, int $k, bool $mel, bool $bude) use (&$okna, $nejkratsi, $pm, $odTed) {
        $s = max($z + ($mel ? $pm : 0), $odTed);
        $s = (int)(ceil($s / 5) * 5);
        $max = $k - $s - ($bude ? $pm : 0);
        if ($max >= $nejkratsi) $okna[] = [$s, $max];
    };
    $kurz = $od; $mel = false;
    foreach ($bloky as [$a, $b]) {
        if ($a > $kurz) $pridej($kurz, $a, $mel, true);
        if ($b >= $kurz) { $kurz = $b; $mel = true; }
    }
    if ($do > $kurz) $pridej($kurz, $do, $mel, false);
    return $okna;
}

/** „až 50 min“ / „3 h a víc“ – dlouhá okna nemá smysl vypisovat na minuty. */
function prehled_delka(int $min): string { return $min >= 180 ? '3 h a víc' : 'až ' . $min . ' min'; }

function stranka_prehled(array $u): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = (string)($_POST['akce'] ?? '');
        if (in_array($akce, PLAN_AKCE, true)) plan_ajax($akce);   // přetažení, změna masérky (odpoví JSONem a skončí)
        stranka_dnes($u);
        return;
    }
    tablet_auto();   // nejvýš jednou za 8 s: pošle na tablety, co se blíží

    $dnes = date('Y-m-d');
    $zitra = date('Y-m-d', strtotime('+1 day'));
    $nyni = time();
    $ted = (int)date('G') * 60 + (int)date('i');
    $pm = prestavka();
    $lead = tablet_predstih();
    $minz = fn($dt) => (int)date('G', strtotime($dt)) * 60 + (int)date('i', strtotime($dt));
    $skupiny = prichody_skupiny();
    $minOd = min_z(nastaveni('oteviraci_od', '09:00')); $minDo = min_z(nastaveni('oteviraci_do', '20:00'));
    $nejkratsi = (int)qv('SELECT MIN(minuty) FROM varianty WHERE aktivni=1 AND minuty>0') ?: 30;

    // --- dnešní plán po masérnách (stejné rozdělení jako Kalendář masérn) ---
    $plan = $skupiny ? masterny_den($dnes, $skupiny, true) : [];
    foreach ($plan as $bl) foreach ($bl as $r) $minDo = max($minDo, $minz($r['konec']));
    $jmMas = q('SELECT id, jmeno FROM maserky')->fetchAll(PDO::FETCH_KEY_PAIR);
    $kNavrhu = [];
    foreach ($plan as $bl) foreach ($bl as $r) if ($r['stav'] === 'potvrzeno' && (int)$r['var_maserky'] > 0) $kNavrhu[] = $r;
    usort($kNavrhu, fn($a, $b) => strcmp($a['zacatek'], $b['zacatek']) ?: $a['id'] <=> $b['id']);
    $navrhMas = $kNavrhu ? prichody_navrh($kNavrhu) : [];

    // --- stav tabletů (kdo je právě v které masérně) ---
    $stavMist = [];   // id tabletu => sezení (+ příjmení, masérka) nebo null
    foreach (q('SELECT * FROM mistnosti WHERE aktivni=1 ORDER BY poradi, nazev')->fetchAll() as $m) {
        $sz = sezeni_aktivni((int)$m['id']);
        if ($sz) $sz += q1('SELECT z.prijmeni, z.jmeno, ma.jmeno AS maserka FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id LEFT JOIN maserky ma ON ma.id=? WHERE r.id=?', [$sz['maserka_id'], $sz['rezervace_id']]) ?: [];
        $stavMist[(int)$m['id']] = $sz;
    }
    $sezRez = [];     // rezervace_id => poslední sezení dnešní rezervace (pro štítek u bloku)
    foreach (q("SELECT rezervace_id, mistnost_id, stav, neni_ona, plan_konec FROM sezeni WHERE stav<>'zruseno' AND rezervace_id IN (SELECT id FROM rezervace WHERE zacatek >= ? AND zacatek < ?) ORDER BY id", [$dnes, $zitra])->fetchAll() as $x)
        $sezRez[(int)$x['rezervace_id']] = $x;

    // --- sloupce masérn: hlavička „teď“ + seznam masáží a děr ---
    $sloupce = []; $okna = [];
    foreach ($skupiny as $kl => $tablety) {
        $nazev = trim($tablety[0]['skupina']) !== '' ? $tablety[0]['skupina'] : $tablety[0]['nazev'];
        $bloky = $plan[$kl] ?? [];
        $iv = array_map(fn($r) => [$minz($r['zacatek']), $minz($r['konec'])], $bloky);
        $ok = $dnes === date('Y-m-d') ? prehled_okna($iv, $minOd, $minDo, $nejkratsi, $pm, $ted) : [];
        foreach ($ok as [$s, $mx]) $okna[] = [$s, $mx, $kl, $nazev];
        $sloupce[$kl] = ['nazev' => $nazev, 'duo' => count($tablety) > 1, 'well' => (bool)array_filter($tablety, fn($t) => !empty($t['wellness'])),
            'tablety' => $tablety, 'bloky' => $bloky, 'okna' => $ok, 'volno' => array_sum(array_column($ok, 1))];
    }
    usort($okna, fn($a, $b) => $a[0] <=> $b[0] ?: $b[1] <=> $a[1]);

    // --- upozornění ---
    $poplach = [];
    foreach ($skupiny as $kl => $tablety) foreach ($tablety as $t) { $sz = $stavMist[(int)$t['id']] ?? null; if ($sz && $sz['neni_ona']) $poplach[] = [$sloupce[$kl]['nazev'], $sz]; }
    $konf = konflikty_dostupnosti();
    $nevesel = $plan[''] ?? [];

    // --- k vyřízení ---
    $zadosti = q("SELECT r.*, z.jmeno, z.prijmeni, z.telefon, s.nazev AS sluzba, v.popis AS var_popis
        FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id LEFT JOIN varianty v ON v.id=r.varianta_id
        LEFT JOIN sluzby s ON s.id=v.sluzba_id WHERE r.stav='zadost' ORDER BY r.vytvoreno")->fetchAll();
    $kontrola = ke_kontrole();
    $neodeslane = (int)qv("SELECT COUNT(*) FROM emaily WHERE stav<>'odeslano'");

    hlavicka('Masérny', 'dnes'); ?>
    <style>
      .pb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;align-items:start}
      .pb-col{border-radius:12px;transition:background .12s}
      .pb-head{margin-bottom:8px}.pb-head b{font-size:1.05rem}.pb-head small{font-weight:400;opacity:.8}
      .pb-head .ln{display:block;color:inherit;text-decoration:none;margin-top:3px}.pb-head a.ln:hover{text-decoration:underline}
      .pb-blk{position:relative;display:block;color:inherit;padding:6px 9px;margin-bottom:6px;border-radius:8px;background:#fff;border:1px solid var(--bd);border-left:4px solid #7a5ea8;-webkit-user-select:none;user-select:none;touch-action:manipulation}
      .pb-blk.lze{padding-left:28px}
      .pb-blk.lze:hover{background:#faf6f0}
      .pb-blk.minulo{opacity:.55}.pb-blk.nevesel{border-left-color:#b3261e;background:#fbe9e7}
      .pb-blk a.n{color:inherit;text-decoration:none;-webkit-user-drag:none}.pb-blk a.n:hover{text-decoration:underline}
      .pb-blk .t{font-weight:700;margin-right:6px}.pb-blk .s{display:block;font-size:.85rem;color:var(--mut)}
      .pb-blk .st{float:right;font-size:.78rem;font-weight:600;margin-left:6px}.pb-blk .st.al{color:#fff;background:#d9262c;border-radius:6px;padding:0 6px}
      .pb-blk .st.mut{font-weight:500;color:var(--mut)}.pb-blk .st.varuj{color:#9a5b00}
      .pb-blk .grip{position:absolute;left:0;top:0;bottom:0;width:26px;display:flex;align-items:center;justify-content:center;cursor:grab;touch-action:none}
      .pb-blk .grip::before{content:"";width:12px;height:24px;background:radial-gradient(circle,#a89884 1.5px,transparent 2px) 0 0/6px 8px}
      .pb-blk button.mas{font:inherit;color:inherit;border:1px dashed #b9a98f;background:#faf6f0;border-radius:6px;padding:0 7px;cursor:pointer}
      .pb-blk button.mas:hover{background:#efe6d6}
      .pb-blk.pb-ceka{opacity:.5;pointer-events:none}
      .pb-blk{isolation:isolate}
      .pb-blk .ted-v{position:absolute;z-index:-1;left:0;right:0;height:0;border-top:2px dashed #d9262c;opacity:.6;pointer-events:none}
      .pb-blk .ted-v::before{content:"";position:absolute;left:-4px;top:-6px;width:10px;height:10px;border-radius:50%;background:#d9262c}
      .pb-ted{position:relative;height:0;border-top:2px solid #d9262c;margin:13px 0 15px}
      .pb-ted::before{content:"";position:absolute;left:-4px;top:-6px;width:10px;height:10px;border-radius:50%;background:#d9262c}
      .pb-ted span{position:absolute;right:0;top:-12px;background:#d9262c;color:#fff;font-size:.8rem;font-weight:700;padding:1px 8px;border-radius:999px}
      .pb-hodiny{display:inline-block;margin-left:10px;padding:1px 10px;border-radius:999px;background:#d9262c;color:#fff;font-size:.9rem;font-weight:700;vertical-align:middle}
      .pg{display:block;text-decoration:none;padding:7px 9px;margin-bottom:6px;border:1.5px dashed #5b8a72;border-radius:8px;background:rgba(227,239,232,.55);color:#2f5446}
      .pg:hover{background:#e3efe8}.pg b{font-size:1.02rem}
      .pb-pills{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px}
      .pb-pills a{display:inline-block;text-decoration:none;padding:5px 11px;border-radius:999px;background:#e3efe8;border:1px solid #5b8a72;color:#2f5446;font-size:.9rem}
      .pb-pills a:hover{background:#d3e7da}
      .pb-minulo summary{cursor:pointer;color:var(--mut);font-size:.85rem;margin:2px 0 6px}
      .vyrizeni summary{cursor:pointer;font-weight:600;color:var(--hn)}.vyrizeni summary .cnt{margin-left:4px}
      /* přetahování */
      body.pm-dnd{-webkit-user-select:none;user-select:none;cursor:grabbing}
      .pb-blk.drag-src{opacity:.3}
      .pb-duch{position:fixed;left:0;top:0;z-index:9000;pointer-events:none;box-shadow:0 10px 28px rgba(0,0,0,.28);opacity:.95;margin:0;border-color:#7a5ea8}
      .pb-col.cil{outline:3px dashed #5b8a72;outline-offset:3px;background:rgba(227,239,232,.55)}
      .pb-col.cil-ne{outline:3px dashed #b3261e;outline-offset:3px;background:rgba(251,233,231,.55)}
      .pb-blk.prohodi{outline:3px solid #e08a00;background:#fff3e0}
      .pm-hint{position:fixed;left:50%;top:12px;transform:translate(-50%,-140%);z-index:9100;max-width:min(92vw,640px);padding:10px 16px;border-radius:12px;background:#2f5446;color:#fff;font-weight:600;box-shadow:0 6px 20px rgba(0,0,0,.3);transition:transform .15s;pointer-events:none;text-align:center}
      .pm-hint.show{transform:translate(-50%,0)}.pm-hint.prohod{background:#b86e00}.pm-hint.ne{background:#b3261e}
      .pm-toast{position:fixed;left:50%;bottom:22px;transform:translate(-50%,160%);z-index:9200;max-width:min(92vw,640px);padding:12px 18px;border-radius:12px;background:#2f5446;color:#fff;font-weight:600;box-shadow:0 6px 20px rgba(0,0,0,.3);transition:transform .2s;text-align:center}
      .pm-toast.show{transform:translate(-50%,0)}.pm-toast.err{background:#b3261e}
      /* okno s masérkami */
      .pm-okno{position:fixed;inset:0;background:rgba(40,30,20,.45);display:flex;align-items:center;justify-content:center;z-index:9500;padding:16px}
      .pm-okno[hidden]{display:none}
      .pm-box{background:#fff;border-radius:16px;max-width:600px;width:100%;max-height:90vh;overflow:auto;padding:18px 20px;box-shadow:0 20px 60px rgba(0,0,0,.35)}
      .pm-box h3{margin:0 0 2px}.pm-box h4{margin:14px 0 8px;color:var(--mut)}.pm-box p{margin:6px 0}
      .pm-mr{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
      .pm-m{font:inherit;font-size:1.05rem;min-height:58px;border-radius:12px;border:2px solid var(--bd);background:#fff;color:inherit;cursor:pointer;padding:8px 10px}
      .pm-m:hover:not(:disabled){background:#faf6f0}
      .pm-m.akt{border-color:#5b8a72;background:#e3efe8;font-weight:700}
      .pm-m.obsazena{border-style:dashed;background:#fff8ee}
      .pm-m:disabled{opacity:.45;cursor:not-allowed}
      .pm-m small{display:block;font-size:.72rem;color:var(--mut);font-weight:400}
      .pm-chyba{color:#b3261e;font-weight:600;min-height:1.2em}
      .pm-pat{display:flex;gap:10px;justify-content:flex-end;margin-top:14px}
      @media (pointer:coarse),(any-pointer:coarse),(max-width:1100px){
        .pb-blk{padding:12px 14px}.pb-blk.lze{padding-left:52px}.pb-blk .grip{width:44px}.pb-blk button.mas{padding:8px 13px;min-height:40px}
        .pg{padding:14px;min-height:52px}.pb-pills a{padding:12px 17px;font-size:1rem}
        .pb-head{padding:12px 14px}.pb-head a.ln{padding:11px 0 8px}.pb-minulo summary{padding:12px 0;font-size:.95rem}.vyrizeni summary{padding:10px 0;font-size:1.05rem}
        .pm-m{min-height:64px;font-size:1.1rem}
      }
    </style>
    <h1>Masérny <small><?= h(datum_cz($dnes, false)) ?></small></h1>

    <?php foreach ($poplach as [$mn, $sz]): ?>
      <div class="flash err">⚠ <b><?= h($mn) ?></b>: masérka hlásí, že to není ona (<?= h(trim($sz['prijmeni'] ?: $sz['jmeno'])) ?>). <a class="btn small" href="/?r=rezervace&id=<?= (int)$sz['rezervace_id'] ?>#tablet">Vyřešit</a></div>
    <?php endforeach ?>
    <?php if ($konf): ?>
      <div class="flash warn">⚠ <b><?= count($konf) ?> masáž<?= count($konf) === 1 ? '' : (count($konf) < 5 ? 'e' : 'í') ?> u masérky, která v tu dobu nepracuje</b> – nejbližší <?= h(datum_cz($konf[0]['zacatek'])) ?> <?= h(jmeno_z($konf[0])) ?>. <a class="btn small" href="/?r=dostupnost">Vyřešit</a></div>
    <?php endif ?>
    <?php if ($nevesel): ?>
      <div class="flash warn">⚠ <b><?= count($nevesel) ?> dnešní rezervace se nevejde do žádné masérny</b> (např. <?= h(date('H:i', strtotime($nevesel[0]['zacatek']))) ?> <?= h(jmeno_z($nevesel[0])) ?>). <a class="btn small" href="/?r=kalendar&pohled=masterny">Otevřít kalendář masérn</a></div>
    <?php endif ?>
    <?php ukoncene_dnes_banner(); ?>

    <?php if ($zadosti): ?>
    <section class="card" id="zadosti">
      <h2>Žádosti z webu <span class="muted">(<?= count($zadosti) ?>)</span></h2>
      <?php foreach ($zadosti as $r): $z = q1('SELECT * FROM zakaznici WHERE id=?', [$r['zakaznik_id']]); ?>
        <div class="row-item">
          <div>
            <b><?= h(jmeno_z($r)) ?></b> · <a href="tel:<?= h($r['telefon']) ?>"><?= h(telefon_cz($r['telefon'])) ?></a>
            <?= stitky_html(zakaznik_stitky($z, zakaznik_statistika((int)$z['id']))) ?><br>
            <?= h($r['sluzba'] . ' – ' . $r['var_popis']) ?> · <b>hodí se:</b> <?= h($r['preferovano'] ?: '—') ?>
            <?php if ($r['poznamka_zakaznik']): ?><br><span class="muted">„<?= h($r['poznamka_zakaznik']) ?>“</span><?php endif ?>
          </div>
          <a class="btn primary" href="/?r=rezervace&id=<?= $r['id'] ?>">Naplánovat</a>
        </div>
      <?php endforeach ?>
    </section>
    <?php endif ?>

    <?php if (!$skupiny): ?>
      <p class="flash warn">Nejsou nastavené masérny. Přidejte je v Správa → Tým → Masérny a tablety.</p>
    <?php else: ?>
    <section class="card" id="board">
      <h2>Masérny teď <span class="pb-hodiny" title="Aktuální čas">🕐 <?= date('H:i') ?></span></h2>
      <?php if ($okna): ?>
        <div class="pb-pills" title="Nejbližší volná místa v masérnách (po přestávce <?= $pm ?> min)">
          <?php foreach (array_slice($okna, 0, 8) as [$s, $mx, $kl, $nz]): ?>
            <a href="/?r=rez_nova&d=<?= h($dnes) ?>&t=<?= hhmm($s) ?>">+ <b><?= hhmm($s) ?></b> · <?= h($nz) ?> · <?= prehled_delka($mx) ?></a>
          <?php endforeach ?>
        </div>
      <?php elseif ($ted >= $minDo): ?>
        <p class="muted">Dnes už je po otevírací době.</p>
      <?php else: ?>
        <p class="muted">Dnes už není žádné volné okno, kam by se vešla masáž – masérny jsou plné. ✓</p>
      <?php endif ?>

      <div class="pb-grid">
      <?php foreach ($sloupce as $kl => $c):
          // řádky sloupce: [začátek v min, pořadí při shodě (0 čára, 1 masáž, 2 okno), druh, data]; masáž, která právě probíhá, nese čáru „teď“ v sobě
          $radky = []; $minule = []; $bezelaNyni = false;
          foreach ($c['bloky'] as $r) {
              $od = $minz($r['zacatek']); $do = $minz($r['konec']);
              if ($do <= $ted) $minule[] = $r; else { $radky[] = [$od, 1, 'blok', $r]; if ($od < $ted) $bezelaNyni = true; }
          }
          foreach ($c['okna'] as [$s, $mx]) $radky[] = [$s, 2, 'okno', $mx];
          if (!$bezelaNyni) $radky[] = [$ted, 0, 'ted', null];
          usort($radky, fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
          // hlavička: co je v masérně právě teď
          $ted_ln = []; $trida = 'm-volno';
          foreach ($c['tablety'] as $t) {
              $sz = $stavMist[(int)$t['id']] ?? null; if (!$sz) continue;
              $jm = trim($sz['prijmeni'] ?: $sz['jmeno']);
              if ($sz['neni_ona']) { $trida = 'm-alarm'; $ted_ln[] = ['⚠ NENÍ ONA · ' . $jm, (int)$sz['rezervace_id']]; }
              elseif ($sz['stav'] === 'bezi') { if ($trida !== 'm-alarm') $trida = 'm-bezi'; $ted_ln[] = ['▶ ' . $jm . ($sz['maserka'] ? ' · ' . $sz['maserka'] : '') . ($sz['plan_konec'] ? ' · do ' . substr($sz['plan_konec'], 11, 5) : ''), (int)$sz['rezervace_id']]; }
              elseif ($sz['stav'] === 'konec') { if ($trida === 'm-volno') $trida = 'm-konec'; $ted_ln[] = ['⌛ hodnocení · ' . $jm, (int)$sz['rezervace_id']]; }
              else { if ($trida === 'm-volno') $trida = 'm-cekani'; $ted_ln[] = ['📲 čeká na masérku · ' . $jm, (int)$sz['rezervace_id']]; }
          } ?>
        <div class="pb-col" data-maserna="<?= h($kl) ?>" data-nazev="<?= h($c['nazev']) ?>" data-luzka="<?= count($c['tablety']) ?>" data-well="<?= $c['well'] ? 1 : 0 ?>">
          <div class="mist <?= $trida ?> pb-head">
            <b><?= h($c['nazev']) ?><?= $c['duo'] ? ' (duo)' : '' ?><?= $c['well'] ? ' ♨' : '' ?></b>
            <?php if ($ted_ln): foreach ($ted_ln as [$tx, $rid]): ?><a class="ln" href="/?r=rezervace&id=<?= $rid ?>#tablet"><?= h($tx) ?></a><?php endforeach;
            else: ?><span class="ln">volná</span><?php endif ?>
            <small><?= $c['volno'] ? 'dnes ještě volno cca ' . h(rtrim(rtrim(number_format($c['volno'] / 60, 1, ',', ''), '0'), ',')) . ' h' : ($ted < $minDo ? 'plno ✓' : '') ?></small>
          </div>

          <?php if ($minule): ?>
            <details class="pb-minulo"><summary>Dříve dnes (<?= count($minule) ?>)</summary>
              <?php foreach ($minule as $r): ?>
                <div class="pb-blk minulo" data-rid="<?= (int)$r['id'] ?>" data-od="<?= $minz($r['zacatek']) ?>" data-do="<?= $minz($r['konec']) ?>" data-lze="0" data-jmeno="<?= h(jmeno_z($r)) ?>" data-cas="<?= date('H:i', strtotime($r['zacatek'])) ?>–<?= date('H:i', strtotime($r['konec'])) ?>">
                  <span class="t"><?= date('H:i', strtotime($r['zacatek'])) ?>–<?= date('H:i', strtotime($r['konec'])) ?></span><a class="n" draggable="false" href="/?r=rezervace&id=<?= (int)$r['id'] ?>"><b><?= h(jmeno_z($r)) ?></b></a>
                  <span class="s"><?= h($r['sluzba'] . ' ' . $r['var_popis']) ?></span>
                </div>
              <?php endforeach ?>
            </details>
          <?php endif ?>

          <?php if (count($radky) === 1 && $radky[0][2] === 'ted'): ?><p class="muted small" style="margin:6px 2px"><?= $ted < $minDo ? 'Nic dalšího naplánováno.' : 'Pro dnešek hotovo.' ?></p><?php endif ?>
          <?php foreach ($radky as $rd):
              if ($rd[2] === 'ted'): ?>
                <div class="pb-ted" role="separator" aria-label="Aktuální čas"><span><?= date('H:i') ?></span></div>
              <?php elseif ($rd[2] === 'okno'): ?>
                <a class="pg" href="/?r=rez_nova&d=<?= h($dnes) ?>&t=<?= hhmm($rd[0]) ?>">+ rezervace od <b><?= hhmm($rd[0]) ?></b> <span>· volno <?= prehled_delka((int)$rd[3]) ?></span></a>
              <?php else: $r = $rd[3]; $rid = (int)$r['id']; $sz = $sezRez[$rid] ?? null;
                $od = $minz($r['zacatek']); $do = $minz($r['konec']);
                $mas = trim(($r['maserka'] ?? '') . ($r['maserka2'] ? ' + ' . $r['maserka2'] : '')); $masNavrh = false;
                if ($mas === '' && (int)$r['var_maserky'] > 0 && !empty($navrhMas[$rid])) {
                    $nv = $navrhMas[$rid];
                    $mas = trim(($jmMas[$nv['maserka']] ?? '') . (!empty($nv['maserka2']) ? ' + ' . ($jmMas[$nv['maserka2']] ?? '') : '')); $masNavrh = $mas !== '';
                }
                $par = (int)$r['var_maserky'] >= 2 || $r['maserka2_id'];
                $lze = $r['stav'] === 'potvrzeno' && strtotime($r['konec']) > $nyni && (!$sz || $sz['stav'] === 'cekani') && $r['zdroj'] !== 'nevesel';
                $st = '';
                if ($sz) {
                    if ($sz['neni_ona']) $st = '<span class="st al">⚠ NENÍ ONA</span>';
                    elseif ($sz['stav'] === 'bezi') $st = '<span class="st">▶ běží' . ($sz['plan_konec'] ? ' do ' . h(substr($sz['plan_konec'], 11, 5)) : '') . '</span>';
                    elseif ($sz['stav'] === 'cekani') $st = '<span class="st">📲 na tabletu</span>';
                    elseif ($sz['stav'] === 'konec') $st = '<span class="st">⌛ hodnocení</span>';
                } elseif ($r['stav'] === 'potvrzeno' && (int)$r['var_maserky'] > 0) {
                    $odeslat = strtotime($r['zacatek']) - $lead * 60;
                    if ($mas === '') $st = '<span class="st varuj">⚠ bez masérky</span>';
                    elseif ($nyni < $odeslat) $st = '<span class="st mut">📲 odešle se ' . date('H:i', $odeslat) . '</span>';
                    elseif ($nyni <= strtotime($r['zacatek']) + TABLET_POZDE_MIN * 60) $st = '<span class="st varuj">⏳ čeká na tablet</span>';
                } ?>
                <div class="pb-blk<?= $lze ? ' lze' : '' ?><?= $r['zdroj'] === 'nevesel' ? ' nevesel' : '' ?>" data-rid="<?= $rid ?>" data-od="<?= $od ?>" data-do="<?= $do ?>" data-lze="<?= $lze ? 1 : 0 ?>"
                     data-par="<?= $par ? 1 : 0 ?>" data-well="<?= (int)$r['w'] ?>" data-jmeno="<?= h(jmeno_z($r)) ?>" data-cas="<?= date('H:i', strtotime($r['zacatek'])) ?>–<?= date('H:i', strtotime($r['konec'])) ?>">
                  <?php if ($lze): ?><span class="grip" title="Chyťte a přetáhněte do jiné masérny"></span><?php endif ?>
                  <?= $st ?><span class="t"><?= date('H:i', strtotime($r['zacatek'])) ?>–<?= date('H:i', strtotime($r['konec'])) ?></span><a class="n" draggable="false" href="/?r=rezervace&id=<?= $rid ?>"><b><?= h(jmeno_z($r)) ?></b></a>
                  <span class="s"><?= h($r['sluzba'] . ' ' . $r['var_popis']) ?> · <?php
                      if ($mas === '') echo (int)$r['var_maserky'] === 0 ? '<i>bez masérky</i>' : '<i>masérka zatím nepřidělena</i>';
                      elseif ($lze && (int)$r['var_maserky'] > 0) echo '<button type="button" class="mas" title="Změnit masérku">👤 ' . h($mas) . ($masNavrh ? ' <i>(návrh)</i>' : '') . '</button>';
                      else echo '👤 ' . h($mas) . ($masNavrh ? ' <i>(návrh)</i>' : '');
                  ?></span>
                  <?php if ($od <= $ted && $ted < $do): ?><span class="ted-v" style="top:<?= round(($ted - $od) / max(1, $do - $od) * 100, 1) ?>%"></span><?php endif ?>
                </div>
              <?php endif ?>
          <?php endforeach ?>
        </div>
      <?php endforeach ?>
      </div>
      <p class="muted small" style="margin-top:8px">Chytněte masáž za úchyt (tečky vlevo) a přetáhněte ji do jiné masérny – když tam ve stejný čas někdo je, prohodí se. Dvojklik (na tabletu dvojité klepnutí) nebo klepnutí na jméno masérky otevře výběr masérky; vybraná masérka z jiné masérny se s touhle prohodí. Rezervace se na tablety posílají samy <?= $lead ?> min před začátkem, jakmile je tablet volný. Zelená okna jsou volná místa po přestávce <?= $pm ?> min mezi masážemi; klepnutím otevřete rezervaci s předvyplněným časem. Červená čára je aktuální čas.</p>
    </section>

    <div id="pm-ui" data-csrf="<?= h(csrf()) ?>">
      <div class="pm-hint" id="pm-hint" role="status"></div>
      <div class="pm-toast" id="pm-toast" role="status"></div>
      <div class="pm-okno" id="pm-okno" hidden>
        <div class="pm-box" role="dialog" aria-modal="true" aria-labelledby="pm-nadpis">
          <h3 id="pm-nadpis"></h3>
          <p class="muted" id="pm-info"></p>
          <div id="pm-telo"></div>
          <p class="pm-chyba" id="pm-chyba" role="alert"></p>
          <div class="pm-pat"><button type="button" class="btn" id="pm-zavrit">Zavřít</button></div>
        </div>
      </div>
    </div>
    <?php endif ?>

    <?php if ($kontrola || $neodeslane): ?>
    <details class="card vyrizeni">
      <summary>K vyřízení<?php if ($kontrola): ?> · Přišel klient? <b class="cnt"><?= count($kontrola) ?></b><?php endif ?><?php if ($neodeslane): ?> · e-maily čekají <b class="cnt"><?= $neodeslane ?></b><?php endif ?></summary>
      <?php if ($neodeslane): ?>
        <p class="flash warn" style="margin-top:10px"><?= $neodeslane ?> e-mail(ů) čeká na odeslání – <a href="/?r=emaily">zobrazit</a>.</p>
      <?php endif ?>
      <?php if ($kontrola): ?>
      <h3>Přišel klient? <span class="muted">(<?= count($kontrola) ?> ke kontrole)</span></h3>
      <p class="muted small">Termín už skončil a masáž nebyla spuštěná na tabletu. „Nepřišel“ a „Zrušeno pozdě“ se počítají do štítku ⚠️ Nespolehlivý, do bonusu jen „Proběhlo“.</p>
      <form id="hromadne" method="post" class="row-item" style="align-items:center;gap:8px;flex-wrap:wrap">
        <?= csrf_pole() ?><input type="hidden" name="akce" value="hromadne_stav">
        <label class="check"><input type="checkbox" id="vsechny" onchange="document.querySelectorAll('.kont-cb').forEach(c=>c.checked=this.checked)"> Vybrat všechny</label>
        <button name="stav" value="probehlo" class="btn primary" data-confirm="Označit všechny zaškrtnuté jako Proběhlo (dorazili)?">✓ Zaškrtnutí dorazili</button>
        <button name="stav" value="neprisel" class="btn danger" data-confirm="Označit všechny zaškrtnuté jako Nepřišel?">Zaškrtnutí nepřišli</button>
      </form>
      <?php foreach ($kontrola as $r): ?>
        <form method="post" action="/?r=rezervace&id=<?= $r['id'] ?>" class="row-item">
          <?= csrf_pole() ?><input type="hidden" name="akce" value="stav"><input type="hidden" name="zpet" value="dnes">
          <input type="checkbox" class="kont-cb big-cb" name="ids[]" value="<?= $r['id'] ?>" form="hromadne" aria-label="vybrat">
          <div class="time"><?= date('H:i', strtotime($r['zacatek'])) ?><br><span class="muted small"><?= date('j. n.', strtotime($r['zacatek'])) ?></span></div>
          <div class="grow"><a href="/?r=rezervace&id=<?= $r['id'] ?>"><b><?= h(jmeno_z($r)) ?></b></a> · <a href="tel:<?= h($r['telefon']) ?>"><?= h(telefon_cz($r['telefon'])) ?></a><br>
            <?= h($r['sluzba'] . ' – ' . $r['var_popis']) ?> · <?= h(($r['maserka'] ?? 'bez masérky') . (!empty($r['maserka2']) ? ' + ' . $r['maserka2'] : '')) ?></div>
          <button name="stav" value="probehlo" class="btn primary">✓ Proběhlo</button>
          <button name="stav" value="neprisel" class="btn danger">Nepřišel</button>
          <button name="stav" value="zruseno_pozde" class="btn">Zrušeno pozdě</button>
        </form>
      <?php endforeach ?>
      <?php endif ?>
    </details>
    <?php endif ?>

    <p><a class="btn small" href="/?r=dnes_stary">Podrobný plán dne (starý přehled)</a></p>

    <script>
    // Obrazovka se obnovuje sama každých 5 s (masérny, volná okna, čára času). Při přetahování a s otevřeným oknem se nepřekresluje.
    (function () {
      const ui = document.getElementById('pm-ui'), board = document.getElementById('board');
      if (!ui || !board) return;
      const CSRF = ui.dataset.csrf, $ = (s, r) => (r || document).querySelector(s);
      const okno = $('#pm-okno'), hint = $('#pm-hint');
      let bezi = false, zamek = 0, start = null, drag = null, ult = null, sy = 0, tt = null;
      const mk = (t, c, x) => { const e = document.createElement(t); if (c) e.className = c; if (x != null) e.textContent = x; return e; };

      // ---------- komunikace ----------
      function toast(text, typ) {
        const t = $('#pm-toast'); t.textContent = text; t.className = 'pm-toast ' + (typ === 'err' ? 'err ' : '') + 'show';
        clearTimeout(tt); tt = setTimeout(() => t.classList.remove('show'), typ === 'err' ? 8000 : 4000);
      }
      async function posli(akce, data) {
        const fd = new FormData(); fd.append('csrf', CSRF); fd.append('akce', akce);
        for (const k in data) fd.append(k, data[k]);
        let r;
        try { r = await fetch('/?r=dnes', { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' }); }
        catch (e) { return { ok: false, chyba: 'Nepodařilo se spojit se serverem.' }; }
        try { return await r.json(); }
        catch (e) { return { ok: false, chyba: r.status === 400 ? 'Platnost stránky vypršela – obnovte ji (F5).' : 'Server vrátil nečekanou odpověď.' }; }
      }

      // ---------- samoobnova ----------
      const cinny = () => { const a = document.activeElement; return !!(a && /INPUT|TEXTAREA|SELECT/.test(a.tagName)); };
      const blokuje = () => zamek > 0 || document.body.classList.contains('pm-dnd') || !okno.hidden;
      async function obnov(sila) {
        if (bezi || document.hidden || (!sila && blokuje())) return; bezi = true;
        try {
          const r = await fetch(location.href, { credentials: 'same-origin', cache: 'no-store' }); if (!r.ok) return;
          if (!sila && blokuje()) return;
          const d = new DOMParser().parseFromString(await r.text(), 'text/html');
          const volne = !cinny() && !document.querySelector('details.vyrizeni[open]');
          ['#board'].concat(volne ? ['#zadosti'] : []).forEach(q => {
            const n = d.querySelector(q), o = document.querySelector(q);
            if (!n !== !o) { if (volne && !blokuje()) location.reload(); }
            else if (n && n.innerHTML !== o.innerHTML) o.innerHTML = n.innerHTML;
          });
        } catch (e) {} finally { bezi = false; }
      }
      setInterval(obnov, 5000); document.addEventListener('visibilitychange', () => obnov());

      // ---------- přetahování masáže do jiné masérny ----------
      /** Co by se stalo, kdyby se blok pustil do sloupce: ok | prohod | nelze | stejna. */
      function posudek(blk, col) {
        if (col.dataset.maserna === blk.dataset.maserna || col.contains(blk)) return { typ: 'stejna' };
        if (blk.dataset.par === '1' && +col.dataset.luzka < 2) return { typ: 'nelze', text: '✖ Párová masáž potřebuje dvoulůžkovou masérnu.' };
        if (blk.dataset.well === '1' && document.querySelector('.pb-col[data-well="1"]') && col.dataset.well !== '1') return { typ: 'nelze', text: '✖ Whirlpool a sauna patří do wellness masérny.' };
        const od = +blk.dataset.od, dd = +blk.dataset.do;
        const st = [...col.querySelectorAll('.pb-blk[data-od]')].filter(b => +b.dataset.od < dd && +b.dataset.do > od);
        if (!st.length) return { typ: 'ok', text: 'Přesunout do: ' + col.dataset.nazev };
        if (st.some(b => b.dataset.lze !== '1')) return { typ: 'nelze', text: '✖ V té masérně v tu dobu masáž běží nebo už proběhla.', st };
        return { typ: 'prohod', text: '⇄ Prohodí se s: ' + st.map(b => b.dataset.jmeno + ' (' + b.dataset.cas + ')').join(', '), st };
      }
      const zrusZvyrazneni = () => document.querySelectorAll('.pb-col.cil,.pb-col.cil-ne,.pb-blk.prohodi').forEach(x => x.classList.remove('cil', 'cil-ne', 'prohodi'));

      board.addEventListener('pointerdown', e => {
        const blk = e.target.closest('.pb-blk[data-lze="1"]'); if (!blk || drag || start) return;
        if (e.pointerType === 'mouse') { if (e.button !== 0 || e.target.closest('a,button')) return; }
        else if (!e.target.closest('.grip')) return;                       // dotyk: tahá se jen za úchyt (jinak by se nedalo rolovat)
        start = { x: e.clientX, y: e.clientY, blk, id: e.pointerId };
        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onUp);
        document.addEventListener('pointercancel', onZrus);
      });
      board.addEventListener('contextmenu', e => { if (e.target.closest('.grip')) e.preventDefault(); });

      function zrusPosluchace() {
        document.removeEventListener('pointermove', onMove); document.removeEventListener('pointerup', onUp); document.removeEventListener('pointercancel', onZrus);
      }
      function konecTahu() {
        if (drag) { drag.duch.remove(); drag.blk.classList.remove('drag-src'); }
        zrusZvyrazneni(); hint.classList.remove('show'); document.body.classList.remove('pm-dnd'); drag = null; start = null; sy = 0;
      }
      function onZrus() { zrusPosluchace(); konecTahu(); }
      function zacniTah(e) {
        const blk = start.blk, r = blk.getBoundingClientRect(), duch = blk.cloneNode(true);
        duch.classList.add('pb-duch'); duch.classList.remove('drag-src'); duch.style.width = r.width + 'px';
        document.body.appendChild(duch);
        drag = { blk, duch, dx: e.clientX - r.left, dy: e.clientY - r.top, x: e.clientX, y: e.clientY, col: undefined, p: null };
        blk.classList.add('drag-src'); document.body.classList.add('pm-dnd');
        requestAnimationFrame(smycka);
      }
      function urciSloupec() {
        if (!drag) return;
        const el = document.elementFromPoint(drag.x, drag.y), col = el && el.closest ? el.closest('.pb-col') : null;
        if (col === drag.col) return;
        zrusZvyrazneni(); drag.col = col; drag.p = null;
        if (!col) { hint.classList.remove('show'); return; }
        const p = posudek(drag.blk, col); drag.p = p;
        if (p.typ === 'stejna') { hint.classList.remove('show'); return; }
        col.classList.add(p.typ === 'nelze' ? 'cil-ne' : 'cil');
        (p.st || []).forEach(b => b.classList.add('prohodi'));
        hint.textContent = p.text; hint.className = 'pm-hint show ' + (p.typ === 'nelze' ? 'ne' : p.typ);
      }
      function smycka() {
        if (!drag) return;
        if (sy) { window.scrollBy(0, sy); drag.col = undefined; }          // po posunu stránky je pod prstem jiný sloupec
        urciSloupec(); requestAnimationFrame(smycka);
      }
      function onMove(e) {
        if (!start || e.pointerId !== start.id) return;
        if (!drag) { if (Math.hypot(e.clientX - start.x, e.clientY - start.y) < 7) return; zacniTah(e); }
        drag.x = e.clientX; drag.y = e.clientY;
        drag.duch.style.transform = 'translate(' + (e.clientX - drag.dx) + 'px,' + (e.clientY - drag.dy) + 'px) rotate(1.2deg)';
        sy = e.clientY < 90 ? -18 : (e.clientY > innerHeight - 90 ? 18 : 0);
      }
      async function onUp(e) {
        if (!start || e.pointerId !== start.id) return;
        zrusPosluchace();
        if (!drag) { start = null; return; }
        drag.x = e.clientX; drag.y = e.clientY; drag.col = undefined; urciSloupec();
        const d = drag; konecTahu();
        const f = ev => { ev.stopPropagation(); ev.preventDefault(); };      // po tažení nesmí „proklik“ otevřít detail
        document.addEventListener('click', f, true); setTimeout(() => document.removeEventListener('click', f, true), 60);
        if (!d.col || !d.p || d.p.typ === 'stejna') return;
        if (d.p.typ === 'nelze') { toast(d.p.text.replace(/^✖ /, ''), 'err'); return; }
        d.blk.classList.add('pb-ceka'); zamek++;
        const j = await posli('presun_masaz', { rez_id: d.blk.dataset.rid, maserna: d.col.dataset.maserna });
        zamek--;
        toast(j.zprava || j.chyba || 'Hotovo.', j.ok ? 'ok' : 'err');
        obnov(true);
      }
      document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (!okno.hidden) zavri(); else if (drag) onZrus();
      });

      // ---------- změna masérky (dvojklik / dvojité klepnutí / klepnutí na jméno masérky) ----------
      board.addEventListener('dblclick', e => {
        const blk = e.target.closest('.pb-blk[data-rid]');
        if (blk && !e.target.closest('a,.grip')) otevriMaserky(+blk.dataset.rid);
      });
      board.addEventListener('pointerup', e => {                            // iPad a spol. dvojklik z dvojitého klepnutí nevyrobí
        if (e.pointerType === 'mouse' || drag) return;
        const blk = e.target.closest('.pb-blk[data-rid]');
        if (!blk || e.target.closest('a,.grip,button')) { ult = null; return; }
        const rid = +blk.dataset.rid, t = Date.now();
        if (ult && ult.rid === rid && t - ult.t < 450 && Math.hypot(e.clientX - ult.x, e.clientY - ult.y) < 30) { ult = null; otevriMaserky(rid); }
        else ult = { rid, t, x: e.clientX, y: e.clientY };
      });
      board.addEventListener('click', e => {
        const b = e.target.closest('button.mas');
        if (b) { e.preventDefault(); otevriMaserky(+b.closest('.pb-blk').dataset.rid); }
      });

      function zavri() { okno.hidden = true; $('#pm-telo').innerHTML = ''; $('#pm-chyba').textContent = ''; }
      $('#pm-zavrit').onclick = zavri;
      okno.addEventListener('click', e => { if (e.target === okno) zavri(); });

      async function otevriMaserky(rid) {
        if (!okno.hidden || zamek > 0) return;
        zamek++; const j = await posli('maserky_info', { rez_id: rid }); zamek--;
        if (!j.ok) { toast(j.chyba || 'Nepodařilo se načíst masérky.', 'err'); return; }
        if (j.bez_maserky) { toast('Tahle služba je bez masérky.', 'err'); return; }
        if (j.zamek) { toast(j.zamek + ' Masérku nejde změnit.', 'err'); return; }
        ukazVyber(rid, j);
      }
      function ukazVyber(rid, j) {
        $('#pm-nadpis').textContent = j.zakaznik; $('#pm-info').textContent = j.cas + ' · ' + j.sluzba; $('#pm-chyba').textContent = '';
        const telo = $('#pm-telo'); telo.innerHTML = '';
        (j.par ? [1, 2] : [1]).forEach(sl => {
          const akt = sl === 1 ? j.m1 : j.m2, sek = mk('div'), mr = mk('div', 'pm-mr');
          if (j.par) sek.append(mk('h4', '', sl + '. masérka'));
          j.maserky.forEach(m => {
            const b = mk('button', 'pm-m ' + m.stav + (m.id === akt ? ' akt' : ''));
            b.type = 'button'; b.textContent = m.jmeno + (m.id === akt ? ' ✓' : m.stav === 'obsazena' ? ' ⇄' : '');
            if (m.stav === 'nepracuje') { b.disabled = true; b.dataset.nelze = '1'; b.append(mk('small', '', 'nepracuje')); }
            b.onclick = () => zvol(rid, sl, m, false);
            mr.append(b);
          });
          sek.append(mr); telo.append(sek);
        });
        okno.hidden = false;
      }
      async function zvol(rid, slot, m, prohodit) {
        const tl = [...okno.querySelectorAll('button')]; tl.forEach(b => b.disabled = true);
        const j = await posli('zmen_maserku', { rez_id: rid, slot, maserka_id: m.id, prohodit: prohodit ? 1 : 0 });
        if (j.potvrdit) { ukazPotvrzeni(rid, slot, m, j.zprava); return; }
        if (j.ok) { zavri(); toast(j.zprava, 'ok'); obnov(true); return; }
        tl.forEach(b => b.disabled = b.dataset.nelze === '1');
        $('#pm-chyba').textContent = j.chyba || 'Masérku se nepodařilo změnit.';
      }
      function ukazPotvrzeni(rid, slot, m, text) {
        const telo = $('#pm-telo'); telo.innerHTML = ''; $('#pm-chyba').textContent = '';
        telo.append(mk('p', '', '⚠ ' + text));
        const pat = mk('div', 'pm-pat'), ano = mk('button', 'btn primary', '⇄ Prohodit'), ne = mk('button', 'btn', 'Zpět');
        ano.type = ne.type = 'button';
        ano.onclick = () => { ano.disabled = ne.disabled = true; zvol(rid, slot, m, true); };
        ne.onclick = zavri; pat.append(ano, ne); telo.append(pat);
      }
    })();
    </script>
    <?php paticka();
}
