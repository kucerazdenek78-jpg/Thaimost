<?php
// Stránky AI asistenta: chat recepce + karty návrhů, pravidla salonu, log.
require_once __DIR__ . '/ai.php';

const AI_DUVODY = [
    'cas' => 'Zákazníkovi nevyhovuje čas',
    'maserka' => 'Chce jinou masérku',
    'pravidlo' => 'Porušuje pravidlo / zvyklost salonu',
    'data' => 'Chybná data v kartotéce',
    'jine' => 'Jiný důvod',
];
const AI_RYCHLE = [
    'Ranní přehled: projdi dnešní rozvrh a kontroly. Napiš obsazenost, volné hodiny, nepotvrzené žádosti, rezervace u masérek s volnem a zákazníky se štítkem Nespolehlivý.',
    'Co dnes potřebuje pozornost? (žádosti z webu, konflikty s volnem masérek, neoznačené termíny)',
    'Zaplň volné hodiny: najdi dnešní a zítřejší volná okna a navrhni, koho z last-minute zákazníků oslovit.',
    'Týdenní přehled: hodnocení masérek za posledních 7 dní a kdo je kandidát na odměnu týdne.',
];

/** Text odpovědi → HTML: escapování, [Z:id] → odkaz se jménem, **tučně**, odrážky a řádky. */
function ai_html(string $t): string
{
    $t = h($t);
    $t = preg_replace_callback('~\[Z:(\d+)\]~', function ($m) {
        $z = q1('SELECT id, jmeno, prijmeni, telefon FROM zakaznici WHERE id=?', [(int)$m[1]]);
        return $z ? '<a class="aiz" href="/?r=zakaznik&id=' . $z['id'] . '">' . h(jmeno_z($z)) . '</a>' : '<span class="muted">(zákazník #' . (int)$m[1] . ' nenalezen)</span>';
    }, $t);
    $t = preg_replace('~\*\*(.+?)\*\*~s', '<b>$1</b>', $t);
    $out = ''; $vSeznamu = false;
    foreach (explode("\n", $t) as $r) {
        if (preg_match('~^\s*(?:[-•*]|\d+\.)\s+(.*)$~', $r, $m)) { if (!$vSeznamu) { $out .= '<ul>'; $vSeznamu = true; } $out .= '<li>' . $m[1] . '</li>'; continue; }
        if ($vSeznamu) { $out .= '</ul>'; $vSeznamu = false; }
        $r = preg_replace('~^#{1,4}\s*(.*)$~', '<b>$1</b>', $r);
        $out .= trim($r) === '' ? '<br>' : $r . '<br>';
    }
    if ($vSeznamu) $out .= '</ul>';
    return preg_replace('~(<br>){3,}~', '<br><br>', $out);
}

function ai_navrh_url(array $n): string
{
    return '/?r=rez_nova&' . http_build_query(array_filter([
        'd' => substr($n['zacatek'], 0, 10), 't' => substr($n['zacatek'], 11, 5), 'm' => $n['maserka_id'], 'm2' => $n['maserka2_id'],
        'v' => $n['varianta_id'], 'z' => $n['zakaznik_id'], 'ai' => $n['id']], fn($x) => $x !== null && $x !== ''));
}

