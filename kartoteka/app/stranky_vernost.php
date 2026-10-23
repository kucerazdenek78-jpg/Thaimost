<?php
// Rychlé hledání zákazníků, věrnostní karty s QR (razítka online), hledání volných termínů, tisk poukazu.

const KARTA_ZNAKY = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/** Bez diakritiky a malými písmeny – aby "novak" našlo "Novák" (SQLite LIKE diakritiku nezná). */
function hledej_normalizuj(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, [
        'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r',
        'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u',
    ]);
}

/** Z textu (kód nebo celý odkaz z QR) vytáhne kód karty, jinak null. */
function karta_kod_z_textu(string $t): ?string
{
    $t = trim($t);
    if (preg_match('~[?&]k=([A-Za-z0-9]{10})~', $t, $m)) $t = $m[1];
    $t = strtoupper($t);
    return preg_match('~^[' . KARTA_ZNAKY . ']{10}$~', $t) ? $t : null;
}

function karta_url(string $kod): string { return rtrim(cfg()['url'] ?? '', '/') . '/?r=karta&k=' . $kod; }

function karta_zakaznika(int $zid): ?array
{
    return q1('SELECT * FROM karty WHERE zakaznik_id=? AND aktivni=1 ORDER BY prirazeno DESC, id DESC', [$zid]);
}

/** Zákazníci odpovídající hledanému textu: příjmení / jméno / telefon / kód karty. */
function zakaznici_hledej(string $hl, int $limit = 300): array
{
    $hl = trim($hl);
    if ($hl === '') return [];
    $kod = karta_kod_z_textu($hl);
    if ($kod) {
        $r = q1('SELECT z.* FROM karty k JOIN zakaznici z ON z.id=k.zakaznik_id WHERE k.kod=?', [$kod]);
        return $r ? [$r] : [];
    }
    $tel = preg_replace('~\D~', '', $hl);
    $jenCisla = $tel !== '' && preg_match('~^[\d\s+()-]+$~', $hl);
    $out = [];
    if ($jenCisla) {
        // telefon: stačí kus čísla (typicky poslední číslice), od 3 číslic
        if (strlen($tel) < 3) return [];
        $out = q('SELECT * FROM zakaznici WHERE telefon LIKE ? ORDER BY prijmeni, jmeno LIMIT ' . $limit, ['%' . $tel . '%'])->fetchAll();
        return $out;
    }
    $casti = array_values(array_filter(preg_split('~\s+~', hledej_normalizuj($hl))));
    foreach (q('SELECT * FROM zakaznici ORDER BY prijmeni, jmeno')->fetchAll() as $z) {
        $hay = hledej_normalizuj($z['jmeno'] . ' ' . $z['prijmeni'] . ' ' . $z['email']);
        $ok = true;
        foreach ($casti as $c) if (strpos($hay, $c) === false) { $ok = false; break; }
        if ($ok) { $out[] = $z; if (count($out) >= $limit) break; }
    }
    return $out;
}

