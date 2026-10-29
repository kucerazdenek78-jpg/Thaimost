<?php
declare(strict_types=1);

/**
 * Dárkový poukaz salonu: originální návrh (app/poukaz/darkovy-poukaz-original.pdf) + vyplněná políčka
 * „Jméno“, „Procedura“, „Platnost“ a kód poukazu s QR.
 *
 * Jak se PDF vyrábí (bez PDF knihovny a bez rozšíření GD, na NAS běží holý php:8.3-cli):
 *  - app/poukaz/sablona.pdf je původní PDF jen přeuložené nástrojem qpdf (bez linearizace, klasická xref tabulka),
 *  - k šabloně se připojí „přírůstková aktualizace“ PDF: písmo (podmnožina Gelasio, OFL), obsah stránky s texty
 *    a QR a nový objekt stránky; původní návrh se tím nijak nemění,
 *  - totéž (text + QR) umí poukaz_html() pro tisk z prohlížeče a e-mail zákazníkovi dostane PDF jako přílohu.
 */
require_once __DIR__ . '/qrcode.php';

const POUKAZ_SIRKA = 595.276;                 // šířka stránky v bodech (210 mm)
const POUKAZ_VYSKA = 283.465;                 // výška stránky v bodech (100 mm)
/** Políčka na originálu: [zleva, zprava] v bodech od levého okraje. Svisle jsou všechna stejná. */
const POUKAZ_POLE = ['jmeno' => [25.4, 169.9], 'procedura' => [184.3, 329.3], 'platnost' => [343.7, 488.2]];
const POUKAZ_POLE_Y = [210.2, 238.9];         // horní a dolní okraj políček (od horního okraje stránky)
const POUKAZ_ZAKLADNI_LINKA = 235.0;          // na které výšce (od horního okraje) leží písmo ve vyplněném políčku
const POUKAZ_VELIKOST = 11.0;                 // největší a nejmenší velikost písma v políčku (pt)
const POUKAZ_VELIKOST_MIN = 6.5;
const POUKAZ_BARVA_TEXTU = [0.38, 0.14, 0.04]; // tmavě hnědá, laděná k hnědé z návrhu
/** QR s kódem v béžovém panelu vpravo od „MOST“: [x, y shora, strana] včetně bílého okraje (pt). */
const POUKAZ_QR = [214.0, 147.0, 33.0];
/** Kód poukazu bílým písmem na zeleném pruhu: konec textu zprava (x), základní linka od shora, velikost. */
const POUKAZ_KOD = [518.0, 188.7, 6.8];

function poukaz_dir(): string { return __DIR__ . '/poukaz'; }

