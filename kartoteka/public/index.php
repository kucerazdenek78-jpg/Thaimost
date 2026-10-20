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

$r = preg_replace('~[^a-z_]~', '', $_GET['r'] ?? 'dnes');
if ($r === 'login') { stranka_login(); exit; }
if ($r === 'logout') { session_start_safe(); session_destroy(); presmeruj('/?r=login'); }

$u = vyzaduj_prihlaseni();
uzavri_probehle();

$stranky = [
    'dnes' => 'stranka_dnes', 'kalendar' => 'stranka_kalendar',
    'rez_nova' => 'stranka_rez_nova', 'rezervace' => 'stranka_rezervace',
    'zakaznici' => 'stranka_zakaznici', 'zakaznik' => 'stranka_zakaznik', 'hledat' => 'stranka_hledat',
    'poukazy' => 'stranka_poukazy', 'poukaz' => 'stranka_poukaz', 'poukaz_novy' => 'stranka_poukaz_novy',
    'nastaveni' => 'stranka_nastaveni', 'emaily' => 'stranka_emaily',
];
if (!isset($stranky[$r])) { http_response_code(404); $r = 'dnes'; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') over_csrf();
$stranky[$r]($u);