// ---------- JSON pro vyhledávací políčko v hlavičce ----------
function stranka_hledej_zak(array $u): void
{
    header('Content-Type: application/json; charset=utf-8');
    $hl = trim($_GET['q'] ?? '');
    $out = [];
    if (mb_strlen($hl) >= 2) {
        foreach (zakaznici_hledej($hl, 8) as $z) {
            $st = zakaznik_statistika((int)$z['id']); $b = zakaznik_bonus($z);
            $info = [];
            if ($st['posledni']) $info[] = 'naposledy ' . datum_cz($st['posledni']['zacatek'], false) . ' – ' . trim(($st['posledni']['sluzba'] ?? '') . ' ' . ($st['posledni']['var_popis'] ?? ''));
            else $info[] = 'zatím bez návštěvy';
            $info[] = $b['razitka'] . '/' . $b['po'] . ' razítek' . ($b['zdarma'] ? ' · 🎉 zdarma' : '');
            if (!empty($z['blokovany'])) $info[] = '⛔ blokovaný';
            $out[] = ['typ' => 'zak', 'nazev' => jmeno_z($z), 'tel' => telefon_cz($z['telefon']), 'info' => implode(' · ', $info),
                      'telefon' => telefon_cz($z['telefon']), 'jmeno' => $z['jmeno'], 'prijmeni' => $z['prijmeni'], 'email' => $z['email'], 'maserka_id' => $z['pref_maserka_id'],
                      'url' => '/?r=zakaznik&id=' . $z['id'], 'rez' => '/?r=rez_nova&z=' . $z['id'], 'volno' => '/?r=volno&z=' . $z['id']];
        }
        // poukazy podle kódu / jména
        $like = '%' . $hl . '%';
        foreach (q("SELECT * FROM poukazy WHERE kod LIKE ? OR pro_koho LIKE ? OR objednal_jmeno LIKE ? ORDER BY vytvoreno DESC LIMIT 4", [$like, $like, $like])->fetchAll() as $p) {
            $out[] = ['typ' => 'poukaz', 'nazev' => '🎁 ' . $p['kod'], 'tel' => '', 'info' => (STAVY_POUKAZU[$p['stav']] ?? $p['stav']) . ' · zůstatek ' . kc($p['zustatek']) . ($p['pro_koho'] ? ' · pro ' . $p['pro_koho'] : ''),
                      'url' => '/?r=poukaz&id=' . $p['id']];
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Veřejná stránka karty (QR) + pohled recepce ----------
function stranka_karta(): void
{
    $kod = karta_kod_z_textu((string)($_GET['k'] ?? ''));
    if (!limit_ip('karta', 120)) { http_response_code(429); exit('Příliš mnoho dotazů, zkuste to později.'); }
    $k = $kod ? q1('SELECT * FROM karty WHERE kod=?', [$kod]) : null;
    if (!$k) { http_response_code(404); header('Cache-Control: no-store'); exit('Karta nenalezena.'); }
    header('Cache-Control: no-store');
    $u = uzivatel();

    // recepce: přiřazení / zneplatnění
    if ($u && $_SERVER['REQUEST_METHOD'] === 'POST') {
        over_csrf();
        $akce = $_POST['akce'] ?? '';
        $zpet = '/?r=karta&k=' . $k['kod'];
        if ($akce === 'prirad' && !$k['zakaznik_id']) {
            $tel = telefon_norm($_POST['telefon'] ?? '');
            if ($tel === '') { flash('Zadejte platný telefon zákazníka.', 'err'); presmeruj($zpet); }
            $zid = zakaznik_najdi_nebo_zaloz($tel, trim($_POST['jmeno'] ?? ''), trim($_POST['prijmeni'] ?? ''));
            q('UPDATE karty SET zakaznik_id=?, prirazeno=?, kdo=? WHERE id=?', [$zid, nyni(), kdo(), $k['id']]);
            if (($_POST['razitka'] ?? '') !== '') q('UPDATE zakaznici SET pocatecni_razitka=? WHERE id=?', [max(0, (int)$_POST['razitka']), $zid]);
            zapis_log('karta_prirazena', $k['kod'] . " → zákazník #$zid");
            flash('Karta přiřazena zákazníkovi.');
        } elseif ($akce === 'zneplatnit') {
            q('UPDATE karty SET aktivni=0 WHERE id=?', [$k['id']]);
            zapis_log('karta_zneplatnena', $k['kod']);
            flash('Karta zneplatněna.');
        } elseif ($akce === 'obnovit') {
            q('UPDATE karty SET aktivni=1 WHERE id=?', [$k['id']]);
            flash('Karta znovu platná.');
        }
        presmeruj($zpet);
    }

    $z = $k['zakaznik_id'] ? q1('SELECT * FROM zakaznici WHERE id=?', [$k['zakaznik_id']]) : null;
    $b = $z ? zakaznik_bonus($z) : null;
    $st = $z ? zakaznik_statistika((int)$z['id']) : null;
    $poz = $st['posledni'] ?? null;
    ?><!doctype html>
<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>Věrnostní karta · Thai Most</title>
<style>
body{margin:0;font:17px/1.45 -apple-system,"Segoe UI",Roboto,sans-serif;background:#f6f1ea;color:#2b2520}
.w{max-width:460px;margin:0 auto;padding:18px}
.c{background:#fff;border-radius:16px;padding:22px;box-shadow:0 2px 12px rgba(80,50,20,.12);margin-bottom:14px}
h1{margin:0 0 2px;font-size:1.35rem;color:#6b4a2b}.m{color:#7d7067;font-size:.9rem}
.raz{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}.raz i{width:42px;height:42px;border-radius:50%;border:2.5px solid #b07a3a;display:flex;align-items:center;justify-content:center;font-style:normal;font-size:1.2rem}
.raz i.on{background:#b07a3a;color:#fff}.raz i.dar{border-style:dashed}
.big{font-size:2rem;font-weight:700;color:#6b4a2b}
dl{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;margin:10px 0 0}dt{color:#7d7067}dd{margin:0;font-weight:500}
.zdarma{background:#fff1c7;border-radius:10px;padding:10px 14px;font-weight:600;margin-top:10px}
.staff{background:#eef5f0;border:1px solid #bcd9c6;border-radius:12px;padding:14px;font-size:.92rem}
.staff input{display:block;width:100%;margin:4px 0 10px;padding:9px;border:1px solid #ccc;border-radius:8px;font:inherit}
.staff button,.staff a.b{padding:9px 14px;border:0;border-radius:8px;background:#b07a3a;color:#fff;font:inherit;cursor:pointer;text-decoration:none;display:inline-block}
.err{background:#fbe9e7;color:#b3261e;padding:10px 14px;border-radius:10px}.ok{background:#e7f3ec;color:#2e7d4f;padding:10px 14px;border-radius:10px;margin-bottom:10px}
</style></head><body><div class="w">
<?php foreach (flash() as [$typ, $msg]): ?><div class="<?= $typ === 'err' ? 'err' : 'ok' ?>"><?= h($msg) ?></div><?php endforeach ?>
<div class="c">
  <h1>Thai Most · věrnostní karta</h1>
  <?php if (!$k['aktivni']): ?>
    <p class="m">Tato karta byla zneplatněna. Obraťte se prosím na recepci.</p>
  <?php elseif (!$z): ?>
    <p class="m">Karta zatím není aktivovaná. Požádejte o aktivaci na recepci – razítka z papírové kartičky vám převedeme.</p>
  <?php else: ?>
    <p class="m"><?= $z['jmeno'] ? 'Dobrý den, ' . h($z['jmeno']) . '.' : 'Dobrý den.' ?></p>
    <div class="big"><?= $b['razitka'] ?> / <?= $b['po'] ?> <span class="m" style="font-weight:400">razítek</span></div>
    <div class="raz"><?php for ($i = 0; $i < $b['po']; $i++): ?><i class="<?= $i < $b['razitka'] ? 'on' : '' ?><?= $i === $b['po'] - 1 ? ' dar' : '' ?>"><?= $i < $b['razitka'] ? '✓' : ($i === $b['po'] - 1 ? '🎁' : '') ?></i><?php endfor ?></div>
    <p class="m">Po <?= $b['po'] ?> zaplacených masážích máte jednu zdarma.</p>
    <?php if ($b['zdarma'] > 0): ?><div class="zdarma">🎉 Máte nárok na masáž zdarma<?= $b['zdarma'] > 1 ? ' (' . $b['zdarma'] . '×)' : '' ?> – řekněte na recepci.</div><?php endif ?>
    <dl>
      <dt>Masáží celkem</dt><dd><?= (int)$st['pocet'] ?></dd>
      <?php if ($poz): ?>
        <dt>Naposledy</dt><dd><?= h(datum_cz($poz['zacatek'], false)) ?></dd>
        <dt>Masáž</dt><dd><?= h(trim(($poz['sluzba'] ?? '') . ' ' . ($poz['var_popis'] ?? ''))) ?></dd>
        <?php if (!empty($poz['maserka'])): ?><dt>Masérka</dt><dd><?= h($poz['maserka']) ?></dd><?php endif ?>
      <?php endif ?>
    </dl>
  <?php endif ?>
</div>

<?php if ($u): ?>
<div class="staff">
  <b>Recepce</b> (přihlášen: <?= h($u['jmeno']) ?>) · karta <?= h($k['kod']) ?>
  <?php if (!$z && $k['aktivni']): ?>
    <form method="post"><?= csrf_pole() ?><input type="hidden" name="akce" value="prirad">
      <p style="margin:8px 0 2px">Přiřadit zákazníkovi (existujícího najdu podle telefonu, nového založím):</p>
      <input name="telefon" type="tel" placeholder="Telefon *" required autofocus>
      <input name="jmeno" placeholder="Jméno (u nového)"><input name="prijmeni" placeholder="Příjmení (u nového)">
      <input name="razitka" type="number" min="0" placeholder="Razítka z papírové kartičky (nepovinné)">
      <button>Přiřadit kartu</button>
    </form>
  <?php elseif ($z): ?>
    <p style="margin:8px 0"><a class="b" href="/?r=zakaznik&id=<?= $z['id'] ?>">Karta zákazníka: <?= h(jmeno_z($z)) ?></a>
      <a class="b" style="background:#6b4a2b" href="/?r=volno&z=<?= $z['id'] ?>">Volné termíny</a></p>
  <?php endif ?>
  <form method="post" style="margin-top:8px"><?= csrf_pole() ?>
    <?php if ($k['aktivni']): ?><button name="akce" value="zneplatnit" style="background:#b3261e" onclick="return confirm('Zneplatnit kartu (ztracená)? Zákazník dostane novou.')">Zneplatnit kartu</button>
    <?php else: ?><button name="akce" value="obnovit">Obnovit platnost</button><?php endif ?>
  </form>
</div>
<?php endif ?>
</div></body></html><?php
    exit;
}

// ---------- Správa karet ----------
function stranka_karty(array $u): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = $_POST['akce'] ?? '';
        if ($akce === 'nove') {
            $n = max(1, min(200, (int)($_POST['pocet'] ?? 0)));
            $ins = db()->prepare('INSERT OR IGNORE INTO karty(kod,kdo) VALUES(?,?)');
            $hotovo = 0;
            while ($hotovo < $n) {
                $kod = '';
                for ($i = 0; $i < 10; $i++) $kod .= KARTA_ZNAKY[random_int(0, strlen(KARTA_ZNAKY) - 1)];
                $ins->execute([$kod, kdo()]);
                if ($ins->rowCount()) $hotovo++;
            }
            zapis_log('karty_nove', (string)$n);
            flash("Vytvořeno $n nových karet. Vytiskněte je tlačítkem „Tisk QR“.");
        } elseif ($akce === 'najit') {
            $kod = karta_kod_z_textu($_POST['kod'] ?? '');
            if ($kod && q1('SELECT 1 FROM karty WHERE kod=?', [$kod])) presmeruj('/?r=karta&k=' . $kod);
            flash('Karta s tímto kódem neexistuje.', 'err');
        }
        presmeruj('/?r=karty');
    }
    $nevydane = (int)qv('SELECT COUNT(*) FROM karty WHERE zakaznik_id IS NULL AND aktivni=1');
    $rows = q('SELECT k.*, z.jmeno, z.prijmeni, z.telefon FROM karty k LEFT JOIN zakaznici z ON z.id=k.zakaznik_id ORDER BY k.id DESC LIMIT 300')->fetchAll();
    hlavicka('Věrnostní karty', 'karty'); ?>
    <div class="cal-nav"><h1>Věrnostní karty s QR</h1></div>
    <p class="muted small">Plastová karta má QR kód. Zákazník ho naskenuje telefonem a uvidí svá razítka, počet masáží a poslední masáž (bez přihlášení). Recepce kartu naskenuje svým telefonem (už přihlášena) nebo kód zadá níže a přiřadí ji zákazníkovi + přepíše razítka z papírové kartičky.</p>
    <div class="cols"><div>
      <table class="tbl">
        <tr><th>Kód</th><th>Zákazník</th><th>Stav</th><th>Přiřazeno</th></tr>
        <?php foreach ($rows as $k): ?>
          <tr onclick="location='/?r=karta&k=<?= h($k['kod']) ?>'">
            <td><a href="/?r=karta&k=<?= h($k['kod']) ?>"><?= h($k['kod']) ?></a></td>
            <td><?= $k['zakaznik_id'] ? '<a href="/?r=zakaznik&id=' . $k['zakaznik_id'] . '">' . h(jmeno_z($k)) . '</a>' : '<span class="muted">nepřiřazena</span>' ?></td>
            <td><?= $k['aktivni'] ? '' : '<span class="tag nesp">zneplatněna</span>' ?></td>
            <td class="small"><?= h($k['prirazeno'] ? datum_cz($k['prirazeno'], false) : '') ?></td>
          </tr>
        <?php endforeach ?>
        <?php if (!$rows): ?><tr><td colspan="4" class="muted">Zatím žádné karty. Vytvořte první dávku vpravo.</td></tr><?php endif ?>
      </table>
    </div><div>
      <form method="post" class="card side"><?= csrf_pole() ?><input type="hidden" name="akce" value="nove">
        <h3>Vytvořit nové karty</h3>
        <label>Kolik kusů<input type="number" name="pocet" min="1" max="200" value="50"></label>
        <button class="btn primary">Vytvořit</button>
        <p class="muted small">Nepřiřazených karet: <b><?= $nevydane ?></b></p>
        <a class="btn" href="/?r=karty_tisk" target="_blank">Tisk QR (nepřiřazené)</a>
      </form>
      <form method="post" class="card side"><?= csrf_pole() ?><input type="hidden" name="akce" value="najit">
        <h3>Najít kartu podle kódu</h3>
        <input name="kod" placeholder="kód nebo odkaz z QR" autocomplete="off">
        <button class="btn" style="margin-top:8px">Otevřít</button>
        <button type="button" class="btn" style="margin-top:8px" onclick="ThaiScan.open(function(t){var f=document.querySelector('input[name=kod]');f.value=t;f.form.submit()})">📷 Skenovat</button>
      </form>
    </div></div>
    <?php paticka();
}

/** Tisková sestava QR kódů nepřiřazených karet (na štítky / plastové karty). */
function stranka_karty_tisk(array $u): void
{
    $rows = q('SELECT kod FROM karty WHERE zakaznik_id IS NULL AND aktivni=1 ORDER BY id LIMIT 200')->fetchAll(PDO::FETCH_COLUMN);
    ?><!doctype html><html lang="cs"><head><meta charset="utf-8"><title>QR karty – tisk</title>
<style>
body{margin:0;font:12px sans-serif;color:#000}.top{padding:10px;font-size:14px}
.g{display:grid;grid-template-columns:repeat(3,1fr);gap:0}.k{border:1px dashed #999;padding:10px;text-align:center;height:62mm;display:flex;flex-direction:column;align-items:center;justify-content:center;break-inside:avoid}
.k .q{width:36mm;height:36mm}.k b{font-size:13px;margin-top:4px}.k span{font-size:10px;letter-spacing:1px}
@media print{.top{display:none}}
</style></head><body>
<div class="top">Karet k tisku: <?= count($rows) ?>. <button onclick="print()">Tisknout</button> (nastavte měřítko 100 %, bez záhlaví).</div>
<div class="g"><?php foreach ($rows as $kod): ?>
  <div class="k"><div class="q" data-qr="<?= h(karta_url($kod)) ?>"></div><b>Thai Most</b><span><?= h($kod) ?></span></div>
<?php endforeach ?></div>
<script src="/qrcode.js"></script>
<script>document.querySelectorAll('.q').forEach(function(e){var q=qrcode(0,'M');q.addData(e.dataset.qr);q.make();e.innerHTML=q.createSvgTag({cellSize:4,margin:0,scalable:true});var v=e.querySelector('svg');if(v){v.style.width='100%';v.style.height='100%'}})</script>
</body></html><?php
    exit;
}

// ---------- Hledání volných termínů ----------
function stranka_volno(array $u): void
{
    $z = !empty($_GET['z']) ? q1('SELECT * FROM zakaznici WHERE id=?', [(int)$_GET['z']]) : null;
    $st = $z ? zakaznik_statistika((int)$z['id']) : null;
    $varId = (int)($_GET['v'] ?? 0);
    if (!$varId && $st && $st['posledni']) $varId = (int)$st['posledni']['varianta_id'];
    $mas = isset($_GET['m']) ? (int)$_GET['m'] : 0;   // výchozí: všechny masérky (oblíbená je označená ★ a první)
    $oblib = $z && $z['pref_maserka_id'] ? (int)$z['pref_maserka_id'] : 0;
    $od = preg_match('~^\d{4}-\d{2}-\d{2}$~', $_GET['od'] ?? '') ? $_GET['od'] : date('Y-m-d');
    $dny = max(1, min(14, (int)($_GET['dny'] ?? 7)));
    $krok = in_array((int)($_GET['krok'] ?? 30), [15, 30, 60], true) ? (int)($_GET['krok'] ?? 30) : 30;
    $oknoOd = preg_match('~^\d{2}:\d{2}$~', $_GET['okno_od'] ?? '') ? $_GET['okno_od'] : '';
    $oknoDo = preg_match('~^\d{2}:\d{2}$~', $_GET['okno_do'] ?? '') ? $_GET['okno_do'] : '';
    $var = $varId ? q1('SELECT v.*, s.nazev FROM varianty v JOIN sluzby s ON s.id=v.sluzba_id WHERE v.id=?', [$varId]) : null;
    $delka = (int)($_GET['delka'] ?? 0) ?: (int)($var['minuty'] ?? 0);

    $maserky = q('SELECT * FROM maserky WHERE aktivni=1 ORDER BY poradi')->fetchAll();
    $kandidati = array_values(array_filter($maserky, fn($m) => !$mas || (int)$m['id'] === $mas));
    usort($kandidati, fn($a, $b) => ((int)$b['id'] === $oblib) <=> ((int)$a['id'] === $oblib));

    $volno = [];   // [datum => [čas => [masérka_id, ...]]]
    if ($var && $delka > 0) {
        $zacOd = nastaveni('oteviraci_od', '09:00'); $zacDo = nastaveni('oteviraci_do', '20:00');
        $minOd = (int)substr($zacOd, 0, 2) * 60 + (int)substr($zacOd, 3, 2);
        $minDo = (int)substr($zacDo, 0, 2) * 60 + (int)substr($zacDo, 3, 2);
        $konecRozsahu = date('Y-m-d', strtotime("$od +$dny days"));
        $obs = [];
        foreach (q("SELECT maserka_id, zacatek, konec FROM rezervace WHERE maserka_id IS NOT NULL AND stav IN ('potvrzeno','probehlo') AND zacatek < ? AND konec > ?", [$konecRozsahu, $od])->fetchAll() as $r)
            $obs[(int)$r['maserka_id']][] = [strtotime($r['zacatek']), strtotime($r['konec'])];
        for ($i = 0; $i < $dny; $i++) {
            $d = date('Y-m-d', strtotime("$od +$i days"));
            for ($t = $minOd; $t + $delka <= $minDo; $t += $krok) {
                $hh = sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
                if ($oknoOd && $hh < $oknoOd) continue;
                if ($oknoDo && $hh > $oknoDo) continue;
                $s = strtotime("$d $hh:00"); $e = $s + $delka * 60;
                if ($s < time()) continue;
                foreach ($kandidati as $m) {
                    $kol = false;
                    foreach ($obs[(int)$m['id']] ?? [] as [$a, $b]) if ($a < $e && $b > $s) { $kol = true; break; }
                    if (!$kol) $volno[$d][$hh][] = (int)$m['id'];
                }
            }
        }
    }
    $nazvy = array_column($maserky, 'jmeno', 'id');
    $barvy = array_column($maserky, 'barva', 'id');
    $dnyCz = ['Neděle', 'Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota'];

    hlavicka('Volné termíny', 'volno'); ?>
    <div class="cal-nav"><h1>Volné termíny</h1></div>
    <?php if ($z): ?>
      <p class="card" style="padding:10px 14px"><b><?= h(jmeno_z($z)) ?></b> · <?= h(telefon_cz($z['telefon'])) ?> · <?= stitky_html(zakaznik_stitky($z, $st)) ?> <?= bonus_html(zakaznik_bonus($z)) ?><br>
        <?php if ($st['posledni']): ?><span class="small">Minule: <?= h(datum_cz($st['posledni']['zacatek'], false)) ?> – <?= h(trim(($st['posledni']['sluzba'] ?? '') . ' ' . ($st['posledni']['var_popis'] ?? ''))) ?>, <?= h($st['posledni']['maserka'] ?? '—') ?><?= $st['posledni']['poznamka_masaz'] ? ' · „' . h(mb_strimwidth($st['posledni']['poznamka_masaz'], 0, 100, '…')) . '“' : '' ?></span><br><?php endif ?>
        <?php if ($z['pref_text'] || $z['pref_tlak']): ?><span class="small">💬 <?= h(trim(($z['pref_tlak'] ? 'tlak ' . TLAK[$z['pref_tlak']] . '. ' : '') . $z['pref_text'])) ?></span><?php endif ?></p>
    <?php else: ?>
      <p class="muted small">Zákazníka vyberete až při rezervaci (telefon). Nebo začněte hledáním nahoře a na jeho kartě zvolte „Najít volný termín“.</p>
    <?php endif ?>
    <form class="card" method="get"><input type="hidden" name="r" value="volno"><?php if ($z): ?><input type="hidden" name="z" value="<?= $z['id'] ?>"><?php endif ?>
      <div class="grid3">
        <label>Služba / délka *<select name="v" required><?= varianty_select($varId ?: null) ?></select></label>
        <label>Masérka<select name="m"><?= maserky_select($mas ?: null, true, '— kterákoli volná —') ?></select></label>
        <label>Od data<input type="date" name="od" value="<?= h($od) ?>"></label>
      </div>
      <div class="grid3">
        <label>Počet dní<select name="dny"><?= moznosti([1 => '1', 3 => '3', 7 => '7', 14 => '14'], $dny) ?></select></label>
        <label>Začátek v čase od – do<span style="display:flex;gap:6px"><input type="time" name="okno_od" value="<?= h($oknoOd) ?>"><input type="time" name="okno_do" value="<?= h($oknoDo) ?>"></span></label>
        <label>Krok<select name="krok"><?= moznosti([15 => '15 min', 30 => '30 min', 60 => '60 min'], $krok) ?></select></label>
      </div>
      <button class="btn primary">Hledat</button>
    </form>
    <?php if ($var):
        $qs = $_GET; unset($qs['m']); ?>
      <div class="mas-prepinac"><span class="muted small">Masérka:</span>
        <a class="chip<?= !$mas ? ' on' : '' ?>" href="/?<?= h(http_build_query($qs)) ?>">všechny</a>
        <?php foreach ($maserky as $m): ?><a class="chip<?= $mas === (int)$m['id'] ? ' on' : '' ?>" style="border-color:<?= h($m['barva']) ?>" href="/?<?= h(http_build_query($qs + ['m' => $m['id']])) ?>"><?= h($m['jmeno']) ?><?= $oblib === (int)$m['id'] ? ' ★' : '' ?></a><?php endforeach ?>
      </div>
    <?php endif ?>
    <?php if (!$var): ?>
      <p class="muted">Vyberte službu a klikněte na Hledat.</p>
    <?php elseif (!$volno): ?>
      <p class="flash warn">V tomto rozsahu není nic volného (délka <?= $delka ?> min). Zkuste jinou masérku, víc dní nebo jiné časové okno.</p>
    <?php else: ?>
      <p class="muted small">Volné začátky pro <b><?= h($var['nazev'] . ' ' . $var['popis']) ?></b> (<?= $delka ?> min). Klepnutím vytvoříte rezervaci<?= $z ? ' pro ' . h(jmeno_z($z)) : '' ?>.</p>
      <?php foreach ($volno as $d => $casy): ?>
        <section class="card"><h2><?= $dnyCz[(int)date('w', strtotime($d))] ?> <?= h(date('j. n. Y', strtotime($d))) ?></h2>
          <div class="slots"><?php foreach ($casy as $hh => $ids): foreach ($ids as $mid):
              $url = '/?r=rez_nova&d=' . $d . '&t=' . $hh . '&m=' . $mid . '&v=' . $varId . ($delka !== (int)$var['minuty'] ? '&delka=' . $delka : '') . ($z ? '&z=' . $z['id'] : ''); ?>
            <a class="slotbtn" style="border-color:<?= h($barvy[$mid] ?? '#b07a3a') ?>" href="<?= h($url) ?>"><b><?= $hh ?></b> <span><?= h($nazvy[$mid] ?? '') ?><?= $oblib === $mid ? ' ★' : '' ?></span></a>
          <?php endforeach; endforeach ?></div></section>
      <?php endforeach ?>
    <?php endif ?>
    <?php paticka();
}

// ---------- Tisk poukazu ----------
function stranka_poukaz_tisk(array $u): void
{
    $p = q1('SELECT * FROM poukazy WHERE id=?', [(int)($_GET['id'] ?? 0)]);
    if (!$p) { http_response_code(404); exit('Poukaz nenalezen.'); }
    $hodnota = $p['sluzba_text'] ?: kc($p['hodnota']);
    ?><!doctype html><html lang="cs"><head><meta charset="utf-8"><title>Poukaz <?= h($p['kod']) ?></title>
<style>
@page{size:A5 landscape;margin:0}
body{margin:0;font-family:Georgia,"Times New Roman",serif;color:#3b2a18;background:#eee}
.p{width:210mm;height:148mm;margin:0 auto;background:#fffaf0;border:3mm double #b07a3a;box-sizing:border-box;padding:12mm 16mm;display:flex;flex-direction:column;align-items:center;text-align:center;position:relative}
.p h1{margin:0;font-size:34pt;letter-spacing:3px;color:#6b4a2b;font-weight:400}.p .s{letter-spacing:5px;font-size:11pt;color:#b07a3a;margin:2mm 0 6mm}
.p .h{font-size:30pt;font-weight:700;color:#6b4a2b;margin:2mm 0}.p .r{font-size:13pt;margin:1mm 0}
.p .v{font-size:15pt;font-style:italic;margin:6mm 10mm 2mm;max-width:150mm}
.p .f{position:absolute;left:16mm;right:16mm;bottom:10mm;display:flex;justify-content:space-between;align-items:flex-end;font-size:10pt;text-align:left}
.p .q{width:24mm;height:24mm}.top{padding:10px;text-align:center;font:14px sans-serif}
@media print{body{background:#fff}.top{display:none}}
</style></head><body>
<div class="top"><button onclick="print()">Tisknout</button> – A5 na šířku, bez záhlaví a zápatí</div>
<div class="p">
  <h1>Dárkový poukaz</h1><div class="s">THAI MOST · THAJSKÉ MASÁŽE · MOST</div>
  <div class="h"><?= h($hodnota) ?></div>
  <?php if ($p['sluzba_text']): ?><div class="r">v hodnotě <?= h(kc($p['hodnota'])) ?></div><?php endif ?>
  <?php if ($p['pro_koho']): ?><div class="r">Pro: <b><?= h($p['pro_koho']) ?></b></div><?php endif ?>
  <?php if ($p['od_koho']): ?><div class="r">Od: <b><?= h($p['od_koho']) ?></b></div><?php endif ?>
  <?php if ($p['venovani']): ?><div class="v">„<?= h($p['venovani']) ?>“</div><?php endif ?>
  <div class="f"><div>Kód poukazu: <b style="font-size:13pt;letter-spacing:1px"><?= h($p['kod']) ?></b><br>Platnost do: <b><?= $p['platnost_do'] ? h(date('j. n. Y', strtotime($p['platnost_do']))) : 'neomezeně' ?></b></div>
    <div class="q" data-qr="<?= h($p['kod']) ?>"></div></div>
</div>
<script src="/qrcode.js"></script>
<script>document.querySelectorAll('.q').forEach(function(e){var q=qrcode(0,'M');q.addData(e.dataset.qr);q.make();e.innerHTML=q.createSvgTag({cellSize:4,margin:0,scalable:true});var v=e.querySelector('svg');if(v){v.style.width='100%';v.style.height='100%'}})</script>
</body></html><?php
    exit;
}
