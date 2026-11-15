# Thai Most – nový web

Nový web pro **Thajské masáže Most** (thaimost.cz). Statický web pro GitHub Pages – bez serveru, bez databáze.

## Co obsahuje
- `index.html` – celý web: úvod, O nás, služby a ceník (19 položek), bonus program, **dárkové poukazy**, kontakt s mapou
- `salon.html` – interní stránka pro salon: vystavení poukazu s unikátním kódem a QR, tisk / PDF, evidence (uplatnit, propadlé, export/import CSV). Není v menu, odkaz je v patičce („Pro salon“), vyřazená z vyhledávačů.
- `firma.html` – skrytá firemní stránka (zámeček v patičce, heslo se ověřuje jako SHA-256 v prohlížeči; změna hesla = nový hash v `firma.html`): brand (barvy, písma, logo), reklamy a profily, texty inzerátů, poukaz, odkaz na kartotéku masage.dvere.app. Není v menu, `noindex` + robots.
- `assets/js/data.js` – **všechny ceny, texty služeb a kontakty na jednom místě**

Texty a ceny jsou převzaté z původního thaimost.cz (stav 6. 10. 2026).

## Jak fungují poukazy
1. Zákazník na webu vybere poukaz (na částku nebo konkrétní masáž), napíše pro koho poukaz je (jméno se vytiskne) a vidí živý náhled na originálním návrhu salonu.
2. Po odeslání se mu otevře předvyplněný e-mail na `stepanova.hanka@centrum.cz` s číslem objednávky (+ možnost zkopírovat nebo zavolat).
3. Salon v `salon.html` poukaz vystaví (kód typu `TM-26-XXXXX`), vytiskne nebo uloží jako PDF a eviduje.

**Vzhled poukazu** (11/2026) je nový návrh ve stylu webu – teak, zlatá, lotosové logo, foto wellness (210 × 100 mm). Zdroj je `assets/poukaz-navrh/poukaz-navrh.html` (vykreslit v Chrome při 4× měřítku → `assets/img/poukaz-pozadi.jpg` a `kartoteka/public/poukaz-pozadi.jpg`; PDF šablona `kartoteka/app/poukaz/sablona.pdf` = ten obrázek na jedné stránce, klasická xref tabulka). Políčka Jméno / Procedura / Platnost, kód i QR jsou na **stejných pozicích** jako v původním návrhu salonu (`kartoteka/app/poukaz/darkovy-poukaz-original.pdf`), takže `voucher.js` i `poukaz_pdf.php` se nemění. Písmo vyplněných políček Gelasio (OFL, `assets/fonts/`). Po změně podkladu přepsat SHA-256 a commit v `POUKAZ_PODKLADY` / `POUKAZ_ZDROJ` v `kartoteka/app/poukaz_pdf.php`. Polohy políček jsou v `assets/js/voucher.js` a v `kartoteka/app/poukaz_pdf.php` (stejné číslo v bodech originálního PDF).

## Recenze a „Proč Thai Most“ (11/2026)
- Sekce `#recenze` na úvodní stránce: hodnocení **4,8 z 240 recenzí Google** (stav 3. 11. 2026) + 4 citace. Čísla jsou napsaná přímo v `index.html` (sekce a `aggregateRating` v JSON-LD) – při výrazné změně počtu recenzí je přepsat na obou místech.
- Odkazy: profil `https://www.google.com/maps?cid=13481975087391624487`, napsat recenzi `https://search.google.com/local/writereview?placeid=ChIJu1881C0gCkcRJ82kUumYGbs`.
- Sekce `#proc-my`: 5 masérek, 3 masérny, párová masáž, privátní wellness, ceny, parkování – odlišení od konkurence.
- WhatsApp tlačítko (`wa.me/420778429329`) u objednávky a v mobilní liště.
- Adresy v hlavičce, sitemap a robots už ukazují na `https://www.thaimost.cz/` (připraveno na přesměrování domény).

## Úvodní video
- `assets/video/thaimost-uvod.mp4` (15 s, 16:9, smyčka, s thajskou hudbou) vyrobené v Magnific (Seedance 2.0 Mini), poster `thaimost-uvod-poster.jpg`. Autoplay běží potichu, zvuk zapne tlačítko „Zvuk“ vpravo dole v úvodu.