function ai_navrh_karta(array $n): void
{
    $z = q1('SELECT * FROM zakaznici WHERE id=?', [$n['zakaznik_id']]);
    $var = q1('SELECT v.popis, s.nazev FROM varianty v JOIN sluzby s ON s.id=v.sluzba_id WHERE v.id=?', [$n['varianta_id']]);
    $mas = implode(' + ', array_filter([qv('SELECT jmeno FROM maserky WHERE id=?', [$n['maserka_id']]), qv('SELECT jmeno FROM maserky WHERE id=?', [$n['maserka2_id']])])) ?: 'bez masérky';
    $platny = strtotime($n['zacatek']) > time(); ?>
    <div class="ainavrh s-<?= h($n['stav']) ?>">
      <div><b>🤖 Návrh: <?= $z ? '<a href="/?r=zakaznik&id=' . $z['id'] . '">' . h(jmeno_z($z)) . '</a>' : '?' ?></b> · <?= h(datum_cz($n['zacatek'])) ?> · <?= h(trim(($var['nazev'] ?? '') . ' ' . ($var['popis'] ?? ''))) ?> · <?= h($mas) ?></div>
      <?php if ($n['zduvodneni']): ?><div class="small muted">Proč: <?= h($n['zduvodneni']) ?></div><?php endif ?>
      <?php if ($n['stav'] === 'prijato'): ?>
        <div class="small">✅ Přijato (<?= h($n['rozhodl']) ?>)<?= $n['rezervace_id'] ? ' → <a href="/?r=rezervace&id=' . (int)$n['rezervace_id'] . '">rezervace</a>' : '' ?></div>
      <?php elseif ($n['stav'] === 'zamitnuto'): ?>
        <div class="small">✖ Zamítnuto (<?= h($n['rozhodl']) ?>): <?= h($n['duvod']) ?></div>
      <?php elseif (!$platny): ?>
        <div class="small muted">Termín už minul.</div>
      <?php else: ?>
        <form method="post" class="ainavrh-akce">
          <?= csrf_pole() ?><input type="hidden" name="navrh_id" value="<?= (int)$n['id'] ?>">
          <button class="btn small primary" name="akce" value="prijmout" title="Otevře předvyplněnou rezervaci – zkontrolujte a uložte">Přijmout → otevřít rezervaci</button>
          <?php if ($n['stav'] === 'otevreno'): ?><span class="small muted">otevřeno, rezervace zatím neuložena</span><?php endif ?>
          <details><summary class="btn small">Zamítnout…</summary>
            <select name="duvod_typ" class="small-in"><?= moznosti(AI_DUVODY, 'cas') ?></select>
            <input name="duvod" class="small-in" placeholder="Upřesnění (pomůže asistentovi příště)">
            <button class="btn small danger" name="akce" value="zamitnout">Zamítnout</button>
          </details>
        </form>
      <?php endif ?>
    </div>
<?php
}

