<?php
// Databáze (SQLite) – schéma se vytvoří/doplní samo při prvním spuštění.

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $cfg = cfg();
    $dir = dirname($cfg['db_path']);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $pdo = new PDO('sqlite:' . $cfg['db_path'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    migrate($pdo);
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}
function q1(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function qv(string $sql, array $p = []) { $r = q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }

function migrate(PDO $pdo): void
{
    $ver = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($ver >= 1) return;

    $pdo->exec(<<<SQL
    CREATE TABLE uzivatele (
        id INTEGER PRIMARY KEY, login TEXT UNIQUE NOT NULL, jmeno TEXT NOT NULL,
        heslo_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'recepce', aktivni INTEGER NOT NULL DEFAULT 1,
        vytvoreno TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE maserky (
        id INTEGER PRIMARY KEY, jmeno TEXT NOT NULL, aktivni INTEGER NOT NULL DEFAULT 1,
        poradi INTEGER NOT NULL DEFAULT 0, barva TEXT NOT NULL DEFAULT '#b07a3a', ucet TEXT NOT NULL DEFAULT ''
    );
    CREATE TABLE sluzby (
        id INTEGER PRIMARY KEY, kod TEXT UNIQUE, nazev TEXT NOT NULL, kat TEXT NOT NULL DEFAULT 'masaze',
        aktivni INTEGER NOT NULL DEFAULT 1, poradi INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE varianty (
        id INTEGER PRIMARY KEY, sluzba_id INTEGER NOT NULL REFERENCES sluzby(id),
        popis TEXT NOT NULL, minuty INTEGER, cena INTEGER NOT NULL DEFAULT 0,
        aktivni INTEGER NOT NULL DEFAULT 1, poradi INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE zakaznici (
        id INTEGER PRIMARY KEY, telefon TEXT UNIQUE NOT NULL, jmeno TEXT NOT NULL DEFAULT '',
        prijmeni TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '',
        poznamka_interni TEXT NOT NULL DEFAULT '',
        pref_tlak TEXT NOT NULL DEFAULT '', pref_maserka_id INTEGER REFERENCES maserky(id),
        pref_text TEXT NOT NULL DEFAULT '',
        zdravi_text TEXT NOT NULL DEFAULT '', zdravi_souhlas TEXT,
        volny_termin INTEGER NOT NULL DEFAULT 0, volny_kdy TEXT NOT NULL DEFAULT '',
        blokovany INTEGER NOT NULL DEFAULT 0,
        pocatecni_razitka INTEGER NOT NULL DEFAULT 0,
        gdpr_souhlas TEXT, zdroj TEXT NOT NULL DEFAULT 'recepce',
        vytvoreno TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE poukazy (
        id INTEGER PRIMARY KEY, kod TEXT UNIQUE NOT NULL, hodnota INTEGER NOT NULL DEFAULT 0,
        zustatek INTEGER NOT NULL DEFAULT 0, sluzba_text TEXT NOT NULL DEFAULT '',
        platnost_do TEXT, stav TEXT NOT NULL DEFAULT 'objednano',
        objednal_jmeno TEXT NOT NULL DEFAULT '', objednal_tel TEXT NOT NULL DEFAULT '', objednal_email TEXT NOT NULL DEFAULT '',
        pro_koho TEXT NOT NULL DEFAULT '', od_koho TEXT NOT NULL DEFAULT '', venovani TEXT NOT NULL DEFAULT '',
        prevzeti TEXT NOT NULL DEFAULT '', platba TEXT NOT NULL DEFAULT '', poznamka TEXT NOT NULL DEFAULT '',
        zakaznik_id INTEGER REFERENCES zakaznici(id), zdroj TEXT NOT NULL DEFAULT 'recepce',
        vytvoreno TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE rezervace (
        id INTEGER PRIMARY KEY, zakaznik_id INTEGER NOT NULL REFERENCES zakaznici(id),
        varianta_id INTEGER REFERENCES varianty(id), maserka_id INTEGER REFERENCES maserky(id),
        zacatek TEXT, konec TEXT,
        stav TEXT NOT NULL DEFAULT 'zadost',
        zdroj TEXT NOT NULL DEFAULT 'recepce',
        preferovano TEXT NOT NULL DEFAULT '', poznamka_zakaznik TEXT NOT NULL DEFAULT '',
        poznamka_masaz TEXT NOT NULL DEFAULT '',
        platba TEXT NOT NULL DEFAULT '', cena INTEGER, poukaz_id INTEGER REFERENCES poukazy(id),
        vytvoreno TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        zmeneno TEXT, zmenil TEXT NOT NULL DEFAULT ''
    );
    CREATE INDEX rez_zacatek ON rezervace(zacatek);
    CREATE INDEX rez_zakaznik ON rezervace(zakaznik_id);
    CREATE INDEX rez_stav ON rezervace(stav);
    CREATE TABLE poukaz_pohyby (
        id INTEGER PRIMARY KEY, poukaz_id INTEGER NOT NULL REFERENCES poukazy(id), castka INTEGER NOT NULL,
        rezervace_id INTEGER REFERENCES rezervace(id), poznamka TEXT NOT NULL DEFAULT '',
        kdo TEXT NOT NULL DEFAULT '', cas TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE nastaveni (klic TEXT PRIMARY KEY, hodnota TEXT NOT NULL);
    CREATE TABLE emaily (
        id INTEGER PRIMARY KEY, komu TEXT NOT NULL, predmet TEXT NOT NULL, text TEXT NOT NULL,
        stav TEXT NOT NULL DEFAULT 'ceka', chyba TEXT NOT NULL DEFAULT '',
        vytvoreno TEXT NOT NULL DEFAULT (datetime('now','localtime')), odeslano TEXT
    );
    CREATE TABLE limit_ip (klic TEXT PRIMARY KEY, pocet INTEGER NOT NULL, okno TEXT NOT NULL);
    CREATE TABLE log (
        id INTEGER PRIMARY KEY, cas TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        kdo TEXT NOT NULL DEFAULT '', akce TEXT NOT NULL, detail TEXT NOT NULL DEFAULT ''
    );
    SQL);

    // Výchozí nastavení
    $def = [
        'rezim_webu' => 'zajem',          // zajem | terminy (terminy zatím nepoužito)
        'oteviraci_od' => '09:00',
        'oteviraci_do' => '20:00',
        'prah_staly_navstev' => '6',      // Stálý: tolik návštěv za posledních 6 měsíců
        'prah_spici_dni' => '90',         // Spící: stálý zákazník, nepřišel tolik dní
        'prah_nespolehlivy' => '2',       // Nespolehlivý: tolik „nepřišel“ / pozdních storen za 12 měsíců
        'bonus_po' => '5',                // po kolika zaplacených masážích je jedna zdarma
        'bonus_pocita_poukaz' => '0',     // počítá se do bonusu masáž placená poukazem? (ověřit se salonem)
    ];
    $st = $pdo->prepare('INSERT INTO nastaveni(klic,hodnota) VALUES(?,?)');
    foreach ($def as $k => $v) $st->execute([$k, $v]);

    // Služby a ceny převzaté z webu (assets/js/data.js, 10/2026)
    $seed = json_decode(file_get_contents(__DIR__ . '/seed-sluzby.json'), true);
    $ss = $pdo->prepare('INSERT INTO sluzby(kod,nazev,kat,poradi) VALUES(?,?,?,?)');
    $sv = $pdo->prepare('INSERT INTO varianty(sluzba_id,popis,minuty,cena,poradi) VALUES(?,?,?,?,?)');
    foreach ($seed as $s) {
        $ss->execute([$s['kod'], $s['nazev'], $s['kat'], $s['poradi']]);
        $sid = $pdo->lastInsertId();
        foreach ($s['varianty'] as $v) $sv->execute([$sid, $v['popis'], $v['minuty'], $v['cena'], $v['poradi']]);
    }

    // 5 masérek – jména doplní salon v Nastavení
    $sm = $pdo->prepare('INSERT INTO maserky(jmeno,poradi,barva) VALUES(?,?,?)');
    $barvy = ['#b07a3a', '#5b8a72', '#7a5ea8', '#c0605a', '#3f7fae'];
    for ($i = 1; $i <= 5; $i++) $sm->execute(["Masérka $i", $i, $barvy[$i - 1]]);

    $pdo->exec('PRAGMA user_version = 1');
}

function nastaveni(string $k, $default = null)
{
    static $cache = null;
    if ($cache === null) $cache = q('SELECT klic,hodnota FROM nastaveni')->fetchAll(PDO::FETCH_KEY_PAIR);
    return $cache[$k] ?? $default;
}