## Jazyky webu: čeština, angličtina, němčina (11/2026)
Veřejný web (`index.html`) je i anglicky (`/en/`) a německy (`/de/`). Zdrojem je vždy **česká** stránka (`index.html` + `assets/js/data.js`); anglická a německá stránka se z ní **generují**, nepíšou se ručně. `salon.html`, `firma.html` a kartotéka zůstávají česky.
- `tools/i18n/en.json`, `de.json` – slovníky {český text → překlad}. Překlady vznikly přes DeepL (bezplatný účet, pokyn „Formality“ se neuplatní). Kde byl český text pro překladač nejednoznačný (např. „salon“, „termín“), jsou upřesňující vstupy v `tools/i18n/vstup.json` (do slovníku jde překlad, ne tento vstup).
- `tools/i18n.py` (potřebuje `pip install beautifulsoup4`):
  - `python3 tools/i18n.py check` – kolik textů chybí a zda v překladech nechybí čísla, HTML značky a zástupné {…};
  - `python3 tools/i18n.py todo --size 50` – vypíše nepřeložené texty do dávek `tools/i18n/davka-N.json` (+ `davka-N.vstup.json` pro překladač) po 50 (limit DeepL);
  - `python3 tools/i18n.py merge en|de preklady.json davka-N.json` – přidá přeložené dávky do slovníku;
  - `python3 tools/i18n.py build` – vygeneruje `en/index.html`, `de/index.html` a `assets/i18n/en.js`, `de.js`.
- **Po každé změně českého textu** (index.html, data.js) spusťte `check`; nové věty přeložte (`todo` → překladač → `merge`) a spusťte `build`, jinak se na `/en/` a `/de/` ukáže česky (nic se nerozbije). Samotné **ceny** se po změně v `firma.html` ve všech jazycích přepíšou za běhu z `data.js`; statické ceny v `/en/` a `/de/` (pro vyhledávače) se obnoví až po `build`.
- Za běhu skripty používají `assets/js/i18n.js`: `T("český text")` vrací překlad podle `<html lang>`. Údaje pro salon (e-mail s objednávkou, odesílaná data do kartotéky) jsou vždy česky; k poznámce se připíše „[English]“ / „[Deutsch]“. Ceny se v en/de píšou jako CZK. Náhled poukazu zůstává česky (poukaz vystavuje salon česky).
- Mapa webu (`sitemap.xml`) a `<link rel="alternate" hreflang>` propojují všechny tři jazyky; přepínač CS / EN / DE je v hlavičce.

## Co je potřeba doplnit / rozhodnout
- **Číslo účtu** pro platbu poukazů převodem se nastavuje v kartotéce: Nastavení → 🎁 Poukazy a platba převodem (kontrola modulo 11, IBAN se dopočítá). Web si ho bere z `/api/stav`; bez účtu se nabízí jen platba v salonu. Po odeslání objednávky dostane zákazník QR platbu s **VS = číslo poukazu** (id v kartotéce, vrací `/api/poukaz`) a platba se spáruje v Platbách.
- **Platnost poukazů**: v kartotéce Nastavení → Platnost poukazu (výchozí 6 měsíců od nákupu). Nastaví se při objednávce (web i recepce), je vytištěná v políčku Platnost a pod políčky („do tohoto data je potřeba se objednat, zavolejte…“), web ji ukazuje u platby i po odeslání.
- **Fotky služeb**: `assets/img/ai/<id>.jpg` (Magnific, Nano Banana 2.1, 11/2026) – vyrobené ve stylu skutečných fotek salonu (černo-zlatý úbor, fialové ručníky, bambus). Původní fotky jsou v `assets/img/sluzby/`. Navíc `ai/parova.jpg` (párová masáž) pro reklamy.
- Evidence poukazů v `salon.html` je uložená jen v prohlížeči, kde se vystavují – zálohovat přes Export CSV.

## Zveřejnění
GitHub → Settings → Pages → Source: *Deploy from a branch* → `main` / `(root)`.

## Nabídka masáží (e-book ke stažení)

- `nabidka-masazi.html` – vícestránková nabídka (A4), generuje se automaticky z `assets/js/data.js` (ceny + texty + fotky). Na webu odkaz v sekci „Služby a ceník“.
- `nabidka-masazi.pdf` – stažitelný PDF e-book. **Po změně cen/textů v `data.js` přegenerovat:** `python3 tools/nabidka_pdf.py` (potřebuje Google Chrome / Chromium). Poté commitnout nový PDF.
- `nabidka-masazi-tisk.pdf` (a `-en-tisk`, `-de-tisk`) – verze pro tiskárnu: A4 na šířku, spad 3 mm, ořezové značky, TrimBox/BleedBox. Přegenerování: `python3 tools/nabidka_tisk.py`.
