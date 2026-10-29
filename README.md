# Thai Most – nový web

Nový web pro **Thajské masáže Most** (thaimost.cz). Statický web pro GitHub Pages – bez serveru, bez databáze.

## Co obsahuje
- `index.html` – celý web: úvod, O nás, služby a ceník (19 položek), bonus program, **dárkové poukazy**, kontakt s mapou
- `salon.html` – interní stránka pro salon: vystavení poukazu s unikátním kódem a QR, tisk / PDF, evidence (uplatnit, propadlé, export/import CSV). Není v menu, odkaz je v patičce („Pro salon“), vyřazená z vyhledávačů.
- `assets/js/data.js` – **všechny ceny, texty služeb a kontakty na jednom místě**

Texty a ceny jsou převzaté z původního thaimost.cz (stav 6. 10. 2026).

## Jak fungují poukazy
1. Zákazník na webu vybere poukaz (na částku nebo konkrétní masáž), napíše pro koho poukaz je (jméno se vytiskne) a vidí živý náhled na originálním návrhu salonu.
2. Po odeslání se mu otevře předvyplněný e-mail na `stepanova.hanka@centrum.cz` s číslem objednávky (+ možnost zkopírovat nebo zavolat).
3. Salon v `salon.html` poukaz vystaví (kód typu `TM-26-XXXXX`), vytiskne nebo uloží jako PDF a eviduje.

**Vzhled poukazu** je originální návrh salonu (`Thai – Dárkový poukaz`, 210 × 100 mm): do prázdných políček Jméno / Procedura / Platnost se doplní text, dole kód s QR. Originál je v `kartoteka/app/poukaz/darkovy-poukaz-original.pdf`, podklad pro web a tisk je `assets/img/poukaz-pozadi.jpg` (vykreslený z toho PDF), písmo vyplněných políček Gelasio (licence OFL, `assets/fonts/`). Polohy políček jsou v `assets/js/voucher.js` a v `kartoteka/app/poukaz_pdf.php` (stejné číslo v bodech originálního PDF).

## Co je potřeba doplnit / rozhodnout
- **Číslo účtu** pro platbu poukazů převodem – v `data.js` → `platba.cislo_uctu`. Dokud je prázdné, nabízí se jen platba v salonu. Po doplnění se zákazníkovi zobrazí QR platba s VS = číslo objednávky.
- **Platnost poukazů** – na webu se neuvádí (původní web ji neuvádí). V `salon.html` se volí při vystavení.
- **Fotky a video** se zatím načítají z původního webu (`thaimost.cz/media/…`). Před přesměrováním domény je nahrát do `assets/img/` a upravit cestu `img` v `data.js` a odkazy v `index.html`.
- Evidence poukazů v `salon.html` je uložená jen v prohlížeči, kde se vystavují – zálohovat přes Export CSV.

## Zveřejnění
GitHub → Settings → Pages → Source: *Deploy from a branch* → `main` / `(root)`.
