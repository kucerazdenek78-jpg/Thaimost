<?php
// Platby: bankovní avíza (e-mail „přišla platba“) se čtou ze samostatné schránky (Správa → Pošta → 💳 Platby),
// z textu se vytáhne částka, VS, protiúčet a zpráva a kartotéka navrhne, ke kterému objednanému poukazu platba patří.
// Zaplaceno se poukaz označí až po potvrzení na stránce Správa → Platby (nebo automaticky, je-li to zapnuté,
// avízo přišlo od nastavené adresy banky a sedí VS i částka). Schránka se jen čte: zprávy zůstanou nepřečtené.

const PLATBY_INTERVAL = 600;   // jak často se schránka kontroluje na pozadí (s)
const PLATBY_STAVY = ['nova' => 'Ke spárování', 'sparovano' => 'Spárováno', 'ignorovano' => 'Nepatří k poukazu', 'odchozi' => 'Odchozí', 'nerozpoznano' => 'Nerozpoznané avízo'];

/** VS pro poukaz z recepce (web má VS = číslo objednávky z formuláře, 10 číslic). 9 číslic → nikdy se nepotká s webem. */
function poukaz_vs_recepce(int $id): string { return (string)(900000000 + $id); }

function platby_ucet(): array
{
    $c = [];
    foreach (['user', 'pass', 'imap_host', 'imap_port', 'webmail', 'odesilatel', 'auto'] as $p) $c[$p] = posta_n("platby.$p");
    $c['imap_port'] = (int)$c['imap_port'] ?: 993;
    return $c;
}
function platby_nastaveno(): bool { $c = platby_ucet(); return $c['imap_host'] !== '' && $c['user'] !== '' && $c['pass'] !== ''; }

/** Je odesílatel avíza na seznamu adres banky? (adresa i doména, např. „automat@fio.cz“ nebo „@csob.cz“) */
function platby_odesilatel_ok(string $from): ?bool
{
    $povolene = array_filter(array_map('trim', preg_split('~[\s,;]+~', strtolower(platby_ucet()['odesilatel'])) ?: []), 'strlen');
    if (!$povolene) return null;                                   // nenastaveno = nevíme
    $adr = preg_match('~<([^>]+)>~', $from, $m) ? strtolower(trim($m[1])) : strtolower(trim($from));
    foreach ($povolene as $p) if ($adr === $p || (str_starts_with($p, '@') && str_ends_with($adr, $p))) return true;
    return false;
}

// ---------- Načtení avíz ze schránky ----------

/** Projde nové zprávy ve schránce plateb, uloží avíza a spáruje je. Vrací ['ok','nove','chyba']. */
function platby_nacti(): array
{
    $c = platby_ucet();
    if (!platby_nastaveno()) return ['ok' => false, 'nove' => 0, 'chyba' => 'Schránka pro platby není nastavená (Správa → Pošta).'];
    $nove = 0;
    try {
        $spoj = imap_prihlas($c['imap_host'], $c['imap_port'], $c['user'], $c['pass']);
        $cmd = $spoj[1];
        $ex = $cmd('EXAMINE INBOX');                                 // jen čtení – nic se neoznačí jako přečtené
        $uv = preg_match('~UIDVALIDITY (\d+)~', $ex, $m) ? $m[1] : '0';
        $posl = posta_n('platby.uidvalidity') === $uv ? (int)posta_n('platby.posledni_uid', '0') : 0;
        $hledej = $posl > 0 ? 'UID SEARCH UID ' . ($posl + 1) . ':*' : 'UID SEARCH SINCE ' . date('j-M-Y', strtotime('-60 days'));
        $se = $cmd($hledej);
        $uids = preg_match('~\* SEARCH([\d ]*)~', $se, $m) ? array_map('intval', array_filter(explode(' ', trim($m[1])), 'strlen')) : [];
        $uids = array_values(array_filter($uids, fn($u) => $u > $posl));
        sort($uids);
        foreach (array_slice($uids, 0, 40) as $uid) {               // po dávkách, ať jedno načtení netrvá dlouho
            $f = $cmd("UID FETCH $uid (BODY.PEEK[]<0.300000>)");
            $raw = preg_match('~\{(\d+)\}\r\n~', $f, $m, PREG_OFFSET_CAPTURE) ? substr($f, $m[0][1] + strlen($m[0][0]), (int)$m[1][0]) : '';
            if ($raw !== '' && platby_uloz_zpravu($raw, "$uv:$uid")) $nove++;
            posta_uloz('platby.posledni_uid', (string)$uid);
        }
        posta_uloz('platby.uidvalidity', $uv);
        imap_odhlas($spoj);
        $r = ['ok' => true, 'nove' => $nove, 'chyba' => ''];
    } catch (Throwable $e) {
        $r = ['ok' => false, 'nove' => $nove, 'chyba' => mb_substr($e->getMessage(), 0, 200)];
    }
    posta_uloz('platby.stav', json_encode($r + ['cas' => nyni()], JSON_UNESCAPED_UNICODE));
    return $r;
}

