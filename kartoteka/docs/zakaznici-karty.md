# Zákazníci, volné termíny, věrnostní karty a poukazy

## Rychlé hledání
Políčko **🔍** v horní liště (na každé stránce; klávesa `/` do něj skočí). Hledá bez ohledu na diakritiku:
příjmení / jméno (i obojí dohromady), telefon (stačí kus čísla, od 3 číslic), kód věrnostní karty (nebo celý odkaz z QR) a kód / jméno u poukazů.
Ve výsledku je poslední návštěva, razítka a tlačítka **Termín** (hledání volného místa) a **Rezervace**. Enter otevře první nález.

## Zákazník volá / přijde osobně
1. Vyhledat telefon nebo příjmení → karta zákazníka (štítky, preference, zdraví, minulá masáž + poznámka, razítka, poukazy).
2. **Najít volný termín** → vybrat službu (předvyplní se minulá), masérku (předvyplní se oblíbená, nebo „kterákoli volná“), rozsah dnů a časové okno → klepnout na čas.
3. Otevře se rezervace s vyplněným zákazníkem, časem, masérkou a službou → uložit. Kolize s jinou rezervací se hlídá.
Volné termíny se počítají z otevírací doby (Nastavení) a potvrzených rezervací; **směny masérek systém zatím nezná** (jen směny recepčních).

## Věrnostní karty s QR (razítka online)
- **Karty → Vytvořit nové karty** (např. 50) → **Tisk QR** (A4, 3 sloupce; na štítky / plastové karty).
- Zákazník naskenuje QR běžným fotoaparátem → bez přihlášení uvidí razítka, počet masáží, poslední masáž a masérku (jen křestní jméno).
- Recepce: naskenuje kartu svým telefonem (je-li přihlášená v kartotéce) → zadá telefon zákazníka (existující najde / nového založí) a **razítka z papírové kartičky** → karta je přiřazena. Kartu lze zneplatnit (ztráta) a dát novou.
- Razítka = zaplacené masáže + počáteční razítka (stejná logika jako dosud; každá N-tá zdarma, N v Nastavení).

## Poukazy
- Poukaz se vede pod kupujícím (podle telefonu). Na kartě poukazu: **Držitel** (komu patří – lze změnit, když ho kupující daruje), **věnováno komu / od koho / vzkaz**, náhled poukazu a tlačítka **🖨 Tisk poukazu** (A4, poukaz 210 × 100 mm nahoře, stačí vystřihnout), **⬇ Stáhnout PDF** a **✉ Poslat poukaz jako PDF** (zákazníkovi e-mailem, jen u zaplaceného nebo vydaného poukazu).
- Vzhled je originální návrh salonu (`app/poukaz/darkovy-poukaz-original.pdf`). Vyplní se **Jméno** (Pro koho), **Procedura** (služba, jinak „Hodnota … Kč“) a **Platnost**; dole je kód poukazu a QR (čte ho skener v horním hledání). Co v kartotéce chybí, zůstane prázdné k dopsání. „Od koho“ a věnování se netisknou, zůstávají jen v kartotéce.
- E-mail s PDF odejde jen když je v `config.php` nastavený SMTP; jinak se uloží do fronty („Neodeslané e-maily“) a kartotéka to při odeslání napíše.
- Poukaz jde najít v horním hledání podle kódu nebo jména.

## Import kontaktů (Google / Android)
Zákazníci → **Import z Google kontaktů**. Nahrajete export kontaktů (Google CSV nebo vCard `.vcf`), uvidíte náhled (nový / už existuje / bez telefonu), vyberete a importujete. Existující zákazníci (stejný telefon) se nepřepisují, jen se doplní prázdné jméno a e-mail; další čísla kontaktu jdou do interní poznámky.
Export: contacts.google.com → Exportovat → Google CSV; nebo v aplikaci Kontakty na Androidu → Nastavení → Exportovat → soubor .vcf (kontakty uložené jen v telefonu).

## Zákazníci v telefonu (Google kontakty)
**Správa → Zákazníci v telefonu (Google kontakty)** (jen admin). Kartotéka zapíše zákazníky do Google kontaktů jednoho účtu (např. firemního telefonu salonu), takže při příchozím hovoru telefon ukáže jméno i značky. Používá se Google People API; kontakty se **nikdy nemažou**.

**Co se zapíše**
- Jméno + značky: ⛔ blokovaný · ⚠️ nespolehlivý · ⭐ stálý · 🆕 nový · 💤 spící · 🎁 platný poukaz · 🎉 nárok na masáž zdarma (razítka) · ⏱ vezme i volný termín; volitelně „· Lenka“ (oblíbená, jinak poslední masérka). Příklad: *Jana Nováková ⭐🎁 · Lenka*.
- Číslo a krátká poznámka (poslední návštěva, razítka, zůstatek poukazu). Poznámku kartotéka přepisuje jen mezi řádky `▶ Thai Most kartotéka` a `◀ konec zápisu`; váš vlastní text kolem zůstane.
- Do telefonu **nejde**: zdraví, interní poznámky, preference, e-mail, kód poukazu.
- Kontakty jsou i ve skupině „Thai Most“.

**Jednorázové nastavení** (návod je i přímo na stránce): v Google Cloud vytvořit projekt, zapnout People API, nastavit OAuth (publikum Externí, oprávnění `…/auth/contacts`, aplikaci **publikovat** – jinak přihlášení po 7 dnech vyprší), vytvořit klienta typu Webová aplikace s adresou přesměrování `https://masage.dvere.app/google-zpet`, zkopírovat ID klienta a tajný klíč na stránku a **Připojit Google účet**. Google ukáže „Aplikace není ověřená“ – je to vaše vlastní aplikace pro jeden účet (Rozšířené → Pokračovat). V telefonu musí být tento účet přidaný se zapnutou synchronizací kontaktů.

**Jak se synchronizuje**
1. **Náhled změn** nic nezapisuje; ukáže, kdo se založí, komu se změní jméno/značky a kde už v Googlu kontakt se stejným číslem je (předzaškrtnuté jsou jen případy, kdy jde evidentně o stejnou osobu).
2. **Zapsat vybrané** provede zápis. Cizí kontakty (už existující v Googlu) se přepíšou jen když je výslovně vyberete.
3. Kontakt, který někdo v Googlu smazal, se sám nevrací; v náhledu se nabídne k obnovení.
4. Po první ruční synchronizaci jde zapnout **automatika**: při práci v kartotéce (kontrola při běžném dotazu na pozadí, nejvýš jednou za 5 minut) se přidají noví zákazníci a změní značky. Bez změn se Google vůbec nevolá. Automatika nepřepisuje cizí kontakty a nevrací smazané. Při chybě se na půl hodiny odmlčí a administrátorovi se nahoře ukáže upozornění.

**Soukromí:** zákazníci jsou tím uloženi i u Googlu (v účtu salonu) – počítejte s tím v informacích pro zákazníky o zpracování osobních údajů. Přihlašovací údaje (tajný klíč, tokeny) jsou v databázi v samostatné tabulce a na stránkách se nikdy nezobrazují. Odpojení účtu přístup odvolá u Googlu.
