<?php
// Pomocné funkce kartotéky.

function cfg(): array
{
    static $c = null;
    if ($c) return $c;
    $f = dirname(__DIR__) . '/config.php';
    if (!is_file($f)) $f = dirname(__DIR__) . '/config.example.php';
    $c = require $f;
    return $c;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function kc($n): string { return $n === null || $n === '' ? '—' : number_format((int)$n, 0, ',', ' ') . ' Kč'; }
function nyni(): string { return date('Y-m-d H:i:s'); }

function datum_cz(?string $dt, bool $cas = true): string
{
    if (!$dt) return '—';
    $t = strtotime($dt);
    $dny = ['ne', 'po', 'út', 'st', 'čt', 'pá', 'so'];
    return $dny[(int)date('w', $t)] . ' ' . date('j. n. Y', $t) . ($cas ? date(' H:i', $t) : '');
}

/** Telefon na jednotný tvar +420XXXXXXXXX (zahraniční ponechá s +). Vrací '' pokud nesedí. */
function telefon_norm(string $t): string
{
    $t = preg_replace('~[^\d+]~', '', $t);
    if (str_starts_with($t, '00')) $t = '+' . substr($t, 2);
    if (preg_match('~^\d{9}$~', $t)) $t = '+420' . $t;
    if (!preg_match('~^\+\d{9,15}$~', $t)) return '';
    return $t;
}
function telefon_cz(string $t): string
{
    if (preg_match('~^\+420(\d{3})(\d{3})(\d{3})$~', $t, $m)) return "$m[1] $m[2] $m[3]";
    return $t;
}

// ---------- Přihlášení ----------
function session_start_safe(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || !empty($_SERVER['HTTPS']);
    session_name('tmk');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    session_save_path(dirname(cfg()['db_path']));
    session_start();
}
function uzivatel(): ?array
{
    session_start_safe();
    if (empty($_SESSION['uid'])) prihlaseni_z_cookie();
    if (empty($_SESSION['uid'])) return null;
    static $u = false;
    if ($u === false) $u = q1('SELECT * FROM uzivatele WHERE id=? AND aktivni=1', [$_SESSION['uid']]);
    return $u;
}
function vyzaduj_prihlaseni(): array
{
    $u = uzivatel();
    if (!$u) {
        // po přihlášení zpět na stránku, kam člověk šel (např. aplikace asistenta)
        $kam = $_SERVER['REQUEST_URI'] ?? '';
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('~^/\?r=[a-z_]+[^\s]*$~', $kam)) $_SESSION['po_prihlaseni'] = $kam;
        header('Location: /?r=login'); exit;
    }
    return $u;
}

// ---------- Trvalé přihlášení („Zůstat přihlášen“, hlavně pro aplikaci v telefonu) ----------
const TRVALE_COOKIE = 'tmk_trvale';
// prohlížeče drží cookie nejvýš ~400 dní; platnost se při každém použití prodlužuje,
// takže kdo kartotéku používá, zůstane přihlášený natrvalo (do odhlášení)
const TRVALE_DNI = 400;