function stranka_ai(array $u): void
{
    session_start_safe();
    $_SESSION['ai_hist'] = $_SESSION['ai_hist'] ?? [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = $_POST['akce'] ?? '';
        if ($akce === 'dotaz') {
            $dotaz = trim((string)($_POST['dotaz'] ?? ''));
            if ($dotaz !== '' && mb_strlen($dotaz) <= 2000) {
                $hist = $_SESSION['ai_hist'];
                session_write_close();   // dotaz může trvat desítky sekund – nezamykat session ostatním stránkám
                @set_time_limit(180);
                try {
                    $res = ai_dotaz($dotaz, $hist, $u);
                    $novy = [['role' => 'user', 'text' => $dotaz], ['role' => 'assistant', 'text' => $res['text'], 'navrhy' => $res['navrhy'], 'nastroje' => $res['nastroje']]];
                } catch (Throwable $e) {
                    error_log((string)$e);
                    ai_log('chyba', $e->getMessage());
                    $novy = null;
                    $chyba = $e->getMessage();
                }
                session_start_safe();
                if ($novy) $_SESSION['ai_hist'] = array_slice(array_merge($_SESSION['ai_hist'] ?? [], $novy), -30);
                else flash('Asistent: ' . $chyba, 'err');
            }
        } elseif ($akce === 'vymazat') {
            $_SESSION['ai_hist'] = [];
        } elseif (in_array($akce, ['prijmout', 'zamitnout'], true)) {
            $n = q1("SELECT * FROM ai_navrhy WHERE id=? AND stav IN ('ceka','otevreno')", [(int)($_POST['navrh_id'] ?? 0)]);
            if (!$n) flash('Návrh už je vyřízený.', 'warn');
            elseif ($akce === 'prijmout') {
                q("UPDATE ai_navrhy SET stav='otevreno', rozhodl=?, rozhodnuto=? WHERE id=?", [kdo(), nyni(), $n['id']]);
                presmeruj(ai_navrh_url($n));
            } else {
                $duvod = (AI_DUVODY[$_POST['duvod_typ'] ?? ''] ?? 'Jiný důvod') . (trim($_POST['duvod'] ?? '') !== '' ? ': ' . trim($_POST['duvod']) : '');
                q("UPDATE ai_navrhy SET stav='zamitnuto', duvod=?, rozhodl=?, rozhodnuto=? WHERE id=?", [mb_substr($duvod, 0, 300), kdo(), nyni(), $n['id']]);
                ai_log('navrh_zamitnut', "#{$n['id']} $duvod");
                flash('Návrh zamítnut, důvod je uložený.');
            }
        }
        presmeruj('/?r=ai#konec');
    }

    $hist = $_SESSION['ai_hist'];
    $cekaji = q("SELECT * FROM ai_navrhy WHERE stav IN ('ceka','otevreno') AND zacatek > ? ORDER BY zacatek", [nyni()])->fetchAll();
    hlavicka('Asistent', 'ai'); ?>
    <div class="cal-nav"><h1>🤖 Asistent <small>zkušební verze · jen navrhuje, rezervace ukládá recepce</small></h1>
      <span class="grow"></span>
      <a class="btn small" href="/?r=ai_pravidla">Pravidla salonu</a>
      <?php if (je_admin($u)): ?><a class="btn small" href="/?r=ai_log">Nastavení a spotřeba</a><?php endif ?>
    </div>
    <?php if (!ai_zapnuto()): ?>
      <div class="flash warn">Asistent zatím není zapnutý – chybí API klíč z Claude Console. <?= je_admin($u) ? '<a href="/?r=ai_log">Vložit klíč →</a>' : 'Vloží ho admin v Asistent → Nastavení a spotřeba.' ?></div>
    <?php endif ?>
    <div class="cols">
      <section class="card aichat">
        <?php if (!$hist): ?>
          <p class="muted">Zeptejte se třeba: „Najdi Novákové termín na úterý odpoledne“, „Kdo je zítra volný od 14:00?“, „Co dnes hlídat?“. Asistent vidí rozvrh, zákazníky (bez telefonů a zdravotních poznámek), masérky, volna a hodnocení.</p>
        <?php endif ?>
        <?php foreach ($hist as $h): ?>
          <div class="aimsg <?= $h['role'] === 'user' ? 'ja' : 'on' ?>">
            <?= $h['role'] === 'user' ? nl2br(h($h['text'])) : ai_html($h['text']) ?>
            <?php if (!empty($h['nastroje'])): ?><div class="small muted">🔎 <?= h(implode(', ', array_unique($h['nastroje']))) ?></div><?php endif ?>
            <?php foreach ($h['navrhy'] ?? [] as $nid) if ($n = q1('SELECT * FROM ai_navrhy WHERE id=?', [$nid])) ai_navrh_karta($n); ?>
          </div>
        <?php endforeach ?>
        <a id="konec"></a>
        <form method="post" id="aiform">
          <?= csrf_pole() ?><input type="hidden" name="akce" value="dotaz">
          <textarea name="dotaz" id="aidotaz" rows="3" maxlength="2000" placeholder="Napište dotaz… (Ctrl+Enter odešle)" <?= ai_zapnuto() ? '' : 'disabled' ?>></textarea>
          <div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap">
            <button class="btn primary" id="aibtn" <?= ai_zapnuto() ? '' : 'disabled' ?>>Zeptat se</button>
            <span id="aiwait" class="muted small" hidden>⏳ Asistent hledá v kartotéce… (může trvat i 30 s)</span>
            <span class="grow"></span>
            <?php if ($hist): ?><button class="btn small" name="akce" value="vymazat" formnovalidate>Nová konverzace</button><?php endif ?>
          </div>
          <div class="airychle"><?php foreach (AI_RYCHLE as $q): ?><button type="button" class="chip" data-q="<?= h($q) ?>"><?= h(mb_strimwidth(strtok($q, ':?(') ?: $q, 0, 40, '…')) ?></button><?php endforeach ?></div>
        </form>
      </section>
      <aside class="card side">
        <h2>Návrhy čekající na recepci</h2>
        <?php if (!$cekaji): ?><p class="muted small">Žádné.</p><?php endif ?>
        <?php foreach ($cekaji as $n) ai_navrh_karta($n); ?>
        <p class="small muted">Přijmout = otevře se předvyplněná rezervace; uložením ji potvrdíte vy. Zamítnutí s důvodem slouží k vylepšení pravidel.</p>
      </aside>
    </div>
    <script>
    (function(){var f=document.getElementById('aiform'),t=document.getElementById('aidotaz'),b=document.getElementById('aibtn'),w=document.getElementById('aiwait');if(!f)return;
    f.addEventListener('submit',function(e){var s=e.submitter;if(s&&s.value==='vymazat')return;if(!t.value.trim()){e.preventDefault();return}b.disabled=true;w.hidden=false});
    t.addEventListener('keydown',function(e){if(e.key==='Enter'&&(e.ctrlKey||e.metaKey)){e.preventDefault();f.requestSubmit(b)}});
    document.querySelectorAll('.airychle .chip').forEach(function(c){c.addEventListener('click',function(){t.value=c.dataset.q;f.requestSubmit(b)})});
    var k=document.getElementById('konec');if(k&&location.hash==='#konec')k.scrollIntoView();})();
    </script>
    <?php paticka();
}

