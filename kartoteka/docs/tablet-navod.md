# Tablety v masérnách – návod pro provoz

## Jednorázové nastavení
1. **Tým → Masérny a tablety → Přidat masérnu** (např. „Masérna 1“, „Masérna 2“). U každé se ukáže *odkaz pro tablet*.
2. Odkaz otevři na tabletu dané masérny (nejlépe Chrome → Přidat na plochu / celá obrazovka). Tablet se nepřihlašuje, pozná se podle tajného odkazu. Při prvním klepnutí na tabletu se zapne zvuk (bez toho prohlížeč znělku nepustí).
3. **Tým → Masérky → profil masérky → Účet na spropitné** (jen se souhlasem masérky; formát `123456789/0100` nebo IBAN). Kdo účet nemá, dostane QR na společný účet z **Masérny a tablety → Spropitné a odměna týdne**.
4. Tamtéž nastav minimum hodnocení za týden pro odměnu (výchozí 3).

## Běžný provoz
1. Recepce v detailu potvrzené rezervace (karta **Tablet v masérně**) vybere masérnu a dá **Poslat na tablet** (rezervace musí mít masérku).
2. Masérka na tabletu potvrdí své jméno (nebo „Nejsem to já“ → recepce uvidí upozornění v rezervaci i ve správě masérn a pošle znovu).
3. Po potvrzení uvidí příjmení zákazníka, masáž, preference, poznámky a upozornění na zdraví. Po startu se tyto údaje z tabletu skryjí.
4. **Zahájit masáž** → odpočet zaplacené doby (délka rezervace). Předčasné ukončení: podržet nenápadný text pod kruhem 2 s.
5. Na konci zazní znělka, tablet poděkuje, nabídne hodnocení smajlíky (5 stupňů, ukládá se jako 1–5) a QR spropitné (50/100/200 Kč / jiná částka). Rezervace se sama přepne na „proběhlo“. Po 15 min (nebo tlačítkem Hotovo) se tablet vrátí do klidového stavu.
6. **Tým → Odměna týdne**: žebříček podle průměru hodnocení (min. počet hodnocení, při shodě víc hodnocení → víc masáží); odměnu zapíše admin/hlavní.

## Jazyk, vzhled a zvuk
- Pro masérku je hlavní jazyk **angličtina** (příkazy, názvy masáží, štítky). Obrazovka pro zákazníka (poděkování, smajlíky, spropitné) je **česky**.
- Tlačítko **Hold = česky** vpravo nahoře: dokud ho držíš, je celá obrazovka česky (včetně názvu masáže), po pustení se vrátí angličtina.
- Texty od recepce (zdravotní upozornění, preference, poznámky) se **nepřekládají strojově**. Recepce je píše ručně anglicky do polí 🇬🇧 *English version*: v kartě zákazníka (preference, zdraví – anglické zdraví se ukládá jen se souhlasem zákazníka) a v rezervaci v části Poznámka k masáži (jedna anglická poznámka nahradí na tabletu všechny poznámky). Na tabletu se ukáže angličtina; když chybí, ukáže se český text se štítkem „Czech original“, a v rezervaci recepci vyskočí upozornění, co doplnit. Podržení tlačítka „Hold = česky“ ukáže český originál. Anglické názvy masáží jsou v `MASAZ_EN` v `app/stranky_tablet.php` (co tam chybí, zůstane česky).
- Pozadí: polopropustná zelená přes volitelnou fotku. Fotku nahraj jako `public/pozadi.jpg` (nebo `pozadi.webp`, ideálně 1920×1200, do ~300 kB); bez fotky je jen grafika (lotos, vlnky).
- Znělka na konci je thajská: xylofon ranat a gong ve stupnici 7 stejných dílů oktávy.

## Řešení potíží
- „Uvolnit tablet“ (Masérny a tablety → upravit masérnu) zruší zaseknuté sezení.
- „Nový odkaz“ zneplatní starý odkaz (např. při ztrátě tabletu).
- Stav „⚪ nikdy nepřipojen / naposledy…“ ukazuje, jestli tablet komunikuje (online = spojení do 30 s).
