#!/usr/bin/env php
<?php
// Vzorová data z příkazové řádky (totéž co tlačítka v Nastavení).
// Použití: php bin/vzor.php nahrat | smazat
require dirname(__DIR__) . '/app/lib.php';
require dirname(__DIR__) . '/app/db.php';
require dirname(__DIR__) . '/app/stranky_vernost.php'; // KARTA_ZNAKY
require dirname(__DIR__) . '/app/demo.php';
date_default_timezone_set(cfg()['casova_zona'] ?? 'Europe/Prague');
$a = $argv[1] ?? '';
try {
    if ($a === 'nahrat') echo demo_nahrat(), "\n";
    elseif ($a === 'smazat') echo demo_smazat(), "\n";
    else exit("Použití: php bin/vzor.php nahrat | smazat\n");
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
