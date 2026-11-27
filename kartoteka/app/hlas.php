<?php
// Hlasový správce (box ESP32-S3-BOX-3 v kanceláři) – čtení z kartotéky Thai Most (27. 11. 2026).
//   GET/POST /?r=hlas&akce=<nástroj>&…   hlavička X-Hlas-Key: <klíč>
// Klíč vytváří admin na stránce /?r=hlas_klic; na serveru je uložený jen jeho SHA-256 (data/hlas-klic.txt).
// Box smí JEN ČÍST – používá stejné čtecí nástroje jako AI asistent recepce (app/ai.php → ai_nastroj).
// Návrhy změn (navrhnout_*) se odsud nevolají. Zákazníky [Z:id] převádí na jméno, aby je box mohl vyslovit;
// telefony a zdravotní údaje se neposílají (nástroje je nevracejí).

function hlas_klic_soubor(): string { return dirname(cfg()['db_path']) . '/hlas-klic.txt'; }
function hlas_klic_hash(): string { $f = hlas_klic_soubor(); return is_file($f) ? trim((string)file_get_contents($f)) : ''; }

const HLAS_NASTROJE = ['rozvrh', 'najdi_zakaznika', 'zakaznik_detail', 'sluzby', 'maserky', 'volne_terminy', 'kontroly', 'zakaznici_last_minute', 'hodnoceni'];