/** Uloží jednu zprávu jako avízo (jen pokud jde o platbu nebo je od banky). Vrací true, když přibyl nový záznam. */
function platby_uloz_zpravu(string $raw, string $klic): bool
{
    $m = mime_rozloz($raw);
    $id = trim($m['hlavicky']['message-id'] ?? '') ?: $klic;
    if (qv('SELECT 1 FROM platby WHERE msg_id=?', [$id])) return false;
    $from = imap_dekoduj($m['hlavicky']['from'] ?? '');
    $predmet = imap_dekoduj($m['hlavicky']['subject'] ?? '');
    $ts = strtotime($m['hlavicky']['date'] ?? '') ?: time();
    $overeny = platby_odesilatel_ok($from);
    if ($overeny === false) return false;                          // jiný odesílatel než banka – nezajímá nás
    $p = platby_rozpoznej($predmet . "\n" . $m['text']);
    if ($p['castka'] === null && $overeny === null) return false;   // bez nastavené banky bereme jen zprávy s částkou
    $stav = $p['castka'] === null ? 'nerozpoznano' : ($p['castka'] < 0 ? 'odchozi' : 'nova');
    q('INSERT INTO platby(msg_id,prijato,odesilatel,predmet,castka_hal,vs,protistrana,zprava,text,overeny,stav) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
        [$id, date('Y-m-d H:i:s', $ts), mb_substr($from, 0, 200), mb_substr($predmet, 0, 200), $p['castka'], $p['vs'], $p['protistrana'], $p['zprava'],
         mb_substr(trim($m['text']), 0, 3000), $overeny ? 1 : 0, $stav]);
    $pid = (int)db()->lastInsertId();
    if ($stav === 'nova') platby_navrh($pid);
    return true;
}

