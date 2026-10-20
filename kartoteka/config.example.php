<?php
// Zkopírujte jako config.php a doplňte. config.php se do gitu NENAHRÁVÁ.
return [
    // Kde leží databáze (SQLite). V kontejneru je /app/data namapované na NAS.
    'db_path' => __DIR__ . '/data/kartoteka.sqlite',

    // Odkud smí web posílat formuláře (CORS). Nový web na GitHub Pages + budoucí doména.
    'povolene_origin' => [
        'https://kucerazdenek78-jpg.github.io',
        'https://thaimost.cz',
        'https://www.thaimost.cz',
    ],

    // Kam chodí upozornění na nové žádosti a objednávky poukazů.
    'email_salon' => 'kucerazd@outlook.cz',

    // Odesílání e-mailů (SMTP). Dokud je host prázdný, e-maily se jen uloží
    // do „Neodeslané e-maily“ v kartotéce a nic se neodešle.
    'smtp' => [
        'host' => '',          // např. smtp.seznam.cz
        'port' => 465,         // 465 = SSL, 587 = STARTTLS
        'user' => '',
        'pass' => '',
        'from' => '',          // adresa odesílatele (obvykle = user)
        'from_name' => 'Thai Most',
    ],

    // Veřejná adresa kartotéky (do odkazů v e-mailech pro salon).
    'url' => 'https://masage.dvere.app',

    'casova_zona' => 'Europe/Prague',
];
