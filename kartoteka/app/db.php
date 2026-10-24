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

const DB_VERZE = 10;

/** Migrace běží v jedné transakci a po získání zámku se verze ověří znovu (souběžné první požadavky). */
function migrate(PDO $pdo): void
{
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() >= DB_VERZE) return;
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $ver = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($ver < 1) migrate_v1($pdo);
        if ($ver < 2) migrate_v2($pdo);
        if ($ver < 3) migrate_v3($pdo);
        if ($ver < 4) migrate_v4($pdo);
        if ($ver < 5) migrate_v5($pdo);
        if ($ver < 6) migrate_v6($pdo);
        if ($ver < 7) migrate_v7($pdo);
        if ($ver < 8) migrate_v8($pdo);
        if ($ver < 9) migrate_v9($pdo);
        if ($ver < 10) migrate_v10($pdo);
        $pdo->exec('PRAGMA user_version = ' . DB_VERZE);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function migrate_v1(PDO $pdo): void
{
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
}

/** v10: akce asistenta (potvrzení žádosti, přesun, změna stavu, volno) a trvalé přihlášení v aplikaci. */
function migrate_v10(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    ALTER TABLE ai_navrhy ADD COLUMN data TEXT NOT NULL DEFAULT '{}';         -- parametry akce (JSON)
    ALTER TABLE ai_navrhy ADD COLUMN cil_rez_id INTEGER REFERENCES rezervace(id);  -- rezervace, které se akce týká
    CREATE TABLE prihlaseni_trvale (
        id INTEGER PRIMARY KEY, uzivatel_id INTEGER NOT NULL REFERENCES uzivatele(id), token_hash TEXT NOT NULL,
        zarizeni TEXT NOT NULL DEFAULT '', vytvoreno TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        posledni TEXT, expirace TEXT NOT NULL
    );
    CREATE INDEX prihl_trvale_uziv ON prihlaseni_trvale(uzivatel_id);
    SQL);
}

/** v9: AI asistent – pravidla salonu (jediný „trénink“ agenta), návrhy ke schválení recepcí, log volání. */
function migrate_v9(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    CREATE TABLE ai_pravidla (
        id INTEGER PRIMARY KEY, kod TEXT NOT NULL DEFAULT '', typ TEXT NOT NULL DEFAULT 'tvrde',   -- tvrde | mekke
        text TEXT NOT NULL, aktivni INTEGER NOT NULL DEFAULT 1, stav TEXT NOT NULL DEFAULT 'schvaleno',  -- navrh | schvaleno
        autor TEXT NOT NULL DEFAULT '', schvalil TEXT NOT NULL DEFAULT '',
        vytvoreno TEXT NOT NULL DEFAULT (datetime('now','localtime')), zmeneno TEXT
    );
    CREATE TABLE ai_navrhy (
        id INTEGER PRIMARY KEY, typ TEXT NOT NULL DEFAULT 'rezervace',
        zakaznik_id INTEGER REFERENCES zakaznici(id), varianta_id INTEGER REFERENCES varianty(id),
        maserka_id INTEGER REFERENCES maserky(id), maserka2_id INTEGER REFERENCES maserky(id),
        zacatek TEXT, delka INTEGER, zduvodneni TEXT NOT NULL DEFAULT '',
        stav TEXT NOT NULL DEFAULT 'ceka',            -- ceka | otevreno | prijato | zamitnuto
        duvod TEXT NOT NULL DEFAULT '', rozhodl TEXT NOT NULL DEFAULT '', rozhodnuto TEXT,
        rezervace_id INTEGER REFERENCES rezervace(id), pro_uzivatele TEXT NOT NULL DEFAULT '',
        vytvoreno TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE INDEX ai_navrhy_stav ON ai_navrhy(stav);
    CREATE TABLE ai_log (
        id INTEGER PRIMARY KEY, cas TEXT NOT NULL DEFAULT (datetime('now','localtime')), kdo TEXT NOT NULL DEFAULT '',
        typ TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '', tokeny_in INTEGER NOT NULL DEFAULT 0, tokeny_out INTEGER NOT NULL DEFAULT 0
    );
    CREATE INDEX ai_log_cas ON ai_log(cas);
    SQL);
    // první verze pravidel z provozu salonu (admin je upraví v Asistent → Pravidla)
    $st = $pdo->prepare("INSERT INTO ai_pravidla(kod,typ,text,autor,schvalil) VALUES(?,?,?,'výchozí','výchozí')");
    foreach ([
        ['R-01', 'tvrde', 'Rezervaci vždy potvrzuje recepce. Asistent jen navrhuje, sám nic neukládá ani neruší.'],
        ['R-02', 'tvrde', 'Salon má 2 jednolůžkové masérny a 1 dvoulůžkovou, která slouží i pro wellness. Na každém lůžku je tablet.'],
        ['R-03', 'tvrde', 'Masáže trvají 30, 60 nebo 90 minut podle varianty v ceníku.'],
        ['R-04', 'tvrde', 'Párový balíček (párová masáž + vířivka a sauna) = 2 masérky na 1 hodinu ve dvoulůžkové masérně; plánovat na konec směny, wellness pak běží po zavírací době jen s recepční.'],
        ['R-05', 'tvrde', 'Samotná párová masáž je jiná služba než wellness balíček.'],
        ['R-06', 'mekke', 'Cíl: každý den co nejplnější, bez prázdných hodin.'],
        ['R-07', 'tvrde', 'Každá 5. zaplacená návštěva s věrnostní kartou je zdarma.'],
        ['R-08', 'mekke', 'Při výpadku masérky nabídnout zákazníkovi jinou masérku nebo přeplánování.'],
    ] as $r) $st->execute($r);
}

