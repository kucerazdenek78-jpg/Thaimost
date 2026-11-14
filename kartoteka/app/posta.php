<?php
// Pošta (Správa → Pošta): dva účty – komunikace se zákazníky a ostatní komunikace – nastavitelné přímo v aplikaci,
// hlídání odesílání (fronta, chyby), nepřečtená pošta ve schránkách (IMAP, jen čtení) a kontrola DNS domény (MX, SPF, DKIM, DMARC).
// Dokud v aplikaci není nic vyplněno, platí původní nastavení z config.php (smtp, email_salon).

const POSTA_UCTY = [
    'zakaznici' => ['👥 Komunikace se zákazníky', 'Potvrzení termínů, odpovědi na žádosti z webu, dárkové poukazy e-mailem. Zákazníci odpovídají na tuto adresu (nebo na „Odpovědi chodí na“).'],
    'interni'   => ['🏢 Ostatní komunikace', 'Upozornění pro salon (nové žádosti z webu, objednávky poukazů, předčasně ukončené masáže) a rozpisy směn masérkám.'],
];
const POSTA_POLE = ['host', 'port', 'user', 'pass', 'from', 'from_name', 'reply_to', 'imap_host', 'imap_port', 'webmail'];
/** Předvolby: název, SMTP host, port (SSL), IMAP host, port (SSL), webmail. */
const POSTA_PRESETY = [
    'seznam' => ['Seznam – Email Profi (vlastní doména) i @seznam.cz / @email.cz', 'smtp.seznam.cz', 465, 'imap.seznam.cz', 993, 'https://email.seznam.cz'],
    'gmail'  => ['Gmail / Google Workspace (potřebuje „heslo aplikace“)', 'smtp.gmail.com', 465, 'imap.gmail.com', 993, 'https://mail.google.com'],
];

function posta_n(string $k, string $def = ''): string
{
    $v = qv('SELECT hodnota FROM posta_nast WHERE klic=?', [$k]);
    return $v === null ? $def : (string)$v;
}

function posta_uloz(string $k, ?string $v): void
{
    if ($v === null) q('DELETE FROM posta_nast WHERE klic=?', [$k]);
    else q('INSERT INTO posta_nast(klic,hodnota) VALUES(?,?) ON CONFLICT(klic) DO UPDATE SET hodnota=excluded.hodnota', [$k, $v]);
}

/**
 * Nastavení účtu pro odesílání. 'zdroj': aplikace | config (zatím jen config.php) | stejny (ostatní = stejný účet jako zákazníci) | nic.
 */
function posta_ucet(string $ucet): array
{
    $prazdny = array_fill_keys(POSTA_POLE, '') + ['port' => 465, 'imap_port' => 993, 'from_name' => 'Thai Most'];
    if ($ucet === 'interni' && posta_n('interni.stejny', '1') === '1') return ['zdroj' => 'stejny'] + posta_ucet('zakaznici');
    $c = [];
    foreach (POSTA_POLE as $p) $c[$p] = posta_n("$ucet.$p");
    if ($c['host'] !== '') {
        $c['port'] = (int)$c['port'] ?: 465;
        $c['imap_port'] = (int)$c['imap_port'] ?: 993;
        if ($c['from_name'] === '') $c['from_name'] = 'Thai Most';
        return ['zdroj' => 'aplikace'] + $c;
    }
    $cfg = cfg()['smtp'] ?? [];
    if ($ucet === 'zakaznici' && !empty($cfg['host']))
        return ['zdroj' => 'config'] + array_merge($prazdny, array_intersect_key($cfg, $prazdny), ['port' => (int)($cfg['port'] ?? 465)]);
    return ['zdroj' => 'nic'] + $prazdny;
}

/** Adresy, kam chodí upozornění pro salon (nové žádosti, objednávky poukazů…). */
function posta_upozorneni(): array
{
    $t = posta_n('upozorneni', (string)(cfg()['email_salon'] ?? ''));
    $a = array_filter(preg_split('~[\s,;]+~', $t) ?: [], fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL));
    return array_values(array_unique($a));
}

/** Stav účtu pro hlídání: počty ve frontě za 30 dní, poslední odeslání a poslední chyba. */
function posta_stav(string $ucet): array
{
    $c = posta_ucet($ucet);
    $od = date('Y-m-d H:i:s', strtotime('-30 days'));
    $poc = q("SELECT stav, COUNT(*) FROM emaily WHERE ucet=? AND vytvoreno>=? GROUP BY stav", [$ucet, $od])->fetchAll(PDO::FETCH_KEY_PAIR);
    $ok = q1("SELECT odeslano, komu FROM emaily WHERE ucet=? AND stav='odeslano' ORDER BY odeslano DESC LIMIT 1", [$ucet]);
    $err = q1("SELECT vytvoreno, komu, chyba FROM emaily WHERE ucet=? AND stav='chyba' ORDER BY id DESC LIMIT 1", [$ucet]);
    $ceka = (int)qv("SELECT COUNT(*) FROM emaily WHERE ucet=? AND stav='ceka'", [$ucet]);
    if ($c['host'] === '') $s = ['nenastaveno', '⚪ Nenastaveno – e-maily se jen ukládají do fronty'];
    elseif ($err && (!$ok || $err['vytvoreno'] > $ok['odeslano'])) $s = ['chyba', '🔴 Poslední odeslání selhalo'];
    elseif ($ok) $s = ['ok', '🟢 Odesílání funguje'];
    else $s = ['nevyzkouseno', '🟡 Nastaveno, zatím nic neodešlo – pošlete zkušební e-mail'];
    return ['kod' => $s[0], 'text' => $s[1], 'odeslano' => (int)($poc['odeslano'] ?? 0), 'chyby' => (int)($poc['chyba'] ?? 0),
            'ceka' => $ceka, 'posledni_ok' => $ok, 'posledni_chyba' => $err, 'ucet' => $c];
}