function stranka_ai_pravidla(array $u): void
{
    $smi = smi_upravit_tym($u);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = $_POST['akce'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        $typ = ($_POST['typ'] ?? '') === 'mekke' ? 'mekke' : 'tvrde';
        $text = trim((string)($_POST['text'] ?? ''));
        if ($akce === 'pridat' && $text !== '') {
            $kod = 'R-' . str_pad((string)((int)qv("SELECT COALESCE(MAX(CAST(SUBSTR(kod,3) AS INTEGER)),0) FROM ai_pravidla WHERE kod LIKE 'R-%'") + 1), 2, '0', STR_PAD_LEFT);
            q('INSERT INTO ai_pravidla(kod,typ,text,stav,autor,schvalil) VALUES(?,?,?,?,?,?)', [$kod, $typ, mb_substr($text, 0, 500), $smi ? 'schvaleno' : 'navrh', kdo(), $smi ? kdo() : '']);
            flash($smi ? "Pravidlo $kod přidáno – asistent ho použije od dalšího dotazu." : "Pravidlo $kod navrženo, čeká na schválení adminem nebo hlavním pracovníkem.");
        } elseif ($smi && $akce === 'ulozit' && $id && $text !== '') {
            q('UPDATE ai_pravidla SET typ=?, text=?, zmeneno=? WHERE id=?', [$typ, mb_substr($text, 0, 500), nyni(), $id]);
            flash('Pravidlo upraveno.');
        } elseif ($smi && $akce === 'schvalit' && $id) {
            q("UPDATE ai_pravidla SET stav='schvaleno', aktivni=1, schvalil=?, zmeneno=? WHERE id=?", [kdo(), nyni(), $id]);
            flash('Pravidlo schváleno.');
        } elseif ($smi && $akce === 'vypnout' && $id) {
            q('UPDATE ai_pravidla SET aktivni=1-aktivni, zmeneno=? WHERE id=?', [nyni(), $id]);
            flash('Stav pravidla změněn.');
        }
        presmeruj('/?r=ai_pravidla');
    }
    $pravidla = q('SELECT * FROM ai_pravidla ORDER BY stav DESC, aktivni DESC, kod, id')->fetchAll();
    $zamitnute = q("SELECT n.*, s.nazev AS sluzba FROM ai_navrhy n LEFT JOIN varianty v ON v.id=n.varianta_id LEFT JOIN sluzby s ON s.id=v.sluzba_id
        WHERE n.stav='zamitnuto' AND n.rozhodnuto >= ? ORDER BY n.rozhodnuto DESC LIMIT 50", [date('Y-m-d', strtotime('-30 days'))])->fetchAll();
    $stat = q1("SELECT SUM(stav='prijato') prijato, SUM(stav='zamitnuto') zamitnuto, SUM(stav IN ('ceka','otevreno')) ceka FROM ai_navrhy WHERE vytvoreno >= ?", [date('Y-m-d', strtotime('-30 days'))]);
    hlavicka('Pravidla asistenta', 'ai'); ?>
    <div class="cal-nav"><h1>Pravidla salonu pro asistenta</h1><span class="grow"></span><a class="btn small" href="/?r=ai">← Asistent</a></div>
    <p class="muted">Asistent se „učí“ jen tím, co je tady. Každý dotaz dostane všechna schválená a zapnutá pravidla. <b>Tvrdé</b> nesmí porušit, <b>měkké</b> jsou preference.
      Preference konkrétního zákazníka patří na jeho kartu (oblíbená masérka, preference, kdy může), ne sem.</p>
    <div class="cols">
      <section class="card">
        <?php foreach ($pravidla as $p): ?>
          <form method="post" class="row-item" style="align-items:flex-start<?= $p['aktivni'] ? '' : ';opacity:.5' ?>">
            <?= csrf_pole() ?><input type="hidden" name="id" value="<?= $p['id'] ?>">
            <b style="min-width:48px"><?= h($p['kod']) ?></b>
            <div class="grow">
              <?php if ($smi): ?>
                <textarea name="text" rows="2"><?= h($p['text']) ?></textarea>
                <div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;align-items:center">
                  <select name="typ" style="width:auto;margin:0"><?= moznosti(['tvrde' => 'tvrdé', 'mekke' => 'měkké'], $p['typ']) ?></select>
                  <button class="btn small" name="akce" value="ulozit">Uložit</button>
                  <?php if ($p['stav'] === 'navrh'): ?><button class="btn small primary" name="akce" value="schvalit">Schválit</button><?php endif ?>
                  <button class="btn small" name="akce" value="vypnout"><?= $p['aktivni'] ? 'Vypnout' : 'Zapnout' ?></button>
                  <span class="small muted"><?= $p['stav'] === 'navrh' ? '⏳ návrh od ' . h($p['autor']) : 'schválil ' . h($p['schvalil']) ?></span>
                </div>
              <?php else: ?>
                <?= h($p['text']) ?> <span class="tag"><?= $p['typ'] === 'tvrde' ? 'tvrdé' : 'měkké' ?></span>
                <?php if ($p['stav'] === 'navrh'): ?><span class="tag">⏳ čeká na schválení</span><?php elseif (!$p['aktivni']): ?><span class="tag">vypnuto</span><?php endif ?>
              <?php endif ?>
            </div>
          </form>
        <?php endforeach ?>
        <form method="post" style="margin-top:14px"><?= csrf_pole() ?>
          <h2><?= $smi ? 'Přidat pravidlo' : 'Navrhnout pravidlo' ?></h2>
          <textarea name="text" rows="2" required maxlength="500" placeholder="Např.: Masáž 90 min neplánovat po 18:30."></textarea>
          <div style="display:flex;gap:8px;margin-top:6px"><select name="typ" style="width:auto;margin:0"><?= moznosti(['tvrde' => 'tvrdé', 'mekke' => 'měkké'], 'tvrde') ?></select>
          <button class="btn primary" name="akce" value="pridat"><?= $smi ? 'Přidat' : 'Navrhnout' ?></button></div>
        </form>
      </section>
      <aside class="card side">
        <h2>Zpětná vazba za 30 dní</h2>
        <p class="small">Návrhů přijato: <b><?= (int)$stat['prijato'] ?></b> · zamítnuto: <b><?= (int)$stat['zamitnuto'] ?></b> · čeká: <b><?= (int)$stat['ceka'] ?></b></p>
        <p class="small muted">Důvody zamítnutí – podklad pro nová pravidla:</p>
        <?php if (!$zamitnute): ?><p class="small muted">Zatím žádné.</p><?php endif ?>
        <?php foreach ($zamitnute as $n): ?>
          <div class="small" style="border-top:1px solid var(--bd);padding:6px 0"><?= h(datum_cz($n['zacatek'])) ?> · <?= h($n['sluzba'] ?? '') ?><br><b><?= h($n['duvod']) ?></b> <span class="muted">(<?= h($n['rozhodl']) ?>)</span></div>
        <?php endforeach ?>
      </aside>
    </div>
    <?php paticka();
}

function stranka_ai_log(array $u): void
{
    vyzaduj_admina();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = $_POST['akce'] ?? '';
        $f = ai_soubor_cti();
        $model = isset(AI_MODELY[$_POST['model'] ?? '']) ? $_POST['model'] : ai_cfg()['model'];
        $klic = trim((string)($_POST['api_key'] ?? ''));
        if ($akce === 'ulozit') {
            if ($klic !== '' && !preg_match('~^sk-ant-[A-Za-z0-9_-]{20,}$~', $klic)) { flash('Tohle nevypadá jako API klíč Anthropicu (začíná „sk-ant-“).', 'err'); presmeruj('/?r=ai_log'); }
            if ($klic !== '' && ($err = ai_test($klic, $model))) { flash('Klíč neuložen – zkušební volání selhalo: ' . $err, 'err'); presmeruj('/?r=ai_log'); }
            if ($klic !== '') $f['api_key'] = $klic;
            $f['model'] = $model;
            $f['zmenil'] = kdo(); $f['zmeneno'] = nyni();
            ai_soubor_uloz($f);
            ai_log('nastaveni', 'model ' . $model . ($klic !== '' ? ', nový klíč …' . substr($klic, -4) : ''));
            flash($klic !== '' ? 'Klíč ověřen a uložen, asistent je zapnutý.' : 'Nastavení uloženo.');
        } elseif ($akce === 'odebrat') {
            unset($f['api_key']); ai_soubor_uloz($f);
            ai_log('nastaveni', 'klíč odebrán');
            flash('Klíč odebrán.' . (trim((string)(cfg()['ai']['api_key'] ?? '')) !== '' ? ' Pozor: v config.php je další klíč, ten platí dál.' : ' Asistent je vypnutý.'), 'warn');
        } elseif ($akce === 'test') {
            $err = ai_zapnuto() ? ai_test() : 'Není vložený klíč.';
            flash($err ? 'Spojení nefunguje: ' . $err : 'Spojení funguje (' . ai_cfg()['model'] . ').', $err ? 'err' : 'ok');
        }
        presmeruj('/?r=ai_log');
    }
    $dnes = q1("SELECT COUNT(*) volani, COALESCE(SUM(tokeny_in),0) i, COALESCE(SUM(tokeny_out),0) o FROM ai_log WHERE typ='api' AND cas >= ?", [date('Y-m-d 00:00:00')]);
    $mesic = q1("SELECT COUNT(*) volani, COALESCE(SUM(tokeny_in),0) i, COALESCE(SUM(tokeny_out),0) o FROM ai_log WHERE typ='api' AND cas >= ?", [date('Y-m-01 00:00:00')]);
    $rows = q('SELECT * FROM ai_log ORDER BY id DESC LIMIT 200')->fetchAll();
    $c = ai_cfg();
    $f = ai_soubor_cti();
    hlavicka('Nastavení asistenta', 'ai'); ?>
    <div class="cal-nav"><h1>Nastavení a spotřeba asistenta</h1><span class="grow"></span><a class="btn small" href="/?r=ai">← Asistent</a></div>
    <form method="post" class="card"><?= csrf_pole() ?>
      <h2>API klíč a model</h2>
      <p class="small">Stav: <?= ai_zapnuto() ? '<b style="color:var(--ok)">zapnuto</b> – klíč …' . h(substr($c['api_key'], -4)) . ' (' . h($c['zdroj_klice'] ?? '') . ')' . (!empty($f['zmenil']) && ($c['zdroj_klice'] ?? '') === 'kartoteka' ? ', vložil ' . h($f['zmenil']) . ' ' . h(datum_cz($f['zmeneno'])) : '') : '<b class="warn">vypnuto – chybí klíč</b>' ?></p>
      <div class="grid3">
        <label>Nový API klíč<input type="password" name="api_key" autocomplete="off" placeholder="sk-ant-…" spellcheck="false"></label>
        <label>Model<select name="model"><?= moznosti(AI_MODELY, $c['model']) ?></select></label>
        <div style="display:flex;gap:6px;align-items:end;margin-bottom:10px;flex-wrap:wrap">
          <button class="btn primary" name="akce" value="ulozit">Uložit</button>
          <?php if (ai_zapnuto()): ?><button class="btn" name="akce" value="test">Vyzkoušet spojení</button><?php endif ?>
          <?php if (!empty($f['api_key'])): ?><button class="btn danger" name="akce" value="odebrat" data-confirm="Odebrat klíč? Asistent se vypne.">Odebrat klíč</button><?php endif ?>
        </div>
      </div>
      <p class="small muted">Klíč vytvoříte v Claude Console (console.anthropic.com → API keys); tam nastavte i měsíční limit útraty. Před uložením se klíč ověří krátkým voláním.
        Uloží se na NAS do <code>data/</code> (mimo web, mimo git) – zálohuje se s databází, takže zálohu chraňte. Klíč už se nikde nezobrazí celý.</p>
    </form>
    <p>Denní limit volání: <?= (int)$c['denni_limit'] ?><br>
      Dnes: <b><?= (int)$dnes['volani'] ?></b> volání, <?= number_format((int)$dnes['i'], 0, ',', ' ') ?> vstupních / <?= number_format((int)$dnes['o'], 0, ',', ' ') ?> výstupních tokenů ·
      tento měsíc: <b><?= (int)$mesic['volani'] ?></b> volání, <?= number_format((int)$mesic['i'], 0, ',', ' ') ?> / <?= number_format((int)$mesic['o'], 0, ',', ' ') ?> tokenů.
      <span class="muted small">Cenu podle tokenů ukazuje Claude Console; tam nastavte i měsíční limit útraty.</span></p>
    <section class="card"><table class="tbl" style="width:100%;font-size:.85rem">
      <tr><th align="left">Čas</th><th align="left">Kdo</th><th align="left">Typ</th><th align="left">Detail</th><th>Tokeny</th></tr>
      <?php foreach ($rows as $r): ?><tr><td><?= h($r['cas']) ?></td><td><?= h($r['kdo']) ?></td><td><?= h($r['typ']) ?></td><td><?= h(mb_strimwidth($r['detail'], 0, 220, '…')) ?></td><td align="right"><?= $r['typ'] === 'api' ? (int)$r['tokeny_in'] . ' / ' . (int)$r['tokeny_out'] : '' ?></td></tr><?php endforeach ?>
    </table></section>
    <?php paticka();
}