/** v8: kolik masérek služba potřebuje a na jak dlouho (párová: 2 masérky 60 min, pak whirlpool bez masérky),
 *  zda zabírá wellness masérnu; u rezervace druhá masérka a konec části s masérkami. */
function migrate_v8(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    ALTER TABLE varianty ADD COLUMN maserky INTEGER NOT NULL DEFAULT 1;      -- 0 = bez masérky (whirlpool, sauna), 1, 2 = párová
    ALTER TABLE varianty ADD COLUMN maserky_min INTEGER;                    -- jak dlouho od začátku jsou masérky potřeba (prázdné = celou dobu)
    ALTER TABLE varianty ADD COLUMN wellness_mistnost INTEGER NOT NULL DEFAULT 0;  -- zabírá celou wellness masérnu
    ALTER TABLE rezervace ADD COLUMN maserka2_id INTEGER REFERENCES maserky(id);
    ALTER TABLE rezervace ADD COLUMN masaz_konec TEXT;                      -- konec části s masérkami (prázdné = konec)
    SQL);
    // výchozí podle ceníku salonu: whirlpool/sauna bez masérky ve wellness masérně, párová = 2 masérky na 60 min + whirlpool
    $pdo->exec("UPDATE varianty SET maserky=0, wellness_mistnost=1 WHERE sluzba_id IN (SELECT id FROM sluzby WHERE kod='whirlpool')");
    $pdo->exec("UPDATE varianty SET maserky=2, maserky_min=60, wellness_mistnost=1 WHERE sluzba_id IN (SELECT id FROM sluzby WHERE kod='whirlpool') AND popis LIKE '%párov%'");
    $pdo->exec("UPDATE rezervace SET masaz_konec=datetime(zacatek, '+60 minutes') WHERE varianta_id IN (SELECT id FROM varianty WHERE maserky_min=60) AND zacatek IS NOT NULL");
    $pdo->exec("UPDATE rezervace SET maserka_id=NULL WHERE varianta_id IN (SELECT id FROM varianty WHERE maserky=0)");
}