/** Krátké shrnutí pro kartu v Nastavení a přehled: [počet problémů, text]. */
function posta_souhrn(): array
{
    $p = 0; $t = [];
    foreach (array_keys(POSTA_UCTY) as $u) {
        $s = posta_stav($u);
        if (in_array($s['kod'], ['chyba', 'nenastaveno'], true)) $p++;
        $nove = posta_imap_cache($u)['nove'] ?? null;
        $t[] = POSTA_UCTY[$u][0] . ': ' . $s['text'] . ($nove ? " · 📥 $nove nepřečtených" : '');
    }
    return [$p, $t];
}

// ---------- Schránka (IMAP, jen čtení) ----------

/** Poslední známý stav schránky (uložený, aby se stránky nenačítaly pomalu). */
function posta_imap_cache(string $ucet): array
{
    $j = json_decode(posta_n("imap_cache.$ucet"), true);
    return is_array($j) ? $j : [];
}

/** Přečte stav schránky přes IMAP (EXAMINE = jen čtení, BODY.PEEK = zprávy zůstanou nepřečtené) a uloží ho. */
function posta_imap_obnov(string $ucet): array
{
    $c = posta_ucet($ucet);
    if (($c['zdroj'] ?? '') === 'stejny') return posta_imap_obnov('zakaznici');
    if ($c['imap_host'] === '' || $c['user'] === '' || $c['pass'] === '') return ['ok' => false, 'chyba' => 'Schránka není nastavená (IMAP server, uživatel a heslo).'];
    try {
        $r = imap_stav_cti($c['imap_host'], (int)$c['imap_port'], $c['user'], $c['pass']);
        $r = ['ok' => true, 'cas' => nyni()] + $r;
    } catch (Throwable $e) {
        $r = ['ok' => false, 'cas' => nyni(), 'chyba' => mb_substr($e->getMessage(), 0, 200)];
    }
    posta_uloz("imap_cache.$ucet", json_encode($r, JSON_UNESCAPED_UNICODE));
    return $r;
}

/** Přihlásí se k IMAP schránce (SSL). Vrací [socket, $cmd] – $cmd('PŘÍKAZ') vrátí celou odpověď, při NO/BAD vyhodí výjimku. */
function imap_prihlas(string $host, int $port, string $user, string $pass): array
{
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $s = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) throw new RuntimeException("Spojení se schránkou: $errstr ($errno)");
    stream_set_timeout($s, 30);
    $n = 0;
    $cmd = function (string $c) use ($s, &$n): string {
        $tag = 'a' . (++$n);
        fwrite($s, "$tag $c\r\n");
        $out = '';
        while (($line = fgets($s, 8192)) !== false) {
            $out .= $line;
            if (preg_match('~\{(\d+)\}\r\n$~', $line, $m)) {          // literál – načíst přesně N bajtů
                $zbyva = (int)$m[1];
                while ($zbyva > 0 && !feof($s)) { $kus = fread($s, $zbyva); if ($kus === false || $kus === '') break; $out .= $kus; $zbyva -= strlen($kus); }
                continue;
            }
            if (str_starts_with($line, "$tag ")) {
                if (!preg_match("~^$tag OK~", $line)) throw new RuntimeException('Schránka: ' . trim(substr($line, strlen($tag) + 1)));
                return $out;
            }
        }
        throw new RuntimeException('Schránka neodpověděla.');
    };
    $uvod = fgets($s, 8192);
    if (!$uvod || !str_starts_with($uvod, '* OK')) throw new RuntimeException('Schránka: neočekávaná odpověď serveru.');
    $q = fn(string $t) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $t) . '"';
    try {
        $cmd('LOGIN ' . $q($user) . ' ' . $q($pass));
    } catch (RuntimeException $e) {
        throw new RuntimeException('Přihlášení ke schránce se nepovedlo (špatné jméno/heslo, nebo je u schránky vypnutý přístup přes IMAP).');
    }
    return [$s, $cmd];
}

function imap_odhlas(array $spoj): void
{
    try { ($spoj[1])('LOGOUT'); } catch (Throwable $e) {}
    @fclose($spoj[0]);
}