function trvale_cookie(string $hodnota, int $expirace): void
{
    if (headers_sent()) return;
    $https = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || !empty($_SERVER['HTTPS']);
    setcookie(TRVALE_COOKIE, $hodnota, ['expires' => $expirace, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
}

/** Po úspěšném přihlášení se zaškrtnutým „Zůstat přihlášen“: náhodný token v cookie, v databázi jen jeho hash. */
function trvale_prihlaseni_vytvor(int $uid): void
{
    $token = bin2hex(random_bytes(32));
    $exp = time() + TRVALE_DNI * 86400;
    q('INSERT INTO prihlaseni_trvale(uzivatel_id,token_hash,zarizeni,expirace) VALUES(?,?,?,?)',
        [$uid, hash('sha256', $token), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), date('Y-m-d H:i:s', $exp)]);
    trvale_cookie(db()->lastInsertId() . ':' . $token, $exp);
}

function prihlaseni_z_cookie(): void
{
    $c = (string)($_COOKIE[TRVALE_COOKIE] ?? '');
    if (!preg_match('~^(\d+):([0-9a-f]{64})$~', $c, $m)) return;
    try {
        $t = q1('SELECT t.*, u.aktivni FROM prihlaseni_trvale t JOIN uzivatele u ON u.id=t.uzivatel_id WHERE t.id=?', [(int)$m[1]]);
    } catch (Throwable $e) { return; }   // tabulka ještě neexistuje (před migrací)
    if (!$t || !hash_equals($t['token_hash'], hash('sha256', $m[2])) || $t['expirace'] < nyni() || !(int)$t['aktivni']) {
        if ($t && $t['expirace'] < nyni()) q('DELETE FROM prihlaseni_trvale WHERE id=?', [$t['id']]);
        trvale_cookie('', time() - 3600);
        return;
    }
    if (!headers_sent()) session_regenerate_id(true);
    $_SESSION['uid'] = (int)$t['uzivatel_id'];
    // klouzavá platnost: při používání se prodlužuje
    $exp = time() + TRVALE_DNI * 86400;
    q('UPDATE prihlaseni_trvale SET posledni=?, expirace=? WHERE id=?', [nyni(), date('Y-m-d H:i:s', $exp), $t['id']]);
    trvale_cookie($c, $exp);
}

function trvale_prihlaseni_zrus(): void
{
    $c = (string)($_COOKIE[TRVALE_COOKIE] ?? '');
    if (preg_match('~^(\d+):~', $c, $m)) { try { q('DELETE FROM prihlaseni_trvale WHERE id=?', [(int)$m[1]]); } catch (Throwable $e) {} }
    trvale_cookie('', time() - 3600);
}
/** Role: admin (vše) · hlavni (hlavní pracovník – upravuje masérky) · recepce. */
function je_admin(?array $u = null): bool { return (($u ?? uzivatel())['role'] ?? '') === 'admin'; }
function smi_upravit_tym(?array $u = null): bool { return in_array(($u ?? uzivatel())['role'] ?? '', ['admin', 'hlavni'], true); }
function vyzaduj_admina(): void { if (!je_admin()) { http_response_code(403); exit('Tuto akci smí jen admin.'); } }
function vyzaduj_tym(): void { if (!smi_upravit_tym()) { http_response_code(403); exit('Tuto akci smí jen admin nebo hlavní pracovník.'); } }
function kdo(): string { return uzivatel()['jmeno'] ?? 'web'; }

function csrf(): string
{
    session_start_safe();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_pole(): string { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }
function over_csrf(): void
{
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Neplatný formulář – obnovte stránku.'); }
}

function flash(?string $msg = null, string $typ = 'ok')
{
    session_start_safe();
    if ($msg !== null) { $_SESSION['flash'][] = [$typ, $msg]; return null; }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}
function presmeruj(string $url): void { header('Location: ' . $url); exit; }

function zapis_log(string $akce, string $detail = ''): void
{
    q('INSERT INTO log(kdo,akce,detail) VALUES(?,?,?)', [kdo(), $akce, $detail]);
}

// ---------- Zákazník: statistiky, štítky, bonus ----------
const STAVY = [
    'zadost' => 'Žádost z webu',
    'potvrzeno' => 'Potvrzeno',
    'probehlo' => 'Proběhlo',
    'neprisel' => 'Nepřišel',
    'zruseno_vcas' => 'Zrušeno včas',
    'zruseno_pozde' => 'Zrušeno pozdě',
    'odmitnuto' => 'Odmítnuto',
];
const PLATBY = [
    '' => '— neurčeno —',
    'hotove' => 'Hotově',
    'karta' => 'Kartou',
    'prevod' => 'Převodem',
    'poukaz' => 'Poukazem',
    'bonus' => 'Bonus – zdarma',
];
const TLAK = ['' => '—', 'jemne' => 'jemně', 'stredne' => 'středně', 'silne' => 'silně'];

/** Atmosféry tematické masérny (projekce na zeď + hudba). Klíč se ukládá k rezervaci (rezervace.atmosfera) a chodí i z webu. */
const ATMOSFERY = [
    'prales'  => ['nazev' => 'Prales',  'en' => 'Rainforest', 'ikona' => '🌿', 'popis' => 'mlha, zeleň a zpěv ptáků'],
    'plaz'    => ['nazev' => 'Pláž',    'en' => 'Beach',      'ikona' => '🏝', 'popis' => 'šum moře při západu slunce'],
    'vodopad' => ['nazev' => 'Vodopád', 'en' => 'Waterfall',  'ikona' => '💧', 'popis' => 'tekoucí voda a klid'],
    'chram'   => ['nazev' => 'Chrám',   'en' => 'Temple',     'ikona' => '🪷', 'popis' => 'lotosy, svíčky a večerní klid'],
];
/** Krátký štítek atmosféry („🌿 Prales“), prázdný pro neznámý klíč. */
function atmosfera_html(?string $klic, bool $en = false): string
{
    $a = ATMOSFERY[(string)$klic] ?? null;
    return $a ? $a['ikona'] . ' ' . h($en ? $a['en'] : $a['nazev']) : '';
}
/** Existuje aspoň jedna tematická masérna? (jinak se výběr atmosféry nikde nenabízí) */
function tematicka_maserna_je(): bool
{
    static $je = null;
    return $je ??= (bool)qv('SELECT 1 FROM mistnosti WHERE aktivni=1 AND tematicka=1 LIMIT 1');
}

/** Proběhlo se nastaví samo jen tam, kde masérka masáž spustila na tabletu (START) – to je důkaz, že klient přišel.
 *  Ostatní potvrzené termíny, které už skončily, zůstanou „ke kontrole“ v přehledu Dnes, aby se nepřišel
 *  zapomenutý klient nepočítal jako návštěva (a nedostal razítko do bonusu). */
function uzavri_probehle(): void
{
    q("UPDATE rezervace SET stav='probehlo', zmeneno=?, zmenil='automaticky (tablet)'
       WHERE stav='potvrzeno' AND konec IS NOT NULL AND konec < ?
         AND EXISTS (SELECT 1 FROM sezeni s WHERE s.rezervace_id=rezervace.id AND s.start IS NOT NULL)", [nyni(), nyni()]);
}

/** Potvrzené termíny, které už skončily a nikdo neoznačil, jestli klient přišel. */
function ke_kontrole(): array
{
    return q("SELECT r.*, z.jmeno, z.prijmeni, z.telefon, s.nazev AS sluzba, v.popis AS var_popis, m.jmeno AS maserka
        FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id LEFT JOIN varianty v ON v.id=r.varianta_id
        LEFT JOIN sluzby s ON s.id=v.sluzba_id LEFT JOIN maserky m ON m.id=r.maserka_id
        WHERE r.stav='potvrzeno' AND r.konec IS NOT NULL AND r.konec < ? ORDER BY r.zacatek", [nyni()])->fetchAll();
}

function zakaznik_statistika(int $id): array
{
    $navstevy = q("SELECT r.*, v.popis AS var_popis, s.nazev AS sluzba, m.jmeno AS maserka
        FROM rezervace r LEFT JOIN varianty v ON v.id=r.varianta_id LEFT JOIN sluzby s ON s.id=v.sluzba_id
        LEFT JOIN maserky m ON m.id=r.maserka_id
        WHERE r.zakaznik_id=? AND r.stav='probehlo' ORDER BY r.zacatek DESC", [$id])->fetchAll();
    $pocet = count($navstevy);
    $posledni = $navstevy[0] ?? null;

    // obvyklý interval mezi návštěvami
    $interval = null;
    if ($pocet >= 2) {
        $t1 = strtotime($navstevy[0]['zacatek']); $t2 = strtotime($navstevy[$pocet - 1]['zacatek']);
        $interval = (int)round(($t1 - $t2) / 86400 / ($pocet - 1));
    }
    $nejSluzba = qv("SELECT s.nazev FROM rezervace r JOIN varianty v ON v.id=r.varianta_id JOIN sluzby s ON s.id=v.sluzba_id
        WHERE r.zakaznik_id=? AND r.stav='probehlo' GROUP BY s.id ORDER BY COUNT(*) DESC LIMIT 1", [$id]);
    $nejMaserka = qv("SELECT m.jmeno FROM rezervace r JOIN maserky m ON m.id=r.maserka_id
        WHERE r.zakaznik_id=? AND r.stav='probehlo' GROUP BY m.id ORDER BY COUNT(*) DESC LIMIT 1", [$id]);
    $neprisel = (int)qv("SELECT COUNT(*) FROM rezervace WHERE zakaznik_id=? AND stav IN ('neprisel','zruseno_pozde')
        AND COALESCE(zacatek,vytvoreno) >= ?", [$id, date('Y-m-d', strtotime('-12 months'))]);
    $za6m = (int)qv("SELECT COUNT(*) FROM rezervace WHERE zakaznik_id=? AND stav='probehlo' AND zacatek >= ?",
        [$id, date('Y-m-d', strtotime('-6 months'))]);

    return compact('navstevy', 'pocet', 'posledni', 'interval', 'nejSluzba', 'nejMaserka', 'neprisel', 'za6m');
}

/** Automatické štítky podle historie + ruční štítky recepce. Vrací [[text, třída, popis], ...] */
function zakaznik_stitky(array $z, array $st): array
{
    $out = [];
    if (($z['zdroj'] ?? '') === 'vzor') $out[] = ['🧪 VZOR', 'vzor', 'vzorový zákazník – smaže se v Nastavení'];
    $prahStaly = (int)nastaveni('prah_staly_navstev', 6);
    $prahSpici = (int)nastaveni('prah_spici_dni', 90);
    $prahNesp = (int)nastaveni('prah_nespolehlivy', 2);
    $dniOdPosledni = $st['posledni'] ? (int)floor((time() - strtotime($st['posledni']['zacatek'])) / 86400) : null;

    if ($st['pocet'] <= 1) $out[] = ['🆕 Nový', 'nove', 'nejvýš 1 návštěva'];
    if ($st['za6m'] >= $prahStaly) $out[] = ['⭐ Stálý', 'staly', "$st[za6m] návštěv za 6 měsíců"];
    elseif ($st['pocet'] >= $prahStaly && $dniOdPosledni !== null && $dniOdPosledni >= $prahSpici)
        $out[] = ['💤 Spící', 'spici', "naposledy před $dniOdPosledni dny"];
    if ($st['neprisel'] >= $prahNesp) $out[] = ['⚠️ Nespolehlivý', 'nesp', "$st[neprisel]× nepřišel / pozdní storno za 12 měsíců"];
    if ($z['volny_termin']) $out[] = ['⏱ Vezme i volný termín', 'volny', $z['volny_kdy']];
    if ($z['blokovany']) $out[] = ['⛔ Blokovaný', 'blok', 'online jen žádost, rozhodne recepce'];
    if (!empty($z['pap_karta_od'])) $out[] = ['📇 Papírová kartička', 'pap', 'po zaplacené masáži orazítkovat kartičku'];
    return $out;
}

/**
 * Razítko za nákup poukazu: dostane ho kupující (podle telefonu objednatele; když v kartotéce není, založí se),
 * a to jednou, jakmile je poukaz zaplacený. Vrací id zákazníka nebo null.
 */
function poukaz_razitko(int $poukazId): ?int
{
    $p = q1('SELECT * FROM poukazy WHERE id=?', [$poukazId]);
    if (!$p || !empty($p['razitko_zak_id']) || !in_array($p['stav'], ['zaplaceno', 'vydano', 'uplatneno'], true)) return $p['razitko_zak_id'] ?? null;
    $tel = telefon_norm((string)$p['objednal_tel']);
    if ($tel === '') return null;
    $jm = preg_split('~\s+~', trim((string)$p['objednal_jmeno']), 2);
    $zid = zakaznik_najdi_nebo_zaloz($tel, $jm[0] ?? '', $jm[1] ?? '', (string)$p['objednal_email']);
    if (!$zid) return null;
    q('UPDATE poukazy SET razitko_zak_id=? WHERE id=? AND razitko_zak_id IS NULL', [$zid, $poukazId]);
    return $zid;
}

/**
 * Vlastní fotky služeb pro web (Nastavení → Ceník → Fotky služeb). Uložené v data/sluzby-foto/<kód>.<jpg|webp|png>,
 * na web jdou přes veřejnou kopii v public/ (v názvu čas změny kvůli mezipaměti). Web je dostane v /api/stav (fotky)
 * a u služby s vlastní fotkou už nepíše „Ilustrační foto“. Prohlížeč fotku před nahráním zmenší (max. 1600 px).
 */
const SLUZBA_FOTO_TYPY = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_PNG => 'png'];

function sluzba_foto_dir(): string { return dirname(cfg()['db_path']) . '/sluzby-foto'; }

function sluzba_foto_soubor(string $kod): ?string
{
    if (!preg_match('~^[a-z0-9-]{1,40}$~', $kod)) return null;
    foreach (SLUZBA_FOTO_TYPY as $ext) { $f = sluzba_foto_dir() . "/$kod.$ext"; if (is_file($f)) return $f; }
    return null;
}

/** Adresa veřejné kopie (relativní, např. /sluzba-foto-tradicni-1794000000.jpg), nebo null. */
function sluzba_foto_url(string $kod): ?string
{
    $f = sluzba_foto_soubor($kod);
    if (!$f) return null;
    $ext = pathinfo($f, PATHINFO_EXTENSION);
    $jmeno = "sluzba-foto-$kod-" . filemtime($f) . ".$ext";
    $pub = dirname(__DIR__) . "/public/$jmeno";
    if (!is_file($pub)) {
        foreach (glob(dirname(__DIR__) . "/public/sluzba-foto-$kod-*") ?: [] as $stary) @unlink($stary);
        if (!@copy($f, $pub)) return null;
    }
    return "/$jmeno";
}

/** Všechny vlastní fotky pro web: [kód služby => absolutní adresa]. */
function sluzby_fotky_web(): array
{
    $o = [];
    foreach (q('SELECT kod FROM sluzby WHERE kod IS NOT NULL AND aktivni=1')->fetchAll(PDO::FETCH_COLUMN) as $kod) {
        $u = sluzba_foto_url((string)$kod);
        if ($u) $o[$kod] = rtrim(cfg()['url'] ?? '', '/') . $u;
    }
    return $o;
}

function sluzba_foto_nahraj(string $kod, array $soubor): ?string
{
    if (!preg_match('~^[a-z0-9-]{1,40}$~', $kod) || !qv('SELECT 1 FROM sluzby WHERE kod=?', [$kod])) return 'Neznámá služba.';
    if (($soubor['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($soubor['tmp_name'])) return 'Fotka se nenahrála (možná je moc velká – zkuste menší).';
    if ($soubor['size'] > 6 * 1024 * 1024) return 'Fotka je větší než 6 MB.';
    $i = @getimagesize($soubor['tmp_name']);
    if (!$i || !isset(SLUZBA_FOTO_TYPY[$i[2]])) return 'Fotka musí být JPG, WebP nebo PNG.';
    if ($i[0] < 600 || $i[1] < 400) return 'Fotka je moc malá – aspoň 600 × 400 px (ideálně na šířku, 3 : 2).';
    if (!is_dir(sluzba_foto_dir()) && !@mkdir(sluzba_foto_dir(), 0775, true)) return 'Nejde vytvořit složku pro fotky.';
    sluzba_foto_smaz($kod);
    $cil = sluzba_foto_dir() . "/$kod." . SLUZBA_FOTO_TYPY[$i[2]];
    if (!move_uploaded_file($soubor['tmp_name'], $cil)) return 'Fotku se nepodařilo uložit.';
    @chmod($cil, 0664);
    sluzba_foto_url($kod);
    return null;
}

function sluzba_foto_smaz(string $kod): void
{
    if (!preg_match('~^[a-z0-9-]{1,40}$~', $kod)) return;
    foreach (SLUZBA_FOTO_TYPY as $ext) @unlink(sluzba_foto_dir() . "/$kod.$ext");
    foreach (glob(dirname(__DIR__) . "/public/sluzba-foto-$kod-*") ?: [] as $stary) @unlink($stary);
}

/** Chce zákazník poukaz poslat e-mailem? / Platí převodem? (texty voleb z webu se ukládají česky) */
function poukaz_chce_email(array $p): bool { return mb_stripos((string)$p['prevzeti'], 'e-mail') !== false; }
function poukaz_platba_prevodem(array $p): bool { return mb_stripos((string)$p['platba'], 'převod') !== false; }

/**
 * Potvrzení objednávky poukazu z webu zákazníkovi (bez poukazu – ten platí až po zaplacení):
 *  - převodem: údaje k platbě, poukaz přijde e-mailem do dvou hodin od připsání platby;
 *  - hotově / vyzvednutí: ať se pro poukaz zastaví na recepci v otevírací době a zaplatí hotově.
 */
function poukaz_email_objednavka(int $pid): void
{
    $p = q1('SELECT * FROM poukazy WHERE id=?', [$pid]);
    if (!$p || !filter_var($p['objednal_email'], FILTER_VALIDATE_EMAIL)) return;
    $popis = trim((string)$p['sluzba_text']) !== '' ? $p['sluzba_text'] . ' (' . kc((int)$p['hodnota']) . ')' : 'na částku ' . kc((int)$p['hodnota']);
    $cislo = $p['vs'] !== '' ? $p['vs'] : (string)$p['id'];
    $r = ['Dobrý den,', '', "děkujeme za objednávku dárkového poukazu č. $cislo – $popis" . (trim((string)$p['pro_koho']) !== '' ? ', pro: ' . trim($p['pro_koho']) : '') . '.', ''];
    $ucet = salon_ucet();
    if (poukaz_platba_prevodem($p) && $ucet !== '') {
        array_push($r, 'Prosíme o úhradu převodem:', "  Účet: $ucet", '  IBAN: ' . ucet_iban($ucet), '  Částka: ' . kc((int)$p['hodnota']), "  Variabilní symbol: $cislo", '',
            poukaz_chce_email($p)
                ? 'Jakmile platba dorazí na náš účet, pošleme vám poukaz e-mailem – obvykle do dvou hodin od připsání platby (převod z jiné banky může trvat až do dalšího pracovního dne).'
                : 'Jakmile platba dorazí na náš účet, poukaz pro vás připravíme na recepci.');
    } else {
        array_push($r, 'Pro poukaz se prosím zastavte na recepci v otevírací době (Po–Pá 9–20 h) a zaplaťte ho v hotovosti – ' . kc((int)$p['hodnota']) . '.',
            "Stačí říct číslo objednávky $cislo.");
    }
    if (!empty($p['platnost_do'])) array_push($r, '', 'Poukaz platí do ' . date('j. n. Y', strtotime((string)$p['platnost_do'])) . ' – do té doby je potřeba se na masáž objednat (telefon 778 429 329).');
    array_push($r, '', 'Děkujeme a těšíme se na vás.', 'Thajské masáže Most', 'SNP 2654/26, 434 01 Most', '+420 778 429 329 · salon@thaimost.cz', 'www.thaimost.cz');
    posli_email($p['objednal_email'], "Thai Most – objednávka dárkového poukazu č. $cislo", implode("\n", $r) . "\n");
}

/**
 * Po spárování platby převodem: poukaz se aktivuje (stav „vydáno“) a když ho zákazník chtěl e-mailem, rovnou se mu pošle.
 * Vrací text do poznámky / logu.
 */
function poukaz_po_zaplaceni(int $pid): string
{
    $p = q1('SELECT * FROM poukazy WHERE id=?', [$pid]);
    if (!$p || $p['stav'] !== 'zaplaceno') return '';
    if (!poukaz_chce_email($p)) return '';
    if (!filter_var($p['objednal_email'], FILTER_VALIDATE_EMAIL)) return 'Poukaz chtěl e-mailem, ale e-mail chybí – pošlete ho ručně.';
    if (!function_exists('poukaz_posli_emailem')) return '';
    [$txt, $typ] = poukaz_posli_emailem($p, $p['objednal_email']);
    if ($typ === 'ok') {
        q("UPDATE poukazy SET stav='vydano', poznamka=TRIM(poznamka || ' ' || ?) WHERE id=? AND stav='zaplaceno'", ['Poukaz odeslán e-mailem ' . date('j. n. Y H:i') . ' na ' . $p['objednal_email'] . ' (po spárování platby) – aktivní.', $pid]);
        return "poukaz odeslán na $p[objednal_email] a aktivován";
    }
    q("UPDATE poukazy SET poznamka=TRIM(poznamka || ' ' || ?) WHERE id=?", ['⚠ Automatické odeslání poukazu se nepovedlo: ' . $txt, $pid]);
    foreach (posta_upozorneni() as $adr) posli_email($adr, "Poukaz $p[kod] se nepodařilo odeslat zákazníkovi", "Platba za poukaz $p[kod] je spárovaná, ale poukaz se nepodařilo poslat na $p[objednal_email]:\n$txt\n\n" . rtrim(cfg()['url'], '/') . "/?r=poukaz&id=$pid\n", null, 'interni');
    return 'odeslání poukazu selhalo: ' . $txt;
}

/** Platby, za které se dává razítko (masáž na poukaz jen když je to v nastavení zapnuté). */
function bonus_platby(): array
{
    $p = ['hotove', 'karta', 'prevod'];
    if (nastaveni('bonus_pocita_poukaz') === '1') $p[] = 'poukaz';
    return $p;
}

/**
 * Papírová věrnostní kartička (starší zákazníci bez internetu): elektronicky víme, kolik razítek na ní má být.
 * Vrací položky od vydání kartičky, které recepce ještě na papír nevyznačila:
 * zaplacené masáže (už začaly / proběhly), koupené dárkové poukazy (razítko kupujícímu) a masáže zdarma (výměna plné kartičky).
 */
function pap_neorazitkovane(array $z): array
{
    if (empty($z['pap_karta_od'])) return [];
    $in = "'" . implode("','", bonus_platby()) . "'";
    $o = [];
    foreach (q("SELECT r.id, r.zacatek, r.platba, s.nazev AS sluzba, v.popis AS var_popis FROM rezervace r
        LEFT JOIN varianty v ON v.id=r.varianta_id LEFT JOIN sluzby s ON s.id=v.sluzba_id
        WHERE r.zakaznik_id=? AND r.pap_razitko IS NULL AND r.zacatek>=? AND r.zacatek<=?
          AND (r.stav='probehlo' OR r.stav='potvrzeno') AND (r.platba IN ($in) OR r.platba='bonus')
        ORDER BY r.zacatek", [$z['id'], $z['pap_karta_od'], nyni()])->fetchAll() as $r)
        $o[] = ['typ' => 'rez', 'id' => (int)$r['id'], 'kdy' => $r['zacatek'], 'zdarma' => $r['platba'] === 'bonus',
                'popis' => trim(($r['sluzba'] ?? 'masáž') . ' ' . ($r['var_popis'] ?? ''))];
    try {
        foreach (q("SELECT id, kod, vytvoreno FROM poukazy WHERE razitko_zak_id=? AND pap_razitko IS NULL
            AND stav IN ('zaplaceno','vydano','uplatneno') AND vytvoreno>=? ORDER BY vytvoreno", [$z['id'], $z['pap_karta_od']])->fetchAll() as $p)
            $o[] = ['typ' => 'pk', 'id' => (int)$p['id'], 'kdy' => $p['vytvoreno'], 'zdarma' => false, 'popis' => 'nákup poukazu ' . $p['kod']];
    } catch (Throwable $e) {}
    usort($o, fn($a, $b) => strcmp($a['kdy'], $b['kdy']));
    return $o;
}

/** Recepce vyznačila položku na papírové kartičce. */
function pap_orazitkuj(string $typ, int $id): void
{
    $t = $typ === 'pk' ? 'poukazy' : 'rezervace';
    q("UPDATE $t SET pap_razitko=? WHERE id=? AND pap_razitko IS NULL", [nyni(), $id]);
}

/** Akce s papírovou kartičkou z karty zákazníka / rezervace (POST akce=pap_*). Vrací true, když akci obsloužila. */
function pap_post(string $zpet): bool
{
    $akce = (string)($_POST['akce'] ?? '');
    if (strncmp($akce, 'pap_', 4) !== 0) return false;
    $zid = (int)($_POST['zid'] ?? 0);
    $z = $zid ? q1('SELECT * FROM zakaznici WHERE id=?', [$zid]) : null;
    if (!$z) { flash('Zákazník nenalezen.', 'err'); presmeruj($zpet); }
    if ($akce === 'pap_zapnout') {
        q('UPDATE zakaznici SET pap_karta_od=? WHERE id=? AND pap_karta_od IS NULL', [nyni(), $zid]);
        if (($_POST['razitka'] ?? '') !== '') q('UPDATE zakaznici SET pocatecni_razitka=? WHERE id=?', [max(0, (int)$_POST['razitka']), $zid]);
        zapis_log('pap_karta', "zákazník #$zid – má papírovou kartičku");
        flash('Zapsáno: zákazník má papírovou kartičku. Od teď vám kartotéka připomene, kdy ji orazítkovat.');
    } elseif ($akce === 'pap_vypnout') {
        q('UPDATE zakaznici SET pap_karta_od=NULL WHERE id=?', [$zid]);
        zapis_log('pap_karta', "zákazník #$zid – papírová kartička zrušena");
        flash('Papírová kartička zrušena (razítka v kartotéce zůstávají).');
    } elseif ($akce === 'pap_razitko') {
        pap_orazitkuj((string)($_POST['typ'] ?? ''), (int)($_POST['id'] ?? 0));
        zapis_log('pap_razitko', "zákazník #$zid");
        flash('Zapsáno – papírová kartička orazítkována.');
    } elseif ($akce === 'pap_vse') {
        $n = 0;
        foreach (pap_neorazitkovane($z) as $x) { pap_orazitkuj($x['typ'], $x['id']); $n++; }
        zapis_log('pap_razitko', "zákazník #$zid – vše ($n)");
        flash("Zapsáno – orazítkováno $n×.");
    }
    presmeruj($zpet);
    return true;
}

/** Kolik razítek má být teď na papírové kartičce + text pro recepci. */
function pap_stav(array $b): array
{
    // plná kartička čeká na masáž zdarma → na kartičce je všech N razítek
    $na = $b['zdarma'] > 0 ? $b['po'] : $b['razitka'];
    return ['na' => $na, 'po' => $b['po'], 'plna' => $b['zdarma'] > 0];
}

/**
 * Bonus: každá N-tá zaplacená masáž zdarma.
 * Věrnostní karta platí nastaveni('bonus_platnost_mesicu') měsíců od poslední zapsané masáže (nebo nákupu poukazu);
 * když zákazník tak dlouho nepřijde, nasbíraná razítka propadnou a karta začíná od nuly (0 = bez expirace).
 */
function zakaznik_bonus(array $z): array
{
    $po = max(1, (int)nastaveni('bonus_po', 5));
    $platnostM = max(0, (int)nastaveni('bonus_platnost_mesicu', 12));
    $in = "'" . implode("','", bonus_platby()) . "'";

    // chronologický seznam bonusových událostí (zaplacené proběhlé masáže + nákupy poukazů)
    $ud = [];
    foreach (q("SELECT zacatek AS kdy FROM rezervace WHERE zakaznik_id=? AND stav='probehlo' AND platba IN ($in)", [$z['id']])->fetchAll() as $r)
        $ud[] = (string)$r['kdy'];
    try {
        foreach (q("SELECT vytvoreno AS kdy FROM poukazy WHERE razitko_zak_id=? AND stav IN ('zaplaceno','vydano','uplatneno')", [$z['id']])->fetchAll() as $p)
            $ud[] = (string)$p['kdy'];
    } catch (Throwable $e) {}
    sort($ud);

    $vyuzite = (int)qv("SELECT COUNT(*) FROM rezervace WHERE zakaznik_id=? AND stav IN ('probehlo','potvrzeno') AND platba='bonus'", [$z['id']]);

    $posledni = $ud ? end($ud) : null;
    $zaplacene = count($ud);     // bez expirace: všechny události
    $resetnuto = false;          // došlo k propadnutí kvůli delší pauze
    $plati_do = null;
    $prosle = false;

    if ($platnostM > 0 && $posledni) {
        // začátek aktuálního „streaku“ = poslední událost, před níž byla pauza delší než platnost
        $start = 0;
        for ($i = 1, $n = count($ud); $i < $n; $i++) {
            if (strtotime($ud[$i]) > strtotime($ud[$i - 1] . " +$platnostM months")) { $start = $i; $resetnuto = true; }
        }
        $zaplacene = count($ud) - $start;                                   // jen události v aktuálním streaku
        $plati_do = date('Y-m-d', strtotime($posledni . " +$platnostM months"));
        if (nyni() > date('Y-m-d H:i:s', strtotime($posledni . " +$platnostM months"))) $prosle = true;
    }

    // papírová razítka počítáme jen pokud nedošlo k propadnutí
    $pocat = $resetnuto ? 0 : (int)$z['pocatecni_razitka'];
    $razitka = $prosle ? 0 : ($pocat + $zaplacene);
    $zdarma = $prosle ? 0 : max(0, intdiv($razitka, $po) - $vyuzite);

    return ['razitka' => $razitka % $po, 'po' => $po, 'zdarma' => $zdarma, 'celkem' => $razitka,
            'prosle' => $prosle, 'posledni' => $posledni, 'plati_do' => $plati_do, 'platnost_m' => $platnostM];
}

function zakaznik_poukazy(int $id): array
{
    return q("SELECT * FROM poukazy WHERE zakaznik_id=? AND stav IN ('zaplaceno','vydano') AND zustatek>0 ORDER BY platnost_do", [$id])->fetchAll();
}

function jmeno_z(array $z): string { return trim(($z['jmeno'] ?? '') . ' ' . ($z['prijmeni'] ?? '')) ?: telefon_cz($z['telefon'] ?? ''); }

/** Najde zákazníka podle telefonu, nebo založí nového. Prázdná jména u existujícího doplní, nepřepisuje. */
function zakaznik_najdi_nebo_zaloz(string $tel, string $jmeno, string $prijmeni, string $email = '', string $zdroj = 'recepce'): int
{
    $z = q1('SELECT * FROM zakaznici WHERE telefon=?', [$tel]);
    if ($z) {
        $upd = [];
        if ($z['jmeno'] === '' && $jmeno !== '') $upd['jmeno'] = $jmeno;
        if ($z['prijmeni'] === '' && $prijmeni !== '') $upd['prijmeni'] = $prijmeni;
        if ($z['email'] === '' && $email !== '') $upd['email'] = $email;
        foreach ($upd as $k => $v) q("UPDATE zakaznici SET $k=? WHERE id=?", [$v, $z['id']]);
        return (int)$z['id'];
    }
    q('INSERT INTO zakaznici(telefon,jmeno,prijmeni,email,zdroj) VALUES(?,?,?,?,?)', [$tel, $jmeno, $prijmeni, $email, $zdroj]);
    return (int)db()->lastInsertId();
}

// ---------- Rezervace ----------
function varianty_select(?int $vybrana = null): string
{
    $rows = q("SELECT v.id, v.popis, v.cena, s.nazev, s.kat FROM varianty v JOIN sluzby s ON s.id=v.sluzba_id
        WHERE v.aktivni=1 AND s.aktivni=1 ORDER BY s.poradi, v.poradi")->fetchAll();
    $o = '<option value="">— vyberte —</option>';
    $kat = null;
    foreach ($rows as $r) {
        if ($r['kat'] !== $kat) { if ($kat !== null) $o .= '</optgroup>'; $kat = $r['kat']; $o .= '<optgroup label="' . ($kat === 'wellness' ? 'Wellness a rituály' : 'Masáže') . '">'; }
        $o .= '<option value="' . $r['id'] . '"' . ($vybrana == $r['id'] ? ' selected' : '') . '>' . h($r['nazev'] . ' – ' . $r['popis'] . ' – ' . kc($r['cena'])) . '</option>';
    }
    return $o . '</optgroup>';
}

/** Kolize: jiná aktivní rezervace, kde je masérka (jako první nebo druhá) ve stejném čase. U párové masérky končí dřív (masaz_konec). */
function prestavka(): int { return max(0, min(60, (int)nastaveni('prestavka_min', '10'))); }

function kolize(int $maserka, string $od, string $do, int $mimoId = 0): array
{
    $pm = prestavka();   // čas na výměnu: masérka musí mít mezi dvěma masážemi aspoň tolik minut
    return q("SELECT r.*, z.jmeno, z.prijmeni, z.telefon, COALESCE(r.masaz_konec, r.konec) AS m_konec FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id
        WHERE (r.maserka_id=? OR r.maserka2_id=?) AND r.stav IN ('potvrzeno','probehlo') AND r.id<>? AND r.zacatek < datetime(?, '+$pm minutes') AND datetime(COALESCE(r.masaz_konec, r.konec), '+$pm minutes') > ?",
        [$maserka, $maserka, $mimoId, $do, $od])->fetchAll();
}

/** Do kdy jsou u rezervace potřeba masérky. */
function rez_masaz_konec(array $r): string { return $r['masaz_konec'] ?: $r['konec']; }

function rezervace_detail(int $id): ?array
{
    return q1("SELECT r.*, v.popis AS var_popis, v.minuty, v.cena AS var_cena, v.maserky AS var_maserky, v.maserky_min, v.wellness_mistnost,
        s.nazev AS sluzba, m.jmeno AS maserka, m2.jmeno AS maserka2, z.jmeno, z.prijmeni, z.telefon, z.email
        FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id
        LEFT JOIN varianty v ON v.id=r.varianta_id LEFT JOIN sluzby s ON s.id=v.sluzba_id
        LEFT JOIN maserky m ON m.id=r.maserka_id LEFT JOIN maserky m2 ON m2.id=r.maserka2_id WHERE r.id=?", [$id]);
}

// ---------- Poukazy ----------
function poukaz_kod(): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $k = 'TM-' . date('y') . '-';
        for ($i = 0; $i < 5; $i++) $k .= $abc[random_int(0, strlen($abc) - 1)];
    } while (qv('SELECT 1 FROM poukazy WHERE kod=?', [$k]));
    return $k;
}
/** Hledání poukazu podle kódu bez ohledu na pomlčky, mezery a velikost písmen („tm 0123“ = „TM-0123“). Vrací řádek nebo null. */
function poukaz_podle_kodu(string $k): ?array
{
    $klic = preg_replace('~[^A-Z0-9]~', '', strtoupper($k));
    if ($klic === '') return null;
    return q1("SELECT * FROM poukazy WHERE REPLACE(REPLACE(REPLACE(REPLACE(UPPER(kod),'-',''),' ',''),'/',''),'.','')=? ORDER BY id LIMIT 1", [$klic]);
}

/** Kód poukazu tak, jak se ukládá a hledá: velká písmena, bez mezer (i ručně opsané kódy z vytištěných poukazů). */
function poukaz_kod_norm(string $k): string
{
    return mb_substr(preg_replace('~[^A-Z0-9/._-]~', '', strtoupper(preg_replace('~\s+~', '', $k))), 0, 40);
}
const STAVY_POUKAZU = [
    'objednano' => 'Objednáno (čeká na platbu)',
    'zaplaceno' => 'Zaplaceno',
    'vydano' => 'Aktivní (vydáno)',
    'uplatneno' => 'Uplatněno',
    'propadle' => 'Propadlé',
    'zruseno' => 'Zrušeno',
];

// ---------- E-maily ----------
require_once __DIR__ . '/posta.php';
require_once __DIR__ . '/platby.php';

/**
 * Zařadí e-mail do fronty a (je-li účet nastavený) hned ho odešle. Vrací číslo ve frontě, 0 = neplatná adresa.
 * $ucet: 'zakaznici' = komunikace se zákazníky, 'interni' = ostatní (upozornění pro salon, směny…). Účty: Správa → Pošta.
 */
function posli_email(string $komu, string $predmet, string $text, ?array $priloha = null, string $ucet = 'zakaznici'): int
{
    if (!filter_var($komu, FILTER_VALIDATE_EMAIL)) return 0;
    if (!isset(POSTA_UCTY[$ucet])) $ucet = 'zakaznici';
    q('INSERT INTO emaily(komu,predmet,text,priloha_nazev,priloha_mime,priloha_obsah,ucet) VALUES(?,?,?,?,?,?,?)',
        [$komu, $predmet, $text, $priloha['nazev'] ?? '', $priloha['mime'] ?? '', $priloha['obsah'] ?? '', $ucet]);
    $id = (int)db()->lastInsertId();
    odesli_frontu($id);
    return $id;
}

function odesli_frontu(?int $jenId = null): void
{
    $rows = $jenId ? q("SELECT * FROM emaily WHERE id=? AND stav<>'odeslano'", [$jenId])->fetchAll()
                   : q("SELECT * FROM emaily WHERE stav<>'odeslano' ORDER BY id LIMIT 20")->fetchAll();
    if (!$rows) return;
    require_once __DIR__ . '/smtp.php';
    foreach ($rows as $e) {
        $smtp = posta_ucet($e['ucet'] ?? 'zakaznici');
        if ($smtp['host'] === '') continue; // účet nenastavený – e-mail čeká ve frontě
        try {
            smtp_send($smtp, $e['komu'], $e['predmet'], $e['text'],
                $e['priloha_obsah'] !== '' ? ['nazev' => $e['priloha_nazev'], 'mime' => $e['priloha_mime'], 'obsah' => $e['priloha_obsah']] : null);
            q("UPDATE emaily SET stav='odeslano', odeslano=?, chyba='' WHERE id=?", [nyni(), $e['id']]);
        } catch (Throwable $ex) {
            q("UPDATE emaily SET stav='chyba', chyba=? WHERE id=?", [mb_substr($ex->getMessage(), 0, 300), $e['id']]);
        }
    }
}

// ---------- Ochrana veřejného API ----------
function limit_ip(string $akce, int $max): bool
{
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $okno = date('Y-m-d H');
    $klic = $akce . ':' . hash('sha256', $ip . $okno . __FILE__);
    q("DELETE FROM limit_ip WHERE okno < ?", [date('Y-m-d H', strtotime('-2 hours'))]);
    $r = q1('SELECT pocet FROM limit_ip WHERE klic=?', [$klic]);
    if ($r && $r['pocet'] >= $max) return false;
    if ($r) q('UPDATE limit_ip SET pocet=pocet+1 WHERE klic=?', [$klic]);
    else q('INSERT INTO limit_ip(klic,pocet,okno) VALUES(?,1,?)', [$klic, $okno]);
    return true;
}