/** v7: volna a přesčasy masérek; tablety (lůžka) seskupené do masérn, masérna pro wellness. */
function migrate_v7(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    CREATE TABLE maserky_volno (
        id INTEGER PRIMARY KEY, maserka_id INTEGER NOT NULL REFERENCES maserky(id),
        typ TEXT NOT NULL DEFAULT 'volno',          -- volno | prescas
        datum_od TEXT NOT NULL, datum_do TEXT NOT NULL, cas_od TEXT, cas_do TEXT,
        poznamka TEXT NOT NULL DEFAULT '', kdo TEXT NOT NULL DEFAULT '',
        vytvoreno TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE INDEX mvolno_datum ON maserky_volno(datum_od, datum_do);
    -- jeden záznam v „mistnosti“ = jedno lůžko s tabletem; skupina = masérna (místnost), ve které lůžko je
    ALTER TABLE mistnosti ADD COLUMN skupina TEXT NOT NULL DEFAULT '';
    ALTER TABLE mistnosti ADD COLUMN wellness INTEGER NOT NULL DEFAULT 0;
    SQL);
}

/** v6: plastové věrnostní karty s QR (zákazník si na telefonu otevře razítka, recepce kartu přiřadí k zákazníkovi). */
function migrate_v6(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    CREATE TABLE karty (
        id INTEGER PRIMARY KEY, kod TEXT NOT NULL UNIQUE, zakaznik_id INTEGER REFERENCES zakaznici(id),
        aktivni INTEGER NOT NULL DEFAULT 1,
        vytvoreno TEXT NOT NULL DEFAULT (datetime('now','localtime')), prirazeno TEXT, kdo TEXT NOT NULL DEFAULT ''
    );
    CREATE INDEX karty_zak ON karty(zakaznik_id);
    SQL);
}

/** v5: anglické verze textů pro masérky na tabletu (píše recepce ručně). */
function migrate_v5(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    ALTER TABLE zakaznici ADD COLUMN pref_text_en TEXT NOT NULL DEFAULT '';
    ALTER TABLE zakaznici ADD COLUMN zdravi_text_en TEXT NOT NULL DEFAULT '';
    ALTER TABLE rezervace ADD COLUMN poznamka_en TEXT NOT NULL DEFAULT '';
    SQL);
}

/** v2: tým – profily masérek a recepčních, hodnocení spokojenosti, směny recepčních, přílohy e-mailů. */
function migrate_v4(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    -- masérny s tablety: tablet se hlásí tajným tokenem v adrese, bez přihlášení
    CREATE TABLE mistnosti (
        id INTEGER PRIMARY KEY, nazev TEXT NOT NULL, token TEXT NOT NULL UNIQUE,
        aktivni INTEGER NOT NULL DEFAULT 1, poradi INTEGER NOT NULL DEFAULT 0, posledni_spojeni TEXT
    );
    -- jedno sezení = jedna masáž na tabletu (od odeslání recepcí po zavření hodnocení)
    CREATE TABLE sezeni (
        id INTEGER PRIMARY KEY, mistnost_id INTEGER NOT NULL REFERENCES mistnosti(id),
        rezervace_id INTEGER NOT NULL REFERENCES rezervace(id), maserka_id INTEGER REFERENCES maserky(id),
        stav TEXT NOT NULL DEFAULT 'cekani',     -- cekani | bezi | konec | hotovo | zruseno
        minuty INTEGER NOT NULL DEFAULT 60,
        poslano TEXT NOT NULL DEFAULT (datetime('now','localtime')), poslal TEXT NOT NULL DEFAULT '',
        potvrzeno TEXT,                           -- masérka potvrdila, že je to ona
        neni_ona TEXT,                            -- masérka hlásí, že jde o jinou osobu (upozornění recepci)
        start TEXT, plan_konec TEXT, konec TEXT, zavreno TEXT,
        hodnoceni_id INTEGER REFERENCES hodnoceni_maserek(id)
    );
    CREATE INDEX sezeni_mistnost ON sezeni(mistnost_id, stav);
    CREATE INDEX sezeni_rez ON sezeni(rezervace_id);
    -- odměna týdne (nejlépe hodnocená masérka)
    CREATE TABLE odmeny (
        tyden TEXT PRIMARY KEY, maserka_id INTEGER NOT NULL REFERENCES maserky(id),
        poznamka TEXT NOT NULL DEFAULT '', kdo TEXT NOT NULL DEFAULT '', cas TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    INSERT OR IGNORE INTO nastaveni(klic, hodnota) VALUES ('odmena_min_hodnoceni', '3');
    INSERT OR IGNORE INTO nastaveni(klic, hodnota) VALUES ('spropitne_ucet', '');
    SQL);
}