/** Počet zpráv, nepřečtených a hlavičky posledních 10 nepřečtených (EXAMINE = jen čtení). */
function imap_stav_cti(string $host, int $port, string $user, string $pass): array
{
    $spoj = imap_prihlas($host, $port, $user, $pass);
    $cmd = $spoj[1];
    $ex = $cmd('EXAMINE INBOX');
    $celkem = preg_match('~\* (\d+) EXISTS~', $ex, $m) ? (int)$m[1] : 0;
    $se = $cmd('SEARCH UNSEEN');
    $ids = preg_match('~\* SEARCH([\d ]*)~', $se, $m) ? array_map('intval', array_filter(explode(' ', trim($m[1])), 'strlen')) : [];
    $zpravy = [];
    if ($ids) {
        $posl = array_slice($ids, -10);
        $f = $cmd('FETCH ' . implode(',', $posl) . ' (BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)])');
        foreach (preg_split('~^\* \d+ FETCH ~m', $f) as $blok) {
            $hl = imap_hlavicky($blok);
            if ($hl['from'] === '' && $hl['subject'] === '') continue;
            $zpravy[] = $hl;
        }
        $zpravy = array_reverse($zpravy);
    }
    imap_odhlas($spoj);
    return ['celkem' => $celkem, 'nove' => count($ids), 'zpravy' => $zpravy];
}

function imap_hlavicky(string $t): array
{
    $t = preg_replace("~\r?\n[ \t]+~", ' ', $t);                     // spojit zalomené hlavičky
    $h = ['from' => '', 'subject' => '', 'date' => ''];
    foreach (['from' => 'From', 'subject' => 'Subject', 'date' => 'Date'] as $k => $n)
        if (preg_match("~^$n:\s*(.*)$~mi", $t, $m)) $h[$k] = imap_dekoduj(trim($m[1]));
    if ($h['date'] !== '' && ($ts = strtotime($h['date']))) $h['date'] = date('Y-m-d H:i', $ts);
    return $h;
}

function imap_dekoduj(string $v): string
{
    if (function_exists('iconv_mime_decode')) {
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($d !== false) return mb_substr($d, 0, 200);
    }
    return mb_substr(mb_decode_mimeheader($v), 0, 200);
}

// ---------- Kontrola DNS domény ----------

/** Zkontroluje DNS domény odesílatele. Vrací seznam [nazev, ok (true/false/null = jen info), text, co_udelat]. */
function posta_dns(string $domena, string $smtpHost): array
{
    $domena = strtolower(trim($domena));
    if ($domena === '' || !function_exists('dns_get_record')) return [];
    $seznam = str_contains($smtpHost, 'seznam.cz');
    $google = str_contains($smtpHost, 'gmail.com') || str_contains($smtpHost, 'google');
    if (in_array($domena, ['seznam.cz', 'email.cz', 'post.cz', 'gmail.com', 'outlook.cz', 'outlook.com', 'centrum.cz'], true))
        return [['Doména', null, "Adresa na veřejné doméně $domena – DNS spravuje poskytovatel, nic se nenastavuje.", '']];
    $txt = function (string $n): array {
        $r = @dns_get_record($n, DNS_TXT) ?: [];
        return array_map(fn($x) => $x['txt'] ?? implode('', $x['entries'] ?? []), $r);
    };
    // bez funkčního DNS by kontrola hlásila „chybí“ i u správně nastavené domény – raději neříct nic
    if (!(@dns_get_record('seznam.cz', DNS_MX))) return [['DNS', null, 'Kartotéka se teď nedostane k DNS serveru, kontrola neproběhla. Zkuste stránku otevřít znovu.', '']];
    $vysl = [];
    $mx = @dns_get_record($domena, DNS_MX) ?: [];
    usort($mx, fn($a, $b) => $a['pri'] <=> $b['pri']);
    $mxT = implode(', ', array_map(fn($x) => $x['pri'] . ' ' . $x['target'], $mx));
    $mxSeznam = $mx && !array_filter($mx, fn($x) => !str_contains($x['target'], 'seznam.cz'));
    if (!$mx) $vysl[] = ['MX (kam chodí pošta)', false, 'Žádný MX záznam – na adresy @' . $domena . ' nic nedojde.', 'V DNS (Cloudflare) přidejte MX podle administrace poskytovatele.'];
    elseif ($seznam) $vysl[] = ['MX (kam chodí pošta)', $mxSeznam, $mxT, $mxSeznam ? '' : 'Pošta zatím chodí jinam než na Seznam. Po založení Email Profi nahraďte MX hodnotami z administrace Seznamu.'];
    else $vysl[] = ['MX (kam chodí pošta)', null, $mxT, ''];

    $spf = array_values(array_filter($txt($domena), fn($t) => stripos($t, 'v=spf1') === 0));
    if (count($spf) === 0) $vysl[] = ['SPF', false, 'Chybí – e-maily mohou padat do spamu.', 'TXT @: v=spf1 mx a' . ($seznam ? ' include:spf.seznam.cz' : ($google ? ' include:_spf.google.com' : '')) . ' ~all'];
    elseif (count($spf) > 1) $vysl[] = ['SPF', false, 'Víc SPF záznamů (' . count($spf) . ') – platí jen jeden.', 'Slučte je do jednoho záznamu.'];
    else {
        $pot = $seznam ? 'include:spf.seznam.cz' : ($google ? 'include:_spf.google.com' : '');
        $ok = $pot === '' ? null : stripos($spf[0], $pot) !== false;
        $vysl[] = ['SPF', $ok, $spf[0], $ok === false ? "Do stávajícího záznamu doplňte „$pot“ (před ~all)." : ''];
    }

    if ($seznam) {
        $chybi = [];
        foreach (['szn1', 'szn2', 'szn3'] as $k) {
            $c = @dns_get_record("$k._domainkey.$domena", DNS_CNAME) ?: [];
            // pozor na zástupný záznam *.domena → domena: platí jen CNAME, který opravdu míří na seznam.cz
            if (!array_filter($c, fn($x) => str_ends_with(rtrim($x['target'] ?? '', '.'), "$k._domainkey.seznam.cz"))) $chybi[] = $k;
        }
        $vysl[] = ['DKIM (podpis Seznamu)', !$chybi, $chybi ? 'Chybí: ' . implode(', ', array_map(fn($k) => "$k._domainkey", $chybi)) : 'szn1, szn2, szn3 → seznam.cz',
            $chybi ? 'CNAME (v Cloudflare jen DNS, šedý mráček): ' . implode('; ', array_map(fn($k) => "$k._domainkey → $k._domainkey.seznam.cz", $chybi)) : ''];
    } elseif ($google) {
        $g = array_filter($txt("google._domainkey.$domena"), fn($t) => stripos($t, 'v=DKIM1') !== false);
        $vysl[] = ['DKIM (podpis Googlu)', (bool)$g, $g ? 'google._domainkey nastaven' : 'Chybí google._domainkey', $g ? '' : 'Vygenerujte v Google Admin → Gmail → Ověření e-mailů.'];
    }

    $dm = array_values(array_filter($txt("_dmarc.$domena"), fn($t) => stripos($t, 'v=DMARC1') === 0));
    $vysl[] = ['DMARC', $dm ? true : false, $dm[0] ?? 'Chybí', $dm ? '' : 'TXT _dmarc: v=DMARC1; p=none (zatím jen sledovat, později p=quarantine).'];
    return $vysl;
}

