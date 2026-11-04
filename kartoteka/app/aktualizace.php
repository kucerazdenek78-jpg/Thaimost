<?php
// Aktualizace souborů kartotéky z GitHubu přes soubor (když nejde terminál kontejneru), stejně jako vzorová data:
// do data/ se nahraje aktualizace.json  {"commit": "<sha commitu>", "soubory": {"app/lib.php": "<sha256 souboru>", …}}
// Při nejbližším načtení stránky se všechny soubory stáhnou z raw.githubusercontent.com (pevný commit), ověří se
// jejich SHA-256 a teprve když sedí VŠECHNY, zapíší se (staré verze do nasazeni-tmp/zaloha-<datum>/). Výsledek je
// v data/aktualizace-vysledek.txt, soubor aktualizace.json se smaže. Soubor může vložit jen ten, kdo má přístup k NAS.

const AKTUALIZACE_REPO = 'kucerazdenek78-jpg/Thaimost';

function aktualizace_spust(): void
{
    $dir = dirname(cfg()['db_path']);
    $f = "$dir/aktualizace.json";
    if (!is_file($f)) return;
    $zadani = json_decode((string)file_get_contents($f), true);
    if (!@unlink($f)) return;   // nejde smazat = nespouštět (jinak by běželo pořád dokola)
    $vysl = "$dir/aktualizace-vysledek.txt";
    $log = function (string $t) use ($vysl) { @file_put_contents($vysl, date('Y-m-d H:i:s') . "  $t\n", FILE_APPEND); };
    try {
        $commit = (string)($zadani['commit'] ?? '');
        $soubory = $zadani['soubory'] ?? [];
        if (!preg_match('~^[0-9a-f]{7,40}$~', $commit) || !is_array($soubory) || !$soubory) throw new RuntimeException('špatné zadání (commit, soubory)');
        $root = dirname(__DIR__);
        $stazeno = [];
        foreach ($soubory as $cesta => $sha) {
            if (!preg_match('~^(app|public)/[A-Za-z0-9_.-]+\.(php|css|js|json)$~', (string)$cesta)) throw new RuntimeException("nepovolená cesta $cesta");
            $url = 'https://raw.githubusercontent.com/' . AKTUALIZACE_REPO . "/$commit/kartoteka/$cesta";
            $ctx = stream_context_create(['http' => ['timeout' => 30, 'header' => "User-Agent: thaimost-kartoteka\r\n"]]);
            $obsah = @file_get_contents($url, false, $ctx);
            if ($obsah === false || $obsah === '') throw new RuntimeException("stažení selhalo: $cesta");
            if (!hash_equals(strtolower((string)$sha), hash('sha256', $obsah))) throw new RuntimeException("nesedí SHA-256: $cesta");
            $stazeno[$cesta] = $obsah;
        }
        $zal = "$root/nasazeni-tmp/zaloha-" . date('Ymd-His');
        if (!is_dir($zal) && !@mkdir($zal, 0775, true)) throw new RuntimeException('nejde založit zálohu');
        foreach ($stazeno as $cesta => $obsah) {
            $cil = "$root/$cesta";
            if (is_file($cil)) @copy($cil, $zal . '/' . basename($cesta));
            if (@file_put_contents($cil, $obsah) === false) throw new RuntimeException("zápis selhal: $cesta (zbytek nezapsán, záloha v $zal)");
        }
        $log("OK commit $commit: " . implode(', ', array_keys($stazeno)) . " (záloha $zal)");
    } catch (Throwable $e) {
        $log('CHYBA: ' . $e->getMessage());
    }
}