/** Vytáhne z textu avíza částku (v haléřích, záporná = odchozí), VS, protiúčet a zprávu. Formáty bank se liší – bere obvyklé popisky. */
function platby_rozpoznej(string $t): array
{
    $t = str_replace(["\xc2\xa0", "\t"], ' ', $t);
    $r = ['castka' => null, 'vs' => '', 'protistrana' => '', 'zprava' => ''];
    $cislo = '([+\-−]?\s?\d{1,3}(?:[ .]\d{3})+(?:,\d{1,2})?|[+\-−]?\s?\d+(?:[.,]\d{1,2})?)';
    if (preg_match('~(?:částka|castka|amount|suma|připsáno|pripsano|kredit|výše platby)\b[^0-9+\-−\n]{0,40}' . $cislo . '\s*(?:CZK|Kč|Kc)?~iu', $t, $m)
        || preg_match('~' . $cislo . '\s*(?:CZK|Kč)~u', $t, $m)) {
        $n = str_replace(['−', ' '], ['-', ''], $m[1]);
        if (str_contains($n, ',')) $n = str_replace(['.', ','], ['', '.'], $n);          // 1.000,00 / 1000,00
        elseif (preg_match('~\.\d{3}$~', $n)) $n = str_replace('.', '', $n);              // 1.000 = tisíc
        $r['castka'] = (int)round((float)$n * 100);
        if (preg_match('~\b(odchozí|odchozi|odepsáno|odepsano|debet|platba kartou)\b~iu', $t) && $r['castka'] > 0 && !preg_match('~\b(příchozí|prichozi|připsáno|pripsano|kredit)\b~iu', $t)) $r['castka'] = -$r['castka'];
    }
    if (preg_match('~(?:\bVS\b|variabilní symbol|variabilni symbol|var\.\s*symbol)\s*[:.]?\s*(\d{1,10})\b~iu', $t, $m)) $r['vs'] = ltrim($m[1], '0');
    elseif (preg_match('~\bpoukaz\s+(\d{6,10})\b~iu', $t, $m)) $r['vs'] = $m[1];               // zpráva z QR platby na webu „Poukaz 2611141530“
    if (preg_match('~(?:protiúčet|protiucet|z účtu|z uctu|účet plátce|ucet platce|název protiúčtu|nazev protiuctu|plátce|platce|odesílatel platby)\s*[:]\s*([^\n]{2,120})~iu', $t, $m)) $r['protistrana'] = trim($m[1]);
    if (preg_match('~(?:zpráva pro příjemce|zprava pro prijemce|zpráva příjemci|zprava prijemci|zpráva|zprava|poznámka|poznamka)\s*[:]\s*([^\n]{1,140})~iu', $t, $m)) $r['zprava'] = trim($m[1]);
    return $r;
}

/** Návrh spárování: VS + částka → poukaz ve stavu „objednáno“. */
function platby_navrh(int $id): void
{
    $p = q1('SELECT * FROM platby WHERE id=?', [$id]);
    if (!$p || $p['stav'] !== 'nova' || $p['castka_hal'] === null) return;
    $kand = $p['vs'] !== '' ? q("SELECT * FROM poukazy WHERE stav='objednano' AND vs=?", [$p['vs']])->fetchAll() : [];
    $typ = ''; $pk = null;
    if (count($kand) === 1) { $pk = $kand[0]; $typ = ((int)$pk['hodnota'] * 100 === (int)$p['castka_hal']) ? 'presny' : 'castka'; }
    elseif (!$kand && (int)$p['castka_hal'] % 100 === 0) {        // bez VS: jen celé koruny, stejná částka, poukaz objednaný nejvýš 45 dní před platbou
        $kand = q("SELECT * FROM poukazy WHERE stav='objednano' AND hodnota=? AND vytvoreno >= ?", [intdiv((int)$p['castka_hal'], 100), date('Y-m-d H:i:s', strtotime($p['prijato'] . ' -45 days'))])->fetchAll();
        if (count($kand) === 1) { $pk = $kand[0]; $typ = 'jen_castka'; }
    }
    q('UPDATE platby SET navrh_poukaz_id=?, navrh_typ=? WHERE id=?', [$pk['id'] ?? null, $typ, $id]);
    if ($typ === 'presny' && $p['overeny'] && platby_ucet()['auto'] === '1') platby_potvrd($id, (int)$pk['id'], 'automaticky');
}

