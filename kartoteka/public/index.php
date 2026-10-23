<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/lib.php';
require $root . '/app/db.php';

date_default_timezone_set(cfg()['casova_zona'] ?? 'Europe/Prague');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$cesta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if (str_starts_with($cesta, '/api/')) {
    require $root . '/app/api.php';
    try { api_route($cesta); }
    catch (Throwable $e) { error_log((string)$e); api_odpoved(['ok' => false, 'chyba' => 'Chyba serveru, zkuste to prosím později nebo zavolejte.'], 500); }
}

if ($cesta !== '/') { http_response_code(404); exit('Nenalezeno'); }

require $root . '/app/layout.php';
require $root . '/app/stranky.php';

// Spouštěč vzorových dat přes soubor (když nejde terminál kontejneru): nahrát do data/ prázdný soubor
// „vzor-nahrat“ nebo „vzor-smazat“ (File Station). Při nejbližším načtení stránky se akce provede,
// soubor se smaže a výsledek se zapíše do data/vzor-vysledek.txt. Soubor může vložit jen ten, kdo má přístup k NAS.
foreach (['nahrat' => 'demo_nahrat', 'smazat' => 'demo_smazat', 'obnovit' => fn() => demo_smazat() . ' ' . demo_nahrat()] as $k => $fn) {
    $f = dirname(cfg()['db_path']) . "/vzor-$k";
    foreach ([$f, "$f.txt"] as $ff) {
        if (!is_file($ff) || !@unlink($ff)) continue;
        try { $txt = $fn(); } catch (Throwable $e) { $txt = 'CHYBA: ' . $e->getMessage(); }
        @file_put_contents(dirname($f) . '/vzor-vysledek.txt', date('Y-m-d H:i:s') . "  $txt\n", FILE_APPEND);
        break 2;
    }
}

$r = preg_replace('~[^a-z_]~', '', $_GET['r'] ?? 'dnes');
if ($r === 'login') { stranka_login(); exit; }
if ($r === 'ical') { stranka_ical(); }   // kalendářový odkaz chráněný tokenem, bez přihlášení
// tablet v masérně: bez přihlášení, pozná se tajným tokenem místnosti (neplatný token = 403/404)
if ($r === 'tablet') { stranka_tablet(); }
// věrnostní karta zákazníka (QR): bez přihlášení, pozná se tajným kódem karty
if ($r === 'karta') { stranka_karta(); }
if ($r === 'tablet_api') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Metoda není povolena'); }
    require $root . '/app/api.php';
    try { stranka_tablet_api(); }
    catch (Throwable $e) { error_log((string)$e); api_odpoved(['ok' => false, 'chyba' => 'Chyba serveru.'], 500); }
}
if ($r === 'logout') { session_start_safe(); session_destroy(); presmeruj('/?r=login'); }

$u = vyzaduj_prihlaseni();
uzavri_probehle();

$stranky = [
    'dnes' => 'stranka_dnes', 'kalendar' => 'stranka_kalendar',
    'rez_nova' => 'stranka_rez_nova', 'rezervace' => 'stranka_rezervace',
    'zakaznici' => 'stranka_zakaznici', 'zakaznik' => 'stranka_zakaznik', 'hledat' => 'stranka_hledat',
    'poukazy' => 'stranka_poukazy', 'poukaz' => 'stranka_poukaz', 'poukaz_novy' => 'stranka_poukaz_novy',
    'nastaveni' => 'stranka_nastaveni', 'emaily' => 'stranka_emaily',
    'masterny' => 'stranka_masterny', 'odmena' => 'stranka_odmena',
    'tym' => 'stranka_maserky', 'maserky' => 'stranka_maserky', 'maserka' => 'stranka_maserka', 'recepcni' => 'stranka_recepcni', 'uzivatele' => 'stranka_recepcni', 'smeny' => 'stranka_smeny',
    'hledej_zak' => 'stranka_hledej_zak', 'karty' => 'stranka_karty', 'karty_tisk' => 'stranka_karty_tisk',
    'volno' => 'stranka_volno', 'den_obs' => 'stranka_den_obs', 'dostupnost' => 'stranka_dostupnost', 'import' => 'stranka_import', 'poukaz_tisk' => 'stranka_poukaz_tisk',
];
if (!isset($stranky[$r])) { http_response_code(404); $r = 'dnes'; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') over_csrf();
$stranky[$r]($u);
