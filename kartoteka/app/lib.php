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
const TRVALE_DNI = 60;

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
    return $out;
}

/** Bonus: každá N-tá zaplacená masáž zdarma. */
function zakaznik_bonus(array $z): array
{
    $po = max(1, (int)nastaveni('bonus_po', 5));
    $platby = ['hotove', 'karta', 'prevod'];
    if (nastaveni('bonus_pocita_poukaz') === '1') $platby[] = 'poukaz';
    $in = "'" . implode("','", $platby) . "'";
    $zaplacene = (int)qv("SELECT COUNT(*) FROM rezervace WHERE zakaznik_id=? AND stav='probehlo' AND platba IN ($in)", [$z['id']]);
    $vyuzite = (int)qv("SELECT COUNT(*) FROM rezervace WHERE zakaznik_id=? AND stav IN ('probehlo','potvrzeno') AND platba='bonus'", [$z['id']]);
    $razitka = (int)$z['pocatecni_razitka'] + $zaplacene;
    $zdarma = max(0, intdiv($razitka, $po) - $vyuzite);
    return ['razitka' => $razitka % $po, 'po' => $po, 'zdarma' => $zdarma, 'celkem' => $razitka];
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
function prestavka(): int { return max(0, min(60, (int)nastaveni('prestavka_min', '0'))); }

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
const STAVY_POUKAZU = [
    'objednano' => 'Objednáno (čeká na platbu)',
    'zaplaceno' => 'Zaplaceno',
    'vydano' => 'Vydáno',
    'uplatneno' => 'Uplatněno',
    'propadle' => 'Propadlé',
    'zruseno' => 'Zrušeno',
];

// ---------- E-maily ----------
function posli_email(string $komu, string $predmet, string $text, ?array $priloha = null): void
{
    if (!filter_var($komu, FILTER_VALIDATE_EMAIL)) return;
    q('INSERT INTO emaily(komu,predmet,text,priloha_nazev,priloha_mime,priloha_obsah) VALUES(?,?,?,?,?,?)',
        [$komu, $predmet, $text, $priloha['nazev'] ?? '', $priloha['mime'] ?? '', $priloha['obsah'] ?? '']);
    $id = (int)db()->lastInsertId();
    odesli_frontu($id);
}

function odesli_frontu(?int $jenId = null): void
{
    $smtp = cfg()['smtp'] ?? [];
    if (empty($smtp['host'])) return; // bez SMTP jen uloženo
    require_once __DIR__ . '/smtp.php';
    $rows = $jenId ? q("SELECT * FROM emaily WHERE id=? AND stav<>'odeslano'", [$jenId])->fetchAll()
                   : q("SELECT * FROM emaily WHERE stav<>'odeslano' ORDER BY id LIMIT 20")->fetchAll();
    foreach ($rows as $e) {
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
