<?php
// Úvodní obrazovka recepce „Masérny“: co se děje v masérnách teď, kdy jsou v plánu díry, kdo právě přichází a co je k vyřízení.
// Cíl: masérny plné lidí. Recepční si termín vybírá sama – systém jen ukazuje volná místa (odkaz „+ rezervace“ otevře formulář s předvyplněným časem).
// POST akce (tablety, příchody, ukončení, hromadné stavy) obsluhuje dál stranka_dnes(); starý podrobný přehled je na /?r=dnes_stary.

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
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { stranka_dnes($u); return; }

    $dnes = date('Y-m-d');
    $zitra = date('Y-m-d', strtotime('+1 day'));
    $ted = (int)date('G') * 60 + (int)date('i');
    $pm = prestavka();
    $minz = fn($dt) => (int)date('G', strtotime($dt)) * 60 + (int)date('i', strtotime($dt));
    $skupiny = prichody_skupiny();
    $minOd = min_z(nastaveni('oteviraci_od', '09:00')); $minDo = min_z(nastaveni('oteviraci_do', '20:00'));
    $nejkratsi = (int)qv('SELECT MIN(minuty) FROM varianty WHERE aktivni=1 AND minuty>0') ?: 30;

    // --- dnešní plán po masérnách (stejné rozdělení jako Kalendář masérn) ---
    $plan = $skupiny ? masterny_den($dnes, $skupiny) : [];
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
    foreach (q("SELECT rezervace_id, stav, neni_ona, plan_konec FROM sezeni WHERE stav<>'zruseno' AND rezervace_id IN (SELECT id FROM rezervace WHERE zacatek >= ? AND zacatek < ?) ORDER BY id", [$dnes, $zitra])->fetchAll() as $x)
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
      .pb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:12px;align-items:start}
      .pb-head{margin-bottom:8px}.pb-head b{font-size:1.05rem}.pb-head small{font-weight:400;opacity:.8}
      .pb-head .ln{display:block;color:inherit;text-decoration:none;margin-top:3px}.pb-head a.ln:hover{text-decoration:underline}
      .pb-blk{display:block;text-decoration:none;color:inherit;padding:6px 9px;margin-bottom:6px;border-radius:8px;background:#fff;border:1px solid var(--bd);border-left:4px solid #7a5ea8}
      .pb-blk:hover{background:#faf6f0}
      .pb-blk.minulo{opacity:.55}.pb-blk.nevesel{border-left-color:#b3261e;background:#fbe9e7}
      .pb-blk .t{font-weight:700;margin-right:6px}.pb-blk .s{display:block;font-size:.85rem;color:var(--mut)}
      .pb-blk .st{float:right;font-size:.78rem;font-weight:600}.pb-blk .st.al{color:#fff;background:#d9262c;border-radius:6px;padding:0 6px}
      .pg{display:block;text-decoration:none;padding:7px 9px;margin-bottom:6px;border:1.5px dashed #5b8a72;border-radius:8px;background:rgba(227,239,232,.55);color:#2f5446}
      .pg:hover{background:#e3efe8}.pg b{font-size:1.02rem}
      .pb-pills{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px}
      .pb-pills a{display:inline-block;text-decoration:none;padding:5px 11px;border-radius:999px;background:#e3efe8;border:1px solid #5b8a72;color:#2f5446;font-size:.9rem}
      .pb-pills a:hover{background:#d3e7da}
      section.prichody select{display:inline-block;width:auto;min-width:150px;margin:2px 6px 2px 0}
      .pb-minulo summary{cursor:pointer;color:var(--mut);font-size:.85rem;margin:2px 0 6px}
      .vyrizeni summary{cursor:pointer;font-weight:600;color:var(--hn)}.vyrizeni summary .cnt{margin-left:4px}
    </style>
    <h1>Masérny <small><?= h(datum_cz($dnes, false)) ?></small></h1>

    <?php foreach ($poplach as [$mn, $sz]): ?>
      <div class="flash err">⚠ <b><?= h($mn) ?></b>: masérka hlásí, že to není ona (<?= h(trim($sz['prijmeni'] ?: $sz['jmeno'])) ?>). <a href="/?r=rezervace&id=<?= (int)$sz['rezervace_id'] ?>#tablet">Vyřešit</a></div>
    <?php endforeach ?>
    <?php if ($konf): ?>
      <div class="flash warn">⚠ <b><?= count($konf) ?> masáž<?= count($konf) === 1 ? '' : (count($konf) < 5 ? 'e' : 'í') ?> u masérky, která v tu dobu nepracuje</b> – nejbližší <?= h(datum_cz($konf[0]['zacatek'])) ?> <?= h(jmeno_z($konf[0])) ?>. <a href="/?r=dostupnost">Vyřešit</a></div>
    <?php endif ?>
    <?php if ($nevesel): ?>
      <div class="flash warn">⚠ <b><?= count($nevesel) ?> dnešní rezervace se nevejde do žádné masérny</b> (např. <?= h(date('H:i', strtotime($nevesel[0]['zacatek']))) ?> <?= h(jmeno_z($nevesel[0])) ?>). <a href="/?r=kalendar&pohled=masterny">Otevřít kalendář masérn</a></div>
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

    <?php prichody_panel(); ?>

    <?php if (!$skupiny): ?>
      <p class="flash warn">Nejsou nastavené masérny. Přidejte je v Správa → Tým → Masérny a tablety.</p>
    <?php else: ?>
    <section class="card" id="board">
      <h2>Masérny teď</h2>
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
          $radky = []; $minule = [];
          foreach ($c['bloky'] as $r) {
              $od = $minz($r['zacatek']); $do = $minz($r['konec']);
              if ($do <= $ted) $minule[] = $r; else $radky[] = [$od, 'blok', $r];
          }
          foreach ($c['okna'] as [$s, $mx]) $radky[] = [$s, 'okno', $mx];
          usort($radky, fn($a, $b) => $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]));
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
        <div class="pb-col">
          <div class="mist <?= $trida ?> pb-head">
            <b><?= h($c['nazev']) ?><?= $c['duo'] ? ' (duo)' : '' ?><?= $c['well'] ? ' ♨' : '' ?></b>
            <?php if ($ted_ln): foreach ($ted_ln as [$tx, $rid]): ?><a class="ln" href="/?r=rezervace&id=<?= $rid ?>#tablet"><?= h($tx) ?></a><?php endforeach;
            else: ?><span class="ln">volná</span><?php endif ?>
            <small><?= $c['volno'] ? 'dnes ještě volno cca ' . h(rtrim(rtrim(number_format($c['volno'] / 60, 1, ',', ''), '0'), ',')) . ' h' : ($ted < $minDo ? 'plno ✓' : '') ?></small>
          </div>

          <?php if ($minule): ?>
            <details class="pb-minulo"><summary>Dříve dnes (<?= count($minule) ?>)</summary>
              <?php foreach ($minule as $r): ?>
                <a class="pb-blk minulo" href="/?r=rezervace&id=<?= (int)$r['id'] ?>">
                  <span class="t"><?= date('H:i', strtotime($r['zacatek'])) ?>–<?= date('H:i', strtotime($r['konec'])) ?></span><b><?= h(jmeno_z($r)) ?></b>
                  <span class="s"><?= h($r['sluzba'] . ' ' . $r['var_popis']) ?></span>
                </a>
              <?php endforeach ?>
            </details>
          <?php endif ?>

          <?php if (!$radky): ?><p class="muted small" style="margin:6px 2px"><?= $ted < $minDo ? 'Nic dalšího naplánováno.' : 'Pro dnešek hotovo.' ?></p><?php endif ?>
          <?php foreach ($radky as $rd):
              if ($rd[1] === 'okno'): ?>
                <a class="pg" href="/?r=rez_nova&d=<?= h($dnes) ?>&t=<?= hhmm($rd[0]) ?>">+ rezervace od <b><?= hhmm($rd[0]) ?></b> <span>· volno <?= prehled_delka((int)$rd[2]) ?></span></a>
              <?php else: $r = $rd[2]; $sz = $sezRez[(int)$r['id']] ?? null;
                $mas = trim(($r['maserka'] ?? '') . ($r['maserka2'] ? ' + ' . $r['maserka2'] : '')); $masNavrh = false;
                if ($mas === '' && (int)$r['var_maserky'] > 0 && !empty($navrhMas[(int)$r['id']])) {
                    $nv = $navrhMas[(int)$r['id']];
                    $mas = trim(($jmMas[$nv['maserka']] ?? '') . (!empty($nv['maserka2']) ? ' + ' . ($jmMas[$nv['maserka2']] ?? '') : '')); $masNavrh = $mas !== '';
                }
                $st = '';
                if ($sz) {
                    if ($sz['neni_ona']) $st = '<span class="st al">⚠ NENÍ ONA</span>';
                    elseif ($sz['stav'] === 'bezi') $st = '<span class="st">▶ běží' . ($sz['plan_konec'] ? ' do ' . h(substr($sz['plan_konec'], 11, 5)) : '') . '</span>';
                    elseif ($sz['stav'] === 'cekani') $st = '<span class="st">📲 na tabletu</span>';
                    elseif ($sz['stav'] === 'konec') $st = '<span class="st">⌛ hodnocení</span>';
                } ?>
                <a class="pb-blk<?= $r['zdroj'] === 'nevesel' ? ' nevesel' : '' ?>" href="/?r=rezervace&id=<?= (int)$r['id'] ?>">
                  <?= $st ?><span class="t"><?= date('H:i', strtotime($r['zacatek'])) ?>–<?= date('H:i', strtotime($r['konec'])) ?></span><b><?= h(jmeno_z($r)) ?></b>
                  <span class="s"><?= h($r['sluzba'] . ' ' . $r['var_popis']) ?> · <?= $mas !== '' ? '👤 ' . h($mas) . ($masNavrh ? ' <i>(návrh)</i>' : '') : ((int)$r['var_maserky'] === 0 ? '<i>bez masérky</i>' : '<i>masérka zatím nepřidělena</i>') ?></span>
                </a>
              <?php endif ?>
          <?php endforeach ?>
        </div>
      <?php endforeach ?>
      </div>
      <p class="muted small" style="margin-top:8px">Masérna u termínů, které ještě nejsou na tabletu (bez štítku 📲), je návrh – upřesní se při příchodu. Zelená okna jsou volná místa po přestávce <?= $pm ?> min mezi masážemi; klepnutím otevřete rezervaci s předvyplněným časem.</p>
    </section>
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
          <input type="checkbox" class="kont-cb" name="ids[]" value="<?= $r['id'] ?>" form="hromadne" aria-label="vybrat" style="width:22px;height:22px">
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

    <p class="muted small"><a href="/?r=dnes_stary">Podrobný plán dne se všemi tlačítky (starý přehled)</a></p>

    <script>
    // Obrazovka se obnovuje sama: stav masérn a volná okna každých 5 s; příchody a žádosti jen když recepce zrovna nic nevyplňuje
    (function () {
      let bezi = false, dotkl = false;
      document.addEventListener('change', e => { if (e.target.closest && e.target.closest('section.prichody')) dotkl = true; });
      const cinny = () => { const a = document.activeElement; return !!(a && /INPUT|TEXTAREA|SELECT/.test(a.tagName)); };
      const volne = () => !cinny() && !dotkl && !document.querySelector('details.vyrizeni[open]');
      async function obnov() {
        if (bezi || document.hidden) return; bezi = true;
        try {
          const r = await fetch(location.href, { credentials: 'same-origin', cache: 'no-store' }); if (!r.ok) return;
          const d = new DOMParser().parseFromString(await r.text(), 'text/html');
          const sel = ['#board'].concat(volne() ? ['section.prichody', '#zadosti'] : []);
          sel.forEach(q => { const n = d.querySelector(q), o = document.querySelector(q); if (!n !== !o) { if (volne()) location.reload(); } else if (n && n.innerHTML !== o.innerHTML) o.innerHTML = n.innerHTML; });
        } catch (e) {} finally { bezi = false; }
      }
      setInterval(obnov, 5000);
    })();
    </script>
    <?php paticka();
}