/** Spáruje platbu s poukazem a poukaz označí jako zaplacený. Vrací chybu nebo null. */
function platby_potvrd(int $platbaId, int $poukazId, ?string $kdo = null): ?string
{
    $p = q1('SELECT * FROM platby WHERE id=?', [$platbaId]);
    $pk = q1('SELECT * FROM poukazy WHERE id=?', [$poukazId]);
    if (!$p || !$pk) return 'Platba nebo poukaz nenalezen.';
    if ($p['stav'] === 'sparovano') return 'Platba už je spárovaná.';
    $kdo = $kdo ?? kdo();
    $ted = nyni();
    q("UPDATE platby SET stav='sparovano', poukaz_id=?, sparoval=?, sparovano=? WHERE id=?", [$poukazId, $kdo, $ted, $platbaId]);
    $pozn = 'Zaplaceno převodem ' . date('j. n. Y', strtotime($p['prijato'])) . ' (' . number_format($p['castka_hal'] / 100, 2, ',', ' ') . ' Kč' . ($p['vs'] !== '' ? ', VS ' . $p['vs'] : '') . ", spároval $kdo).";
    if ($pk['stav'] === 'objednano') q("UPDATE poukazy SET stav='zaplaceno', poznamka=TRIM(poznamka || ' ' || ?) WHERE id=?", [$pozn, $poukazId]);
    else q("UPDATE poukazy SET poznamka=TRIM(poznamka || ' ' || ?) WHERE id=?", [$pozn, $poukazId]);
    q('INSERT INTO log(kdo,akce,detail) VALUES(?,?,?)', [$kdo, 'platba_sparovana', "platba #$platbaId → poukaz $pk[kod]"]);   // bez session (běží i na pozadí)
    return null;
}

/** Načtení na pozadí (po odeslání odpovědi), nejvýš jednou za PLATBY_INTERVAL. */
function platby_priprav_tik(): void
{
    try {
        if (!platby_nastaveno()) return;
        $st = json_decode(posta_n('platby.stav'), true);
        if ($st && strtotime($st['cas'] ?? '2000-01-01') > time() - PLATBY_INTERVAL) return;
        posta_uloz('platby.stav', json_encode(($st ?: ['ok' => true, 'nove' => 0, 'chyba' => '']) + ['cas' => nyni()], JSON_UNESCAPED_UNICODE)); // zámek proti souběhu
    } catch (Throwable $e) { return; }
    register_shutdown_function(function (): void {
        while (ob_get_level() > 0) @ob_end_flush();
        @flush();
        if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
        try { platby_nacti(); } catch (Throwable $e) { error_log('platby: ' . $e->getMessage()); }
    });
}

function platby_ke_sparovani(): int { try { return (int)qv("SELECT COUNT(*) FROM platby WHERE stav IN ('nova','nerozpoznano')"); } catch (Throwable $e) { return 0; } }

// ---------- MIME (stačí pro textová avíza bank) ----------