// ---------- Stránka Správa → Pošta (/?r=nastaveni&sekce=posta) ----------

function stranka_posta(array $u): void
{
    if (!smi_upravit_tym($u)) { flash('Pošta je jen pro vedoucí a správce.', 'err'); presmeruj('/?r=nastaveni'); }
    $admin = je_admin($u);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = $_POST['akce'] ?? '';
        $ucet = isset(POSTA_UCTY[$_POST['ucet'] ?? '']) ? $_POST['ucet'] : 'zakaznici';
        if ($akce === 'ulozit' && $admin) {
            if ($ucet === 'interni') posta_uloz('interni.stejny', !empty($_POST['stejny']) ? '1' : '0');
            if ($ucet === 'zakaznici' || empty($_POST['stejny'])) {
                $chyby = [];
                foreach (POSTA_POLE as $p) {
                    if (!array_key_exists($p, $_POST)) continue;
                    $v = trim((string)$_POST[$p]);
                    if ($p === 'pass') { if ($v === '') continue; }                       // prázdné heslo = ponechat uložené
                    if (in_array($p, ['from', 'reply_to'], true) && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { $chyby[] = $p === 'from' ? 'adresa odesílatele' : 'adresa pro odpovědi'; continue; }
                    if (in_array($p, ['port', 'imap_port'], true)) $v = $v === '' ? '' : (string)max(1, min(65535, (int)$v));
                    if ($p === 'webmail' && $v !== '' && !preg_match('~^https://~', $v)) $v = '';
                    posta_uloz("$ucet.$p", $v);
                }
                if (!empty($_POST['smazat_heslo'])) posta_uloz("$ucet.pass", '');
                if ($chyby) flash('Neplatná ' . implode(' a ', $chyby) . ' – neuloženo.', 'err');
            }
            zapis_log('posta_nastaveni', $ucet);
            if (!isset($chyby) || !$chyby) flash(POSTA_UCTY[$ucet][0] . ': uloženo.');
        } elseif ($akce === 'platby_ulozit' && $admin) {
            foreach (['user', 'imap_host', 'imap_port', 'webmail', 'odesilatel'] as $p) {
                $v = trim((string)($_POST[$p] ?? ''));
                if ($p === 'imap_port') $v = $v === '' ? '' : (string)max(1, min(65535, (int)$v));
                if ($p === 'webmail' && $v !== '' && !preg_match('~^https://~', $v)) $v = '';
                if ($p === 'odesilatel') $v = implode(', ', array_filter(preg_split('~[\s,;]+~', strtolower($v)) ?: [], 'strlen'));
                posta_uloz("platby.$p", $v);
            }
            if (trim((string)($_POST['pass'] ?? '')) !== '') posta_uloz('platby.pass', trim((string)$_POST['pass']));
            if (!empty($_POST['smazat_heslo'])) posta_uloz('platby.pass', '');
            posta_uloz('platby.auto', !empty($_POST['auto']) ? '1' : '0');
            zapis_log('posta_nastaveni', 'platby');
            flash('💳 Platby: uloženo.');
        } elseif ($akce === 'platby_nacist') {
            $r = platby_nacti();
            $r['ok'] ? flash('Schránka plateb v pořádku' . ($r['nove'] ? ", nových avíz: $r[nove]." : '.')) : flash($r['chyba'], 'err');
        } elseif ($akce === 'upozorneni' && $admin) {
            $a = array_filter(preg_split('~[\s,;]+~', (string)($_POST['upozorneni'] ?? '')) ?: [], 'strlen');
            $spatne = array_filter($a, fn($x) => !filter_var($x, FILTER_VALIDATE_EMAIL));
            if ($spatne) flash('Neplatná adresa: ' . implode(', ', $spatne), 'err');
            else { posta_uloz('upozorneni', implode(', ', array_unique($a))); flash('Adresy pro upozornění uloženy.'); }
        } elseif ($akce === 'test') {
            $komu = trim((string)($_POST['komu'] ?? ''));
            $id = posli_email($komu, 'Thai Most – zkušební e-mail (' . POSTA_UCTY[$ucet][0] . ')',
                "Dobrý den,\n\ntohle je zkušební e-mail z kartotéky Thai Most, odeslaný účtem „" . POSTA_UCTY[$ucet][0] . "“.\n"
                . "Když ho čtete, odesílání funguje.\n\nOdesláno: " . date('j. n. Y H:i') . ' (' . kdo() . ")\n", null, $ucet);
            if ($id === 0) flash('Neplatná adresa pro zkušební e-mail.', 'err');
            else {
                $r = q1('SELECT stav, chyba FROM emaily WHERE id=?', [$id]);
                if ($r['stav'] === 'odeslano') flash("Zkušební e-mail odešel na $komu. Zkontrolujte doručenou poštu (případně spam).");
                elseif ($r['stav'] === 'chyba') flash('Odeslání selhalo: ' . $r['chyba'], 'err');
                else flash('Účet není nastavený – e-mail zůstal ve frontě.', 'err');
            }
        } elseif ($akce === 'schranka') {
            $r = posta_imap_obnov($ucet);
            $r['ok'] ? flash('Schránka zkontrolována.') : flash($r['chyba'], 'err');
        } elseif ($akce === 'odeslat') {
            odesli_frontu(); flash('Fronta e-mailů zpracována.');
        }
        presmeruj('/?r=nastaveni&sekce=posta');
    }
    // schránky: obnovit, je-li uložený stav starší než 10 minut (jinak rychle z paměti)
    foreach (array_keys(POSTA_UCTY) as $uc) {
        $c = posta_ucet($uc);
        if (($c['zdroj'] ?? '') === 'stejny') continue;
        $ca = posta_imap_cache($uc);
        if ($c['imap_host'] !== '' && $c['pass'] !== '' && (!$ca || strtotime($ca['cas'] ?? '2000-01-01') < time() - 600)) posta_imap_obnov($uc);
    }
    $upoz = posta_upozorneni();
    hlavicka('Pošta', 'posta'); ?>
    <p class="muted small"><a href="/?r=nastaveni">← Správa</a></p>
    <h1>✉️ Pošta</h1>
    <p class="muted" style="margin-top:0">Odkud kartotéka posílá e-maily, kam chodí upozornění, co čeká ve frontě a co je nového ve schránkách. Hesla vidí a mění jen správce.</p>

    <?php foreach (POSTA_UCTY as $uc => [$nazev, $popis]):
        $s = posta_stav($uc); $c = $s['ucet']; $stejny = ($c['zdroj'] ?? '') === 'stejny';
        $ca = posta_imap_cache($stejny ? 'zakaznici' : $uc);
        $domena = substr(strrchr($c['from'] ?: $c['user'], '@') ?: '', 1); ?>
    <section class="card posta-ucet">
      <h2><?= h($nazev) ?> <span class="posta-stav posta-<?= h($s['kod']) ?>"><?= h($s['text']) ?></span></h2>
      <p class="muted small" style="margin-top:0"><?= h($popis) ?></p>

      <div class="posta-grid">
        <div>
          <h3>Odesílání</h3>
          <?php if ($stejny): ?><p>Posílá se stejným účtem jako komunikace se zákazníky<?= $c['from'] ? ' (' . h($c['from']) . ')' : '' ?>.</p>
          <?php elseif ($c['host'] !== ''): ?>
            <p>Od: <b><?= h($c['from_name']) ?> &lt;<?= h($c['from'] ?: $c['user']) ?>&gt;</b><?= $c['reply_to'] ? '<br>Odpovědi chodí na: <b>' . h($c['reply_to']) . '</b>' : '' ?><br>
            <span class="muted small">Server <?= h($c['host']) ?>:<?= (int)$c['port'] ?><?= $c['zdroj'] === 'config' ? ' · zatím z config.php – uložením níže se převezme do aplikace' : '' ?></span></p>
          <?php else: ?><p class="warn">Zatím nenastaveno – e-maily se ukládají do fronty a odejdou, až účet vyplníte.</p><?php endif ?>
          <p class="small">Za 30 dní: odesláno <b><?= $s['odeslano'] ?></b> · chyby <b class="<?= $s['chyby'] ? 'warn' : '' ?>"><?= $s['chyby'] ?></b> · čeká <b class="<?= $s['ceka'] ? 'warn' : '' ?>"><?= $s['ceka'] ?></b><?= $s['chyby'] ? ' <span class="muted">(chybné se zkusí znovu tlačítkem „Odeslat čekající znovu“ dole)</span>' : '' ?>
            <?php if ($s['posledni_ok']): ?><br>Naposledy odesláno: <?= h(datum_cz($s['posledni_ok']['odeslano'])) ?> → <?= h($s['posledni_ok']['komu']) ?><?php endif ?>
            <?php if ($s['posledni_chyba']): ?><br><span class="warn">Poslední chyba (<?= h(datum_cz($s['posledni_chyba']['vytvoreno'])) ?>): <?= h($s['posledni_chyba']['chyba']) ?></span><?php endif ?></p>
          <p><a class="btn" href="/?r=emaily&amp;ucet=<?= h($uc) ?>">Fronta tohoto účtu</a></p>
          <form method="post" class="inline posta-test"><?= csrf_pole() ?><input type="hidden" name="akce" value="test"><input type="hidden" name="ucet" value="<?= h($uc) ?>">
            <input type="email" name="komu" required placeholder="kam poslat zkušební e-mail" value="<?= h($upoz[0] ?? '') ?>" inputmode="email" autocapitalize="off">
            <button class="btn">Poslat zkušební e-mail</button></form>
        </div>
        <div>
          <h3>Schránka</h3>
          <?php if ($stejny): ?><p class="muted small">Stejná schránka jako u komunikace se zákazníky (výše).</p>
          <?php elseif (!$ca): ?><p class="muted small"><?= $c['imap_host'] !== '' ? 'Zatím nezkontrolováno.' : 'Pro hlídání nové pošty vyplňte IMAP server (u Seznamu imap.seznam.cz).' ?></p>
          <?php elseif (!$ca['ok']): ?><p class="warn small">❗ <?= h($ca['chyba'] ?? 'Chyba') ?> <span class="muted">(<?= h(datum_cz($ca['cas'] ?? null)) ?>)</span></p>
          <?php else: ?>
            <p>📥 <b><?= (int)$ca['nove'] ?></b> nepřečtených z <?= (int)$ca['celkem'] ?> v doručené poště <span class="muted small">(zkontrolováno <?= h(datum_cz($ca['cas'])) ?>)</span></p>
            <?php if ($ca['zpravy']): ?><ul class="posta-zpravy">
              <?php foreach ($ca['zpravy'] as $z): ?><li><b><?= h($z['subject'] ?: '(bez předmětu)') ?></b><br><span class="muted small"><?= h($z['from']) ?> · <?= h($z['date'] ? datum_cz($z['date']) : '') ?></span></li><?php endforeach ?>
            </ul><?php endif ?>
          <?php endif ?>
          <?php if (!$stejny): ?>
          <div class="posta-akce"><?php if ($c['imap_host'] !== ''): ?><form method="post" class="inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="schranka"><input type="hidden" name="ucet" value="<?= h($uc) ?>"><button class="btn">Zkontrolovat teď</button></form><?php endif ?>
             <?php if ($c['webmail']): ?><a class="btn" href="<?= h($c['webmail']) ?>" target="_blank" rel="noopener">Otevřít poštu ↗</a><?php endif ?></div>
          <p class="muted small">Kartotéka poštu jen čte – zprávy zůstanou nepřečtené, nic nemaže ani neodesílá.</p>
          <?php endif ?>
        </div>
      </div>

      <?php if ($domena !== '' && !$stejny && ($dns = posta_dns($domena, $c['host']))): ?>
      <details class="rozbal"<?= array_filter($dns, fn($x) => $x[1] === false) ? ' open' : '' ?>><summary><h3>DNS domény <?= h($domena) ?> <?= array_filter($dns, fn($x) => $x[1] === false) ? '<span class="warn">– něco chybí</span>' : (array_filter($dns, fn($x) => $x[1] === true) ? '<span class="muted small">– v pořádku</span>' : '<span class="muted small">– jen informace</span>') ?></h3></summary>
        <table class="tbl plain small">
          <?php foreach ($dns as [$co, $ok, $txt, $rada]): ?>
            <tr><td><?= $ok === true ? '✅' : ($ok === false ? '❌' : 'ℹ️') ?> <?= h($co) ?></td><td><code><?= h($txt) ?></code><?= $rada ? '<br><span class="warn">' . h($rada) . '</span>' : '' ?></td></tr>
          <?php endforeach ?>
        </table>
        <p class="muted small">Kontroluje se při každém otevření stránky. Změny v DNS se projeví do pár minut (Cloudflare).</p>
      </details>
      <?php endif ?>

      <?php if ($admin): ?>
      <details class="rozbal"<?= $c['host'] === '' && !$stejny ? ' open' : '' ?>><summary><h3>Nastavit účet</h3></summary>
        <form method="post" class="posta-form" data-presety='<?= h(json_encode(POSTA_PRESETY, JSON_UNESCAPED_UNICODE)) ?>'>
          <?= csrf_pole() ?><input type="hidden" name="akce" value="ulozit"><input type="hidden" name="ucet" value="<?= h($uc) ?>">
          <?php if ($uc === 'interni'): ?>
            <label class="chk"><input type="checkbox" name="stejny" value="1" <?= $stejny ? 'checked' : '' ?>> Posílat stejným účtem jako komunikace se zákazníky</label>
          <?php endif ?>
          <fieldset class="posta-pole"<?= $stejny ? ' hidden' : '' ?>>
            <label>Poskytovatel (vyplní servery)<select class="posta-preset"><option value="">— vlastní / ponechat —</option><?php foreach (POSTA_PRESETY as $k => $p): ?><option value="<?= h($k) ?>"><?= h($p[0]) ?></option><?php endforeach ?></select></label>
            <div class="grid3">
              <label>E-mailová adresa (přihlášení)<input type="email" name="user" value="<?= h($stejny ? '' : $c['user']) ?>" placeholder="salon@thaimost.cz" autocomplete="off" inputmode="email" autocapitalize="off"></label>
              <label>Heslo<input type="password" name="pass" value="" placeholder="<?= !$stejny && $c['pass'] !== '' ? '•••••••• uloženo – prázdné = beze změny' : 'heslo ke schránce' ?>" autocomplete="new-password"></label>
              <label>Jméno odesílatele<input name="from_name" value="<?= h($stejny ? 'Thai Most' : $c['from_name']) ?>"></label>
            </div>
            <div class="grid3">
              <label>Adresa odesílatele <span class="muted small">(prázdné = přihlašovací)</span><input type="email" name="from" value="<?= h($stejny ? '' : $c['from']) ?>" inputmode="email" autocapitalize="off"></label>
              <label>Odpovědi chodí na <span class="muted small">(nepovinné)</span><input type="email" name="reply_to" value="<?= h($stejny ? '' : $c['reply_to']) ?>" inputmode="email" autocapitalize="off"></label>
              <label>Webová pošta <span class="muted small">(odkaz)</span><input type="url" name="webmail" value="<?= h($stejny ? '' : $c['webmail']) ?>" placeholder="https://email.seznam.cz"></label>
            </div>
            <div class="grid3">
              <label>SMTP server (odesílání)<input name="host" value="<?= h($stejny ? '' : $c['host']) ?>" placeholder="smtp.seznam.cz" autocapitalize="off"></label>
              <label>SMTP port <span class="muted small">(465 SSL / 587 STARTTLS)</span><input type="number" name="port" value="<?= h($stejny ? '465' : (string)$c['port']) ?>"></label>
              <span></span>
              <label>IMAP server (hlídání schránky)<input name="imap_host" value="<?= h($stejny ? '' : $c['imap_host']) ?>" placeholder="imap.seznam.cz" autocapitalize="off"></label>
              <label>IMAP port <span class="muted small">(SSL)</span><input type="number" name="imap_port" value="<?= h($stejny ? '993' : (string)$c['imap_port']) ?>"></label>
            </div>
            <?php if (!$stejny && $c['pass'] !== '' && $c['zdroj'] === 'aplikace'): ?><label class="chk"><input type="checkbox" name="smazat_heslo" value="1"> Smazat uložené heslo</label><?php endif ?>
          </fieldset>
          <button class="btn primary">Uložit</button>
          <span class="muted small">Po uložení pošlete zkušební e-mail. U Gmailu je potřeba „heslo aplikace“, ne běžné heslo.</span>
        </form>
      </details>
      <?php endif ?>
    </section>
    <?php endforeach ?>

    <?php $pc = platby_ucet(); $pst = json_decode(posta_n('platby.stav'), true) ?: []; ?>
    <section class="card posta-ucet" id="platby">
      <h2>💳 Platby – avíza z banky <span class="posta-stav posta-<?= !platby_nastaveno() ? 'nenastaveno' : (empty($pst) ? 'nevyzkouseno' : (!empty($pst['ok']) ? 'ok' : 'chyba')) ?>"><?= !platby_nastaveno() ? '⚪ Nenastaveno' : (empty($pst) ? '🟡 Zatím nenačteno' : (!empty($pst['ok']) ? '🟢 Schránka se čte' : '🔴 Schránku se nepodařilo přečíst')) ?></span></h2>
      <p class="muted small" style="margin-top:0">Samostatná adresa (např. platby@thaimost.cz), kterou zadáte v internetovém bankovnictví pro zasílání avíz o příchozích platbách. Kartotéka z avíz vezme částku a VS a navrhne, ke kterému objednanému poukazu platba patří. Výsledek: <a href="/?r=nastaveni&amp;sekce=platby">Správa → Platby</a>.</p>
      <?php if (platby_nastaveno()): ?>
        <p class="small">Schránka <b><?= h($pc['user']) ?></b><?= $pc['odesilatel'] ? ' · bere jen avíza od <b>' . h($pc['odesilatel']) . '</b>' : ' · <span class="warn">adresa banky není vyplněná</span>' ?> · automatické potvrzení: <b><?= $pc['auto'] === '1' ? 'zapnuto' : 'vypnuto' ?></b>
          <?php if ($pst): ?><br><span class="muted">Naposledy <?= h(datum_cz($pst['cas'] ?? null)) ?><?= empty($pst['ok']) ? '</span> <span class="warn">❗ ' . h($pst['chyba'] ?? '') : '' ?></span><?php endif ?>
          · ke spárování: <a href="/?r=nastaveni&amp;sekce=platby"><b><?= platby_ke_sparovani() ?></b></a></p>
        <div class="posta-akce"><form method="post" class="inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="platby_nacist"><button class="btn">Zkontrolovat schránku teď</button></form>
          <?php if ($pc['webmail']): ?><a class="btn" href="<?= h($pc['webmail']) ?>" target="_blank" rel="noopener">Otevřít poštu ↗</a><?php endif ?></div>
      <?php endif ?>
      <?php if ($admin): ?>
      <details class="rozbal"<?= platby_nastaveno() ? '' : ' open' ?>><summary><h3>Nastavit schránku pro platby</h3></summary>
        <form method="post" class="posta-form" data-presety='<?= h(json_encode(POSTA_PRESETY, JSON_UNESCAPED_UNICODE)) ?>'>
          <?= csrf_pole() ?><input type="hidden" name="akce" value="platby_ulozit">
          <label>Poskytovatel (vyplní server)<select class="posta-preset"><option value="">— vlastní / ponechat —</option><?php foreach (POSTA_PRESETY as $k => $p): ?><option value="<?= h($k) ?>"><?= h($p[0]) ?></option><?php endforeach ?></select></label>
          <div class="grid3">
            <label>E-mailová adresa (přihlášení)<input type="email" name="user" value="<?= h($pc['user']) ?>" placeholder="platby@thaimost.cz" autocomplete="off" inputmode="email" autocapitalize="off"></label>
            <label>Heslo<input type="password" name="pass" value="" placeholder="<?= $pc['pass'] !== '' ? '•••••••• uloženo – prázdné = beze změny' : 'heslo ke schránce' ?>" autocomplete="new-password"></label>
            <label>Webová pošta <span class="muted small">(odkaz)</span><input type="url" name="webmail" value="<?= h($pc['webmail']) ?>" placeholder="https://email.seznam.cz"></label>
            <label>IMAP server<input name="imap_host" value="<?= h($pc['imap_host']) ?>" placeholder="imap.seznam.cz" autocapitalize="off"></label>
            <label>IMAP port <span class="muted small">(SSL)</span><input type="number" name="imap_port" value="<?= h((string)$pc['imap_port']) ?>"></label>
          </div>
          <label>Avíza chodí od <span class="muted small">(adresa, ze které banka avíza posílá – najdete ji v prvním avízu; víc adres čárkou, celá doména jako „@banka.cz“)</span><input name="odesilatel" value="<?= h($pc['odesilatel']) ?>" autocapitalize="off"></label>
          <label class="chk"><input type="checkbox" name="auto" value="1" <?= $pc['auto'] === '1' ? 'checked' : '' ?>> Automaticky označit poukaz jako zaplacený, když avízo přišlo z adresy banky výše a sedí VS i částka (jinak se jen navrhne a potvrdí ručně)</label>
          <?php if ($pc['pass'] !== ''): ?><label class="chk"><input type="checkbox" name="smazat_heslo" value="1"> Smazat uložené heslo</label><?php endif ?>
          <button class="btn primary">Uložit</button>
        </form>
      </details>
      <?php endif ?>
    </section>

    <section class="card">
      <h2>🔔 Kam chodí upozornění pro salon</h2>
      <p class="muted small" style="margin-top:0">Nové žádosti o termín z webu, objednávky poukazů a předčasně ukončené masáže (ty navíc dostanou i hlavní pracovníci s e-mailem). Posílá se účtem „Ostatní komunikace“.</p>
      <?php if ($admin): ?>
      <form method="post"><?= csrf_pole() ?><input type="hidden" name="akce" value="upozorneni">
        <label>Adresy (víc oddělte čárkou)<input name="upozorneni" value="<?= h(implode(', ', $upoz)) ?>" placeholder="salon@thaimost.cz" autocapitalize="off"></label>
        <button class="btn primary">Uložit</button></form>
      <?php else: ?><p><b><?= h(implode(', ', $upoz) ?: '— nenastaveno —') ?></b></p><?php endif ?>
    </section>

    <section class="card">
      <h2>📤 Fronta</h2>
      <p>E-maily, které neodešly (účet nenastaven nebo chyba), čekají ve frontě a po opravě se dají odeslat znovu.</p>
      <form method="post" class="inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="odeslat"><button class="btn">Odeslat čekající znovu</button></form>
      <a class="btn" href="/?r=emaily">Celá fronta</a>
    </section>
    <script>
    document.querySelectorAll('.posta-form').forEach(function (f) {
      var pr = JSON.parse(f.dataset.presety), sel = f.querySelector('.posta-preset'), st = f.querySelector('[name=stejny]'), pole = f.querySelector('.posta-pole');
      sel.addEventListener('change', function () {
        var p = pr[sel.value]; if (!p) return;
        if (f.host) { f.host.value = p[1]; f.port.value = p[2]; }
        f.imap_host.value = p[3]; f.imap_port.value = p[4]; if (!f.webmail.value) f.webmail.value = p[5];
      });
      if (st) st.addEventListener('change', function () { pole.hidden = st.checked; });
    });
    </script>
    <?php paticka();
}
