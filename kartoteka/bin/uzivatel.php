#!/usr/bin/env php
<?php
// Založení / změna hesla uživatele kartotéky.
// Použití: php bin/uzivatel.php LOGIN "Jméno" HESLO [admin|recepce]
require dirname(__DIR__) . '/app/lib.php';
require dirname(__DIR__) . '/app/db.php';
[$_, $login, $jmeno, $heslo] = $argv + [null, null, null, null];
$role = $argv[4] ?? 'admin';
if (!$login || !$jmeno || !$heslo) exit("Použití: php bin/uzivatel.php LOGIN \"Jméno\" HESLO [admin|recepce]\n");
if (strlen($heslo) < 8) exit("Heslo musí mít aspoň 8 znaků.\n");
q('INSERT INTO uzivatele(login,jmeno,heslo_hash,role) VALUES(?,?,?,?)
   ON CONFLICT(login) DO UPDATE SET heslo_hash=excluded.heslo_hash, jmeno=excluded.jmeno, role=excluded.role, aktivni=1',
   [$login, $jmeno, password_hash($heslo, PASSWORD_DEFAULT), $role === 'recepce' ? 'recepce' : 'admin']);
echo "Uživatel $login ($role) uložen.\n";
