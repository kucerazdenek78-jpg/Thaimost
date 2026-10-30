<?php
declare(strict_types=1);

/**
 * Google kontakty: zákazníci z kartotéky se sami objeví v kontaktech telefonu (Google účet salonu), takže při hovoru
 * je vidět, kdo volá – a se značkami přímo ve jménu (⭐ stálý, 🎁 poukaz, 🎉 masáž zdarma, ⏱ vezme volný termín, masérka …).
 *
 * Jak to funguje:
 *  - Salon si v Google Cloud vytvoří vlastního „klienta OAuth“ (jednorázově, návod je na stránce /?r=google) a v kartotéce ho
 *    připojí ke svému Google účtu (Správa → Google kontakty). Kartotéka dostane od Googlu jen oprávnění ke kontaktům.
 *  - Kontakty se zakládají přes Google People API do skupiny „Thai Most“. Nikdy se nemažou a nikdy se nepřepisují cizí
 *    kontakty bez vědomí obsluhy: kdo už v Googlu s tím číslem je, ukáže se v náhledu a obsluha zaškrtne, jestli se přepíše.
 *  - Nejdřív vždy náhled změn, teprve potom zápis. Až po první ruční synchronizaci jde zapnout automatika (kontroluje se
 *    nejvýš jednou za 5 minut, pokud je někdo v kartotéce přihlášený; Google se volá jen když je co měnit).
 *  - Do telefonu jdou jen jméno, telefon a krátká poznámka (návštěvy, masérka, razítka, poukaz). Žádné zdravotní údaje,
 *    interní poznámky ani preference masáží.
 *  - Přihlašovací údaje (Client Secret, tokeny) jsou v samostatné tabulce google_nast a nikdy se nezobrazují.
 */

const GK_SKUPINA = 'Thai Most';
const GK_SCOPE = 'openid email https://www.googleapis.com/auth/contacts';
const GK_ZNACKA_ZACATEK = '▶ Thai Most kartotéka';
const GK_ZNACKA_KONEC = '◀ konec zápisu z kartotéky';
const GK_AUTO_INTERVAL = 300;         // automatická kontrola nejvýš jednou za tolik sekund
const GK_CIZI_DNI = 1;                 // jak dlouho automatika věří, že k číslu zákazníka patří cizí kontakt (pak se podívá znovu)
const GK_SMAZANO = 'smazano';          // značka v google_kontakty.etag: kontakt už v Googlu není (smazal ho člověk)
const GK_ZNACKY = [                    // pořadí = pořadí ve jménu kontaktu
    'blok' => ['⛔', 'blokovaný zákazník'],
    'nesp' => ['⚠️', 'nespolehlivý (často nepřijde)'],
    'staly' => ['⭐', 'stálý zákazník'],
    'nove' => ['🆕', 'nový zákazník (nejvýš 1 návštěva)'],
    'spici' => ['💤', 'spící (stálý, ale dlouho nepřišel)'],
    'poukaz' => ['🎁', 'má platný dárkový poukaz'],
    'zdarma' => ['🎉', 'má nárok na masáž zdarma (věrnostní razítka)'],
    'volny' => ['⏱', 'vezme i volný termín'],
];

class GkChyba extends RuntimeException
{
    public int $http = 0;
    public bool $pripojit = false;     // účet je potřeba připojit znovu
}

// ---------- nastavení (tabulka google_nast, mimo běžné „nastaveni“, aby tajné údaje nikam omylem neprosákly) ----------

function gk_nast(string $k, string $def = ''): string
{
    $v = qv('SELECT hodnota FROM google_nast WHERE klic=?', [$k]);
    return $v === null ? $def : (string)$v;
}

function gk_uloz(string $k, ?string $v): void
{
    if ($v === null) q('DELETE FROM google_nast WHERE klic=?', [$k]);
    else q('INSERT INTO google_nast(klic,hodnota) VALUES(?,?) ON CONFLICT(klic) DO UPDATE SET hodnota=excluded.hodnota', [$k, $v]);
}

function gk_nastaveno(): bool { return gk_nast('client_id') !== '' && gk_nast('client_secret') !== ''; }
function gk_pripojeno(): bool { return gk_nast('refresh_token') !== ''; }
function gk_redirect_uri(): string { return rtrim((string)(cfg()['url'] ?? ''), '/') . '/google-zpet'; }

/** Adresy Googlu. Jen pro automatické testy lze v config.php přepsat klíčem google_test (simulovaný server). */
function gk_url(string $co): string
{
    $zaklad = ['auth' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token',
               'revoke' => 'https://oauth2.googleapis.com/revoke', 'api' => 'https://people.googleapis.com/v1/'];
    $t = cfg()['google_test'] ?? null;
    return is_array($t) && isset($t[$co]) ? (string)$t[$co] : $zaklad[$co];
}

function gk_query(array $p): string
{
    $o = [];
    foreach ($p as $k => $v) foreach ((array)$v as $x) $o[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$x);
    return implode('&', $o);
}

// ---------- HTTP ----------

/** @return array{0:int,1:string} [HTTP kód, tělo] */
function gk_http(string $metoda, string $url, array $hlavicky = [], ?string $telo = null, int $timeout = 30): array
{
    $hl = array_merge(['Accept: application/json', 'User-Agent: kartoteka-thaimost'], $hlavicky);
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hl,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false]);
        if ($telo !== null) curl_setopt($c, CURLOPT_POSTFIELDS, $telo);
        $odp = curl_exec($c);
        $kod = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $chyba = curl_error($c);
        curl_close($c);
        if ($odp === false) throw new GkChyba('Spojení s Googlem se nepovedlo (' . ($chyba ?: 'bez odpovědi') . ').');
        return [$kod, (string)$odp];
    }
    $ctx = stream_context_create(['http' => ['method' => $metoda, 'header' => implode("\r\n", $hl), 'content' => $telo ?? '',
        'timeout' => $timeout, 'ignore_errors' => true]]);
    $odp = @file_get_contents($url, false, $ctx);
    if ($odp === false) throw new GkChyba('Spojení s Googlem se nepovedlo.');
    $hlavy = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $kod = 0;
    foreach ($hlavy as $r) if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string)$r, $m)) $kod = (int)$m[1];
    return [$kod, $odp];
}

function gk_chyba_text(int $kod, string $zprava, string $stav = ''): string
{
    if ($kod === 401) return 'Google odmítl přihlášení (účet je potřeba připojit znovu).';
    if (stripos($zprava, 'has not been used') !== false || stripos($zprava, 'is disabled') !== false || stripos($stav, 'SERVICE_DISABLED') !== false
        || stripos($zprava, 'accessNotConfigured') !== false)
        return 'V Google Cloud projektu není zapnuté „People API“ (API a služby → Knihovna → People API → Povolit).';
    if ($kod === 403 && stripos($zprava, 'insufficient') !== false) return 'Google účet nedal kartotéce oprávnění ke kontaktům – připojte účet znovu a oprávnění ke kontaktům nechte zaškrtnuté.';
    if ($kod === 429) return 'Google dočasně omezil počet požadavků – zkuste to za pár minut.';
    return 'Google vrátil chybu ' . $kod . ($zprava !== '' ? ': ' . mb_substr($zprava, 0, 220) : '.');
}