function hlas_odpoved(array $o, int $kod = 200): void
{
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** [Z:12] → „Jana Nováková (zákazník 12)“ ve všech textech výsledku. */
function hlas_jmena($data)
{
    $ids = [];
    array_walk_recursive($data, function ($v) use (&$ids) {
        if (is_string($v) && preg_match_all('~\[Z:(\d+)\]~', $v, $m)) foreach ($m[1] as $i) $ids[(int)$i] = true;
    });
    if (!$ids) return $data;
    $jm = [];
    foreach (array_keys($ids) as $id) { $z = q1('SELECT jmeno, prijmeni, telefon FROM zakaznici WHERE id=?', [$id]); $jm[$id] = $z ? jmeno_z(['jmeno' => $z['jmeno'], 'prijmeni' => $z['prijmeni'], 'telefon' => '']) : ''; }
    array_walk_recursive($data, function (&$v) use ($jm) {
        if (is_string($v)) $v = preg_replace_callback('~\[Z:(\d+)\]~', fn($m) => trim(($jm[(int)$m[1]] ?? '') . ' (zákazník ' . $m[1] . ')'), $v);
    });
    return $data;
}

function stranka_hlas(): void
{
    $hash = hlas_klic_hash();
    if ($hash === '') hlas_odpoved(['ok' => false, 'chyba' => 'Hlasový přístup není nastavený (admin: Správa → /?r=hlas_klic).'], 503);
    $klic = trim((string)($_SERVER['HTTP_X_HLAS_KEY'] ?? ''));
    if ($klic === '' || !hash_equals($hash, hash('sha256', $klic))) { usleep(300000); hlas_odpoved(['ok' => false, 'chyba' => 'Neplatný klíč'], 401); }
    if (!limit_ip('hlas', 300)) hlas_odpoved(['ok' => false, 'chyba' => 'Příliš mnoho dotazů, zkuste za chvíli.'], 429);
    if (!function_exists('ai_nastroj')) require_once __DIR__ . '/ai.php';

    $akce = preg_replace('~[^a-z_]~', '', (string)($_REQUEST['akce'] ?? ''));
    $dny = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];
    $ted = ['datum' => date('Y-m-d'), 'den' => $dny[(int)date('w')], 'cas' => date('H:i')];
    if ($akce === 'ping') hlas_odpoved(['ok' => true, 'system' => 'Thai Most', 'ted' => $ted]);

    $in = [];
    foreach (['datum_od', 'datum_do', 'dotaz', 'cas_od', 'cas_do'] as $k) if (isset($_REQUEST[$k]) && trim((string)$_REQUEST[$k]) !== '') $in[$k] = mb_substr(trim((string)$_REQUEST[$k]), 0, 60);
    foreach (['zakaznik_id', 'varianta_id', 'pocet_dni', 'maserka_id'] as $k) if (isset($_REQUEST[$k]) && (int)$_REQUEST[$k] > 0) $in[$k] = (int)$_REQUEST[$k];
    $nic = [];
    try {
        if ($akce === 'prehled') {
            $dnes = date('Y-m-d');
            $rozvrh = ai_nastroj('rozvrh', ['datum_od' => $dnes, 'datum_do' => $dnes], $nic);
            $kontroly = ai_nastroj('kontroly', [], $nic);
            hlas_odpoved(['ok' => true, 'ted' => $ted, 'otevreno' => nastaveni('oteviraci_od', '09:00') . '–' . nastaveni('oteviraci_do', '20:00')]
                + hlas_jmena(['dnes' => $rozvrh['rezervace'], 'k_vyreseni' => $kontroly]));
        }
        if (!in_array($akce, HLAS_NASTROJE, true)) hlas_odpoved(['ok' => false, 'chyba' => 'Neznámá akce. Povolené: prehled, ping, ' . implode(', ', HLAS_NASTROJE)], 400);
        if (in_array($akce, ['rozvrh', 'maserky'], true) && empty($in['datum_od'])) $in['datum_od'] = date('Y-m-d');
        if ($akce === 'volne_terminy' && empty($in['datum_od'])) $in['datum_od'] = date('Y-m-d');
        if ($akce === 'hodnoceni') { $in += ['datum_od' => date('Y-m-d', strtotime('-30 days')), 'datum_do' => date('Y-m-d')]; }
        hlas_odpoved(['ok' => true, 'ted' => $ted] + hlas_jmena(ai_nastroj($akce, $in, $nic)));
    } catch (InvalidArgumentException $e) {
        hlas_odpoved(['ok' => false, 'chyba' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        error_log('hlas: ' . $e);
        hlas_odpoved(['ok' => false, 'chyba' => 'Chyba serveru.'], 500);
    }
}

/** Správa klíče pro box (jen admin). Klíč se ukáže jen jednou, na serveru zůstane jen jeho otisk. */
function stranka_hlas_klic(array $u): void
{
    vyzaduj_admina();
    $novy = ''; $zprava = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {   // CSRF ověřuje index.php
        if (($_POST['akce'] ?? '') === 'vytvorit') {
            $novy = bin2hex(random_bytes(24));
            if (@file_put_contents(hlas_klic_soubor(), hash('sha256', $novy) . "\n", LOCK_EX) === false) { $novy = ''; $zprava = 'Klíč se nepodařilo uložit.'; }
            else { @chmod(hlas_klic_soubor(), 0600); zapis_log('hlas_klic', 'vytvořen nový klíč pro hlasového správce'); }
        } elseif (($_POST['akce'] ?? '') === 'zrusit') {
            @unlink(hlas_klic_soubor()); zapis_log('hlas_klic', 'klíč hlasového správce zrušen');
            $zprava = 'Klíč zrušen – box už do kartotéky nevidí.';
        }
    }
    $je = hlas_klic_hash() !== '';
    $kdy = $je ? date('j. n. Y H:i', filemtime(hlas_klic_soubor())) : '';
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Hlasový správce – Thai Most</title>
<style>body{margin:0;font-family:system-ui,Arial,sans-serif;background:#f6f1ea;color:#2b2118}main{max-width:720px;margin:0 auto;padding:16px}.box{background:#fff;border:1px solid #e6dccf;border-radius:12px;padding:14px 16px;margin:12px 0}
.mut{color:#7a6a58;font-size:14px}button{font:inherit;padding:9px 15px;border-radius:9px;border:1px solid #6b4a2b;background:#6b4a2b;color:#fff;cursor:pointer}button.sec{background:#fff;color:#b42318;border-color:#b42318}
code.klic{display:block;font-size:20px;background:#fff8d6;border:2px solid #e0a800;border-radius:8px;padding:10px 12px;word-break:break-all;user-select:all}.ok{background:#e3f4e6;padding:8px 12px;border-radius:8px}</style></head><body><main>
<p><a href="/?r=nastaveni">← Nastavení</a></p>
<h1>🎙️ Hlasový správce – klíč</h1>
<p class="mut">Hlasový box v kanceláři se s tímto klíčem připojuje ke kartotéce Thai Most a <b>jen čte</b>: dnešní rozvrh, zákazníky, volné termíny, masérky a co je k vyřešení. Nic nerezervuje ani nemění.</p>
<?php if ($zprava !== ''): ?><div class="ok"><?= h($zprava) ?></div><?php endif; ?>
<?php if ($novy !== ''): ?><div class="box"><b>Nový klíč – opište ho teď, znovu se už neukáže:</b><code class="klic"><?= h($novy) ?></code>
<p>V boxu (připojeném k počítači, v okně <code>idf.py -p COM4 monitor --no-reset</code>) napište:<br><code>masaze_klic <?= h($novy) ?></code><br>a pak <code>masaze_test</code> – má vypsat OK.</p><p class="mut">Starý klíč tímto přestal platit.</p></div><?php endif; ?>
<div class="box"><p>Stav: <?= $je ? '✅ klíč je nastavený (vytvořen ' . h($kdy) . ')' : '⛔ klíč není nastavený' ?></p>
<form method="post" style="display:inline" onsubmit="return <?= $je ? "confirm('Vytvořit nový klíč? Starý přestane platit.')" : 'true' ?>"><?= csrf_pole() ?><input type="hidden" name="akce" value="vytvorit"><button><?= $je ? 'Vytvořit nový klíč' : 'Vytvořit klíč' ?></button></form>
<?php if ($je): ?> <form method="post" style="display:inline" onsubmit="return confirm('Zrušit klíč?')"><?= csrf_pole() ?><input type="hidden" name="akce" value="zrusit"><button class="sec">Zrušit klíč</button></form><?php endif; ?></div>
</main></body></html><?php
}
