<?php
require_once __DIR__ . '/poukaz_pdf.php';
require_once __DIR__ . '/sluzby_web.php';   // texty a ceny služeb pro web   // motivy poukazu (dárkový / vánoční)
require_once __DIR__ . '/push.php';         // Web Push notifikace do nainstalované aplikace Asistent
// Veřejné API pro web (GitHub Pages). Jen to, co web potřebuje – nic z kartotéky nevrací.

function api_odpoved(array $data, int $kod = 200): void
{
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, cfg()['povolene_origin'] ?? [], true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

function api_vstup(): array
{
    $raw = file_get_contents('php://input');
    $d = json_decode($raw ?: '[]', true);
    if (!is_array($d)) $d = $_POST;
    // jednoduché ořezání všech textů
    array_walk($d, function (&$v) { if (is_string($v)) $v = trim(mb_substr($v, 0, 1000)); });
    return $d;
}

function api_route(string $cesta): void
{
    api_cors();
    $m = $_SERVER['REQUEST_METHOD'];

    if ($cesta === '/api/stav' && $m === 'GET') {
        api_odpoved(['ok' => true, 'rezim' => nastaveni('rezim_webu', 'zajem'), 'tematicka' => (string)nastaveni('tematicka_web', '1') === '1' && tematicka_maserna_je(),
            'platby_min' => platby_nastaveno() ? platby_interval() : 0,    // web: za jak dlouho se platba spáruje (0 = ručně)
            'ucet' => salon_ucet() !== '' && ucet_iban(salon_ucet()) !== '' ? salon_ucet() : '', 'iban' => ucet_iban(salon_ucet()),   // platba poukazu převodem (Nastavení)
            'platnost_mes' => poukaz_platnost_mes(),
            // motivy poukazu pro web: obrázky podkladů (vánoční jen když je nahraný) a co je teď přednastavené
            'motivy' => array_filter(['darkovy' => poukaz_motiv_jpg('darkovy') ? rtrim(cfg()['url'], '/') . poukaz_motiv_url('darkovy') : '',
                                      'vanocni' => poukaz_motiv_jpg('vanocni') ? rtrim(cfg()['url'], '/') . poukaz_motiv_url('vanocni') : ''], fn($x) => true),
            'motiv' => poukaz_motiv_vychozi(),
            'fotky' => (object)sluzby_fotky_web(),
            'sluzby' => (object)sluzby_web()]);   // texty (cs + en/de) a ceny služeb z Nastavení → Ceník   // vlastní fotky služeb (Nastavení → Ceník) místo ilustračních
    }

    if ($cesta === '/api/sluzby' && $m === 'GET') {
        $rows = q("SELECT v.id, v.popis, v.minuty, v.cena, s.nazev, s.kat, s.preklady FROM varianty v JOIN sluzby s ON s.id=v.sluzba_id
            WHERE v.aktivni=1 AND s.aktivni=1 ORDER BY s.poradi, v.poradi")->fetchAll();
        foreach ($rows as &$r) { $pr = sluzba_preklady($r); $r['nazev_t'] = ['en' => $pr['en']['nazev'], 'de' => $pr['de']['nazev']]; unset($r['preklady']); }
        unset($r);
        api_odpoved(['ok' => true, 'varianty' => $rows]);
    }

    if ($cesta === '/api/zajem' && $m === 'POST') api_zajem();
    if ($cesta === '/api/poukaz' && $m === 'POST') api_poukaz();
    if ($cesta === '/api/moje' && $m === 'POST') api_moje();

    // Push notifikace do nainstalované aplikace (same-origin, pro přihlášené pracovníky)
    if ($cesta === '/api/push_klic' && $m === 'GET') api_odpoved(['ok' => true, 'klic' => vapid_klice()['pub']]);
    if ($cesta === '/api/push_subscribe' && $m === 'POST') api_push_subscribe();
    if ($cesta === '/api/push_unsubscribe' && $m === 'POST') api_push_unsubscribe();
    if ($cesta === '/api/push_info' && $m === 'GET') api_push_info();
    if ($cesta === '/api/push_test' && $m === 'POST') api_push_test();

    api_odpoved(['ok' => false, 'chyba' => 'Neznámá akce.'], 404);
}

/** Žádost o termín z webu (režim „zájem“). Recepce ji v kartotéce naplánuje a potvrdí. */
function api_zajem(): void
{
    $d = api_vstup();
    if (!empty($d['web'])) api_odpoved(['ok' => true]); // honeypot – roboti
    if (!limit_ip('zajem', 10)) api_odpoved(['ok' => false, 'chyba' => 'Příliš mnoho pokusů, zkuste to prosím později nebo zavolejte.'], 429);

    $tel = telefon_norm($d['telefon'] ?? '');
    $jmeno = $d['jmeno'] ?? ''; $prijmeni = $d['prijmeni'] ?? '';
    $email = filter_var($d['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $d['email'] : '';
    $var = (int)($d['varianta_id'] ?? 0);
    if ($tel === '' || $jmeno === '' || $prijmeni === '') api_odpoved(['ok' => false, 'chyba' => 'Vyplňte jméno, příjmení a telefon.'], 422);
    if (empty($d['gdpr'])) api_odpoved(['ok' => false, 'chyba' => 'Potřebujeme souhlas se zpracováním údajů.'], 422);
    $v = $var ? q1('SELECT v.*, s.nazev FROM varianty v JOIN sluzby s ON s.id=v.sluzba_id WHERE v.id=? AND v.aktivni=1', [$var]) : null;
    if (!$v) api_odpoved(['ok' => false, 'chyba' => 'Vyberte prosím masáž.'], 422);

    $den = preg_match('~^\d{4}-\d{2}-\d{2}$~', $d['den'] ?? '') ? $d['den'] : '';
    $okno = in_array($d['okno'] ?? '', ['dopoledne', 'odpoledne', 'vecer', 'kdykoli'], true) ? $d['okno'] : 'kdykoli';
    $oknoTxt = ['dopoledne' => 'dopoledne', 'odpoledne' => 'odpoledne', 'vecer' => 'večer', 'kdykoli' => 'kdykoli'][$okno];
    $pref = trim(($den ? datum_cz($den, false) . ', ' : '') . $oknoTxt);
    // atmosféra tematické masérny (prales, pláž, vodopád, chrám) – jen známý klíč, u wellness se ignoruje
    $atm = isset(ATMOSFERY[(string)($d['atmosfera'] ?? '')]) && (int)$v['wellness_mistnost'] !== 1 && (string)nastaveni('tematicka_web', '1') === '1' ? (string)$d['atmosfera'] : '';
    $atmTxt = $atm !== '' ? ATMOSFERY[$atm]['nazev'] : '';

    $zid = zakaznik_najdi_nebo_zaloz($tel, $jmeno, $prijmeni, $email, 'web');
    q("UPDATE zakaznici SET gdpr_souhlas=COALESCE(gdpr_souhlas, ?) WHERE id=?", [nyni(), $zid]);
    q("INSERT INTO rezervace(zakaznik_id,varianta_id,stav,zdroj,preferovano,poznamka_zakaznik,cena,atmosfera)
       VALUES(?,?,'zadost','web',?,?,?,?)", [$zid, $var, $pref, $d['poznamka'] ?? '', $v['cena'], $atm]);
    $rid = (int)db()->lastInsertId();

    $kdo = "$jmeno $prijmeni, " . telefon_cz($tel) . ($email ? ", $email" : '');
    $txtSalon = "Nová žádost z webu:\n\n$kdo\n$v[nazev] – $v[popis]\nKdy by se hodilo: $pref\n"
        . ($atmTxt !== '' ? "Atmosféra (tematická masérna): $atmTxt\n" : '')
        . (($d['poznamka'] ?? '') !== '' ? "Poznámka: $d[poznamka]\n" : '')
        . "\nVyřídit: " . rtrim(cfg()['url'], '/') . "/?r=rezervace&id=$rid\n";
    foreach (posta_upozorneni() as $adr) posli_email($adr, "Nová žádost o termín – $jmeno $prijmeni", $txtSalon, null, 'interni');
    if ($email) posli_email($email, 'Thai Most – přijali jsme vaši žádost o termín',
        "Dobrý den,\n\nděkujeme za zájem o $v[nazev] ($v[popis]). Kdy by se vám hodilo: $pref.\n"
        . ($atmTxt !== '' ? "Atmosféra v tematické masérně: $atmTxt.\n" : '') . "\n"
        . "Termín je potvrzený až ve chvíli, kdy se vám ozveme. Na recepci vám zavoláme nebo napíšeme co nejdřív.\n\n"
        . "Thajské masáže Most\n+420 778 429 329 · salon@thaimost.cz\nwww.thaimost.cz\n");

    try { push_vsem(); } catch (Throwable $e) { /* push nikdy neshodí odpověď webu */ }
    api_odpoved(['ok' => true]);
}

/** Posílá push jen na same-origin požadavky (ochrana proti CSRF). */
function push_stejny_origin(): bool
{
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($o === '') return true;   // same-origin POST nemusí Origin posílat
    return parse_url($o, PHP_URL_HOST) === parse_url(rtrim(cfg()['url'], '/'), PHP_URL_HOST);
}

/** Přihlášení zařízení pracovníka k push notifikacím. */
function api_push_subscribe(): void
{
    if (!push_stejny_origin()) api_odpoved(['ok' => false], 403);
    $u = uzivatel();
    if (!$u) api_odpoved(['ok' => false, 'chyba' => 'Nepřihlášeno'], 401);
    $sub = api_vstup();
    $endpoint = (string)($sub['endpoint'] ?? '');
    $keys = is_array($sub['keys'] ?? null) ? $sub['keys'] : [];
    if (!preg_match('~^https://~', $endpoint)) api_odpoved(['ok' => false, 'chyba' => 'Neplatný endpoint'], 422);
    q("INSERT INTO push_odbery(uzivatel_id,endpoint,p256dh,auth,zarizeni) VALUES(?,?,?,?,?)
       ON CONFLICT(endpoint) DO UPDATE SET uzivatel_id=excluded.uzivatel_id, p256dh=excluded.p256dh, auth=excluded.auth, zarizeni=excluded.zarizeni, posledni_chyba=''",
        [$u['id'], $endpoint, (string)($keys['p256dh'] ?? ''), (string)($keys['auth'] ?? ''), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
    api_odpoved(['ok' => true]);
}

/** Odhlášení zařízení (po vypnutí v aplikaci nebo expiraci). */
function api_push_unsubscribe(): void
{
    if (!push_stejny_origin()) api_odpoved(['ok' => false], 403);
    $endpoint = (string)(api_vstup()['endpoint'] ?? '');
    if ($endpoint !== '') q('DELETE FROM push_odbery WHERE endpoint=?', [$endpoint]);
    api_odpoved(['ok' => true]);
}

/** Obsah notifikace – service worker si ho vyzvedne při příchodu push (contentless). */
function api_push_info(): void
{
    $u = uzivatel();
    $vychozi = ['ok' => true, 'title' => 'Thai Most', 'body' => 'Nová poptávka – otevřete kartotéku', 'url' => '/?r=dnes', 'tag' => 'tm-poptavka'];
    if (!$u) api_odpoved($vychozi);
    $n = (int)qv("SELECT COUNT(*) FROM rezervace WHERE stav='zadost' AND zdroj='web'");
    if ($n <= 0) api_odpoved(['ok' => true, 'title' => 'Thai Most', 'body' => 'Otevřete kartotéku', 'url' => '/?r=dnes', 'tag' => 'tm-poptavka']);
    $p = q1("SELECT z.jmeno, z.prijmeni, s.nazev FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id
             LEFT JOIN varianty v ON v.id=r.varianta_id LEFT JOIN sluzby s ON s.id=v.sluzba_id
             WHERE r.stav='zadost' AND r.zdroj='web' ORDER BY r.id DESC LIMIT 1");
    $body = $n === 1
        ? trim(($p['jmeno'] ?? '') . ' ' . ($p['prijmeni'] ?? '')) . (($p['nazev'] ?? '') !== '' ? ' – ' . $p['nazev'] : '')
        : "$n nových poptávek z webu čeká";
    api_odpoved(['ok' => true, 'title' => 'Nová poptávka z webu', 'body' => $body ?: 'Otevřete kartotéku', 'url' => '/?r=dnes', 'tag' => 'tm-poptavka']);
}

/** Zkušební notifikace na zařízení přihlášeného pracovníka. */
function api_push_test(): void
{
    if (!push_stejny_origin()) api_odpoved(['ok' => false], 403);
    $u = uzivatel();
    if (!$u) api_odpoved(['ok' => false], 401);
    $n = 0;
    foreach (q('SELECT * FROM push_odbery WHERE uzivatel_id=?', [$u['id']])->fetchAll() as $s) { try { if (push_posli($s) < 300) $n++; } catch (Throwable $e) {} }
    api_odpoved(['ok' => true, 'poslano' => $n]);
}

/** Objednávka dárkového poukazu z webu. */
function api_poukaz(): void
{
    $d = api_vstup();
    if (!empty($d['web'])) api_odpoved(['ok' => true]);
    if (!limit_ip('poukaz', 10)) api_odpoved(['ok' => false, 'chyba' => 'Příliš mnoho pokusů, zkuste to prosím později.'], 429);

    $tel = telefon_norm($d['telefon'] ?? '');
    $jmeno = $d['jmeno'] ?? '';
    $email = filter_var($d['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $d['email'] : '';
    $hodnota = (int)($d['hodnota'] ?? 0);
    if ($tel === '' || $jmeno === '') api_odpoved(['ok' => false, 'chyba' => 'Vyplňte jméno a telefon.'], 422);
    if ($hodnota < 100 || $hodnota > 50000) api_odpoved(['ok' => false, 'chyba' => 'Zkontrolujte hodnotu poukazu.'], 422);
    if (empty($d['gdpr'])) api_odpoved(['ok' => false, 'chyba' => 'Potřebujeme souhlas se zpracováním údajů.'], 422);

    $kod = poukaz_kod();
    $platnost = poukaz_platnost_od();   // platnost od nákupu (Nastavení, výchozí 6 měsíců) – do té doby se musí zákazník objednat
    q("INSERT INTO poukazy(kod,hodnota,zustatek,sluzba_text,platnost_do,stav,objednal_jmeno,objednal_tel,objednal_email,pro_koho,od_koho,
        venovani,prevzeti,platba,poznamka,zdroj) VALUES(?,?,?,?,?,'objednano',?,?,?,?,?,?,?,?,?,'web')",
        [$kod, $hodnota, $hodnota, $d['sluzba'] ?? '', $platnost, $jmeno, $tel, $email, $d['komu'] ?? '', $d['od'] ?? '',
         $d['venovani'] ?? '', $d['prevzeti'] ?? '', $d['platba'] ?? '', 'Objednávka z webu. ' . ($d['poznamka'] ?? '')]);
    $pid = (int)db()->lastInsertId();
    $cislo = poukaz_vs_recepce($pid);   // číslo poukazu = variabilní symbol platby
    $motiv = poukaz_motiv_dostupny((string)($d['motiv'] ?? '')) ? (string)$d['motiv'] : poukaz_motiv_vychozi();
    q('UPDATE poukazy SET motiv=? WHERE id=?', [$motiv, $pid]);
    q('UPDATE poukazy SET vs=?, poznamka=? WHERE id=?', [$cislo, trim("Objednávka z webu č. $cislo. " . ($d['poznamka'] ?? '')), $pid]);

    $popis = ($d['sluzba'] ?? '') !== '' ? $d['sluzba'] : kc($hodnota);
    $txtPoukaz = ["Objednávka poukazu – $jmeno ($popis)",
        "Nová objednávka poukazu z webu č. $cislo (VS $cislo), motiv: " . POUKAZ_MOTIVY[$motiv] . ":\n\n"
        . "Poukaz: $popis, hodnota " . kc($hodnota) . "\nObjednal: $jmeno, " . telefon_cz($tel) . ($email ? ", $email" : '') . "\n"
        . "Převzetí: " . ($d['prevzeti'] ?? '') . "\nPlatba: " . ($d['platba'] ?? '') . "\n"
        . (($d['poznamka'] ?? '') !== '' ? "Poznámka: $d[poznamka]\n" : '')
        . "\nKód v kartotéce: $kod\n" . rtrim(cfg()['url'], '/') . "/?r=poukaz&id=$pid\n"];
    foreach (posta_upozorneni() as $adr) posli_email($adr, $txtPoukaz[0], $txtPoukaz[1], null, 'interni');
    try { poukaz_email_objednavka($pid); } catch (Throwable $e) { error_log('potvrzení objednávky poukazu: ' . $e->getMessage()); }   // zákazníkovi: jak zaplatit / kdy poukaz dostane

    // Kód poukazu zákazníkovi nevracíme – platí až po zaplacení a vydání v salonu. Číslo poukazu (= VS) a platnost ano.
    $ucet = salon_ucet(); $iban = ucet_iban($ucet);
    api_odpoved(['ok' => true, 'cislo' => $cislo, 'vs' => $cislo, 'castka' => $hodnota, 'platnost_do' => $platnost,
        'platnost_txt' => date('j. n. Y', strtotime($platnost)), 'ucet' => $iban !== '' ? $ucet : '', 'iban' => $iban]);
}


/** Počítadlo pokusů podle libovolného klíče (hodinové okno) – ochrana proti hádání. */
function api_limit_klic(string $klic, int $max): bool
{
    $okno = date('Y-m-d H');
    $k = 'k:' . hash('sha256', $klic . $okno . __FILE__);
    $r = q1('SELECT pocet FROM limit_ip WHERE klic=?', [$k]);
    if ($r && $r['pocet'] >= $max) return false;
    if ($r) q('UPDATE limit_ip SET pocet=pocet+1 WHERE klic=?', [$k]);
    else q('INSERT INTO limit_ip(klic,pocet,okno) VALUES(?,1,?)', [$k, $okno]);
    return true;
}

function api_bez_diakritiky(string $s): string
{
    return strtr(mb_strtolower($s, 'UTF-8'), ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r',
        'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z']);
}

/**
 * Zákazník si na webu ověří razítka a nejbližší termín. Bez jména a kontaktů ve výsledku.
 * Ověření: kód věrnostní karty NEBO telefon + první 3 písmena příjmení.
 */
function api_moje(): void
{
    $d = api_vstup();
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $nic = ['ok' => false, 'chyba' => 'Údaje nesedí. Zkontrolujte kartu, telefon a první tři písmena příjmení, nebo nám zavolejte.'];
    if (!api_limit_klic('moje-ip:' . $ip, 30)) api_odpoved(['ok' => false, 'chyba' => 'Příliš mnoho pokusů, zkuste to prosím za hodinu.'], 429);
    usleep(random_int(150000, 350000));

    $z = null;
    $kodVstup = strtoupper(trim((string)($d['karta'] ?? '')));
    if (preg_match('~[?&]k=([A-Za-z0-9]{10})~', $kodVstup . '', $mm)) $kodVstup = strtoupper($mm[1]);
    if ($kodVstup !== '') {
        if (!api_limit_klic('moje-karta:' . $ip, 12)) api_odpoved(['ok' => false, 'chyba' => 'Příliš mnoho pokusů, zkuste to prosím za hodinu.'], 429);
        if (preg_match('~^[A-HJ-NP-Z2-9]{10}$~', $kodVstup))
            $z = q1('SELECT z.* FROM karty k JOIN zakaznici z ON z.id=k.zakaznik_id WHERE k.kod=? AND k.aktivni=1', [$kodVstup]);
    } else {
        $tel = telefon_norm((string)($d['telefon'] ?? ''));
        if ($tel === '' && preg_match('~^420\d{9}$~', preg_replace('~\D~', '', (string)($d['telefon'] ?? '')))) $tel = '+' . preg_replace('~\D~', '', (string)$d['telefon']);
        $pis = api_bez_diakritiky(preg_replace('~[^\p{L}]~u', '', (string)($d['prijmeni'] ?? '')));
        if ($tel === '' || mb_strlen($pis) < 3) api_odpoved($nic, 200);
        // omezení hádání na jedno číslo: nejvýš 6 pokusů za hodinu bez ohledu na IP
        if (!api_limit_klic('moje-tel:' . $tel, 6)) api_odpoved(['ok' => false, 'chyba' => 'Příliš mnoho pokusů pro toto číslo, zkuste to prosím za hodinu nebo nám zavolejte.'], 429);
        $c = q1('SELECT * FROM zakaznici WHERE telefon=?', [$tel]);
        if ($c) {
            $ulozene = api_bez_diakritiky(preg_replace('~[^\p{L}]~u', '', $c['prijmeni']));
            if ($ulozene !== '' && mb_substr($ulozene, 0, 3) === mb_substr($pis, 0, 3)) $z = $c;
        }
    }
    if (!$z || !empty($z['blokovany'])) api_odpoved($nic, 200);

    $b = zakaznik_bonus($z);
    $t = q1("SELECT zacatek FROM rezervace WHERE zakaznik_id=? AND stav='potvrzeno' AND zacatek >= ? ORDER BY zacatek LIMIT 1", [$z['id'], date('Y-m-d H:i:s')]);
    api_odpoved(['ok' => true, 'razitka' => $b['razitka'], 'po' => $b['po'], 'zdarma' => $b['zdarma'], 'termin' => $t ? $t['zacatek'] : null]);
}
