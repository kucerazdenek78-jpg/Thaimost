<?php
// Web Push notifikace do nainstalované aplikace Asistent.
// Posílá se „contentless“ push (bez obsahu) – text si service worker dotáhne z /api/push_info.
// Díky tomu není potřeba šifrování payloadu (AES-GCM); stačí VAPID JWT (ES256) přes openssl. Bez externích knihoven.

function push_b64url(string $data): string { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }

/** Vrátí (a při prvním použití vygeneruje a uloží do nastavení) VAPID klíče: ['priv'=>PEM, 'pub'=>base64url 65B bod]. */
function vapid_klice(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $priv = (string)qv("SELECT hodnota FROM nastaveni WHERE klic='vapid_priv'");
    $pub  = (string)qv("SELECT hodnota FROM nastaveni WHERE klic='vapid_pub'");
    if ($priv !== '' && $pub !== '') return $cache = ['priv' => $priv, 'pub' => $pub];

    $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$res) throw new RuntimeException('VAPID: openssl neumí vygenerovat EC klíč.');
    openssl_pkey_export($res, $privPem);
    $d = openssl_pkey_get_details($res);
    $pubRaw = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $pub = push_b64url($pubRaw);   // nekomprimovaný bod P-256 (65 B) pro applicationServerKey
    foreach (['vapid_priv' => $privPem, 'vapid_pub' => $pub] as $k => $v)
        q("INSERT INTO nastaveni(klic,hodnota) VALUES(?,?) ON CONFLICT(klic) DO UPDATE SET hodnota=excluded.hodnota", [$k, $v]);
    return $cache = ['priv' => $privPem, 'pub' => $pub];
}

/** DER ECDSA podpis → 64 B (r||s) pro JWS ES256. */
function push_der_na_raw(string $der): string
{
    $off = 0;
    if (ord($der[$off++]) !== 0x30) throw new RuntimeException('DER: čekal SEQUENCE');
    $len = ord($der[$off++]);
    if ($len & 0x80) $off += ($len & 0x7f);   // dlouhá forma délky (u P-256 se nestává)
    $cti = function () use ($der, &$off): string {
        if (ord($der[$off++]) !== 0x02) throw new RuntimeException('DER: čekal INTEGER');
        $l = ord($der[$off++]);
        $v = substr($der, $off, $l); $off += $l;
        return str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT);
    };
    return $cti() . $cti();
}

/** VAPID JWT (ES256) pro audience = origin push endpointu. */
function push_jwt(string $aud, string $privPem): string
{
    $vstup = push_b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_UNESCAPED_SLASHES))
        . '.' . push_b64url(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:salon@thaimost.cz'], JSON_UNESCAPED_SLASHES));
    $key = openssl_pkey_get_private($privPem);
    if (!$key || !openssl_sign($vstup, $der, $key, OPENSSL_ALGO_SHA256)) throw new RuntimeException('VAPID: podpis selhal.');
    return $vstup . '.' . push_b64url(push_der_na_raw($der));
}

/** Odešle contentless push na jeden odběr. Vrací HTTP kód (0 při chybě spojení). Při 404/410 odběr smaže. */
function push_posli(array $sub): int
{
    $kl = vapid_klice();
    $u = parse_url($sub['endpoint']);
    if (empty($u['host'])) return 0;
    $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['TTL: 86400', 'Content-Length: 0', 'Authorization: vapid t=' . push_jwt($aud, $kl['priv']) . ', k=' . $kl['pub']],
    ]);
    curl_exec($ch);
    $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (in_array($kod, [404, 410], true)) q('DELETE FROM push_odbery WHERE id=?', [(int)$sub['id']]);
    elseif ($kod >= 400) q('UPDATE push_odbery SET posledni_chyba=? WHERE id=?', ["HTTP $kod (" . date('j.n. H:i') . ')', (int)$sub['id']]);
    else q("UPDATE push_odbery SET posledni_chyba='' WHERE id=?", [(int)$sub['id']]);
    return $kod;
}

/** Pošle push na všechna přihlášená zařízení (nová poptávka apod.). Vrací počet úspěšně předaných. Nikdy nevyhodí výjimku. */
function push_vsem(): int
{
    try { $odbery = q('SELECT * FROM push_odbery')->fetchAll(); } catch (Throwable $e) { return 0; }
    $ok = 0;
    foreach ($odbery as $s) { try { $k = push_posli($s); if ($k >= 200 && $k < 300) $ok++; } catch (Throwable $e) { /* jedno zařízení neshodí ostatní */ } }
    return $ok;
}
