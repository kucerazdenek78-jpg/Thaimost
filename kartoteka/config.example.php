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

    // AI asistent recepce (Claude API). Dokud je api_key prázdný, asistent je vypnutý.
    // Klíč: Claude Console → API keys. Tamtéž nastavte měsíční limit útraty.
    'ai' => [
        'api_key' => '',
        'model' => 'claude-haiku-5-5',    // silnější (dražší): claude-sonnet-5-5
        'max_tokens' => 1500,
        'max_kroku' => 8,                 // kolik kol s nástroji smí jeden dotaz mít
        'denni_limit' => 300,             // max. volání API za den
    ],
];