function migrate_v3(PDO $pdo): void
{
    $pdo->exec("ALTER TABLE maserky ADD COLUMN ical_token TEXT NOT NULL DEFAULT ''");
}

function migrate_v2(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    ALTER TABLE maserky ADD COLUMN telefon TEXT NOT NULL DEFAULT '';
    ALTER TABLE maserky ADD COLUMN email TEXT NOT NULL DEFAULT '';
    ALTER TABLE maserky ADD COLUMN zamereni TEXT NOT NULL DEFAULT '';        -- štítky oddělené čárkou (např. šíje, thajská, sportovní)
    ALTER TABLE maserky ADD COLUMN preference TEXT NOT NULL DEFAULT '';      -- co dělá ráda / pro koho se hodí / co nedělá
    ALTER TABLE maserky ADD COLUMN poznamka_interni TEXT NOT NULL DEFAULT '';
    ALTER TABLE maserky ADD COLUMN nastup TEXT;

    CREATE TABLE hodnoceni_maserek (
        id INTEGER PRIMARY KEY, maserka_id INTEGER NOT NULL REFERENCES maserky(id),
        rezervace_id INTEGER REFERENCES rezervace(id),
        znamka INTEGER NOT NULL CHECK (znamka BETWEEN 1 AND 5),
        komentar TEXT NOT NULL DEFAULT '', zdroj TEXT NOT NULL DEFAULT 'recepce',  -- recepce | tablet
        kdo TEXT NOT NULL DEFAULT '', cas TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE INDEX hodn_maserka ON hodnoceni_maserek(maserka_id);

    ALTER TABLE uzivatele ADD COLUMN telefon TEXT NOT NULL DEFAULT '';
    ALTER TABLE uzivatele ADD COLUMN email TEXT NOT NULL DEFAULT '';
    ALTER TABLE uzivatele ADD COLUMN poznamka TEXT NOT NULL DEFAULT '';
    ALTER TABLE uzivatele ADD COLUMN barva TEXT NOT NULL DEFAULT '#5b8a72';
    ALTER TABLE uzivatele ADD COLUMN ical_token TEXT NOT NULL DEFAULT '';

    CREATE TABLE smeny (
        id INTEGER PRIMARY KEY, uzivatel_id INTEGER NOT NULL REFERENCES uzivatele(id),
        datum TEXT NOT NULL, od TEXT NOT NULL, do_ TEXT NOT NULL,
        stav TEXT NOT NULL DEFAULT 'navrzeno',          -- navrzeno | schvaleno | odmitnuto | zruseno
        poznamka TEXT NOT NULL DEFAULT '', navrhl TEXT NOT NULL DEFAULT '', schvalil TEXT NOT NULL DEFAULT '',
        verze INTEGER NOT NULL DEFAULT 0, odeslano TEXT,
        vytvoreno TEXT NOT NULL DEFAULT (datetime('now','localtime')), zmeneno TEXT
    );
    CREATE INDEX smeny_datum ON smeny(datum);
    CREATE INDEX smeny_uziv ON smeny(uzivatel_id);

    ALTER TABLE emaily ADD COLUMN priloha_nazev TEXT NOT NULL DEFAULT '';
    ALTER TABLE emaily ADD COLUMN priloha_mime TEXT NOT NULL DEFAULT '';
    ALTER TABLE emaily ADD COLUMN priloha_obsah TEXT NOT NULL DEFAULT '';
    SQL);
    $pdo->exec("INSERT OR IGNORE INTO nastaveni(klic,hodnota) VALUES('ical_vse_token','" . bin2hex(random_bytes(16)) . "')");
}

function nastaveni(string $k, $default = null)
{
    static $cache = null;
    if ($cache === null) $cache = q('SELECT klic,hodnota FROM nastaveni')->fetchAll(PDO::FETCH_KEY_PAIR);
    return $cache[$k] ?? $default;
}
