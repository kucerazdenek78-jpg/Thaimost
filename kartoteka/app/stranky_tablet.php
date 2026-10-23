<?php
declare(strict_types=1);

/*
 * Tablety v masérnách.
 *   Recepce v rezervaci „Pošle na tablet“ → masérka potvrdí, že je to ona → uvidí zákazníka a pozor na zdraví →
 *   spustí masáž → odpočet zaplacené doby → znělka + poděkování → hodnocení (smajlíky 1–5) a QR spropitné → hotovo.
 * Tablet se nepřihlašuje; pozná ho tajný token místnosti v adrese (/?r=tablet&t=TOKEN).
 */

const SEZENI_STAVY = ['cekani' => 'čeká na masérku', 'bezi' => 'masáž běží', 'konec' => 'skončila (hodnocení)', 'hotovo' => 'hotovo', 'zruseno' => 'zrušeno'];

/* ---------- pomocné funkce ---------- */

/** Anglické názvy služeb pro masérky na tabletu (klíč = sluzby.kod). Co tu chybí, zůstane česky. */
/** Fotka masáže na pozadí tabletu (poloprůhledně) – stejné fotky jako na webu (assets/js/data.js).
 *  Jiná fotka: přepsat adresu tady, nebo nahrát public/foto-<kod>.jpg (má přednost). */
const MASAZ_FOTO = [
    "tradicni" => "https://www.thaimost.cz/media/tradicni-3.jpg",
    "hlava" => "https://www.thaimost.cz/media/hlava-3.jpg",
    "zada" => "https://www.thaimost.cz/media/zada-special-1.jpg",
    "olej" => "https://www.thaimost.cz/media/olej-1.jpg",
    "aroma" => "https://www.thaimost.cz/media/aroma-1.jpg",
    "detska" => "https://images.unsplash.com/photo-1611073615830-9f76902c10fe?auto=format&fit=crop&w=900&q=75",
    "lymfa-oblicej" => "https://images.unsplash.com/photo-1598901986949-f593ff2a31a6?auto=format&fit=crop&w=900&q=75",
    "poporodni" => "https://images.pexels.com/photos/6187421/pexels-photo-6187421.jpeg?auto=compress&cs=tinysrgb&w=900",
    "oblicej" => "https://www.thaimost.cz/media/masaz-obliceje-1.jpg",
    "nohy" => "https://www.thaimost.cz/media/cinska-nohy-1.jpg",
    "bylinna" => "https://www.thaimost.cz/media/bylinna-1.jpg",
    "siam" => "https://www.thaimost.cz/media/masaz-siam-1.jpg",
    "ventosa" => "https://images.pexels.com/photos/6188042/pexels-photo-6188042.jpeg?auto=compress&cs=tinysrgb&w=900",
    "lava" => "https://images.unsplash.com/photo-1600334129128-685c5582fd35?auto=format&fit=crop&w=900&q=75",
    "lymfa-telo" => "https://images.pexels.com/photos/6186747/pexels-photo-6186747.jpeg?auto=compress&cs=tinysrgb&w=900",
    "tehotenska" => "https://images.pexels.com/photos/6186763/pexels-photo-6186763.jpeg?auto=compress&cs=tinysrgb&w=900",
    "whirlpool" => "https://www.thaimost.cz/media/was2.jpg",
    "fiji" => "https://www.thaimost.cz/media/fiji-1.jpg",
    "cukr" => "https://www.thaimost.cz/media/cukr-1.jpg",
];

const MASAZ_EN = [
    'tradicni' => 'Traditional Thai massage', 'hlava' => 'Head and neck massage', 'zada' => 'Back and neck massage',
    'olej' => 'Thai oil massage', 'aroma' => 'Aroma massage', 'detska' => "Children's massage",
    'lymfa-oblicej' => 'Lymphatic face and décolleté massage', 'poporodni' => 'Postnatal massage', 'oblicej' => 'Face massage',
    'nohy' => 'Foot massage', 'bylinna' => 'Herbal massage', 'siam' => 'Siam massage', 'ventosa' => 'Ventosa (cupping) massage',
    'lava' => 'Lava stone face massage', 'lymfa-telo' => 'Manual lymphatic full-body massage', 'tehotenska' => 'Pregnancy massage',
    'whirlpool' => 'Whirlpool and sauna', 'fiji' => 'Fiji ritual', 'cukr' => 'Sugar breeze from paradise',
];
const VARIANTA_EN = ['s bylinnými sáčky' => 'with herbal compresses', 'Sauna + sprcha' => 'Sauna + shower', 'párová' => "couple's", 'asistentky' => 'assistants', 'šampaňské' => 'champagne'];
const TLAK_EN = ['jemne' => 'light', 'stredne' => 'medium', 'silne' => 'firm'];

function bez_diakritiky(string $s): string
{
    $s = strtr($s, ['á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z',
        'Á'=>'A','Č'=>'C','Ď'=>'D','É'=>'E','Ě'=>'E','Í'=>'I','Ň'=>'N','Ó'=>'O','Ř'=>'R','Š'=>'S','Ť'=>'T','Ú'=>'U','Ů'=>'U','Ý'=>'Y','Ž'=>'Z']);
    return preg_replace('~[^A-Za-z0-9 .\-]~', '', $s) ?? '';
}

/** Český účet (předčíslí-číslo/kód banky) nebo hotový IBAN → IBAN, jinak null. */
function cz_iban(string $ucet): ?string
{
    $ucet = strtoupper(preg_replace('~\s+~', '', $ucet) ?? '');
    if (preg_match('~^CZ\d{22}$~', $ucet)) return $ucet;
    if (!preg_match('~^(?:(\d{1,6})-)?(\d{2,10})/(\d{4})$~', $ucet, $m)) return null;
    $bban = $m[3] . str_pad($m[1], 6, '0', STR_PAD_LEFT) . str_pad($m[2], 10, '0', STR_PAD_LEFT);
    $cislo = $bban . '123500';
    $zb = 0;
    foreach (str_split($cislo, 7) as $blok) $zb = (int)($zb . $blok) % 97;
    return 'CZ' . str_pad((string)(98 - $zb), 2, '0', STR_PAD_LEFT) . $bban;
}

/** Délka zaplacené masáže v minutách: z rezervace, jinak z varianty, jinak 60. */
function rezervace_minuty(array $r): int
{
    if (!empty($r['zacatek']) && !empty($r['konec'])) {
        $m = (int)round((strtotime($r['konec']) - strtotime($r['zacatek'])) / 60);
        if ($m > 0) return $m;
    }
    $v = !empty($r['varianta_id']) ? (int)qv('SELECT minuty FROM varianty WHERE id=?', [$r['varianta_id']]) : 0;
    return $v > 0 ? $v : 60;
}

function mistnost_podle_tokenu(string $t): ?array
{
    return preg_match('~^[0-9a-f]{32}$~', $t) ? q1('SELECT * FROM mistnosti WHERE token=? AND aktivni=1', [$t]) : null;
}

/** Aktivní sezení místnosti; mimoděk posune stav (konec odpočtu, uklizení staré obrazovky s hodnocením). */
function sezeni_aktivni(int $mistnost): ?array
{
    $s = q1("SELECT * FROM sezeni WHERE mistnost_id=? AND stav IN ('cekani','bezi','konec') ORDER BY id DESC LIMIT 1", [$mistnost]);
    if (!$s) return null;
    $ted = time();
    if ($s['stav'] === 'bezi' && $s['plan_konec'] && strtotime($s['plan_konec']) <= $ted) {
        q("UPDATE sezeni SET stav='konec', konec=plan_konec WHERE id=? AND stav='bezi'", [$s['id']]);
        q("UPDATE rezervace SET stav='probehlo', zmeneno=?, zmenil='tablet' WHERE id=? AND stav='potvrzeno'", [nyni(), $s['rezervace_id']]);
        $s['stav'] = 'konec'; $s['konec'] = $s['plan_konec'];
    }
    if ($s['stav'] === 'konec' && strtotime((string)$s['konec']) < $ted - 15 * 60) {
        q("UPDATE sezeni SET stav='hotovo', zavreno=? WHERE id=?", [nyni(), $s['id']]);
        return null;
    }
    // sezení, které nikdo nezačal do 3 hodin od odeslání, se zahodí
    if ($s['stav'] === 'cekani' && strtotime((string)$s['poslano']) < $ted - 3 * 3600) {
        q("UPDATE sezeni SET stav='zruseno', zavreno=? WHERE id=?", [nyni(), $s['id']]);
        return null;
    }
    return $s;
}

