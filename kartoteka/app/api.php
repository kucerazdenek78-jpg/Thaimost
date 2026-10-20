<?php
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
        api_odpoved(['ok' => true, 'rezim' => nastaveni('rezim_webu', 'zajem')]);
    }

    if ($cesta === '/api/sluzby' && $m === 'GET') {
        $rows = q("SELECT v.id, v.popis, v.minuty, v.cena, s.nazev, s.kat FROM varianty v JOIN sluzby s ON s.id=v.sluzba_id
            WHERE v.aktivni=1 AND s.aktivni=1 ORDER BY s.poradi, v.poradi")->fetchAll();
        api_odpoved(['ok' => true, 'varianty' => $rows]);
    }

    if ($cesta === '/api/zajem' && $m === 'POST') api_zajem();
    if ($cesta === '/api/poukaz' && $m === 'POST') api_poukaz();

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

    $zid = zakaznik_najdi_nebo_zaloz($tel, $jmeno, $prijmeni, $email, 'web');
    q("UPDATE zakaznici SET gdpr_souhlas=COALESCE(gdpr_souhlas, ?) WHERE id=?", [nyni(), $zid]);
    q("INSERT INTO rezervace(zakaznik_id,varianta_id,stav,zdroj,preferovano,poznamka_zakaznik,cena)
       VALUES(?,?,'zadost','web',?,?,?)", [$zid, $var, $pref, $d['poznamka'] ?? '', $v['cena']]);
    $rid = (int)db()->lastInsertId();

    $kdo = "$jmeno $prijmeni, " . telefon_cz($tel) . ($email ? ", $email" : '');
    posli_email(cfg()['email_salon'], "Nová žádost o termín – $jmeno $prijmeni",
        "Nová žádost z webu:\n\n$kdo\n$v[nazev] – $v[popis]\nKdy by se hodilo: $pref\n"
        . (($d['poznamka'] ?? '') !== '' ? "Poznámka: $d[poznamka]\n" : '')
        . "\nVyřídit: " . rtrim(cfg()['url'], '/') . "/?r=rezervace&id=$rid\n");
    if ($email) posli_email($email, 'Thai Most – přijali jsme vaši žádost o termín',
        "Dobrý den,\n\nděkujeme za zájem o $v[nazev] ($v[popis]). Kdy by se vám hodilo: $pref.\n\n"
        . "Termín je potvrzený až ve chvíli, kdy se vám ozveme. Na recepci vám zavoláme nebo napíšeme co nejdřív.\n\n"
        . "Thajské masáže Most\n+420 778 429 329\n");

    api_odpoved(['ok' => true]);
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

    $cislo = preg_replace('~[^A-Z0-9-]~', '', strtoupper($d['cislo'] ?? ''));
    $kod = poukaz_kod();
    q("INSERT INTO poukazy(kod,hodnota,zustatek,sluzba_text,stav,objednal_jmeno,objednal_tel,objednal_email,pro_koho,od_koho,
        venovani,prevzeti,platba,poznamka,zdroj) VALUES(?,?,?,?,'objednano',?,?,?,?,?,?,?,?,?,'web')",
        [$kod, $hodnota, $hodnota, $d['sluzba'] ?? '', $jmeno, $tel, $email, $d['komu'] ?? '', $d['od'] ?? '',
         $d['venovani'] ?? '', $d['prevzeti'] ?? '', $d['platba'] ?? '', trim(($cislo ? "Objednávka z webu $cislo. " : '') . ($d['poznamka'] ?? ''))]);
    $pid = (int)db()->lastInsertId();

    $popis = ($d['sluzba'] ?? '') !== '' ? $d['sluzba'] : kc($hodnota);
    posli_email(cfg()['email_salon'], "Objednávka poukazu – $jmeno ($popis)",
        "Nová objednávka dárkového poukazu z webu" . ($cislo ? " ($cislo)" : '') . ":\n\n"
        . "Poukaz: $popis, hodnota " . kc($hodnota) . "\nObjednal: $jmeno, " . telefon_cz($tel) . ($email ? ", $email" : '') . "\n"
        . "Převzetí: " . ($d['prevzeti'] ?? '') . "\nPlatba: " . ($d['platba'] ?? '') . "\n"
        . (($d['poznamka'] ?? '') !== '' ? "Poznámka: $d[poznamka]\n" : '')
        . "\nKód v kartotéce: $kod\n" . rtrim(cfg()['url'], '/') . "/?r=poukaz&id=$pid\n");

    // Kód poukazu zákazníkovi nevracíme – platí až po zaplacení a vydání v salonu.
    api_odpoved(['ok' => true]);
}