/** Rozloží e-mail na hlavičky (malými písmeny) a čistý text (text/plain, jinak text/html bez značek). */
function mime_rozloz(string $raw): array
{
    [$hl, $telo] = array_pad(preg_split("~\r?\n\r?\n~", $raw, 2), 2, '');
    $hl = preg_replace("~\r?\n[ \t]+~", ' ', $hl);
    $h = [];
    foreach (preg_split("~\r?\n~", $hl) as $l) if (preg_match('~^([A-Za-z0-9-]+):\s*(.*)$~', $l, $m)) { $k = strtolower($m[1]); if (!isset($h[$k])) $h[$k] = $m[2]; }
    $typ = strtolower($h['content-type'] ?? 'text/plain');
    if (str_starts_with($typ, 'multipart/') && preg_match('~boundary="?([^";]+)"?~i', $h['content-type'], $b)) {
        $plain = ''; $html = '';
        foreach (explode('--' . $b[1], $telo) as $cast) {
            $cast = ltrim($cast, "\r\n");
            if ($cast === '' || str_starts_with($cast, '--')) continue;
            $sub = mime_rozloz($cast);
            $st = strtolower($sub['hlavicky']['content-type'] ?? 'text/plain');
            if (str_contains(strtolower($sub['hlavicky']['content-disposition'] ?? ''), 'attachment')) continue;
            if ($sub['text'] === '') continue;
            if (str_starts_with($st, 'text/html') || !empty($sub['html'])) $html .= $sub['text'] . "\n"; else $plain .= $sub['text'] . "\n";
        }
        return ['hlavicky' => $h, 'text' => trim($plain !== '' ? $plain : $html), 'html' => $plain === '' && $html !== ''];
    }
    $enc = strtolower(trim($h['content-transfer-encoding'] ?? ''));
    if ($enc === 'base64') $telo = (string)base64_decode(preg_replace('~\s+~', '', $telo));
    elseif ($enc === 'quoted-printable') $telo = quoted_printable_decode($telo);
    $cs = preg_match('~charset="?([A-Za-z0-9_-]+)~i', $h['content-type'] ?? '', $m) ? strtoupper($m[1]) : 'UTF-8';
    if ($cs !== 'UTF-8' && $cs !== 'US-ASCII') { $k = @mb_convert_encoding($telo, 'UTF-8', $cs); if (is_string($k)) $telo = $k; }
    $html = str_starts_with($typ, 'text/html');
    if ($html) {
        $telo = preg_replace('~<(script|style)\b.*?</\1>~is', '', $telo);
        $telo = preg_replace('~<(br|/p|/div|/tr|/h\d|/li)\b[^>]*>~i', "\n", $telo);
        $telo = preg_replace('~</t[dh]>~i', ' ', $telo);
        $telo = html_entity_decode(strip_tags($telo), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $telo = preg_replace("~[ \t]+~", ' ', $telo);
        $telo = preg_replace("~\n\s*\n+~", "\n", $telo);
    }
    if (!str_starts_with($typ, 'text/') && !str_starts_with($typ, 'multipart/')) $telo = '';
    return ['hlavicky' => $h, 'text' => trim($telo), 'html' => $html];
}

// ---------- Stránka Správa → Platby (/?r=nastaveni&sekce=platby) ----------

function stranka_platby(array $u): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = $_POST['akce'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        if ($akce === 'nacist') {
            $r = platby_nacti();
            $r['ok'] ? flash($r['nove'] ? "Načteno nových avíz: $r[nove]." : 'Žádná nová avíza.') : flash($r['chyba'], 'err');
        } elseif ($akce === 'potvrdit') {
            $chyba = platby_potvrd($id, (int)($_POST['poukaz_id'] ?? 0));
            $chyba ? flash($chyba, 'err') : flash('Spárováno, poukaz je zaplacený.');
        } elseif ($akce === 'ignorovat') {
            q("UPDATE platby SET stav='ignorovano', sparoval=?, sparovano=? WHERE id=? AND stav IN ('nova','nerozpoznano')", [kdo(), nyni(), $id]);
            flash('Platba označena, že k poukazu nepatří.');
        } elseif ($akce === 'vratit') {
            $p = q1('SELECT * FROM platby WHERE id=?', [$id]);
            if ($p && $p['stav'] === 'sparovano' && $p['poukaz_id']) {
                q("UPDATE poukazy SET stav='objednano' WHERE id=? AND stav='zaplaceno'", [$p['poukaz_id']]);
                zapis_log('platba_vracena', "platba #$id");
            }
            q("UPDATE platby SET stav=CASE WHEN castka_hal IS NULL THEN 'nerozpoznano' ELSE 'nova' END, poukaz_id=NULL, sparoval='', sparovano=NULL WHERE id=?", [$id]);
            platby_navrh($id);
            flash('Spárování zrušeno.');
        }
        presmeruj('/?r=nastaveni&sekce=platby' . (isset($_GET['f']) ? '&f=' . urlencode($_GET['f']) : ''));
    }
    // při otevření stránky načíst, je-li poslední kontrola starší než 2 minuty
    $st = json_decode(posta_n('platby.stav'), true) ?: [];
    if (platby_nastaveno() && strtotime($st['cas'] ?? '2000-01-01') < time() - 120) { platby_nacti(); $st = json_decode(posta_n('platby.stav'), true) ?: []; }

    $f = $_GET['f'] ?? 'k_vyrizeni';
    $kde = ['k_vyrizeni' => "p.stav IN ('nova','nerozpoznano')", 'sparovano' => "p.stav='sparovano'", 'vse' => '1=1'][$f] ?? "p.stav IN ('nova','nerozpoznano')";
    $rows = q("SELECT p.*, n.kod AS navrh_kod, n.hodnota AS navrh_hodnota, n.objednal_jmeno AS navrh_jmeno, n.stav AS navrh_stav, s.kod AS sp_kod
               FROM platby p LEFT JOIN poukazy n ON n.id=p.navrh_poukaz_id LEFT JOIN poukazy s ON s.id=p.poukaz_id
               WHERE $kde ORDER BY p.prijato DESC LIMIT 200")->fetchAll();
    $cekaji = q("SELECT id, kod, hodnota, vs, objednal_jmeno, vytvoreno FROM poukazy WHERE stav='objednano' ORDER BY vytvoreno DESC LIMIT 200")->fetchAll();
    $c = platby_ucet();
    $typy = ['presny' => ['✅', 'Sedí VS i částka'], 'castka' => ['⚠️', 'Sedí VS, částka ne'], 'jen_castka' => ['🔎', 'Bez VS – sedí jen částka']];
    hlavicka('Platby', 'nastaveni'); ?>
    <p class="muted small"><a href="/?r=nastaveni">← Správa</a></p>
    <div class="cal-nav"><h1>💳 Platby</h1>
      <form method="post" class="inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="nacist"><button class="btn primary"<?= platby_nastaveno() ? '' : ' disabled' ?>>Načíst avíza teď</button></form></div>
    <?php if (!platby_nastaveno()): ?>
      <p class="flash warn">Schránka pro platby zatím není nastavená. Nastavíte ji v <a href="/?r=nastaveni&amp;sekce=posta#platby">Správa → Pošta → 💳 Platby</a> (adresa, na kterou banka posílá avíza o příchozích platbách).</p>
    <?php else: ?>
      <p class="muted small" style="margin-top:0">Schránka <b><?= h($c['user']) ?></b> · kontroluje se sama každých <?= PLATBY_INTERVAL / 60 ?> min při práci v kartotéce<?= $st ? ' · naposledy ' . h(datum_cz($st['cas'] ?? null)) : '' ?>
        <?= !empty($st) && empty($st['ok']) ? '<br><span class="warn">❗ ' . h($st['chyba'] ?? '') . '</span>' : '' ?>
        <?= $c['odesilatel'] === '' ? '<br><span class="warn">Není vyplněná adresa banky – berou se všechny zprávy s částkou a nic se nepotvrzuje samo.</span>' : '' ?></p>
    <?php endif ?>
    <p class="muted small"><?php foreach (['k_vyrizeni' => 'k vyřízení', 'sparovano' => 'spárované', 'vse' => 'vše'] as $k => $t): ?><a href="/?r=nastaveni&amp;sekce=platby&amp;f=<?= $k ?>"<?= $f === $k ? ' class="on"' : '' ?>><?= $t ?></a> · <?php endforeach ?>
      Poukazů čeká na platbu: <a href="/?r=poukazy&amp;f=objednano"><?= count($cekaji) ?></a></p>

    <?php if (!$rows): ?><p class="card muted"><?= $f === 'k_vyrizeni' ? 'Žádná platba nečeká na spárování.' : 'Zatím žádné platby.' ?></p><?php endif ?>
    <?php foreach ($rows as $p): ?>
    <section class="card platba platba-<?= h($p['stav']) ?>">
      <div class="platba-hl">
        <div class="platba-castka"><?= $p['castka_hal'] === null ? '—' : h(number_format($p['castka_hal'] / 100, ($p['castka_hal'] % 100) ? 2 : 0, ',', ' ')) . ' Kč' ?></div>
        <div class="grow">
          <b><?= $p['vs'] !== '' ? 'VS ' . h($p['vs']) : 'bez VS' ?></b><?= $p['protistrana'] !== '' ? ' · ' . h($p['protistrana']) : '' ?><?= $p['zprava'] !== '' ? '<br><span class="small">„' . h($p['zprava']) . '“</span>' : '' ?>
          <br><span class="muted small"><?= h(datum_cz($p['prijato'])) ?> · <?= h($p['predmet']) ?> · <?= $p['overeny'] ? '🏦 od banky' : '<span class="warn">odesílatel neověřen</span> (' . h($p['odesilatel']) . ')' ?></span>
        </div>
        <span class="tag"><?= h(PLATBY_STAVY[$p['stav']] ?? $p['stav']) ?></span>
      </div>

      <?php if ($p['stav'] === 'sparovano'): ?>
        <p>→ poukaz <a href="/?r=poukaz&amp;id=<?= (int)$p['poukaz_id'] ?>"><b><?= h($p['sp_kod']) ?></b></a> <span class="muted small">(<?= h($p['sparoval']) ?>, <?= h(datum_cz($p['sparovano'])) ?>)</span>
          <form method="post" class="inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="vratit"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn small" data-confirm="Zrušit spárování? Poukaz se vrátí do stavu Objednáno (čeká na platbu).">Zrušit spárování</button></form></p>
      <?php elseif (in_array($p['stav'], ['nova', 'nerozpoznano'], true)): ?>
        <?php if ($p['navrh_poukaz_id'] && $p['navrh_stav'] === 'objednano'): [$ik, $it] = $typy[$p['navrh_typ']] ?? ['🔎', 'Návrh']; ?>
          <form method="post" class="platba-navrh"><?= csrf_pole() ?><input type="hidden" name="akce" value="potvrdit"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="poukaz_id" value="<?= (int)$p['navrh_poukaz_id'] ?>">
            <span><?= $ik ?> <?= h($it) ?>: poukaz <a href="/?r=poukaz&amp;id=<?= (int)$p['navrh_poukaz_id'] ?>"><b><?= h($p['navrh_kod']) ?></b></a> · <?= kc($p['navrh_hodnota']) ?> · <?= h($p['navrh_jmeno']) ?></span>
            <button class="btn primary">Potvrdit – zaplaceno</button></form>
        <?php endif ?>
        <form method="post" class="platba-rucne"><?= csrf_pole() ?><input type="hidden" name="akce" value="potvrdit"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <select name="poukaz_id" required><option value="">— přiřadit k poukazu, který čeká na platbu —</option>
            <?php foreach ($cekaji as $k): ?><option value="<?= (int)$k['id'] ?>"><?= h($k['kod']) ?> · <?= kc($k['hodnota']) ?> · VS <?= h($k['vs'] ?: '—') ?> · <?= h($k['objednal_jmeno']) ?> · <?= h(date('j. n.', strtotime($k['vytvoreno']))) ?></option><?php endforeach ?>
          </select><button class="btn">Přiřadit</button></form>
        <form method="post" class="inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="ignorovat"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn small">Nepatří k poukazu</button></form>
      <?php elseif ($p['stav'] === 'ignorovano'): ?>
        <form method="post" class="inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="vratit"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn small">Vrátit ke spárování</button></form>
      <?php endif ?>
      <details><summary class="muted small">text avíza</summary><pre class="platba-text"><?= h($p['text']) ?></pre></details>
    </section>
    <?php endforeach ?>
    <p class="muted small">Kartotéka čte ze schránky jen avíza o platbách (zprávy zůstanou nepřečtené). Poukaz se označí jako zaplacený až po potvrzení<?= $c['auto'] === '1' ? ' – nebo sám, když avízo přišlo od banky a sedí VS i částka' : '' ?>. VS poukazu je na jeho kartě.</p>
    <?php paticka();
}