/** Recepce: poslat rezervaci na tablet. Vrací chybu nebo null. */
function tablet_poslat(array $r, int $mistnost): ?string
{
    $m = q1('SELECT * FROM mistnosti WHERE id=? AND aktivni=1', [$mistnost]);
    if (!$m) return 'Vyberte masérnu.';
    if (!$r['maserka_id']) return 'U rezervace chybí masérka – nejdřív ji vyberte.';
    if (in_array($r['stav'], ['zadost', 'odmitnuto', 'zruseno_vcas', 'zruseno_pozde'], true)) return 'Rezervace není potvrzená.';
    $akt = sezeni_aktivni($mistnost);
    if ($akt && $akt['stav'] !== 'cekani') return "V masérně „{$m['nazev']}“ právě probíhá jiná masáž.";
    if ($akt) q("UPDATE sezeni SET stav='zruseno', zavreno=? WHERE id=?", [nyni(), $akt['id']]);
    q("UPDATE sezeni SET stav='zruseno', zavreno=? WHERE rezervace_id=? AND stav='cekani'", [nyni(), $r['id']]);
    q('INSERT INTO sezeni(mistnost_id,rezervace_id,maserka_id,minuty,poslal,poslano) VALUES(?,?,?,?,?,?)',
        [$mistnost, $r['id'], $r['maserka_id'], rezervace_minuty($r), kdo(), nyni()]);
    zapis_log('tablet_poslano', "rez #{$r['id']} → {$m['nazev']}");
    return null;
}

/** Karta v detailu rezervace: poslat na tablet / stav sezení. */
function tablet_karta(array $r): void
{
    $mist = q('SELECT * FROM mistnosti WHERE aktivni=1 ORDER BY poradi, nazev')->fetchAll();
    $s = q("SELECT s.*, m.nazev FROM sezeni s JOIN mistnosti m ON m.id=s.mistnost_id WHERE s.rezervace_id=? ORDER BY s.id DESC LIMIT 1", [$r['id']])->fetch();
    if ($s && in_array($s['stav'], ['cekani', 'bezi', 'konec'], true)) sezeni_aktivni((int)$s['mistnost_id']);
    $s = $s ? q1('SELECT s.*, m.nazev FROM sezeni s JOIN mistnosti m ON m.id=s.mistnost_id WHERE s.id=?', [$s['id']]) : null;
    $z = q1('SELECT * FROM zakaznici WHERE id=?', [$r['zakaznik_id']]) ?: [];
    $chybi = [];
    if (trim($z['zdravi_text'] ?? '') !== '' && trim($z['zdravi_text_en'] ?? '') === '') $chybi[] = 'zdravotní upozornění';
    if (trim($z['pref_text'] ?? '') !== '' && trim($z['pref_text_en'] ?? '') === '') $chybi[] = 'preference';
    if ((trim($r['poznamka_zakaznik']) . trim($r['poznamka_masaz']) . trim($z['poznamka_interni'] ?? '')) !== '' && trim($r['poznamka_en']) === '') $chybi[] = 'poznámky';
    if (!$mist && !$s) return; ?>
    <section class="card">
      <h2>Tablet v masérně</h2>
      <?php if ($s && $s['stav'] !== 'zruseno'): ?>
        <p>Masérna <b><?= h($s['nazev']) ?></b>: <b><?= h(SEZENI_STAVY[$s['stav']] ?? $s['stav']) ?></b>
          <?= $s['start'] ? ' · start ' . h(substr($s['start'], 11, 5)) : '' ?><?= $s['stav'] === 'bezi' ? ' · konec ' . h(substr((string)$s['plan_konec'], 11, 5)) : '' ?>
          <?= $s['neni_ona'] ? ' <b class="warn">masérka hlásí, že to není ona!</b>' : '' ?></p>
      <?php endif ?>
      <?php if ($chybi): ?><p class="warn small">⚠ Pro masérku chybí anglický text: <?= h(implode(', ', $chybi)) ?>. Na tabletu se do doplnění ukáže česky. Doplňte ručně v <a href="/?r=zakaznik&id=<?= (int)$r['zakaznik_id'] ?>">kartě zákazníka</a> (zdraví, preference) a v poznámce k masáži níže.</p><?php endif ?>
      <?php if (!$s || !in_array($s['stav'], ['bezi', 'konec'], true)): ?>
      <form method="post" class="inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="tablet_poslat">
        <label>Masérna<select name="mistnost_id"><?php foreach ($mist as $m): ?><option value="<?= $m['id'] ?>" <?= $s && (int)$s['mistnost_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= h($m['nazev']) ?></option><?php endforeach ?></select></label>
        <button class="btn primary"><?= $s && $s['stav'] === 'cekani' ? 'Poslat znovu' : '📲 Poslat na tablet' ?></button>
        <?php if ($s && $s['stav'] === 'cekani'): ?><button name="akce" value="tablet_zrusit" class="btn">Stáhnout z tabletu</button><?php endif ?>
      </form>
      <p class="muted small">Masérka na tabletu potvrdí své jméno, uvidí příjmení zákazníka, masáž, preference a upozornění na zdraví, spustí odpočet zaplacené doby (<?= rezervace_minuty($r) ?> min).</p>
      <?php endif ?>
    </section>
<?php }

/* ---------- API tabletu ---------- */

function masaz_foto(string $kod): string
{
    foreach (['jpg', 'webp'] as $p) {
        $f = __DIR__ . "/../public/foto-$kod.$p";
        if (is_file($f)) return "/foto-$kod.$p?v=" . filemtime($f);
    }
    return MASAZ_FOTO[$kod] ?? '';
}

function sezeni_payload(array $m, ?array $s): array
{
    $out = ['ok' => true, 'ted' => time(), 'mistnost' => $m['nazev'], 'stav' => 'idle'];
    if (!$s) return $out;
    $r = q1('SELECT * FROM rezervace WHERE id=?', [$s['rezervace_id']]);
    $z = q1('SELECT * FROM zakaznici WHERE id=?', [$r['zakaznik_id']]);
    $mas = q1('SELECT * FROM maserky WHERE id=?', [$s['maserka_id']]);
    $v = $r['varianta_id'] ? q1('SELECT v.popis, v.minuty, s.nazev, s.kod FROM varianty v JOIN sluzby s ON s.id=v.sluzba_id WHERE v.id=?', [$r['varianta_id']]) : null;
    $out = array_merge($out, [
        'stav' => $s['stav'], 'sezeni_id' => (int)$s['id'], 'maserka' => $mas['jmeno'] ?? '', 'minuty' => (int)$s['minuty'],
        'potvrzeno' => (bool)$s['potvrzeno'], 'neni_ona' => (bool)$s['neni_ona'],
    ]);
    // údaje o zákazníkovi se na tablet pošlou až po potvrzení jména masérkou (a dál po celou masáž)
    if ($s['potvrzeno'] || $s['stav'] !== 'cekani') {
        $out = array_merge($out, [
            'prijmeni' => trim($z['prijmeni']) !== '' ? $z['prijmeni'] : trim($z['jmeno']),
            'masaz' => $v ? trim($v['nazev'] . ($v['popis'] ? ' – ' . $v['popis'] : '')) : 'Masáž',
            'masaz_en' => $v ? trim((MASAZ_EN[$v['kod']] ?? $v['nazev']) . ($v['popis'] ? ' – ' . strtr($v['popis'], VARIANTA_EN) : '')) : 'Massage',
        ]);
        $out['foto'] = $v ? masaz_foto((string)$v['kod']) : '';
    }
    if ($s['stav'] === 'cekani' && $s['potvrzeno']) {
        $out['tlak'] = $z['pref_tlak'] ? (TLAK[$z['pref_tlak']] ?? '') : '';
        $out['tlak_en'] = TLAK_EN[$z['pref_tlak']] ?? '';
        $out['preference'] = trim($z['pref_text']);
        $out['poznamky'] = array_values(array_filter([trim($r['poznamka_zakaznik']), trim($r['poznamka_masaz']), trim($z['poznamka_interni'])], fn($x) => $x !== ''));
        $out['upozorneni'] = trim($z['zdravi_text']);
        $out['upozorneni_en'] = trim($z['zdravi_text_en']);
        $out['preference_en'] = trim($z['pref_text_en']);
        $out['poznamky_en'] = trim($r['poznamka_en']);
    }
    if ($s['stav'] === 'bezi') {
        $out['start'] = strtotime((string)$s['start']);
        $out['konec'] = strtotime((string)$s['plan_konec']);
    }
    if ($s['stav'] === 'konec') {
        $out['ohodnoceno'] = (bool)$s['hodnoceni_id'];
        $ucet = trim((string)($mas['ucet'] ?? '')) ?: trim((string)nastaveni('spropitne_ucet', ''));
        $iban = $ucet !== '' ? cz_iban($ucet) : null;
        $out['spropitne'] = $iban ? ['iban' => $iban, 'zprava' => 'SPROPITNE ' . strtoupper(bez_diakritiky($mas['jmeno'] ?? 'THAI MOST'))] : null;
    }
    return $out;
}

function stranka_tablet_api(): void
{
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) api_odpoved(['ok' => false, 'chyba' => 'Špatný požadavek.'], 400);
    $m = mistnost_podle_tokenu((string)($in['t'] ?? ''));
    if (!$m) api_odpoved(['ok' => false, 'chyba' => 'Neplatný tablet.'], 403);
    q('UPDATE mistnosti SET posledni_spojeni=? WHERE id=?', [nyni(), $m['id']]);
    $akce = (string)($in['akce'] ?? 'stav');
    $s = sezeni_aktivni((int)$m['id']);

    if ($akce !== 'stav') {
        if (!$s || (int)($in['sezeni_id'] ?? 0) !== (int)$s['id']) api_odpoved(sezeni_payload($m, $s) + ['zastarale' => true]);
        $ted = nyni();
        switch ($akce) {
            case 'potvrdit':
                if ($s['stav'] === 'cekani') q('UPDATE sezeni SET potvrzeno=?, neni_ona=NULL WHERE id=?', [$ted, $s['id']]);
                break;
            case 'nejsem':
                if ($s['stav'] === 'cekani') {
                    q('UPDATE sezeni SET neni_ona=?, potvrzeno=NULL WHERE id=?', [$ted, $s['id']]);
                    zapis_log('tablet_neni_ona', "sezení #{$s['id']} {$m['nazev']}");
                }
                break;
            case 'start':
                if ($s['stav'] === 'cekani' && $s['potvrzeno']) {
                    $konec = date('Y-m-d H:i:s', time() + (int)$s['minuty'] * 60);
                    q("UPDATE sezeni SET stav='bezi', start=?, plan_konec=? WHERE id=?", [$ted, $konec, $s['id']]);
                    zapis_log('tablet_start', "sezení #{$s['id']} {$m['nazev']}");
                }
                break;
            case 'predcasne':
                if ($s['stav'] === 'bezi') {
                    q("UPDATE sezeni SET stav='konec', konec=? WHERE id=?", [$ted, $s['id']]);
                    q("UPDATE rezervace SET stav='probehlo', zmeneno=?, zmenil='tablet' WHERE id=? AND stav='potvrzeno'", [$ted, $s['rezervace_id']]);
                }
                break;
            case 'hodnoceni':
                $zn = (int)($in['znamka'] ?? 0);
                if ($s['stav'] === 'konec' && !$s['hodnoceni_id'] && $zn >= 1 && $zn <= 5 && $s['maserka_id']) {
                    q("INSERT INTO hodnoceni_maserek(maserka_id,rezervace_id,znamka,komentar,zdroj,kdo,cas) VALUES(?,?,?,?,'tablet',?,?)",
                        [$s['maserka_id'], $s['rezervace_id'], $zn, '', 'tablet ' . $m['nazev'], $ted]);
                    q('UPDATE sezeni SET hodnoceni_id=? WHERE id=?', [(int)db()->lastInsertId(), $s['id']]);
                }
                break;
            case 'zavrit':
                if ($s['stav'] === 'konec') q("UPDATE sezeni SET stav='hotovo', zavreno=? WHERE id=?", [$ted, $s['id']]);
                break;
        }
        $s = sezeni_aktivni((int)$m['id']);
    }
    api_odpoved(sezeni_payload($m, $s));
}

