<?php
// Router pro vestavěný PHP server: statické soubory z public/, vše ostatní přes index.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Digital Asset Links pro Android aplikaci (TWA) – plná obrazovka bez adresního řádku.
if ($path === '/.well-known/assetlinks.json') {
    $f = __DIR__ . '/.well-known/assetlinks.json';
    if (is_file($f)) { header('Content-Type: application/json'); readfile($f); exit; }
}
// APK aplikace ke stažení (Android po otevření nabídne instalaci).
if ($path === '/thaimost.apk') {
    $f = __DIR__ . '/thaimost.apk';
    if (is_file($f)) {
        header('Content-Type: application/vnd.android.package-archive');
        header('Content-Disposition: attachment; filename="ThaiMost-Kartoteka.apk"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }
    http_response_code(404);
    exit('APK zatím není nahraná.');
}
if ($path !== '/' && preg_match('~^/[a-z0-9_-]+\.(css|js|json|svg|png|ico|jpg|jpeg|webp)$~i', $path) && is_file(__DIR__ . $path)) {
    return false;
}
require __DIR__ . '/index.php';
