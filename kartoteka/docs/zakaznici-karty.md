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
