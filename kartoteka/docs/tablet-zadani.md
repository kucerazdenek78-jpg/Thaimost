# Tablety v masérnách – zadání a stav (větev `tablet`)

## Zadání od Zdeňka (česky, neformálně; odpovídat česky, nic si nevymýšlet)
Do každé masérny tablet. Workflow:
1. Recepční pošle rezervaci na tablet vybrané masérny.
2. Masérka zkontroluje, že jde o ni (jméno) – potvrdí.
3. Uvidí **příjmení** zákazníka, jakou masáž dělat, preference zákazníka a na co si dát pozor (zlomená noha, vyhřezlé obratle…).
4. Vidí aktuální čas a tlačítko Start. Po startu běží odpočet **zaplacené** doby.
5. Na konci zahraje uklidňující znělka a poděkuje zákazníkovi.
6. Na obrazovce QR kód pro spropitné (masérce) + hodnocení spokojenosti (hvězdy). Hodnocení se eviduje; **jednou týdně se odměňuje masérka s nejlepším skóre**.
Chce návrh i funkční systém, postavený na workflow a úkolech.

## Stav 2026-10-21: úkoly 1–4 a 6 hotovy; lokální test celého toku prošel (78 kontrol: tok, soukromí, token, oprávnění, žebříček, QR/IBAN), vzhled zkontrolován na 1280×800 i 800×1280 (opraven přetékající odpočet, zobrazuje se pražský čas). Zbývá úkol 5 (nasazení update.zip na NAS – dělá uživatel) a vyzkoušení znělky a vzhledu na skutečném tabletu.

## Co je hotové
- `app/db.php`: `DB_VERZE = 4`, `migrate_v4` (tabulky `mistnosti`, `sezeni`, `odmeny`, nastavení `odmena_min_hodnoceni`, `spropitne_ucet`).
- `app/stranky_tablet.php` (lint OK, netestováno): pomocné funkce (`cz_iban`, `rezervace_minuty`, `sezeni_aktivni`), `tablet_poslat`, `tablet_karta`, API `stranka_tablet_api`, kiosk stránka `stranka_tablet` (HTML/JS v nowdoc `TABLET_HTML`: kontrola jména → detail → start → odpočet → znělka (WebAudio) → hvězdy + QR SPAYD), `stranka_masterny` (správa místností/tokenů, účet pro spropitné), `stranka_odmena` (žebříček týdne, zápis odměny).
- `public/qrcode.js` – vendorovaný qrcode-generator 1.4.4 (MIT).
- Soukromí: detail zákazníka (zdraví, preference) se na tablet pošle až po potvrzení jména masérkou a skryje se po startu.

## Zbývá (úkoly)
1. Zapojit: v `app/stranky.php` `require 'stranky_tablet.php'`; v `public/index.php` veřejné routy `tablet` (`stranka_tablet()`) a `tablet_api` (`stranka_tablet_api()`) **před** `vyzaduj_prihlaseni()` (jako `ical`) a přihlášené `masterny`, `odmena`; v `tym_nav()` (`app/stranky_tym.php`) přidat `'masterny' => 'Masérny a tablety', 'odmena' => 'Odměna týdne'`.
2. V detailu rezervace (`stranky.php`, funkce `stranka_rezervace`, POST větve `akce`) přidat `tablet_poslat` / `tablet_zrusit` (volat `tablet_poslat($r, (int)$_POST['mistnost_id'])`, flash chyba/OK, přesměrovat) a před kartu „Stav“ vložit `tablet_karta($r)`.
3. Lokálně otestovat celý tok (config.php s db_path do /tmp, PHP server `php -S 127.0.0.1:8099 -t public public/router.php`, testy přes python urllib – viz předchozí testy): poslat → potvrdit → start → (zkrátit minuty v DB) → konec → hodnocení → zavřít; hlídat, že neplatný token = 403, že detail zákazníka nejde bez potvrzení, že hodnocení se propíše do `hodnoceni_maserek` (zdroj `tablet`) a do žebříčku.
4. Zkontrolovat v prohlížeči vzhled tabletu (playwright screenshoty 1280x800 a 800x1280), znělku nejde otestovat bez zařízení – říct uživateli, ať vyzkouší.
5. Nasazení na NAS: `update.zip` s upravenými soubory (+ `public/qrcode.js`) → DSM File Station (Chrome, uživatel je přihlášen) nahrát do `docker/masaze` (soubor musí být ve scratchpadu), Extrahovat… → Přepsat. Nepřepisovat `docker-compose.yml` ani `config.php`. Migrace proběhne sama při prvním požadavku.
6. Přidat do NAS-UI instrukci pro uživatele: otevřít odkaz z „Tým → Masérny a tablety“ na tabletu, přidat masérkám účet pro spropitné (Tým → Masérky → profil → Účet na spropitné), přidat masérny.
7. Commit na `main` až po testu (větev `tablet` smazat/mergnout), push.

## Rozhodnutí k potvrzení s uživatelem
- Skóre týdne = průměr hodnocení, min. 3 hodnocení (nastavitelné), shoda → víc hodnocení → víc masáží.
- Po skončení odpočtu se rezervace sama přepne na „proběhlo“.
- QR spropitné: účet masérky, jinak společný účet salonu; SPAYD s částkou 50/100/200 nebo bez částky.
