<?php
// Router pro vestavěný PHP server: statické soubory z public/, vše ostatní přes index.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && preg_match('~^/[a-z0-9_-]+\.(css|js|svg|png|ico|jpg|jpeg|webp)$~i', $path) && is_file(__DIR__ . $path)) {
    return false;
}
require __DIR__ . '/index.php';