/** Volání Google People API s platným přístupovým tokenem. */
function gk_api(string $metoda, string $cesta, ?array $telo = null, array $dotaz = [], int $timeout = 30): array
{
    $url = gk_url('api') . $cesta . ($dotaz ? '?' . gk_query($dotaz) : '');
    $hl = ['Authorization: Bearer ' . gk_token()];
    if ($telo !== null) $hl[] = 'Content-Type: application/json; charset=utf-8';
    [$kod, $odp] = gk_http($metoda, $url, $hl, $telo !== null ? json_encode($telo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null, $timeout);
    $j = json_decode($odp, true);
    if ($kod >= 200 && $kod < 300) return is_array($j) ? $j : [];
    $e = new GkChyba(gk_chyba_text($kod, is_array($j) ? (string)($j['error']['message'] ?? '') : '', is_array($j) ? (string)($j['error']['status'] ?? '') : ''));
    $e->http = $kod;
    if ($kod === 401) { gk_uloz('access_do', '0'); $e->pripojit = true; }
    throw $e;
}

// ---------- přihlášení (OAuth 2.0, webový server) ----------

function gk_auth_url(string $state): string
{
    $q = ['client_id' => gk_nast('client_id'), 'redirect_uri' => gk_redirect_uri(), 'response_type' => 'code', 'scope' => GK_SCOPE,
          'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state];
    if (gk_nast('ucet') !== '') $q['login_hint'] = gk_nast('ucet');
    return gk_url('auth') . '?' . gk_query($q);
}

function gk_token_pozadavek(array $pole): array
{
    $pole += ['client_id' => gk_nast('client_id'), 'client_secret' => gk_nast('client_secret')];
    [$kod, $odp] = gk_http('POST', gk_url('token'), ['Content-Type: application/x-www-form-urlencoded'], gk_query($pole), 20);
    $j = json_decode($odp, true);
    return [$kod, is_array($j) ? $j : []];
}

/** Platný přístupový token; podle potřeby ho obnoví z uloženého obnovovacího tokenu. */
function gk_token(): string
{
    $t = gk_nast('access_token');
    if ($t !== '' && (int)gk_nast('access_do', '0') > time() + 60) return $t;
    $rt = gk_nast('refresh_token');
    if ($rt === '') { $e = new GkChyba('Google účet není připojený.'); $e->pripojit = true; throw $e; }
    [$kod, $j] = gk_token_pozadavek(['refresh_token' => $rt, 'grant_type' => 'refresh_token']);
    if ($kod === 200 && !empty($j['access_token'])) {
        gk_uloz('access_token', (string)$j['access_token']);
        gk_uloz('access_do', (string)(time() + max(120, (int)($j['expires_in'] ?? 3600))));
        return (string)$j['access_token'];
    }
    $chyba = (string)($j['error'] ?? '');
    if (in_array($chyba, ['invalid_grant', 'invalid_client', 'unauthorized_client'], true)) {
        foreach (['access_token', 'access_do', 'refresh_token'] as $k) gk_uloz($k, null);
        gk_uloz('spojeni', 'vyprselo');
        $e = new GkChyba('Připojení ke Google účtu vypršelo nebo bylo zrušeno – připojte účet znovu.');
        $e->pripojit = true;
        throw $e;
    }
    throw new GkChyba('Google nevydal přístup (' . ($chyba !== '' ? $chyba : 'HTTP ' . $kod) . '), zkuste to později.');
}

/** E-mail z id_tokenu, který kartotéka dostala přímo od Googlu přes HTTPS (podpis se proto neověřuje). */
function gk_email_z_tokenu(string $jwt): string
{
    $p = explode('.', $jwt);
    if (count($p) !== 3) return '';
    $b = strtr($p[1], '-_', '+/');
    $j = json_decode((string)base64_decode($b . str_repeat('=', (4 - strlen($b) % 4) % 4)), true);
    return is_array($j) && !empty($j['email']) ? mb_strtolower(trim((string)$j['email'])) : '';
}

/** Odpojí účet (a pokud jde, zruší oprávnění u Googlu). Kontakty v telefonu zůstanou. */
function gk_odpoj(bool $zrusit): void
{
    $rt = gk_nast('refresh_token');
    if ($zrusit && $rt !== '') {
        try { gk_http('POST', gk_url('revoke'), ['Content-Type: application/x-www-form-urlencoded'], gk_query(['token' => $rt]), 10); } catch (Throwable $e) {}
    }
    foreach (['access_token', 'access_do', 'refresh_token', 'spojeni', 'auto', 'skupina'] as $k) gk_uloz($k, null);
}

/** Smaže vazby zákazník ↔ kontakt (když se změní Google účet, resource_name z jiného účtu už neplatí). */
function gk_zapomen_vazby(): void
{
    q('DELETE FROM google_kontakty');
    foreach (['prvni_sync', 'posledni_sync', 'posledni_vysledek', 'posledni_chyba', 'posledni_kontrola', 'skupina', 'cizi', 'cizi_cas'] as $k) gk_uloz($k, null);
    gk_uloz('auto', '0');
}

/** Návrat z přihlášení ke Googlu (adresa /google-zpet). */
function stranka_google_zpet(array $u): void
{
    vyzaduj_admina();
    session_start_safe();
    $cekany = (string)($_SESSION['gk_state'] ?? ''); $cas = (int)($_SESSION['gk_state_cas'] ?? 0);
    unset($_SESSION['gk_state'], $_SESSION['gk_state_cas']);
    $state = (string)($_GET['state'] ?? '');
    if ($cekany === '' || !hash_equals($cekany, $state) || $cas < time() - 900) {
        flash('Přihlášení ke Googlu nesedí (vypršelo, nebo bylo otevřené jinde). Klepněte znovu na „Připojit Google účet“.', 'err');
        presmeruj('/?r=google');
    }
    if (isset($_GET['error'])) {
        $chyba = (string)preg_replace('~[^\w .,-]~u', '', (string)$_GET['error']);
        flash($chyba === 'access_denied' ? 'Přístup ke kontaktům jste nepovolil/a, účet se nepřipojil.' : 'Google přihlášení odmítl (' . mb_substr($chyba, 0, 60) . ').', 'err');
        presmeruj('/?r=google');
    }
    $code = (string)($_GET['code'] ?? '');
    if ($code === '') { flash('Google nevrátil kód pro přihlášení.', 'err'); presmeruj('/?r=google'); }
    try {
        [$kod, $j] = gk_token_pozadavek(['code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => gk_redirect_uri()]);
    } catch (GkChyba $e) { flash($e->getMessage(), 'err'); presmeruj('/?r=google'); }
    if ($kod !== 200 || empty($j['access_token'])) {
        $d = (string)($j['error_description'] ?? $j['error'] ?? 'HTTP ' . $kod);
        flash('Google nevydal přístup: ' . mb_substr($d, 0, 160) . ($d === 'invalid_client' || str_contains($d, 'client') ? ' – zkontrolujte Client ID a Client Secret.' : ''), 'err');
        presmeruj('/?r=google');
    }
    $tok = (string)$j['access_token']; $rt = (string)($j['refresh_token'] ?? '');
    $email = gk_email_z_tokenu((string)($j['id_token'] ?? ''));
    $zrusit = function () use ($tok, $rt) { try { gk_http('POST', gk_url('revoke'), ['Content-Type: application/x-www-form-urlencoded'], gk_query(['token' => $rt !== '' ? $rt : $tok]), 10); } catch (Throwable $e) {} };
    if (!str_contains((string)($j['scope'] ?? ''), 'auth/contacts')) {
        $zrusit(); flash('Chybí oprávnění ke kontaktům. Při přihlášení nechte zaškrtnuté „Zobrazit, upravovat, stahovat a mazat vaše kontakty“.', 'err'); presmeruj('/?r=google');
    }
    if ($rt === '') { $zrusit(); flash('Google nevydal trvalé oprávnění. Zkuste to znovu (nejdřív odeberte aplikaci v myaccount.google.com → Zabezpečení → Přístup třetích stran).', 'err'); presmeruj('/?r=google'); }
    $ocekavany = mb_strtolower(gk_nast('ucet'));
    if ($email === '') { $zrusit(); flash('Google nesdělil, ke kterému účtu jste přihlášeni, proto se účet nepřipojil.', 'err'); presmeruj('/?r=google'); }
    if ($ocekavany !== '' && $email !== $ocekavany) {
        $zrusit();
        flash("Přihlásili jste se jako $email, ale v nastavení je účet $ocekavany. Nic se nepřipojilo, aby se kontakty nezapsaly do špatného účtu. Přihlaste se správným účtem, nebo účet v okénku opravte.", 'err');
        presmeruj('/?r=google');
    }
    $drive = gk_nast('ucet_overeny');
    if ($drive !== '' && $drive !== $email) { gk_zapomen_vazby(); flash('Účet se změnil, dřívější vazby na kontakty z jiného účtu jsem zapomněl/a.', 'warn'); }
    gk_uloz('refresh_token', $rt);
    gk_uloz('access_token', $tok);
    gk_uloz('access_do', (string)(time() + max(120, (int)($j['expires_in'] ?? 3600))));
    gk_uloz('ucet_overeny', $email);
    gk_uloz('spojeni', 'ok');
    if ($ocekavany === '') gk_uloz('ucet', $email);
    zapis_log('google_pripojeno', $email);
    flash("Google účet $email je připojený. Teď si v „Náhledu změn“ zkontrolujte, co se zapíše do kontaktů.");
    presmeruj('/?r=google');
}

// ---------- data zákazníka → kontakt ----------

function gk_norm(string $s): string
{
    $s = strtr(mb_strtolower($s, 'UTF-8'), ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r',
        'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ł' => 'l']);
    return (string)preg_replace('~[^a-z]~', '', $s);
}

function gk_datum(?string $d): string
{
    $t = $d ? strtotime($d) : false;
    return $t ? date('j. n. Y', $t) : '';
}

function gk_maserka_jmeno(?int $id): string
{
    static $m = null;
    $m ??= q('SELECT id, jmeno FROM maserky')->fetchAll(PDO::FETCH_KEY_PAIR);
    return $id ? (string)($m[$id] ?? '') : '';
}

/** Zákazníci, kteří se posílají do telefonu (vzorová data a zákazníci bez platného čísla ne). */
function gk_zakaznici(): array
{
    return q("SELECT * FROM zakaznici WHERE zdroj <> 'vzor' ORDER BY prijmeni, jmeno, id")->fetchAll();
}

/**
 * Jméno, značky a poznámka kontaktu. Značky se berou ze stejných pravidel jako štítky v kartotéce (zakaznik_stitky).
 * @return array{jmeno:string,znacky:array,note:string,hash:string}
 */
function gk_kontakt_data(array $z, string $tel, bool $masNaJmeno): array
{
    $st = zakaznik_statistika((int)$z['id']);
    $stitky = array_column(zakaznik_stitky($z, $st), 1);
    $bonus = zakaznik_bonus($z);
    $dnes = date('Y-m-d');
    $poukazy = array_values(array_filter(zakaznik_poukazy((int)$z['id']), fn($p) => empty($p['platnost_do']) || substr((string)$p['platnost_do'], 0, 10) >= $dnes));

    $znacky = [];
    foreach (['blok', 'nesp', 'staly', 'nove', 'spici'] as $k) if (in_array($k, $stitky, true)) $znacky[] = $k;
    if ($poukazy) $znacky[] = 'poukaz';
    if ($bonus['zdarma'] > 0) $znacky[] = 'zdarma';
    if (in_array('volny', $stitky, true)) $znacky[] = 'volny';

    $posledni = $st['posledni'];
    $obl = gk_maserka_jmeno($z['pref_maserka_id'] !== null ? (int)$z['pref_maserka_id'] : null);
    $posMas = (string)($posledni['maserka'] ?? '');
    $maserka = $obl !== '' ? $obl : $posMas;

    $cele = trim(trim((string)$z['jmeno']) . ' ' . trim((string)$z['prijmeni']));
    if ($cele === '') $cele = telefon_cz($tel);
    $sym = implode('', array_map(fn($k) => GK_ZNACKY[$k][0], $znacky));
    $jmeno = $cele . ($sym !== '' ? ' ' . $sym : '') . ($masNaJmeno && $maserka !== '' ? ' · ' . $maserka : '');

    $r = [GK_ZNACKA_ZACATEK . ' (doplňuje se samo, úpravy mezi značkami se přepíší)'];
    if ($znacky) {
        $popis = array_map(fn($k) => GK_ZNACKY[$k][1], $znacky);
        if (in_array('volny', $znacky, true) && trim((string)$z['volny_kdy']) !== '') $popis[count($popis) - 1] .= ' – ' . mb_substr(trim((string)$z['volny_kdy']), 0, 80);
        $r[] = 'Značky: ' . implode(', ', $popis);
    }
    if ($st['pocet'] > 0 && $posledni) {
        $sluzba = trim((string)($posledni['sluzba'] ?? '') . ' ' . (string)($posledni['var_popis'] ?? ''));
        $r[] = 'Návštěv: ' . $st['pocet'] . ' · poslední ' . gk_datum($posledni['zacatek']) . ($sluzba !== '' ? ' (' . $sluzba . ')' : '');
    } else $r[] = 'Návštěv zatím žádná.';
    $mas = [];
    if ($obl !== '') $mas[] = 'oblíbená ' . $obl;
    if ($posMas !== '') $mas[] = 'posledně ' . $posMas;
    if (!$mas && $st['nejMaserka']) $mas[] = 'nejčastěji ' . $st['nejMaserka'];
    if ($mas) $r[] = 'Masérka: ' . implode(', ', $mas);
    $r[] = 'Razítka: ' . $bonus['razitka'] . '/' . $bonus['po'] . ($bonus['zdarma'] > 0 ? ' · na účtě ' . ($bonus['zdarma'] > 1 ? $bonus['zdarma'] . '× ' : '') . 'masáž zdarma' : '');
    foreach (array_slice($poukazy, 0, 3) as $p)
        $r[] = 'Poukaz: zbývá ' . kc($p['zustatek']) . (!empty($p['platnost_do']) ? ', platí do ' . gk_datum((string)$p['platnost_do']) : '');
    if (count($poukazy) > 3) $r[] = 'Poukazů je víc (' . count($poukazy) . ').';
    $r[] = GK_ZNACKA_KONEC;
    $note = implode("\n", $r);

    return ['jmeno' => $jmeno, 'znacky' => $znacky, 'note' => $note, 'hash' => sha1(json_encode([$jmeno, $tel, $note], JSON_UNESCAPED_UNICODE))];
}

/** Vloží blok z kartotéky do poznámky kontaktu; text, který tam napsal člověk mimo značky, zůstane. */
function gk_vloz_blok(string $puvodni, string $blok): string
{
    $vz = '~' . preg_quote(GK_ZNACKA_ZACATEK, '~') . '.*?' . preg_quote(GK_ZNACKA_KONEC, '~') . '~su';
    if (preg_match($vz, $puvodni)) return (string)preg_replace_callback($vz, fn() => $blok, $puvodni, 1);
    $puvodni = trim($puvodni);
    return $puvodni === '' ? $blok : $blok . "\n\n" . $puvodni;
}

function gk_osoba(array $p): array
{
    return ['names' => [['givenName' => $p['jmeno']]],
            'phoneNumbers' => [['value' => $p['telefon'], 'type' => 'mobile']],
            'biographies' => [['value' => $p['note'], 'contentType' => 'TEXT_PLAIN']]];
}

/** Z existujícího kontaktu (podle batchGet) udělá tělo aktualizace: jméno a poznámkový blok se nahradí, cizí telefony zůstanou. */
function gk_uprav_osobu(array $o, array $p): array
{
    $tel = $o['phoneNumbers'] ?? [];
    $ma = false;
    foreach ($tel as $ph) if (telefon_norm((string)($ph['canonicalForm'] ?? $ph['value'] ?? '')) === $p['telefon']) $ma = true;
    if (!$ma) $tel[] = ['value' => $p['telefon'], 'type' => 'mobile'];
    return ['resourceName' => $o['resourceName'], 'etag' => $o['etag'] ?? '', 'metadata' => ['sources' => $o['metadata']['sources'] ?? []],
            'names' => [['givenName' => $p['jmeno']]], 'phoneNumbers' => $tel,
            'biographies' => [['value' => gk_vloz_blok((string)($o['biographies'][0]['value'] ?? ''), $p['note']), 'contentType' => 'TEXT_PLAIN']]];
}

// ---------- načtení kontaktů z Googlu ----------

/** Všechny kontakty účtu: ['res' => [resourceName => ['jmeno','tel'=>[...]]], 'tel' => [telefon => resourceName]] */
function gk_nacti_kontakty(): array
{
    $res = []; $tel = []; $dalsi = null;
    for ($i = 0; $i < 60; $i++) {
        $d = gk_api('GET', 'people/me/connections', null, array_filter(['personFields' => 'names,phoneNumbers,metadata', 'pageSize' => 1000, 'pageToken' => $dalsi]), 60);
        foreach ($d['connections'] ?? [] as $o) {
            $rn = (string)($o['resourceName'] ?? '');
            if ($rn === '' || !empty($o['metadata']['deleted'])) continue;
            $n = $o['names'][0] ?? [];
            $jm = trim((string)($n['displayName'] ?? '')) ?: trim(($n['givenName'] ?? '') . ' ' . ($n['familyName'] ?? ''));
            $t = [];
            foreach ($o['phoneNumbers'] ?? [] as $ph) { $x = telefon_norm((string)($ph['canonicalForm'] ?? $ph['value'] ?? '')); if ($x !== '') { $t[] = $x; $tel[$x] ??= $rn; } }
            $res[$rn] = ['jmeno' => $jm, 'tel' => $t];
        }
        $dalsi = $d['nextPageToken'] ?? null;
        if (!$dalsi) break;
    }
    return ['res' => $res, 'tel' => $tel];
}

/** Aktuální podoba kontaktů (kvůli etagu) po dávkách; chybějící / smazané se ve výsledku nevyskytnou. */
function gk_nacti_osoby(array $rns): array
{
    $out = [];
    foreach (array_chunk($rns, 100) as $c) {
        $d = gk_api('GET', 'people:batchGet', null, ['resourceNames' => $c, 'personFields' => 'names,phoneNumbers,biographies,metadata'], 60);
        foreach ($d['responses'] ?? [] as $r) {
            $o = $r['person'] ?? null;
            if (!is_array($o) || !empty($r['status']['code']) || (($r['httpStatusCode'] ?? 200) >= 300) || !empty($o['metadata']['deleted'])) continue;
            $out[(string)($o['resourceName'] ?? $r['requestedResourceName'] ?? '')] = $o;
        }
    }
    return $out;
}

/** Skupina „Thai Most“ (založí se, když chybí). */
function gk_skupina(): string
{
    static $s = null;
    if ($s !== null) return $s;
    $ulozena = gk_nast('skupina'); $nalezena = ''; $dalsi = null;
    for ($i = 0; $i < 20; $i++) {
        $d = gk_api('GET', 'contactGroups', null, array_filter(['pageSize' => 200, 'groupFields' => 'name,groupType', 'pageToken' => $dalsi]));
        foreach ($d['contactGroups'] ?? [] as $g) {
            if (($g['groupType'] ?? '') !== 'USER_CONTACT_GROUP') continue;
            if ((string)($g['resourceName'] ?? '') === $ulozena) { $nalezena = $ulozena; break 2; }
            if ($nalezena === '' && ($g['name'] ?? '') === GK_SKUPINA) $nalezena = (string)$g['resourceName'];
        }
        $dalsi = $d['nextPageToken'] ?? null;
        if (!$dalsi) break;
    }
    if ($nalezena === '') {
        $d = gk_api('POST', 'contactGroups', ['contactGroup' => ['name' => GK_SKUPINA]]);
        $nalezena = (string)($d['resourceName'] ?? '');
    }
    if (!preg_match('~^contactGroups/[A-Za-z0-9_-]+$~', $nalezena)) throw new GkChyba('Google nevrátil skupinu kontaktů „' . GK_SKUPINA . '“.');
    gk_uloz('skupina', $nalezena);
    return $s = $nalezena;
}

function gk_skupina_pridej(string $skupina, array $rns): void
{
    foreach (array_chunk(array_values(array_unique($rns)), 400) as $c)
        gk_api('POST', $skupina . '/members:modify', ['resourceNamesToAdd' => $c]);
}

// ---------- plán synchronizace ----------

/**
 * Porovná kartotéku s tím, co už je (podle vazeb) v telefonu. $zGoogle = true i podívá do kontaktů v Googlu
 * (cizí kontakty se stejným číslem, smazané kontakty, ručně upravená jména).
 * Akce položky: nova | zmena | obnovit (v Googlu smazán) | vlastni (v Googlu už je cizí kontakt s tímto číslem) | beze
 */
function gk_plan(bool $zGoogle): array
{
    $masNaJmeno = gk_nast('jmeno_maserka', '1') === '1';
    $vazby = q('SELECT zakaznik_id, resource_name, etag, hash, telefon, jmeno, synchronizovano FROM google_kontakty')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $google = $zGoogle ? gk_nacti_kontakty() : null;
    $vazbaRes = array_column($vazby, 'resource_name');
    // Bez dotazu na Google (automatika) si pamatujeme zákazníky, u kterých úplný náhled našel cizí kontakt se stejným číslem,
    // ať se kvůli nim nevolá Google při každé kontrole; po GK_CIZI_DNI dnech se podíváme znovu.
    $cizi = json_decode(gk_nast('cizi', '{}'), true);
    $ciziPlatne = is_array($cizi) && (int)gk_nast('cizi_cas', '0') > time() - GK_CIZI_DNI * 86400;
    $polozky = []; $bezTel = 0;
    $pocty = ['nova' => 0, 'zmena' => 0, 'obnovit' => 0, 'vlastni' => 0, 'beze' => 0];
    foreach (gk_zakaznici() as $z) {
        $tel = telefon_norm((string)$z['telefon']);
        if ($tel === '') { $bezTel++; continue; }
        $d = gk_kontakt_data($z, $tel, $masNaJmeno);
        $v = $vazby[(int)$z['id']] ?? null;
        $p = ['zid' => (int)$z['id'], 'jmeno' => $d['jmeno'], 'telefon' => $tel, 'note' => $d['note'], 'hash' => $d['hash'],
              'stary' => $v['jmeno'] ?? '', 'res' => $v['resource_name'] ?? '', 'cizi' => '', 'stejne' => false, 'duvod' => ''];
        if ($v) {
            $g = $google['res'][$v['resource_name']] ?? null;          // kontakt, jak ho teď vidí Google (jen při náhledu)
            $stary = fn(string $s) => (string)preg_replace('~\s+~u', ' ', trim($s));
            $smazano = $v['etag'] === GK_SMAZANO;                      // při zápisu se zjistilo, že ho člověk v Googlu smazal
            if ($google !== null && $g === null && ($smazano || (strtotime((string)$v['synchronizovano']) ?: 0) < time() - 1800)) { $p['akce'] = 'obnovit'; $p['res'] = ''; }
            elseif ($smazano && $google === null) $p['akce'] = 'beze';   // automatika smazaný kontakt sama nevrací, nabídne ho až ruční náhled
            elseif ($v['hash'] !== $d['hash']) $p['akce'] = 'zmena';
            elseif ($g !== null && ($stary($g['jmeno']) !== $stary($d['jmeno']) || !in_array($tel, $g['tel'], true))) {
                $p['akce'] = 'zmena'; $p['duvod'] = 'v Googlu je upraveno jinak, vrátí se podoba z kartotéky'; $p['stary'] = $g['jmeno'];
            } else $p['akce'] = 'beze';
        } else {
            $rn = $google['tel'][$tel] ?? '';
            if ($rn !== '' && !in_array($rn, $vazbaRes, true)) {
                $p['akce'] = 'vlastni'; $p['res'] = $rn; $p['cizi'] = $google['res'][$rn]['jmeno'];
                // stejná osoba (jen s jinými značkami)? Pak je přepsání bezpečné a v náhledu je předzaškrtnuté.
                $holeCizi = gk_norm((string)preg_replace('~\s·\s.*$~u', '', $p['cizi']));
                $a = gk_norm(trim((string)$z['jmeno'] . ' ' . (string)$z['prijmeni'])); $b = gk_norm(trim((string)$z['prijmeni'] . ' ' . (string)$z['jmeno']));
                $p['stejne'] = $holeCizi !== '' && ($holeCizi === $a || $holeCizi === $b);
            } elseif ($google === null && $ciziPlatne && ($cizi[(string)$z['id']] ?? '') === $tel) $p['akce'] = 'vlastni';
            else $p['akce'] = 'nova';
        }
        $pocty[$p['akce']]++;
        $polozky[] = $p;
    }
    if ($google !== null) {
        $nove = [];
        foreach ($polozky as $p) if ($p['akce'] === 'vlastni') $nove[(string)$p['zid']] = $p['telefon'];
        gk_uloz('cizi', json_encode($nove)); gk_uloz('cizi_cas', (string)time());
    }
    $sirotci = (int)qv('SELECT COUNT(*) FROM google_kontakty WHERE zakaznik_id NOT IN (SELECT id FROM zakaznici)');
    return ['polozky' => $polozky, 'pocty' => $pocty, 'bez_tel' => $bezTel, 'sirotci' => $sirotci, 'google_celkem' => $google ? count($google['res']) : null];
}

function gk_zapis_vazbu(array $p, array $osoba): void
{
    $rn = (string)($osoba['resourceName'] ?? $p['res']);
    if ($rn === '') return;
    q('DELETE FROM google_kontakty WHERE resource_name=? AND zakaznik_id<>?', [$rn, $p['zid']]);
    q('INSERT INTO google_kontakty(zakaznik_id,resource_name,etag,hash,telefon,jmeno,synchronizovano) VALUES(?,?,?,?,?,?,?)
       ON CONFLICT(zakaznik_id) DO UPDATE SET resource_name=excluded.resource_name, etag=excluded.etag, hash=excluded.hash, telefon=excluded.telefon,
         jmeno=excluded.jmeno, synchronizovano=excluded.synchronizovano',
        [$p['zid'], $rn, (string)($osoba['etag'] ?? ''), $p['hash'], $p['telefon'], $p['jmeno'], nyni()]);
}

function gk_osoby_podle_telefonu(array $osoby): array
{
    $out = [];
    foreach ($osoby as $r) {
        $o = $r['person'] ?? $r;
        foreach ($o['phoneNumbers'] ?? [] as $ph) { $x = telefon_norm((string)($ph['canonicalForm'] ?? $ph['value'] ?? '')); if ($x !== '') $out[$x] = $o; }
    }
    return $out;
}

/** Zapíše vybrané položky plánu do Googlu. Vrací počty a chyby; $konec = čas (unix), po kterém se už nové dávky nezačínají. */
function gk_proved(array $polozky, int $konec): array
{
    $v = ['vytvoreno' => 0, 'aktualizovano' => 0, 'chybi' => 0, 'chyby' => [], 'zbyva' => 0];
    $vytvorit = array_values(array_filter($polozky, fn($p) => in_array($p['akce'], ['nova', 'obnovit'], true)));
    $upravit = array_values(array_filter($polozky, fn($p) => in_array($p['akce'], ['zmena', 'vlastni'], true)));
    if (!$vytvorit && !$upravit) return $v;
    $skupina = '';
    try { $skupina = gk_skupina(); }
    catch (GkChyba $e) { if ($e->pripojit) throw $e; $v['chyby'][] = 'Skupinu „' . GK_SKUPINA . '“ se nepodařilo založit: ' . $e->getMessage(); }
    $skup = function (array $rns) use ($skupina, &$v) {
        if ($skupina === '' || !$rns) return;
        try { gk_skupina_pridej($skupina, $rns); }
        catch (GkChyba $e) { if ($e->pripojit) throw $e; $v['chyby'][] = 'Kontakty se nepodařilo zařadit do skupiny „' . GK_SKUPINA . '“: ' . $e->getMessage(); }
    };

    foreach (array_chunk($vytvorit, 100) as $davka) {
        if (time() >= $konec) { $v['zbyva'] += count($davka); continue; }
        $nove = [];
        try {
            $odp = gk_api('POST', 'people:batchCreateContacts', ['contacts' => array_map(fn($p) => ['contactPerson' => gk_osoba($p)], $davka), 'readMask' => 'names,phoneNumbers,metadata'], [], 90);
            $podle = gk_osoby_podle_telefonu($odp['createdPeople'] ?? []);
            foreach ($davka as $p) {
                $o = $podle[$p['telefon']] ?? null;
                if (!$o) { $v['chyby'][] = $p['jmeno'] . ': Google kontakt nevrátil, zkontrolujte ho v kontaktech.'; continue; }
                gk_zapis_vazbu($p, $o); $nove[] = (string)$o['resourceName']; $v['vytvoreno']++;
            }
        } catch (GkChyba $e) {
            if ($e->pripojit || $e->http !== 400) throw $e;
            foreach ($davka as $p) {      // dávku Google odmítl celou (kvůli jednomu kontaktu) → po jednom, ať je vidět, který to je
                try { $o = gk_api('POST', 'people:createContact', gk_osoba($p), ['personFields' => 'names,phoneNumbers,metadata'], 40); gk_zapis_vazbu($p, $o); $nove[] = (string)$o['resourceName']; $v['vytvoreno']++; }
                catch (GkChyba $e2) { if ($e2->pripojit) throw $e2; $v['chyby'][] = $p['jmeno'] . ': ' . $e2->getMessage(); }
            }
        }
        $skup($nove);
    }

    foreach (array_chunk($upravit, 100) as $davka) {
        if (time() >= $konec) { $v['zbyva'] += count($davka); continue; }
        $akt = gk_nacti_osoby(array_column($davka, 'res'));
        $kont = []; $podleRes = [];
        foreach ($davka as $p) {
            $o = $akt[$p['res']] ?? null;
            if (!$o) {
                $v['chybi']++;
                if ($p['akce'] === 'zmena') q('UPDATE google_kontakty SET etag=? WHERE zakaznik_id=?', [GK_SMAZANO, $p['zid']]);
                continue;
            }
            $kont[$p['res']] = gk_uprav_osobu($o, $p); $podleRes[$p['res']] = $p;
        }
        if (!$kont) continue;
        $hotovo = [];
        try {
            $odp = gk_api('POST', 'people:batchUpdateContacts', ['contacts' => $kont, 'updateMask' => 'names,phoneNumbers,biographies', 'readMask' => 'names,phoneNumbers,metadata'], [], 90);
            foreach ($podleRes as $rn => $p) {
                $o = $odp['updateResult'][$rn]['person'] ?? null;
                if (!is_array($o)) { $v['chyby'][] = $p['jmeno'] . ': Google aktualizaci nepotvrdil.'; continue; }
                gk_zapis_vazbu($p, $o + ['resourceName' => $rn]); $hotovo[] = $rn; $v['aktualizovano']++;
            }
        } catch (GkChyba $e) {
            if ($e->pripojit || !in_array($e->http, [400, 409, 412], true)) throw $e;
            foreach ($podleRes as $rn => $p) {
                try {
                    $o = gk_api('PATCH', $rn . ':updateContact', $kont[$rn], ['updatePersonFields' => 'names,phoneNumbers,biographies', 'personFields' => 'names,phoneNumbers,metadata'], 40);
                    gk_zapis_vazbu($p, $o + ['resourceName' => $rn]); $hotovo[] = $rn; $v['aktualizovano']++;
                } catch (GkChyba $e2) { if ($e2->pripojit) throw $e2; $v['chyby'][] = $p['jmeno'] . ': ' . $e2->getMessage(); }
            }
        }
        $skup($hotovo);
    }
    $v['chyby'] = array_slice($v['chyby'], 0, 8);
    return $v;
}

/**
 * Celá synchronizace. $vybrane = ID zákazníků z náhledu (ruční), null = automatika (jen nové a změněné, cizí kontakty se nikdy nepřepisují
 * a smazané se samy nezakládají znovu). Vrací ['ok','zprava','chyby'].
 */
function gk_synchronizuj(?array $vybrane): array
{
    $auto = $vybrane === null;
    if (!gk_zamkni()) return ['ok' => false, 'zprava' => 'Právě běží jiná synchronizace, zkuste to za minutu.', 'chyby' => []];
    @set_time_limit(240);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // aby se zdlouhavé volání Googlu nepralo s ostatními stránkami
    try {
        $plan = gk_plan(!$auto);
        if ($auto && $plan['pocty']['nova'] > 0) $plan = gk_plan(true);          // nové: nejdřív se podívat, jestli to číslo v Googlu už není
        $povolene = $auto ? ['nova', 'zmena'] : ['nova', 'zmena', 'obnovit', 'vlastni'];
        $zpracovat = array_values(array_filter($plan['polozky'], fn($p) => in_array($p['akce'], $povolene, true) && ($auto || in_array($p['zid'], $vybrane, true))));
        if (!$zpracovat) {
            gk_uloz('posledni_kontrola', nyni());
            return ['ok' => true, 'zprava' => 'Telefon je aktuální, není co měnit.', 'chyby' => [], 'zmen' => 0];
        }
        $v = gk_proved($zpracovat, time() + ($auto ? 15 : 50));
        $cast = [];
        if ($v['vytvoreno']) $cast[] = $v['vytvoreno'] . ' nových kontaktů';
        if ($v['aktualizovano']) $cast[] = $v['aktualizovano'] . ' aktualizováno';
        $zprava = 'Hotovo: ' . ($cast ? implode(', ', $cast) : 'nic se nezměnilo') . '.';
        if ($v['chybi']) $zprava .= ' ' . $v['chybi'] . ' kontaktů už v Googlu není (smazané) – v náhledu je půjde vytvořit znovu.';
        if ($v['zbyva']) $zprava .= ' Zbývá ještě ' . $v['zbyva'] . ' položek – synchronizaci spusťte znovu.';
        if ($v['chyby']) $zprava .= ' Nepovedlo se: ' . implode(' | ', $v['chyby']);
        gk_uloz('posledni_sync', nyni()); gk_uloz('posledni_kontrola', nyni()); gk_uloz('posledni_vysledek', $zprava);
        gk_uloz('posledni_chyba', $v['chyby'] ? implode(' | ', $v['chyby']) : null);
        if (!$auto) gk_uloz('prvni_sync', '1');
        return ['ok' => !$v['chyby'], 'zprava' => $zprava, 'chyby' => $v['chyby'], 'zmen' => $v['vytvoreno'] + $v['aktualizovano']];
    } catch (GkChyba $e) {
        $z = $e->getMessage();
        gk_uloz('posledni_chyba', $z);
        return ['ok' => false, 'zprava' => 'Synchronizace se nepovedla: ' . $z, 'chyby' => [$z]];
    } catch (Throwable $e) {
        error_log('gk_synchronizuj: ' . $e);
        gk_uloz('posledni_chyba', 'Neočekávaná chyba: ' . $e->getMessage());
        return ['ok' => false, 'zprava' => 'Synchronizace skončila neočekávanou chybou: ' . $e->getMessage(), 'chyby' => [$e->getMessage()]];
    } finally {
        gk_odemkni();
    }
}

function gk_zamkni(): bool
{
    q("INSERT OR IGNORE INTO google_nast(klic,hodnota) VALUES('zamek','0')");
    $ted = time();
    return q("UPDATE google_nast SET hodnota=? WHERE klic='zamek' AND CAST(hodnota AS INTEGER) < ?", [(string)$ted, $ted - 600])->rowCount() === 1;
}
function gk_odemkni(): void { q("UPDATE google_nast SET hodnota='0' WHERE klic='zamek'"); }

// ---------- automatika ----------

/** Volá se při dotazu stránek na poplach (každé 4 s z každé otevřené stránky): odpověď se nejdřív dopošle prohlížeči, teprve potom případně běží synchronizace. */
function gk_priprav_tik(): void
{
    try {
        if (gk_nast('auto') !== '1' || !gk_pripojeno() || gk_nast('spojeni') === 'vyprselo') return;
        if ((int)gk_nast('auto_cas', '0') > time() - GK_AUTO_INTERVAL) return;
    } catch (Throwable $e) { return; }
    ob_start();
    register_shutdown_function(function (): void {
        if (!headers_sent() && ob_get_level() > 0) { header('Content-Length: ' . (int)ob_get_length()); header('Connection: close'); }
        while (ob_get_level() > 0) @ob_end_flush();
        @flush();
        if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
        gk_tik();
    });
}

function gk_tik(): void
{
    try {
        $ted = time();
        if (gk_nast('auto') !== '1' || !gk_pripojeno() || gk_nast('spojeni') === 'vyprselo') return;
        q("INSERT OR IGNORE INTO google_nast(klic,hodnota) VALUES('auto_cas','0')");
        if (q("UPDATE google_nast SET hodnota=? WHERE klic='auto_cas' AND CAST(hodnota AS INTEGER) <= ?", [(string)$ted, $ted - GK_AUTO_INTERVAL])->rowCount() < 1) return;
        $v = gk_synchronizuj(null);
        if (!$v['ok']) gk_uloz('auto_cas', (string)($ted + 1500));       // po chybě zkusit zase až za zhruba půl hodiny
    } catch (Throwable $e) { error_log('gk_tik: ' . $e->getMessage()); }
}

/** Upozornění nahoře ve všech stránkách pro admina, když automatika přestala fungovat (jinak by si toho nikdo nevšiml). */
function gk_upozorneni(): string
{
    try {
        if (!je_admin() || gk_nast('auto') !== '1') return '';
        if (gk_nast('spojeni') === 'vyprselo') return '⚠ <b>Google kontakty:</b> připojení ke Google účtu vypršelo, telefon se přestal aktualizovat. <a href="/?r=google">Připojit znovu</a>';
        $ch = gk_nast('posledni_chyba');
        if ($ch !== '' && (int)gk_nast('auto_cas', '0') > time()) return '⚠ <b>Google kontakty:</b> poslední automatická synchronizace selhala (' . h(mb_substr($ch, 0, 160)) . '). <a href="/?r=google">Otevřít</a>';
    } catch (Throwable $e) {}
    return '';
}

// ---------- stránka Správa → Google kontakty ----------

function stranka_google(array $u): void
{
    vyzaduj_admina();
    session_start_safe();
    $nahled = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = (string)($_POST['akce'] ?? '');
        if ($akce === 'klient') {
            $ucet = mb_strtolower(trim((string)($_POST['ucet'] ?? '')));
            $id = trim((string)($_POST['client_id'] ?? '')); $tajne = trim((string)($_POST['client_secret'] ?? ''));
            if ($ucet !== '' && !filter_var($ucet, FILTER_VALIDATE_EMAIL)) { flash('Google účet musí být e-mailová adresa.', 'err'); presmeruj('/?r=google'); }
            $zmenaUctu = $ucet !== gk_nast('ucet') && gk_nast('ucet_overeny') !== '' && $ucet !== gk_nast('ucet_overeny');
            $zmenaKlienta = $id !== '' && $id !== gk_nast('client_id');
            gk_uloz('ucet', $ucet);
            if ($id !== '') gk_uloz('client_id', $id);
            if ($tajne !== '') gk_uloz('client_secret', $tajne);
            if (($zmenaUctu || $zmenaKlienta) && gk_pripojeno()) {
                gk_odpoj(true);
                flash('Údaje se změnily, Google účet je potřeba připojit znovu.', 'warn');
            } else flash('Uloženo.');
        } elseif ($akce === 'pripojit') {
            if (!gk_nastaveno() || gk_nast('ucet') === '') flash('Nejdřív vyplňte a uložte Google účet, Client ID a Client Secret.', 'err');
            else {
                $state = bin2hex(random_bytes(16));
                $_SESSION['gk_state'] = $state; $_SESSION['gk_state_cas'] = time();
                presmeruj(gk_auth_url($state));
            }
        } elseif ($akce === 'odpojit') {
            gk_odpoj(true);
            zapis_log('google_odpojeno');
            flash('Google účet je odpojený. Kontakty, které už v telefonu jsou, zůstaly; jen se přestaly obnovovat.');
        } elseif ($akce === 'volby') {
            gk_uloz('jmeno_maserka', !empty($_POST['jmeno_maserka']) ? '1' : '0');
            $auto = !empty($_POST['auto']);
            if ($auto && !gk_pripojeno()) { flash('Automatiku jde zapnout, až bude Google účet připojený.', 'warn'); $auto = false; }
            elseif ($auto && gk_nast('prvni_sync') === '') { flash('Automatiku jde zapnout až po první ruční synchronizaci (ať nejdřív uvidíte, co se do telefonu zapíše).', 'warn'); $auto = false; }
            gk_uloz('auto', $auto ? '1' : '0');
            if ($auto) gk_uloz('auto_cas', '0');
            flash('Uloženo.');
        } elseif ($akce === 'nahled') {
            try { $nahled = gk_plan(true); }
            catch (GkChyba $e) { flash($e->getMessage(), 'err'); presmeruj('/?r=google'); }
        } elseif ($akce === 'synchronizovat') {
            $vyber = array_map('intval', (array)($_POST['zid'] ?? []));
            $v = gk_synchronizuj($vyber);
            if (!empty($v['zmen'])) zapis_log('google_synchronizace', mb_substr($v['zprava'], 0, 300));
            flash($v['zprava'], $v['ok'] ? 'ok' : 'err');
            presmeruj('/?r=google');
        }
        if ($nahled === null) presmeruj('/?r=google');
    }

    $pripojeno = gk_pripojeno(); $vyprselo = gk_nast('spojeni') === 'vyprselo';
    $auto = gk_nast('auto') === '1';
    hlavicka('Google kontakty', 'nastaveni'); ?>
    <div class="cal-nav"><h1>📇 Google kontakty (zákazníci v telefonu)</h1><a class="btn" href="/?r=nastaveni">‹ Správa</a></div>
    <p class="muted">Zákazníci z kartotéky se sami objeví v kontaktech telefonu, takže při hovoru hned vidíte, kdo volá – a podle značek ve jménu i to nejdůležitější (stálý zákazník, poukaz, masáž zdarma, oblíbená masérka). Do telefonu jdou jen jméno, číslo a krátká poznámka; zdravotní údaje ani interní poznámky ne.</p>

    <?php if ($nahled !== null): gk_nahled_html($nahled); else: ?>
    <div class="cols"><div>
      <form method="post" class="card" autocomplete="off"><?= csrf_pole() ?><input type="hidden" name="akce" value="klient">
        <h2>Google účet</h2>
        <p class="small"><?php if ($pripojeno): ?>✅ Připojeno jako <b><?= h(gk_nast('ucet_overeny') ?: gk_nast('ucet')) ?></b>
          <?php elseif ($vyprselo): ?>⚠ <b>Připojení vypršelo</b> – klepněte na „Připojit Google účet“.
          <?php else: ?>Zatím nepřipojeno.<?php endif ?></p>
        <label>Google účet, do jehož kontaktů se budou zákazníci zapisovat (e-mail)<input type="email" name="ucet" value="<?= h(gk_nast('ucet')) ?>" placeholder="salon@gmail.com" inputmode="email" autocapitalize="off"></label>
        <label>Client ID (z Google Cloud)<input type="text" name="client_id" value="<?= h(gk_nast('client_id')) ?>" placeholder="123456789-abc….apps.googleusercontent.com" autocapitalize="off" spellcheck="false"></label>
        <label>Client Secret (tajný klíč)<input type="password" name="client_secret" value="" placeholder="<?= gk_nast('client_secret') !== '' ? '•••••••• uloženo (nechte prázdné, aby zůstal)' : 'GOCSPX-…' ?>" autocomplete="new-password" spellcheck="false"></label>
        <label>Adresa přesměrování – tuto přesně vložte do Google Cloud (Autorizované identifikátory URI přesměrování)<input type="text" value="<?= h(gk_redirect_uri()) ?>" readonly onclick="this.select()"></label>
        <button class="btn primary">Uložit</button>
      </form>
      <form method="post" class="card"><?= csrf_pole() ?>
        <?php if ($pripojeno): ?>
          <button class="btn" name="akce" value="odpojit" data-confirm="Odpojit Google účet? Kontakty v telefonu zůstanou, jen se přestanou obnovovat.">Odpojit Google účet</button>
          <button class="btn" name="akce" value="pripojit" title="Přihlásit se znovu, např. po vypršení">Připojit znovu</button>
        <?php else: ?>
          <button class="btn primary" name="akce" value="pripojit"<?= gk_nastaveno() && gk_nast('ucet') !== '' ? '' : ' disabled' ?>>🔗 Připojit Google účet</button>
          <?php if (!gk_nastaveno() || gk_nast('ucet') === ''): ?><span class="muted small">Nejdřív vyplňte a uložte údaje výše.</span><?php endif ?>
        <?php endif ?>
      </form>

      <?php if ($pripojeno): ?>
      <section class="card"><h2>Synchronizace</h2>
        <form method="post"><?= csrf_pole() ?><input type="hidden" name="akce" value="nahled">
          <p class="small">Nejdřív se ukáže <b>náhled</b>: kdo se založí, komu se změní jméno nebo značky a kde už v Googlu kontakt s tím číslem je. Nic se nezapíše, dokud to neodsouhlasíte.</p>
          <button class="btn primary">🔍 Zobrazit náhled změn</button>
        </form>
        <?php if (gk_nast('posledni_sync') !== ''): ?>
          <p class="small muted" style="margin-bottom:0">Poslední zápis: <?= h(datum_cz(gk_nast('posledni_sync'))) ?> – <?= h(gk_nast('posledni_vysledek')) ?></p>
        <?php endif ?>
        <?php if (gk_nast('posledni_kontrola') !== ''): ?><p class="small muted" style="margin:4px 0 0">Naposledy zkontrolováno: <?= h(datum_cz(gk_nast('posledni_kontrola'))) ?></p><?php endif ?>
        <?php if (gk_nast('posledni_chyba') !== ''): ?><p class="small warn" style="margin:4px 0 0">Poslední potíž: <?= h(gk_nast('posledni_chyba')) ?></p><?php endif ?>
      </section>

      <form method="post" class="card"><?= csrf_pole() ?><input type="hidden" name="akce" value="volby">
        <h2>Volby</h2>
        <label class="check"><input type="checkbox" name="jmeno_maserka" value="1" <?= gk_nast('jmeno_maserka', '1') === '1' ? 'checked' : '' ?>> Do jména připsat i masérku (oblíbenou, jinak poslední) – např. „Jana Nováková ⭐🎁 · Lenka“</label>
        <label class="check"><input type="checkbox" name="auto" value="1" <?= $auto ? 'checked' : '' ?><?= gk_nast('prvni_sync') === '' ? ' disabled' : '' ?>> Automaticky udržovat telefon aktuální (nové zákazníky přidá, změny značek promítne, nejvýš jednou za 5 minut, když je někdo v kartotéce přihlášený)</label>
        <?php if (gk_nast('prvni_sync') === ''): ?><p class="muted small">Automatiku jde zapnout až po první ruční synchronizaci.</p><?php endif ?>
        <button class="btn primary">Uložit volby</button>
      </form>
      <?php endif ?>
    </div><div>
      <section class="card side"><h3>Co znamenají značky ve jménu</h3>
        <table class="tbl plain small"><?php foreach (GK_ZNACKY as [$s, $t]): ?><tr><td style="width:2.2em;font-size:1.2rem"><?= h($s) ?></td><td><?= h($t) ?></td></tr><?php endforeach ?>
          <tr><td colspan="2">Za značkami „<b>· Lenka</b>“ = oblíbená (jinak poslední) masérka</td></tr></table>
        <p class="muted small">Podrobnosti (poslední návštěva, razítka, zůstatek poukazu) jsou v poznámce kontaktu. Kartotéka v poznámce přepisuje jen svůj blok mezi řádky „▶ Thai Most kartotéka“ a „◀ konec zápisu“, vaše vlastní text kolem nechá být.</p>
      </section>
      <section class="card side"><h3>Jak to jednorázově nastavit</h3>
        <ol class="small">
          <li>Otevřete <b>console.cloud.google.com</b> (přihlášeni tím Google účtem, kam mají kontakty padat) a vytvořte projekt, např. „Thai Most kontakty“.</li>
          <li><b>API a služby → Knihovna</b> → najděte <b>People API</b> → <b>Povolit</b>.</li>
          <li><b>Google Auth Platform</b> (dříve „Obrazovka souhlasu OAuth“): název aplikace např. „Thai Most kartotéka“, váš e-mail, publikum <b>Externí</b>. V části <b>Přístup k datům</b> přidejte oprávnění <code>…/auth/contacts</code>. Potom aplikaci <b>publikujte</b> (stav „V produkci“) – jinak přihlášení po 7 dnech samo vyprší a telefon se přestane aktualizovat.</li>
          <li><b>Klienti → Vytvořit klienta</b> → typ <b>Webová aplikace</b>; do „Autorizované identifikátory URI přesměrování“ vložte adresu z okénka vlevo.</li>
          <li>Zkopírujte <b>ID klienta</b> a <b>tajný klíč</b> sem, Uložit, <b>Připojit Google účet</b>. Google může ukázat „Aplikace není ověřená“ – je to vaše vlastní aplikace pro tento jeden účet: <b>Rozšířené → Pokračovat</b>.</li>
          <li>V telefonu musí být tento Google účet přidaný a zapnutá synchronizace kontaktů (Nastavení → Účty → Google → Synchronizace účtu → Kontakty).</li>
        </ol>
        <p class="muted small">Názvy nabídek v Google Cloud se občas mění; smysl kroků zůstává. Zákazníci se tím ukládají u Googlu do účtu salonu – počítejte s tím v informacích pro zákazníky o zpracování údajů.</p>
      </section>
    </div></div>
    <?php endif;
    paticka();
}

/** Náhled: tabulka toho, co se zapíše, s výběrem. */
function gk_nahled_html(array $n): void
{
    $akceTxt = ['nova' => '🆕 nový kontakt', 'zmena' => '✏️ změna', 'obnovit' => '♻️ v Googlu chybí – vytvořit znovu?', 'vlastni' => '⚠️ v Googlu už je váš kontakt s tímto číslem'];
    $porad = ['nova' => 0, 'zmena' => 1, 'vlastni' => 2, 'obnovit' => 3];
    $ukaz = array_values(array_filter($n['polozky'], fn($p) => $p['akce'] !== 'beze'));
    usort($ukaz, fn($a, $b) => [$porad[$a['akce']], $a['jmeno']] <=> [$porad[$b['akce']], $b['jmeno']]);
    $celkem = count($ukaz); $ukaz = array_slice($ukaz, 0, 500);
    $c = $n['pocty']; ?>
    <p><a class="btn" href="/?r=google">‹ Zpět</a></p>
    <section class="card">
      <h2>Náhled změn</h2>
      <p>🆕 nových: <b><?= $c['nova'] ?></b> · ✏️ změn: <b><?= $c['zmena'] ?></b> · ⚠️ už v Googlu: <b><?= $c['vlastni'] ?></b> · ♻️ smazaných v Googlu: <b><?= $c['obnovit'] ?></b> · beze změny: <b><?= $c['beze'] ?></b>
        <?= $n['bez_tel'] ? ' · bez platného telefonu (přeskočeno): <b>' . $n['bez_tel'] . '</b>' : '' ?>
        <?= $n['google_celkem'] !== null ? ' · kontaktů v Google účtu celkem: <b>' . $n['google_celkem'] . '</b>' : '' ?></p>
      <?php if ($n['sirotci']): ?><p class="small muted"><?= $n['sirotci'] ?> kontaktů v Googlu patří zákazníkům, kteří už v kartotéce nejsou. Zůstávají v telefonu; smažte je ručně v Google Kontaktech (skupina „<?= h(GK_SKUPINA) ?>“).</p><?php endif ?>
      <p class="small muted">Nic se nemaže. U řádků „už v Googlu“ se přepíše jméno a doplní poznámka vašeho kontaktu – předzaškrtnuté jsou jen ty, kde je to evidentně stejná osoba.</p>
    </section>
    <?php if (!$ukaz): ?><p class="flash">Telefon je aktuální, není co zapisovat.</p><?php else: ?>
    <form method="post"><?= csrf_pole() ?><input type="hidden" name="akce" value="synchronizovat">
      <p>
        <a href="#" onclick="document.querySelectorAll('.gkc').forEach(c=>c.checked=true);return false">vybrat vše</a> ·
        <a href="#" onclick="document.querySelectorAll('.gkc').forEach(c=>c.checked=false);return false">nic</a> ·
        <a href="#" onclick="document.querySelectorAll('.gkc').forEach(c=>c.checked=c.dataset.vychozi==='1');return false">doporučené</a></p>
      <table class="tbl">
        <tr><th></th><th>Zákazník</th><th>Telefon</th><th>V telefonu bude</th><th>Co se stane</th></tr>
        <?php foreach ($ukaz as $p):
            $vychozi = in_array($p['akce'], ['nova', 'zmena'], true) || ($p['akce'] === 'vlastni' && $p['stejne']); ?>
          <tr>
            <td><input type="checkbox" class="gkc" name="zid[]" value="<?= $p['zid'] ?>" data-vychozi="<?= $vychozi ? 1 : 0 ?>" style="width:auto;margin:0;transform:scale(1.4)" <?= $vychozi ? 'checked' : '' ?>></td>
            <td><a href="/?r=zakaznik&amp;id=<?= $p['zid'] ?>"><?= h($p['jmeno']) ?></a></td>
            <td><?= h(telefon_cz($p['telefon'])) ?></td>
            <td class="small"><b><?= h($p['jmeno']) ?></b></td>
            <td class="small"><?= h($akceTxt[$p['akce']]) ?>
              <?php if ($p['akce'] === 'zmena' && $p['stary'] !== '' && $p['stary'] !== $p['jmeno']): ?><br><span class="muted">teď: <?= h($p['stary']) ?></span><?php endif ?>
              <?php if ($p['duvod'] !== ''): ?><br><span class="muted"><?= h($p['duvod']) ?></span><?php endif ?>
              <?php if ($p['akce'] === 'vlastni'): ?><br><span class="muted">teď: <?= h($p['cizi']) ?></span><?php endif ?></td>
          </tr>
        <?php endforeach ?>
      </table>
      <?php if ($celkem > count($ukaz)): ?><p class="muted small">Zobrazeno prvních <?= count($ukaz) ?> z <?= $celkem ?>; zbytek přijde na řadu při další synchronizaci.</p><?php endif ?>
      <p><button class="btn primary">✅ Zapsat vybrané do Google kontaktů</button> <a class="btn" href="/?r=google">Zrušit</a></p>
    </form>
    <?php endif;
}