/** Metriky písma (mapa Unicode → glyf, šířky, rozměry). */
function poukaz_pismo(): array
{
    static $m = null;
    return $m ??= json_decode((string)file_get_contents(poukaz_dir() . '/pismo.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** Zbaví text řídicích znaků a znaků, které písmo nemá (ty nahradí otazníkem). */
function poukaz_ocisti(string $t): string
{
    $m = poukaz_pismo();
    $t = trim((string)preg_replace('~[\s\x{00A0}]+~u', ' ', $t));
    $o = '';
    foreach (mb_str_split($t) as $ch) $o .= isset($m['gid'][(string)mb_ord($ch)]) ? $ch : '?';
    return $o;
}

/** Šířka textu v bodech při dané velikosti písma. */
function poukaz_sirka(string $t, float $velikost): float
{
    $m = poukaz_pismo();
    $w = 0;
    foreach (mb_str_split($t) as $ch) $w += $m['adv'][$m['gid'][(string)mb_ord($ch)] ?? 0];
    return $w * $velikost / $m['upm'];
}

/** Vloží text do šířky $max: nejdřív zmenší písmo, když ani to nestačí, zkrátí ho třemi tečkami. Vrací [text, velikost]. */
function poukaz_vlozit(string $t, float $max): array
{
    $t = poukaz_ocisti($t);
    for ($v = POUKAZ_VELIKOST; $v >= POUKAZ_VELIKOST_MIN - 1e-6; $v -= 0.25) {
        if (poukaz_sirka($t, $v) <= $max) return [$t, $v];
    }
    $v = POUKAZ_VELIKOST_MIN;
    while (mb_strlen($t) > 1 && poukaz_sirka($t . '…', $v) > $max) $t = rtrim(mb_substr($t, 0, -1));
    return [$t . '…', $v];
}

/** Co se na poukaz vyplní. Prázdné políčko zůstane prázdné (dá se dopsat tužkou). */
function poukaz_texty(array $p): array
{
    $procedura = trim((string)($p['sluzba_text'] ?? ''));
    if ($procedura === '' && (int)($p['hodnota'] ?? 0) > 0) $procedura = 'Hodnota ' . kc((int)$p['hodnota']);
    $platnost = !empty($p['platnost_do']) && strtotime((string)$p['platnost_do']) ? 'do ' . date('j. n. Y', strtotime((string)$p['platnost_do'])) : '';
    $pole = ['jmeno' => trim((string)($p['pro_koho'] ?? '')), 'procedura' => $procedura, 'platnost' => $platnost];
    $out = [];
    foreach (POUKAZ_POLE as $k => [$x0, $x1]) {
        if ($pole[$k] === '') continue;
        [$t, $v] = poukaz_vlozit($pole[$k], ($x1 - $x0) - 2 * 5.5);
        $out[$k] = ['text' => $t, 'x' => $x0 + 5.5, 'y' => POUKAZ_ZAKLADNI_LINKA, 'velikost' => $v];
    }
    return $out;
}

/** Matice QR kódu (pole řádků po bitech) pro kód poukazu. */
function poukaz_qr(string $kod): array
{
    $qr = new QRCode();
    $qr->setErrorCorrectLevel(QR_ERROR_CORRECT_LEVEL_M);
    $qr->addData($kod);
    $qr->make();
    $n = $qr->getModuleCount();
    $m = [];
    for ($r = 0; $r < $n; $r++) for ($c = 0; $c < $n; $c++) $m[$r][$c] = $qr->isDark($r, $c);
    return $m;
}

// ---------------------------------------------------------------- PDF

function poukaz_cislo(float $x): string { return rtrim(rtrim(sprintf('%.3F', $x), '0'), '.') ?: '0'; }

function poukaz_hex(string $t): string
{
    $m = poukaz_pismo();
    $h = '';
    foreach (mb_str_split($t) as $ch) $h .= sprintf('%04X', $m['gid'][(string)mb_ord($ch)] ?? 0);
    return $h;
}

/** Obsah přidané vrstvy: vyplněné texty, kód a QR. */
function poukaz_vrstva(array $p): string
{
    $h = POUKAZ_VYSKA;
    $s = "q\n";
    [$r, $g, $b] = POUKAZ_BARVA_TEXTU;
    foreach (poukaz_texty($p) as $t) {
        $s .= sprintf("BT /FT0 %s Tf %s %s %s rg 1 0 0 1 %s %s Tm <%s> Tj ET\n", poukaz_cislo($t['velikost']), poukaz_cislo($r), poukaz_cislo($g),
            poukaz_cislo($b), poukaz_cislo($t['x']), poukaz_cislo($h - $t['y']), poukaz_hex($t['text']));
    }
    $kod = trim((string)($p['kod'] ?? ''));
    if ($kod !== '') {
        $kod = poukaz_ocisti($kod);
        [$kx, $ky, $kv] = POUKAZ_KOD;
        $text = 'KÓD  ' . $kod;
        $s .= sprintf("BT /FT0 %s Tf 1 1 1 rg 1 0 0 1 %s %s Tm <%s> Tj ET\n", poukaz_cislo($kv), poukaz_cislo($kx - poukaz_sirka($text, $kv)),
            poukaz_cislo($h - $ky), poukaz_hex($text));
        // QR: bílá podložka s tichou zónou + tmavé moduly (vodorovné pásy jedním obdélníkem, ať mezi nimi nejsou škvíry)
        [$qx, $qy, $qs] = POUKAZ_QR;
        $m = poukaz_qr($kod);
        $n = count($m);
        $mod = ($qs - 2 * 2.0) / $n;              // 2 pt bílého okraje
        $s .= sprintf("1 1 1 rg %s %s %s %s re f\n0.13 0.07 0.03 rg\n", poukaz_cislo($qx), poukaz_cislo($h - $qy - $qs), poukaz_cislo($qs), poukaz_cislo($qs));
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (!$m[$i][$j]) continue;
                $k = $j; while ($k + 1 < $n && $m[$i][$k + 1]) $k++;
                $s .= sprintf("%s %s %s %s re\n", poukaz_cislo($qx + 2.0 + $j * $mod), poukaz_cislo($h - ($qy + 2.0 + ($i + 1) * $mod)),
                    poukaz_cislo(($k - $j + 1) * $mod), poukaz_cislo($mod));
                $j = $k;
            }
        }
        $s .= "f\n";
    }
    return $s . "Q\n";
}

/** Hotové PDF poukazu (řetězec bajtů). */
function poukaz_pdf(array $p): string
{
    $sab = (string)file_get_contents(poukaz_dir() . '/sablona.pdf');
    if (!preg_match('~startxref\s+(\d+)\s+%%EOF\s*$~', $sab, $m)) throw new RuntimeException('Šablona poukazu je poškozená (startxref).');
    $prev = (int)$m[1];
    $tr = substr($sab, (int)strrpos($sab, 'trailer'));
    if (!preg_match('~/Size (\d+)~', $tr, $m1) || !preg_match('~/Root (\d+) 0 R~', $tr, $m2)) throw new RuntimeException('Šablona poukazu je poškozená (trailer).');
    $size = (int)$m1[1];
    $info = preg_match('~/Info (\d+) 0 R~', $tr, $m3) ? " /Info $m3[1] 0 R" : '';
    $id = preg_match('~/ID \[[^\]]*\]~', $tr, $m4) ? ' ' . $m4[0] : '';
    // stránka: objekt se slovníkem „/Type /Page >>“ (ne /Pages); hledá se řetězcově, regulární výraz by přes celý soubor narazil na limit PCRE
    $pos = strpos($sab, '/Type /Page >>');
    $zacatek = $pos === false ? false : strrpos(substr($sab, 0, $pos), " 0 obj\n");
    $konec = $pos === false ? false : strpos($sab, "\nendobj", $pos);
    if ($zacatek === false || $konec === false) throw new RuntimeException('V šabloně poukazu chybí stránka.');
    $cisloOd = (int)strrpos(substr($sab, 0, $zacatek), "\n") + 1;
    $pgNum = (int)substr($sab, $cisloOd, $zacatek - $cisloOd);
    $pgDict = substr($sab, $zacatek + 7, $konec - $zacatek - 7);

    $font = poukaz_pismo();
    $ttf = (string)file_get_contents(poukaz_dir() . '/pismo.ttf');
    $upm = $font['upm'];
    $k = 1000 / $upm;
    $jmeno = 'AAAAAA+Gelasio-Medium';
    $objFile = $size; $objDesc = $size + 1; $objCid = $size + 2; $objUni = $size + 3; $objFont = $size + 4; $objCont = $size + 5;

    $w = '';
    foreach ($font['adv'] as $a) $w .= (int)round($a * $k) . ' ';
    $bf = [];
    foreach ($font['gid'] as $cp => $gid) $bf[$gid] = (int)$cp;
    ksort($bf);
    $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
        . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
    foreach (array_chunk($bf, 100, true) as $chunk) {
        $cmap .= count($chunk) . " beginbfchar\n";
        foreach ($chunk as $gid => $cp) {
            $u = $cp > 0xFFFF ? '' : sprintf('%04X', $cp);
            if ($u !== '') $cmap .= sprintf("<%04X> <%s>\n", $gid, $u);
        }
        $cmap .= "endbfchar\n";
    }
    $cmap .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    $vrstva = poukaz_vrstva($p);

    $strm = fn(string $dict, string $data) => "<< $dict /Length " . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    $tf = (string)gzcompress($ttf, 9);
    $bb = $font['bbox'];
    $objekty = [
        $objFile => $strm('/Filter /FlateDecode /Length1 ' . strlen($ttf), $tf),
        $objDesc => "<< /Type /FontDescriptor /FontName /$jmeno /Flags 34 /FontBBox [" . implode(' ', array_map(fn($v) => (int)round($v * $k), $bb)) . '] /ItalicAngle 0'
            . ' /Ascent ' . (int)round($font['ascent'] * $k) . ' /Descent ' . (int)round($font['descent'] * $k) . ' /CapHeight ' . (int)round($font['cap'] * $k)
            . " /StemV 80 /FontFile2 $objFile 0 R >>",
        $objCid => "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /$jmeno /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>"
            . " /FontDescriptor $objDesc 0 R /CIDToGIDMap /Identity /DW 500 /W [0 [" . trim($w) . ']] >>',
        $objUni => $strm('', $cmap),
        $objFont => "<< /Type /Font /Subtype /Type0 /BaseFont /$jmeno /Encoding /Identity-H /DescendantFonts [$objCid 0 R] /ToUnicode $objUni 0 R >>",
        $objCont => $strm('', $vrstva),
    ];
    // nový objekt stránky: stejný jako původní + naše vrstva a písmo FT0
    $novaStranka = preg_replace('~/Contents \[([^\]]*)\]~', '/Contents [$1 ' . $objCont . ' 0 R]', $pgDict, 1, $c1);
    $novaStranka = preg_replace('~/Font << ~', '/Font << /FT0 ' . $objFont . ' 0 R ', (string)$novaStranka, 1, $c2);
    if (!$c1 || !$c2) throw new RuntimeException('Stránku šablony poukazu se nepodařilo upravit.');

    $out = rtrim($sab, "\r\n") . "\n";
    $off = [];
    $off[$pgNum] = strlen($out);
    $out .= "$pgNum 0 obj\n$novaStranka\nendobj\n";
    foreach ($objekty as $n => $telo) {
        $off[$n] = strlen($out);
        $out .= "$n 0 obj\n$telo\nendobj\n";
    }
    $xref = strlen($out);
    ksort($off);
    $out .= "xref\n";
    $cisla = array_keys($off);
    for ($i = 0; $i < count($cisla);) {
        $j = $i;
        while ($j + 1 < count($cisla) && $cisla[$j + 1] === $cisla[$j] + 1) $j++;
        $out .= $cisla[$i] . ' ' . ($j - $i + 1) . "\n";
        for ($x = $i; $x <= $j; $x++) $out .= sprintf("%010d 00000 n \n", $off[$cisla[$x]]);
        $i = $j + 1;
    }
    $out .= 'trailer << /Size ' . ($size + count($objekty)) . " /Root $m2[1] 0 R$info$id /Prev $prev >>\nstartxref\n$xref\n%%EOF\n";
    return $out;
}

/** Název souboru pro stažení / přílohu. */
function poukaz_soubor(array $p): string { return 'darkovy-poukaz-' . preg_replace('~[^A-Za-z0-9-]~', '', (string)$p['kod']) . '.pdf'; }

// ---------------------------------------------------------------- HTML (tisk z prohlížeče, náhled)

/**
 * Poukaz jako HTML: originální návrh jako obrázek (/poukaz-pozadi.jpg) + stejná políčka a QR jako v PDF.
 * Rozměry jsou v procentech šířky, písmo v jednotkách cqw, takže se poukaz dá libovolně zvětšit i zmenšit.
 */
function poukaz_html(array $p): string
{
    $pct = fn(float $v, float $z) => poukaz_cislo($v / $z * 100) . '%';
    $m = poukaz_pismo();
    $A = $m['ascent'] / $m['upm']; $D = -$m['descent'] / $m['upm'];
    // při line-height:1 leží základní linka (1 - (A+D)) / 2 + A násobků velikosti pod horním okrajem řádku
    $horni = fn(float $zakladna, float $velikost) => $zakladna - ((1 - ($A + $D)) / 2 + $A) * $velikost;
    $s = '<div class="pk"><img src="/poukaz-pozadi.jpg" alt="Dárkový poukaz Thajské masáže Most">';
    foreach (poukaz_texty($p) as $t) {
        $s .= '<span class="pk-t" style="left:' . $pct($t['x'], POUKAZ_SIRKA) . ';top:' . $pct($horni($t['y'], $t['velikost']), POUKAZ_VYSKA)
            . ';font-size:' . poukaz_cislo($t['velikost'] / POUKAZ_SIRKA * 100) . 'cqw">' . htmlspecialchars($t['text'], ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $kod = poukaz_ocisti((string)($p['kod'] ?? ''));
    if ($kod !== '') {
        [$kx, $ky, $kv] = POUKAZ_KOD;
        $s .= '<span class="pk-k" style="right:' . $pct(POUKAZ_SIRKA - $kx, POUKAZ_SIRKA) . ';top:' . $pct($horni($ky, $kv), POUKAZ_VYSKA) . ';font-size:'
            . poukaz_cislo($kv / POUKAZ_SIRKA * 100) . 'cqw">KÓD&nbsp; ' . htmlspecialchars($kod, ENT_QUOTES, 'UTF-8') . '</span>';
        [$qx, $qy, $qs] = POUKAZ_QR;
        $q = poukaz_qr($kod);
        $n = count($q);
        $mod = ($qs - 4.0) / $n;                  // velikost jednoho modulu v bodech; 2 pt okraj kolem
        $okraj = 2.0 / $mod; $celek = $qs / $mod;  // totéž v jednotkách modulů (pro viewBox)
        $d = '';
        for ($i = 0; $i < $n; $i++) for ($j = 0; $j < $n; $j++) {
            if (!$q[$i][$j]) continue;
            $k = $j; while ($k + 1 < $n && $q[$i][$k + 1]) $k++;
            $d .= "M$j {$i}h" . ($k - $j + 1) . 'v1h-' . ($k - $j + 1) . 'z';
            $j = $k;
        }
        $vb = poukaz_cislo(-$okraj) . ' ' . poukaz_cislo(-$okraj) . ' ' . poukaz_cislo($celek) . ' ' . poukaz_cislo($celek);
        $s .= '<svg class="pk-qr" style="left:' . $pct($qx, POUKAZ_SIRKA) . ';top:' . $pct($qy, POUKAZ_VYSKA) . ';width:' . $pct($qs, POUKAZ_SIRKA)
            . '" viewBox="' . $vb . '" shape-rendering="crispEdges"><rect x="' . poukaz_cislo(-$okraj) . '" y="' . poukaz_cislo(-$okraj) . '" width="'
            . poukaz_cislo($celek) . '" height="' . poukaz_cislo($celek) . '" fill="#fff"/><path d="' . $d . '" fill="#211208"/></svg>';
    }
    return $s . '</div>';
}

/** Styl pro poukaz_html(); písmo se vkládá přímo do stránky, aby nebylo třeba další soubor. */
function poukaz_css(): string
{
    $font = base64_encode((string)file_get_contents(poukaz_dir() . '/pismo.ttf'));
    [$r, $g, $b] = POUKAZ_BARVA_TEXTU;
    $barva = sprintf('#%02x%02x%02x', (int)round($r * 255), (int)round($g * 255), (int)round($b * 255));
    return "@font-face{font-family:PoukazPismo;src:url(data:font/ttf;base64,$font) format('truetype')}"
        . ".pk{position:relative;width:100%;aspect-ratio:" . poukaz_cislo(POUKAZ_SIRKA) . '/' . poukaz_cislo(POUKAZ_VYSKA) . ";container-type:inline-size;line-height:1;"
        . "-webkit-print-color-adjust:exact;print-color-adjust:exact}"
        . ".pk img{display:block;width:100%;height:100%}"
        . ".pk-t,.pk-k{position:absolute;font-family:PoukazPismo,Georgia,serif;white-space:nowrap}"
        . ".pk-t{color:$barva}"
        . ".pk-k{color:#fff;letter-spacing:.04em}"
        . ".pk-qr{position:absolute;aspect-ratio:1;height:auto}";
}