/* ---------- stránka tabletu (celoobrazovkový kiosk) ---------- */

function stranka_tablet(): void
{
    $m = mistnost_podle_tokenu((string)($_GET['t'] ?? ''));
    if (!$m) { http_response_code(404); exit('Nenalezeno'); }
    header('Cache-Control: no-store');
    $qr = is_file(__DIR__ . '/../public/qrcode.js') ? '/qrcode.js' : '';
    // volitelná fotka na pozadí: stačí nahrát public/pozadi.jpg (nebo .webp); jinak je jen grafika
    $foto = '';
    foreach (['pozadi.jpg', 'pozadi.webp'] as $f) if (is_file(__DIR__ . '/../public/' . $f)) { $foto = ',url(/' . $f . '?v=' . filemtime(__DIR__ . '/../public/' . $f) . ')'; break; }
    echo str_replace(['__TOKEN__', '__NAZEV__', '__QR__', '__FOTO__'], [$m['token'], h($m['nazev']), $qr, $foto], TABLET_HTML);
    exit;
}

const TABLET_HTML = <<<'HTML'
<!doctype html>
<html lang="cs"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<link rel="icon" href="data:,"><meta name="robots" content="noindex, nofollow"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes">
<title>__NAZEV__ · Thai Most</title>
<style>
:root{--bg:#10201f;--bg2:#183432;--card:rgba(22,56,52,.62);--tx:#f3efe6;--mut:#a9bdb7;--acc:#e2b765;--ok:#7fc8a0;--bad:#ef7b6c}
*{box-sizing:border-box}button{font-family:inherit}html,body{height:100%;margin:0}
body{background:#0e1b1a;color:var(--tx);font:20px/1.4 "Segoe UI",system-ui,-apple-system,sans-serif;overflow:hidden;user-select:none;-webkit-user-select:none;touch-action:manipulation}
#bg{position:fixed;inset:0;z-index:0;overflow:hidden;background-color:#0e1b1a;background-image:radial-gradient(55% 55% at 88% 95%,rgba(226,183,101,.5),transparent 70%),radial-gradient(70% 60% at 8% 5%,rgba(70,160,138,.45),transparent 70%)__FOTO__;background-size:100% 100%,100% 100%,cover;background-position:0 0,0 0,center;background-repeat:no-repeat}
#bg::before{content:"";position:absolute;inset:0;background:linear-gradient(160deg,rgba(20,66,58,.74),rgba(9,34,31,.84))}
#ph{position:absolute;inset:0;background:center/cover no-repeat;opacity:0;transition:opacity 1.2s ease}#ph.on{opacity:.28}
#bg svg{position:absolute;fill:none;stroke:#e2b765;stroke-linecap:round;stroke-linejoin:round}
#bg .rip{right:-14vmin;bottom:-30vmin;width:100vmin;opacity:.2;stroke-width:.5}
#bg .rip circle{transform-box:fill-box;transform-origin:center;animation:rp 10s ease-in-out infinite}#bg .rip circle:nth-child(2){animation-delay:-2.5s}#bg .rip circle:nth-child(3){animation-delay:-5s}#bg .rip circle:nth-child(4){animation-delay:-7.5s}
#bg .lot{right:5vmin;bottom:3vmin;width:46vmin;opacity:.3;stroke-width:1}
#bg .lot2{left:-9vmin;top:-6vmin;width:34vmin;opacity:.11;stroke-width:1.1;transform:rotate(155deg)}
@keyframes rp{0%,100%{transform:scale(.94);opacity:.55}50%{transform:scale(1.04);opacity:1}}
@media (prefers-reduced-motion:reduce){#bg .rip circle{animation:none}}
.brand{display:inline-flex;align-items:center;gap:1.2vmin}.brand svg{width:3.4vmin;height:3.4vmin;fill:none;stroke:var(--acc);stroke-width:5;stroke-linecap:round;stroke-linejoin:round}
#app{position:relative;z-index:1;height:100%;display:flex;flex-direction:column;padding:2.2vmin 3vmin}
header{display:flex;justify-content:space-between;align-items:center;color:var(--mut);font-size:2.4vmin;letter-spacing:.04em}
header b{color:var(--acc);font-weight:600}
main{flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;gap:2vmin;min-height:0}
h1{font-size:5.5vmin;margin:0;font-weight:500}h2{font-size:3.6vmin;margin:0;font-weight:500;color:var(--acc)}
.big{font-size:14vmin;font-weight:300;letter-spacing:.02em;font-variant-numeric:tabular-nums;line-height:1}
.huge{font-size:17vmin;font-weight:200;font-variant-numeric:tabular-nums;line-height:1}
.mut{color:var(--mut)}.small{font-size:2.6vmin}
.btn{border:0;border-radius:3vmin;padding:2.4vmin 6vmin;font-size:4vmin;font-weight:600;background:var(--acc);color:#2b2108;min-width:30vmin}
.btn.sec{background:transparent;color:var(--mut);border:.3vmin solid #4a6862;font-weight:400}
.btn.ok{background:var(--ok);color:#0c2a1b}
.btn:active{transform:scale(.97);filter:brightness(.92)}
.row{display:flex;gap:3vmin;flex-wrap:wrap;justify-content:center;align-items:center}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:2vmin;width:100%;max-width:150vmin;text-align:left}
.card{background:var(--card);border-radius:3vmin;padding:2.4vmin 3vmin}
.card .k{color:var(--mut);font-size:2.3vmin;text-transform:uppercase;letter-spacing:.1em}
.card .v{font-size:4.4vmin;font-weight:500;margin-top:.4vmin}
.card.wide{grid-column:1/-1}.card .t{font-size:3vmin;white-space:pre-wrap}
.warn{background:#4b2420;border:.4vmin solid var(--bad)}.warn .k{color:#ffb4a8}.warn .v{color:#fff;font-size:4vmin}
.ring{position:relative;width:64vmin;height:64vmin}.ring svg{position:absolute;inset:0;transform:rotate(-90deg)}
.ring .in{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
.stars{display:flex;gap:2.4vmin}.stars button{background:none;border:0;font-size:12vmin;padding:0;line-height:1;opacity:.6;transition:transform .15s,opacity .15s;font-family:'Noto Color Emoji','Apple Color Emoji','Segoe UI Emoji',sans-serif}.stars button.on{opacity:1;transform:scale(1.3)}
.qr{background:#fff;border-radius:2vmin;padding:1.6vmin;width:34vmin;height:34vmin}.qr svg{width:100%;height:100%;display:block}
.chips{display:flex;gap:1.6vmin;justify-content:center;margin-top:1.4vmin}.chip{border:.3vmin solid #4a6862;background:transparent;color:var(--tx);border-radius:2vmin;padding:1vmin 2.4vmin;font-size:3vmin}.chip.on{background:var(--acc);color:#2b2108;border-color:var(--acc)}
header .r{display:flex;align-items:center}.lg{margin-left:2.4vmin;border:.3vmin solid #4a6862;background:transparent;color:var(--mut);border-radius:2vmin;padding:1vmin 2.2vmin;font-size:2.5vmin;touch-action:none;-webkit-touch-callout:none;-webkit-user-select:none;user-select:none}.lg.on{background:var(--acc);color:#2b2108;border-color:var(--acc)}.lg[hidden]{display:none}.czt{display:inline-block;margin-left:1.2vmin;padding:.2vmin 1.2vmin;border:.25vmin solid #8a7a52;border-radius:1.2vmin;color:#e2b765;font-size:2vmin;text-transform:none;letter-spacing:0}
#snd{position:fixed;inset:0;background:#000b;display:none;align-items:center;justify-content:center;z-index:9;font-size:4vmin;text-align:center;padding:8vmin}
#off{position:fixed;left:50%;transform:translateX(-50%);bottom:1.2vmin;background:#4b2420;color:#fff;padding:.6vmin 2vmin;border-radius:2vmin;font-size:2.2vmin;display:none}
.fade{animation:f .8s ease}@keyframes f{from{opacity:0;transform:translateY(1vmin)}to{opacity:1;transform:none}}
@media (max-aspect-ratio:1/1){.grid{grid-template-columns:1fr}.ring{width:80vmin;height:80vmin}}
</style></head><body>
<div id="bg" aria-hidden="true"><div id="ph"></div><svg class="rip" viewBox="0 0 200 200"><circle cx="100" cy="100" r="34"/><circle cx="100" cy="100" r="54"/><circle cx="100" cy="100" r="74"/><circle cx="100" cy="100" r="96"/></svg><svg class="lot" viewBox="0 0 200 160"><g id="l1"><path d="M100 150C86 124 86 84 100 52C114 84 114 124 100 150Z"/><path d="M100 150C80 130 66 100 70 70C90 88 102 118 100 150Z" transform="rotate(-6 100 150)"/><path d="M100 150C120 130 134 100 130 70C110 88 98 118 100 150Z" transform="rotate(6 100 150)"/><path d="M100 150C70 140 44 116 38 88C66 92 92 114 100 150Z" transform="rotate(-8 100 150)"/><path d="M100 150C130 140 156 116 162 88C134 92 108 114 100 150Z" transform="rotate(8 100 150)"/><path d="M100 150C64 154 30 142 14 118C46 112 82 124 100 150Z"/><path d="M100 150C136 154 170 142 186 118C154 112 118 124 100 150Z"/></g></svg><svg class="lot2" viewBox="0 0 200 160"><g id="l2"><path d="M100 150C86 124 86 84 100 52C114 84 114 124 100 150Z"/><path d="M100 150C80 130 66 100 70 70C90 88 102 118 100 150Z" transform="rotate(-6 100 150)"/><path d="M100 150C120 130 134 100 130 70C110 88 98 118 100 150Z" transform="rotate(6 100 150)"/><path d="M100 150C70 140 44 116 38 88C66 92 92 114 100 150Z" transform="rotate(-8 100 150)"/><path d="M100 150C130 140 156 116 162 88C134 92 108 114 100 150Z" transform="rotate(8 100 150)"/><path d="M100 150C64 154 30 142 14 118C46 112 82 124 100 150Z"/><path d="M100 150C136 154 170 142 186 118C154 112 118 124 100 150Z"/></g></svg></div>
<div id="app"><header><span class="brand"><svg viewBox="0 0 200 160"><use href="#l1"/></svg><span><b>THAI MOST</b> · __NAZEV__</span></span><span class="r"><span id="hd"></span><button type="button" class="lg" id="lg"></button></span></header><main id="m"></main></div>
<div id="snd"><div>🔔 Tap the screen to turn the sound on<br><span class="mut small">Klepnutím zapnete zvuk (bez toho se nepřehraje závěrečná znělka)</span></div></div>
<div id="off">Connection lost – retrying… / Spojení přerušeno, zkouším znovu…</div>
<script src="__QR__"></script>
<script>
"use strict";
const T="__TOKEN__";
let hold=false,S={stav:'idle'},off=0,ctx=null,klic='',tip=100,zvukUz=false,znelaPro=0,online=true;
const $=s=>document.querySelector(s), esc=t=>String(t??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const now=()=>Date.now()+off;
const hhmm=(ms,sec)=>{try{return new Date(ms).toLocaleTimeString('cs-CZ',{timeZone:'Europe/Prague',hour:'2-digit',minute:'2-digit',second:sec?'2-digit':undefined,hour12:false})}catch(e){const d=new Date(ms),p=n=>String(n).padStart(2,'0');return p(d.getHours())+':'+p(d.getMinutes())+(sec?':'+p(d.getSeconds()):'')}};
async function api(akce,data){const r=await fetch('/?r=tablet_api',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({t:T,akce:akce,sezeni_id:S.sezeni_id||0},data||{}))});if(!r.ok&&r.status===403){document.body.innerHTML='<p style="padding:8vmin">Tablet není spárovaný (neplatný odkaz).</p>';throw 0}return r.json()}
async function poll(akce,data){try{const j=await api(akce||'stav',data);if(j.ok){off=j.ted*1000-Date.now();S=j;render()}setOnline(true)}catch(e){setOnline(false)}}
function setOnline(v){online=v;$('#off').style.display=v?'none':'block'}

/* zvuk: uklidňující znělka z několika jemných tónů (WebAudio, žádné soubory) */
function odemkniZvuk(){try{ctx=ctx||new (window.AudioContext||window.webkitAudioContext)();if(ctx.state==='suspended')ctx.resume();zvukUz=ctx.state==='running';$('#snd').style.display='none'}catch(e){}}
['touchstart','mousedown','keydown'].forEach(e=>document.addEventListener(e,odemkniZvuk,{passive:true}));
function znelka(){
  /* thajská znělka: xylofon ranat ek + hluboký gong + zvonivé dozvuky; melodie v thajské stupnici (oktáva = 7 stejných dílů, pentatonika) */
  if(!ctx)return;try{ctx.resume()}catch(e){}
  const t0=ctx.currentTime+.05,master=ctx.createGain();master.gain.value=.55;
  const cmp=ctx.createDynamicsCompressor();master.connect(cmp);cmp.connect(ctx.destination);
  const dl=ctx.createDelay(1);dl.delayTime.value=.31;const fb=ctx.createGain();fb.gain.value=.34;const lp=ctx.createBiquadFilter();lp.type='lowpass';lp.frequency.value=3000;
  dl.connect(lp);lp.connect(fb);fb.connect(dl);lp.connect(master);
  const hlas=(f,t,d,v,casti)=>casti.forEach(([m,a,k])=>{const o=ctx.createOscillator(),g=ctx.createGain();o.type='sine';o.frequency.value=f*m;
    g.gain.setValueAtTime(0,t0+t);g.gain.linearRampToValueAtTime(v*a,t0+t+.006);g.gain.exponentialRampToValueAtTime(.0006,t0+t+d*k);
    o.connect(g);g.connect(master);const s=ctx.createGain();s.gain.value=.55;g.connect(s);s.connect(dl);o.start(t0+t);o.stop(t0+t+d*k+.1)});
  const ranat=(f,t,v)=>hlas(f,t,1.5,v,[[1,1,1],[2.76,.3,.3],[5.4,.1,.12]]);
  const gong=(f,t,v)=>hlas(f,t,8,v,[[1,1,1],[1.47,.5,.8],[2.09,.38,.6],[2.56,.28,.45],[3.4,.16,.3]]);
  const stupen=n=>330*Math.pow(2,n/7);               /* 7 stejných dílů oktávy */
  gong(stupen(-14),0,.5);                              /* úder gongu na začátku */
  const mel=[[4,.9],[5,1.4],[7,1.9],[5,2.5],[4,3.0],[2,3.6],[4,4.3],[2,4.9],[0,5.6],[2,6.4],[0,7.2]];
  mel.forEach(([n,t],i)=>{const v=.3-i*.012;ranat(stupen(n),t,v);ranat(stupen(n-7),t+.02,v*.45)});   /* ranat hraje v oktávách */
  gong(stupen(-14),7.2,.32);                           /* závěrečný gong */
  ranat(stupen(7),7.4,.2);ranat(stupen(4),7.4,.14);
}

/* kvůli kioskovému režimu nenechat usnout obrazovku */
let wl=null;async function drzObrazovku(){try{if(navigator.wakeLock&&!wl){wl=await navigator.wakeLock.request('screen');wl.addEventListener('release',()=>{wl=null})}}catch(e){}}
document.addEventListener('visibilitychange',()=>{if(!document.hidden)drzObrazovku()});drzObrazovku();setInterval(drzObrazovku,60000);

function qrSvg(text){try{const q=qrcode(0,'M');q.addData(text);q.make();const n=q.getModuleCount();let d='';
  for(let r=0;r<n;r++)for(let c=0;c<n;c++)if(q.isDark(r,c))d+='M'+c+' '+r+'h1v1h-1z';
  return '<svg viewBox="-2 -2 '+(n+4)+' '+(n+4)+'" shape-rendering="crispEdges"><path d="'+d+'" fill="#000"/></svg>'}catch(e){return ''}}
function spayd(){const sp=S.spropitne;return 'SPD*1.0*ACC:'+sp.iban+(tip?'*AM:'+tip+'.00':'')+'*CC:CZK*MSG:'+sp.zprava}

/* Jazyk: pro masérku je hlavní angličtina; obrazovka pro zákazníka (poděkování, hodnocení, spropitné) je česky.
   Podržením tlačítka vpravo nahoře se celá obrazovka přepne do češtiny, po pustení zpět do angličtiny. */
const TX={
 en:{ready:'Room ready',next:'Waiting for the next massage',on_duty:'Therapist on duty',check:'Please check that this is you.',yes:n=>'Yes, I am '+n,no:'That is not me',
  notified:'Reception has been notified. Please wait – they will send the booking again.',cust:'Customer',mas:'Massage',warn:'⚠ Caution – health and restrictions',pref:'Customer preferences',press:'Pressure',notes:'Notes',
  now:'Now',start:'▶ Start massage',left:'remaining',endsat:'ends at',early:'End early: hold here for 2 s',cz:'Czech original – no English text entered',lg:'Hold = česky'},
 cs:{ready:'Masérna je připravena',next:'Čekáme na další masáž',on_duty:'Dnes zde masíruje',check:'Zkontrolujte, že jde o vás.',yes:n=>'Ano, jsem '+n,no:'Nejsem to já',
  notified:'Recepce byla upozorněna. Počkejte prosím, pošle masáž znovu.',cust:'Zákazník',mas:'Masáž',warn:'⚠ Pozor – zdraví a omezení',pref:'Preference zákazníka',press:'Tlak',notes:'Poznámky',
  now:'Teď',start:'▶ Zahájit masáž',left:'zbývá',endsat:'konec v',early:'Ukončit dřív: podržte tady 2 s',cz:'',lg:'Česky'}
};
const tx=()=>TX[hold?'cs':'en'];

function render(){
  const k=[S.stav,S.sezeni_id,S.potvrzeno,S.neni_ona,S.ohodnoceno,hold].join('|');
  if(S.stav==='konec'&&znelaPro!==S.sezeni_id){znelaPro=S.sezeni_id;znelka()}
  {const ph=$('#ph'),u=(S.stav!=='idle'&&S.foto)?S.foto:'';if(ph.dataset.u!==u){ph.dataset.u=u;ph.classList.remove('on');if(u){const im=new Image();im.onload=()=>{if(ph.dataset.u===u){ph.style.backgroundImage='url("'+u+'")';ph.classList.add('on')}};im.src=u}}}
  const lgb=$('#lg');lgb.textContent=tx().lg;lgb.classList.toggle('on',hold);lgb.hidden=(S.stav==='konec');
  if(k===klic){tick();return}klic=k;const m=$('#m'),X=tx();let h='';
  const masaz=hold?S.masaz:(S.masaz_en||S.masaz), tlak=hold?S.tlak:(S.tlak_en||S.tlak);
  if(S.stav==='idle'){
    h='<div class="fade"><div class="big" id="cl"></div></div><h1 class="fade">'+X.ready+'</h1><div class="mut small">'+X.next+'</div>';
  }else if(S.stav==='cekani'&&!S.potvrzeno){
    h='<div class="fade"><div class="mut small">'+X.on_duty+'</div><h1 style="font-size:12vmin;margin:1vmin 0 3vmin">'+esc(S.maserka)+'</h1><div class="mut small" style="margin-bottom:4vmin">'+X.check+'</div>'
     +(S.neni_ona?'<div class="card warn" style="margin-bottom:3vmin"><div class="v">'+X.notified+'</div></div>':'')
     +'<div class="row"><button class="btn ok" id="b1">'+esc(X.yes(S.maserka))+'</button><button class="btn sec" id="b2">'+X.no+'</button></div></div>';
  }else if(S.stav==='cekani'){
    /* texty od recepce: angličtina (psaná ručně), není-li, česky s poznámkou; podržení tlačítka = český originál */
    const T2=(cs,en)=>hold?{t:cs,cz:false}:(en?{t:en,cz:false}:{t:cs,cz:!!cs});
    const tag=c=>c&&X.cz?'<span class="czt">'+esc(X.cz)+'</span>':'';
    const uz=T2(S.upozorneni,S.upozorneni_en), pf=T2(S.preference,S.preference_en);
    const pozn=hold?(S.poznamky||[]):(S.poznamky_en?[S.poznamky_en]:(S.poznamky||[])), poznCz=!hold&&!S.poznamky_en&&pozn.length>0;
    h='<div class="grid fade"><div class="card"><div class="k">'+X.cust+'</div><div class="v">'+esc(S.prijmeni)+'</div></div>'
     +'<div class="card"><div class="k">'+X.mas+'</div><div class="v">'+esc(masaz)+(/min/.test(masaz)?'':' · '+S.minuty+' min')+'</div></div>'
     +(uz.t?'<div class="card warn wide"><div class="k">'+X.warn+tag(uz.cz)+'</div><div class="v">'+esc(uz.t)+'</div></div>':'')
     +((tlak||pf.t)?'<div class="card wide"><div class="k">'+X.pref+tag(pf.cz)+'</div><div class="t">'+(tlak?X.press+': <b>'+esc(tlak)+'</b>\n':'')+esc(pf.t)+'</div></div>':'')
     +(pozn.length?'<div class="card wide"><div class="k">'+X.notes+tag(poznCz)+'</div><div class="t">'+pozn.map(esc).join('\n')+'</div></div>':'')
     +'</div><div class="row"><div class="mut small">'+X.now+' <b id="cl2"></b></div><button class="btn ok" id="b3">'+X.start+'</button></div>';
  }else if(S.stav==='bezi'){
    h='<div class="mut small fade">'+esc(S.prijmeni)+' · '+esc(masaz)+'</div><div class="ring fade"><svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="46" fill="none" stroke="#264743" stroke-width="2"/><circle id="pr" cx="50" cy="50" r="46" fill="none" stroke="#e2b765" stroke-width="2.4" stroke-linecap="round" stroke-dasharray="289" stroke-dashoffset="0"/></svg>'
     +'<div class="in"><div class="mut small">'+X.left+'</div><div class="huge" id="cd"></div><div class="mut small" style="margin-top:1.6vmin">'+X.endsat+' <b>'+hhmm(S.konec*1000)+'</b> · '+X.now.toLowerCase()+' <span id="cl2"></span></div></div></div>'
     +'<div class="mut small" id="pre" style="opacity:.35">'+X.early+'</div>';
  }else if(S.stav==='konec'){
    /* obrazovka pro zákazníka: vždy česky */
    h='<div class="fade"><h1>Děkujeme, že jste si u nás odpočinuli.</h1><div class="mut small" style="margin-top:1vmin">Těšíme se na vás příště. Zůstaňte klidně ještě chvíli ležet.</div></div><div class="row" style="align-items:flex-start;gap:8vmin;margin-top:1vmin">'
     +'<div class="fade"><h2>'+(S.ohodnoceno?'Děkujeme za hodnocení ♥':'Jak se vám masáž líbila?')+'</h2>'
     +(S.ohodnoceno?'':'<div class="stars" style="margin-top:1vmin">'+[[1,'😞','Špatné'],[2,'🙁','Slabší'],[3,'😐','Průměrné'],[4,'🙂','Dobré'],[5,'😍','Výborné']].map(a=>'<button data-z="'+a[0]+'" aria-label="'+a[2]+'">'+a[1]+'</button>').join('')+'</div>')+'</div>'
     +(S.spropitne?'<div class="fade"><h2>Spropitné pro '+esc(S.maserka)+'</h2><div class="row" style="gap:3vmin;align-items:center;margin-top:1vmin"><div class="qr" id="qr"></div></div>'
        +'<div class="chips">'+[50,100,200,0].map(c=>'<button class="chip'+(tip===c?' on':'')+'" data-c="'+c+'">'+(c?c+' Kč':'jiná částka')+'</button>').join('')+'</div><div class="mut small" style="margin-top:.8vmin">Naskenujte v bankovní aplikaci</div></div>':'')
     +'</div><button class="btn sec" id="b4" style="margin-top:2vmin">Hotovo</button>';
  }
  m.innerHTML=h;
  const on=(s,f)=>{const e=$(s);if(e)e.onclick=f};
  on('#b1',()=>{odemkniZvuk();poll('potvrdit')});on('#b2',()=>poll('nejsem'));
  on('#b3',()=>{odemkniZvuk();poll('start')});on('#b4',()=>poll('zavrit'));
  m.querySelectorAll('.stars button').forEach(b=>b.onclick=()=>{m.querySelectorAll('.stars button').forEach(x=>x.classList.toggle('on',x===b));setTimeout(()=>poll('hodnoceni',{znamka:+b.dataset.z}),350)});
  m.querySelectorAll('.chip').forEach(b=>b.onclick=()=>{tip=+b.dataset.c;m.querySelectorAll('.chip').forEach(x=>x.classList.toggle('on',+x.dataset.c===tip));kresliQr()});
  const pr=$('#pre');if(pr){let t=null;const s=()=>{t=setTimeout(()=>poll('predcasne'),2000)},e=()=>clearTimeout(t);pr.onpointerdown=s;pr.onpointerup=pr.onpointerleave=pr.onpointercancel=e}
  kresliQr();tick();
}
/* tlačítko „Hold = česky“: dokud je stisknuté, je vše česky */
(function(){const b=$('#lg');let id=null;
  const on=e=>{e.preventDefault();try{b.setPointerCapture(e.pointerId)}catch(x){}hold=true;render()};
  const off2=()=>{if(hold){hold=false;render()}};
  b.addEventListener('pointerdown',on);['pointerup','pointercancel','lostpointercapture'].forEach(ev=>b.addEventListener(ev,off2));
  b.addEventListener('contextmenu',e=>e.preventDefault());
})();
function kresliQr(){const e=$('#qr');if(e&&S.spropitne&&typeof qrcode==='function')e.innerHTML=qrSvg(spayd())}
function tick(){
  const t=now();
  ['#cl','#cl2'].forEach(s=>{const e=$(s);if(e)e.textContent=hhmm(t,s==='#cl'?false:false)});
  $('#hd').textContent=hhmm(t);
  if(S.stav==='bezi'){
    const zb=Math.max(0,Math.ceil((S.konec*1000-t)/1000)),mm=Math.floor(zb/60),ss=zb%60;
    const cd=$('#cd');if(cd){cd.textContent=String(mm).padStart(2,'0')+':'+String(ss).padStart(2,'0');cd.style.color=zb<=300?'#e2b765':''}
    const pr=$('#pr');if(pr){const celk=(S.konec-S.start)*1000;pr.style.strokeDashoffset=String(289*(1-Math.max(0,Math.min(1,(S.konec*1000-t)/celk))))}
    if(zb<=0&&!S._pozadano){S._pozadano=true;poll()}
  }
}
$('#lg').textContent=tx().lg;setInterval(tick,500);setInterval(()=>poll(),3000);poll();
if(!ctx){setTimeout(()=>{if(!zvukUz)$('#snd').style.display='flex'},1200)}
</script></body></html>
HTML;

/* ---------- správa masérn a tabletů ---------- */

function mistnost_url(array $m): string
{
    return rtrim(cfg()['url'] ?? '', '/') . '/?r=tablet&t=' . $m['token'];
}

function stranka_masterny(array $u): void
{
    $smi = smi_upravit_tym($u);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        vyzaduj_tym($u);
        $akce = $_POST['akce'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        if ($akce === 'nova') {
            $n = trim($_POST['nazev'] ?? '');
            if ($n === '') flash('Zadejte název masérny.', 'err');
            else { q('INSERT INTO mistnosti(nazev,token,poradi) VALUES(?,?,?)', [$n, bin2hex(random_bytes(16)), (int)qv('SELECT COALESCE(MAX(poradi),0)+1 FROM mistnosti')]); flash('Masérna přidána.'); }
        } elseif ($akce === 'ulozit' && $id) {
            q('UPDATE mistnosti SET nazev=?, aktivni=? WHERE id=?', [trim($_POST['nazev'] ?? '') ?: 'Masérna', !empty($_POST['aktivni']) ? 1 : 0, $id]);
            flash('Uloženo.');
        } elseif ($akce === 'token' && $id) {
            q('UPDATE mistnosti SET token=? WHERE id=?', [bin2hex(random_bytes(16)), $id]);
            flash('Nový odkaz vytvořen. Tablet je potřeba znovu otevřít na novém odkazu.');
        } elseif ($akce === 'uvolnit' && $id) {
            q("UPDATE sezeni SET stav='zruseno', zavreno=? WHERE mistnost_id=? AND stav IN ('cekani','bezi','konec')", [nyni(), $id]);
            flash('Tablet uvolněn.');
        } elseif ($akce === 'nastaveni') {
            q("INSERT INTO nastaveni(klic,hodnota) VALUES('spropitne_ucet',?) ON CONFLICT(klic) DO UPDATE SET hodnota=excluded.hodnota", [trim($_POST['spropitne_ucet'] ?? '')]);
            q("INSERT INTO nastaveni(klic,hodnota) VALUES('odmena_min_hodnoceni',?) ON CONFLICT(klic) DO UPDATE SET hodnota=excluded.hodnota", [(string)max(1, (int)($_POST['min'] ?? 3))]);
            flash('Nastavení uloženo.');
        }
        presmeruj('/?r=masterny');
    }
    $mist = q('SELECT * FROM mistnosti ORDER BY aktivni DESC, poradi, nazev')->fetchAll();
    $nic = (int)qv('SELECT COUNT(*) FROM maserky WHERE aktivni=1 AND ucet<>\'\'') ;
    hlavicka('Masérny a tablety', 'tym'); ?>
    <h1>Tým</h1>
    <?php tym_nav('masterny'); ?>
    <p class="muted small">Na každou masérnu jeden tablet. Odkaz níže otevřete na tabletu v prohlížeči (nejlépe v celoobrazovkovém / kiosk režimu) – nepřihlašuje se, pozná se podle tajného odkazu. Recepce pak v rezervaci tlačítkem <b>Poslat na tablet</b> pošle masáž do vybrané masérny.</p>
    <table class="tbl">
      <tr><th>Masérna</th><th>Stav</th><th>Tablet</th><th>Odkaz pro tablet</th></tr>
      <?php foreach ($mist as $m):
        $s = $m['aktivni'] ? sezeni_aktivni((int)$m['id']) : null;
        $on = $m['posledni_spojeni'] && strtotime($m['posledni_spojeni']) > time() - 30;
        $info = '—';
        if ($s) {
            $rr = q1('SELECT z.prijmeni, z.jmeno FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id WHERE r.id=?', [$s['rezervace_id']]);
            $info = '<b>' . h(SEZENI_STAVY[$s['stav']]) . '</b> · ' . h($rr['prijmeni'] ?: $rr['jmeno'])
                . ($s['stav'] === 'bezi' ? ' · konec ' . h(substr((string)$s['plan_konec'], 11, 5)) : '')
                . ($s['neni_ona'] ? ' <b class="warn">masérka hlásí, že to není ona</b>' : '');
        } ?>
        <tr<?= $m['aktivni'] ? '' : ' class="muted"' ?>>
          <td><b><?= h($m['nazev']) ?></b><?= $m['aktivni'] ? '' : ' <span class="tag">neaktivní</span>' ?></td>
          <td><?= $info ?></td>
          <td><?= $on ? '🟢 online' : ($m['posledni_spojeni'] ? '⚪ naposledy ' . h(substr($m['posledni_spojeni'], 5, 11)) : '⚪ nikdy nepřipojen') ?></td>
          <td><input readonly value="<?= h(mistnost_url($m)) ?>" onclick="this.select()">
            <details><summary class="muted small">QR kód pro nastavení jiného tabletu / telefonu</summary>
              <div class="qrbox" data-qr="<?= h(mistnost_url($m)) ?>" style="background:#fff;padding:10px;display:inline-block;margin-top:6px;width:220px;height:220px"></div>
              <div class="muted small">Naskenujte běžnou aplikací fotoaparátu a otevřete v „Thai Most Tablet“ (nebo v prohlížeči).</div></details></td>
        </tr>
        <?php if ($smi): ?>
        <tr><td colspan="4" style="border-top:0;padding-top:0"><details><summary class="muted small">upravit <?= h($m['nazev']) ?></summary>
          <form method="post" class="inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="ulozit"><input type="hidden" name="id" value="<?= $m['id'] ?>">
            <label>Název<input name="nazev" value="<?= h($m['nazev']) ?>"></label>
            <label class="check"><input type="checkbox" name="aktivni" value="1" <?= $m['aktivni'] ? 'checked' : '' ?>> Aktivní</label>
            <button class="btn primary">Uložit</button>
          </form>
          <form method="post" class="inline"><?= csrf_pole() ?><input type="hidden" name="id" value="<?= $m['id'] ?>">
            <button name="akce" value="uvolnit" class="btn small" data-confirm="Zrušit právě probíhající nebo čekající masáž na tabletu?">uvolnit tablet</button>
            <button name="akce" value="token" class="btn small" data-confirm="Vytvořit nový odkaz? Starý přestane fungovat a tablet se musí znovu otevřít.">nový odkaz</button>
          </form></details></td></tr>
        <?php endif ?>
      <?php endforeach ?>
      <?php if (!$mist): ?><tr><td colspan="4" class="muted">Zatím žádná masérna. Přidejte první níže.</td></tr><?php endif ?>
    </table>
    <div class="card"><h2>Aplikace pro Android</h2>
      <p class="muted small">Tablet i telefon: nainstalujte aplikaci z odkazu níže (v prohlížeči zařízení) a pak naskenujte QR kód masérny výše – nic se nepřepisuje. Aplikace je volitelná, tablet funguje i v běžném prohlížeči.</p>
      <input readonly value="https://github.com/kucerazdenek78-jpg/thaimost/releases/latest/download/thai-most-tablet.apk" onclick="this.select()">
      <div class="qrbox" data-qr="https://github.com/kucerazdenek78-jpg/thaimost/releases/latest/download/thai-most-tablet.apk" style="background:#fff;padding:10px;display:inline-block;margin-top:8px;width:220px;height:220px"></div></div>
    <script src="/qrcode.js"></script>
    <script>document.querySelectorAll('.qrbox').forEach(function(e){try{var q=qrcode(0,'M');q.addData(e.dataset.qr);q.make();e.innerHTML=q.createSvgTag({cellSize:4,margin:0,scalable:true});var v=e.querySelector('svg');if(v){v.style.width='100%';v.style.height='100%'}}catch(x){e.textContent=e.dataset.qr}})</script>
    <?php if ($smi): ?>
    <form method="post" class="card"><?= csrf_pole() ?><input type="hidden" name="akce" value="nova">
      <h2>Přidat masérnu</h2>
      <label>Název (např. „Masérna 1“ nebo „Pokoj u okna“)<input name="nazev" required></label>
      <button class="btn primary">Přidat</button>
    </form>
    <form method="post" class="card"><?= csrf_pole() ?><input type="hidden" name="akce" value="nastaveni">
      <h2>Spropitné a odměna týdne</h2>
      <label>Společný účet pro spropitné <span class="muted small">(použije se, když masérka nemá vlastní účet; formát 123456789/0100 nebo IBAN)</span>
        <input name="spropitne_ucet" value="<?= h((string)nastaveni('spropitne_ucet', '')) ?>"></label>
      <label>Minimum hodnocení za týden pro odměnu<input type="number" min="1" name="min" value="<?= (int)nastaveni('odmena_min_hodnoceni', 3) ?>"></label>
      <p class="muted small">QR spropitného se bere z účtu u masérky (Tým → Masérky → její profil → „Účet na spropitné“). Aktivních masérek s účtem: <?= $nic ?>.</p>
      <button class="btn primary">Uložit</button>
    </form>
    <?php endif;
    paticka();
}

/* ---------- odměna týdne ---------- */

function stranka_odmena(array $u): void
{
    $smi = smi_upravit_tym($u);
    $pondeli = date('Y-m-d', strtotime('monday this week', strtotime($_GET['tyden'] ?? 'today')));
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $pondeli)) $pondeli = date('Y-m-d', strtotime('monday this week'));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        vyzaduj_tym($u);
        if (($_POST['akce'] ?? '') === 'odmenit' && q1('SELECT id FROM maserky WHERE id=?', [(int)($_POST['maserka_id'] ?? 0)])) {
            q('INSERT INTO odmeny(tyden,maserka_id,poznamka,kdo,cas) VALUES(?,?,?,?,?) ON CONFLICT(tyden) DO UPDATE SET maserka_id=excluded.maserka_id, poznamka=excluded.poznamka, kdo=excluded.kdo, cas=excluded.cas',
                [$pondeli, (int)$_POST['maserka_id'], trim($_POST['poznamka'] ?? ''), kdo(), nyni()]);
            flash('Odměna týdne zapsána.');
        } elseif (($_POST['akce'] ?? '') === 'zrusit') {
            q('DELETE FROM odmeny WHERE tyden=?', [$pondeli]);
            flash('Odměna týdne zrušena.');
        }
        presmeruj('/?r=odmena&tyden=' . $pondeli);
    }
    $od = "$pondeli 00:00:00"; $do = date('Y-m-d 00:00:00', strtotime("$pondeli +7 days"));
    $min = max(1, (int)nastaveni('odmena_min_hodnoceni', 3));
    $radky = [];
    foreach (q('SELECT id, jmeno, barva FROM maserky WHERE aktivni=1 ORDER BY poradi, jmeno')->fetchAll() as $m) {
        $h = q1('SELECT COUNT(*) pocet, AVG(znamka) prumer, SUM(zdroj=\'tablet\') z_tabletu FROM hodnoceni_maserek WHERE maserka_id=? AND cas>=? AND cas<?', [$m['id'], $od, $do]);
        $m['pocet'] = (int)$h['pocet']; $m['prumer'] = $h['pocet'] ? (float)$h['prumer'] : null; $m['tablet'] = (int)$h['z_tabletu'];
        $m['masazi'] = (int)qv("SELECT COUNT(*) FROM rezervace WHERE maserka_id=? AND stav='probehlo' AND zacatek>=? AND zacatek<?", [$m['id'], $od, $do]);
        $m['kvalif'] = $m['pocet'] >= $min;
        $radky[] = $m;
    }
    usort($radky, fn($a, $b) => [$b['kvalif'], $b['prumer'] ?? 0, $b['pocet'], $b['masazi']] <=> [$a['kvalif'], $a['prumer'] ?? 0, $a['pocet'], $a['masazi']]);
    $vitez = $radky && $radky[0]['kvalif'] ? $radky[0] : null;
    $odmena = q1('SELECT o.*, m.jmeno FROM odmeny o JOIN maserky m ON m.id=o.maserka_id WHERE o.tyden=?', [$pondeli]);
    $historie = q('SELECT o.*, m.jmeno FROM odmeny o JOIN maserky m ON m.id=o.maserka_id ORDER BY o.tyden DESC LIMIT 10')->fetchAll();
    $predchozi = date('Y-m-d', strtotime("$pondeli -7 days")); $dalsi = date('Y-m-d', strtotime("$pondeli +7 days"));
    hlavicka('Odměna týdne', 'tym'); ?>
    <h1>Tým</h1>
    <?php tym_nav('odmena'); ?>
    <h2>Odměna týdne
      <small><a href="/?r=odmena&tyden=<?= $predchozi ?>">← předchozí</a> · <?= h(date('j. n.', strtotime($pondeli))) ?> – <?= h(date('j. n. Y', strtotime("$pondeli +6 days"))) ?> · <a href="/?r=odmena&tyden=<?= $dalsi ?>">další →</a></small></h2>
    <p class="muted small">Pořadí podle průměru hodnocení zákazníků za týden (z tabletu i zadaná recepcí). Soutěží ty, které mají aspoň <b><?= $min ?></b> hodnocení; při shodě rozhoduje počet hodnocení, pak počet masáží.</p>
    <table class="tbl">
      <tr><th>#</th><th>Masérka</th><th>Průměr</th><th>Hodnocení</th><th>z toho tablet</th><th>Masáží</th></tr>
      <?php foreach ($radky as $i => $m): ?>
        <tr<?= $m['kvalif'] ? '' : ' class="muted"' ?>>
          <td><?= $m['kvalif'] ? ($i + 1) . ($vitez && $vitez['id'] === $m['id'] ? ' 🏆' : '') : '' ?></td>
          <td><span class="dot" style="background:<?= h($m['barva']) ?>"></span> <a href="/?r=maserka&id=<?= $m['id'] ?>"><?= h($m['jmeno']) ?></a><?= $m['kvalif'] ? '' : ' <span class="muted small">(málo hodnocení)</span>' ?></td>
          <td><?= hvezdy($m['prumer']) ?></td><td><?= $m['pocet'] ?></td><td><?= $m['tablet'] ?></td><td><?= $m['masazi'] ?></td>
        </tr>
      <?php endforeach ?>
    </table>
    <section class="card">
      <?php if ($odmena): ?>
        <h3>🏆 Odměněna: <?= h($odmena['jmeno']) ?></h3>
        <p class="small"><?= h($odmena['poznamka']) ?: '<span class="muted">bez poznámky</span>' ?> <span class="muted">(zapsal <?= h($odmena['kdo']) ?>, <?= h(substr($odmena['cas'], 0, 16)) ?>)</span></p>
      <?php endif ?>
      <?php if ($smi): ?>
      <form method="post" class="inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="odmenit">
        <label>Odměnit<select name="maserka_id"><?php foreach ($radky as $m): ?><option value="<?= $m['id'] ?>" <?= ($odmena ? (int)$odmena['maserka_id'] === (int)$m['id'] : $vitez && $vitez['id'] === $m['id']) ? 'selected' : '' ?>><?= h($m['jmeno']) ?></option><?php endforeach ?></select></label>
        <label>Odměna / poznámka<input name="poznamka" value="<?= h($odmena['poznamka'] ?? '') ?>" placeholder="např. bonus 500 Kč, volný den…"></label>
        <button class="btn primary"><?= $odmena ? 'Změnit' : 'Zapsat odměnu týdne' ?></button>
        <?php if ($odmena): ?><button name="akce" value="zrusit" class="btn" data-confirm="Zrušit zápis odměny za tento týden?">Zrušit zápis</button><?php endif ?>
      </form>
      <?php endif ?>
    </section>
    <?php if ($historie): ?>
    <h3>Dosavadní odměny</h3>
    <table class="tbl plain"><?php foreach ($historie as $o): ?>
      <tr><td><a href="/?r=odmena&tyden=<?= h($o['tyden']) ?>">týden od <?= h(date('j. n. Y', strtotime($o['tyden']))) ?></a></td><td><b><?= h($o['jmeno']) ?></b></td><td class="small"><?= h($o['poznamka']) ?></td></tr>
    <?php endforeach ?></table>
    <?php endif;
    paticka();
}
