# AI asistent recepce (zkušební verze)

Asistent je plovoucí ikona **🤖** vpravo dole na každé stránce kartotéky (není v menu; zkratka Alt+A). Otevře okno s chatem, na mobilu přes celou obrazovku; tlačítkem ⤢ jde otevřít na celou stránku (`/?r=ai`). Recepce se ho ptá běžnou češtinou, on si sám dohledá data v kartotéce a odpoví. Změny (nová rezervace, potvrzení žádosti, přesun, zrušení, přišel/nepřišel, volno a přesčas masérky) **připraví jako kartu** a provedou se až po klepnutí na **✓ Provést**.

- Číslo na ikoně = návrhy čekající na recepci.
- Konverzace zůstává při přechodu mezi stránkami; otevřené okno se na počítači po přechodu otevře znovu.
- **🎤 Říct nahlas** – diktování (Chrome, Edge, Android, Safari). Rozpoznaný dotaz se odešle sám a odpověď se přečte nahlas. Napoprvé prohlížeč požádá o povolení mikrofonu.
- **🔊 Přečíst** u každé odpovědi.
- **✓ Provést** zapíše změnu hned (kartotéka ji před tím znovu ověří). U nové rezervace jde i **Upravit ve formuláři**.

## Aplikace do telefonu (Android)

Asistent jde nainstalovat jako samostatná aplikace s ikonou na ploše (webová aplikace z Chromu, žádné APK):

1. **Nastavení → 📲 Aplikace Asistent do telefonu**: odkaz a QR kód (`/?r=ai&app=1`), na telefonu tlačítko **Nainstalovat aplikaci**.
2. V telefonu otevřít odkaz v Chromu, přihlásit se se zaškrtnutým **Zůstat přihlášen** (60 dní, prodlužuje se při používání).
3. Chrome → **⋮ → Nainstalovat aplikaci**. Aplikace běží na celou obrazovku, menu ☰ vede do kartotéky, pravidel, nastavení a k odhlášení.

Odhlášení zruší i trvalé přihlášení na tom zařízení. Na sdíleném počítači „Zůstat přihlášen“ nezaškrtávat.

## Jak to funguje

1. Recepce napíše dotaz (nebo klikne na rychlé tlačítko, např. „Ranní přehled“).
2. Kartotéka pošle dotaz + schválená pravidla salonu + popis nástrojů do Claude API (Anthropic).
3. Model si říká o data přes nástroje. Kartotéka je vyřizuje sama nad svou databází a vrací jen to nutné.
4. Návrh změny kartotéka ověří už při přípravě (pracovní doba masérky, kolize, kapacita masérn, stav rezervace). Když nesedí, vrátí modelu chybu.
5. Pod odpovědí je karta: **✓ Provést** změnu zapíše stejnými funkcemi jako ruční práce (a znovu ji ověří), **Zamítnout** chce důvod.

Asistent běží přímo v kontejneru `masaze` (PHP), žádný další kontejner na DS223 není potřeba.

## Nástroje asistenta (čtení + návrhy změn)

| Nástroj | Co vrací |
| --- | --- |
| `rozvrh` | rezervace v rozsahu dní (max 7) |
| `najdi_zakaznika` | zákazníci podle jména / části telefonu / kódu karty – jméno a poslední 3 číslice telefonu |
| `zakaznik_detail` | návštěvy, poslední masáž, oblíbená masérka, preference, štítky, razítka, poukazy, budoucí termíny |
| `sluzby` | ceník s id variant, délkou a počtem masérek |
| `maserky` | pracovní doba po dnech včetně volna a přesčasů (max 14 dní) |
| `volne_terminy` | skutečně volné začátky – stejný výpočet jako stránka Volné termíny |
| `kontroly` | žádosti z webu, rezervace u masérky s volnem, neoznačené proběhlé termíny |
| `zakaznici_last_minute` | zákazníci se štítkem „vezme i volný termín“ |
| `hodnoceni` | hodnocení masérek z tabletů a počet masáží |
| `navrhnout_rezervaci` | nová rezervace pro existujícího zákazníka |
| `navrhnout_potvrzeni_zadosti` | žádost z webu → konkrétní termín a masérka (zákazník dostane potvrzení e-mailem) |
| `navrhnout_presun` | potvrzená rezervace na jiný čas / masérku / službu |
| `navrhnout_zmenu_stavu` | proběhlo, nepřišel (jen začaté termíny), zrušeno včas/pozdě, odmítnuto (žádost) |
| `navrhnout_volno` | volno nebo přesčas masérky; vrátí, kolik masáží zasáhne |

## Co jde ven do Claude API

- Telefony nikdy celé (jen poslední 3 číslice ve výsledku hledání), e-maily vůbec.
- Zdravotní poznámky nikdy – jen informace, že zákazník nějakou má.
- V přehledech (rozvrh, kontroly, last-minute) je zákazník jen jako `[Z:číslo]`; jméno do odpovědi doplní až kartotéka.
- Interní poznámka a preference zákazníka ven jdou (jsou potřeba pro plánování).

## Pravidla salonu („učení“)

Odkaz **Pravidla salonu** dole v okně asistenta (`/?r=ai_pravidla`). Každý dotaz dostane všechna schválená a zapnutá pravidla.
- Admin a hlavní pracovník pravidla přidávají, upravují, vypínají a schvalují.
- Recepce může pravidlo jen navrhnout – čeká na schválení.
- Vpravo jsou důvody zamítnutých návrhů za 30 dní – podklad pro nová pravidla.
- Preference konkrétního zákazníka patří na jeho kartu, ne do pravidel.

Při prvním spuštění se založí pravidla R-01 až R-08 (z provozu salonu).

## Zapnutí na NAS

1. Nahrát nové a změněné soubory z repa do složky kartotéky na NAS. Restart kontejneru není potřeba – PHP si soubory načte samo; databáze se doplní sama (verze 10).
2. V Claude Console (console.anthropic.com → API keys) vytvořit klíč a nastavit měsíční limit útraty.
3. V kartotéce jako admin: ikona **🤖** → odkaz **Nastavení a spotřeba** dole v okně (`/?r=ai_log`) → vložit klíč, vybrat model (výchozí Haiku) → Uložit. Klíč se před uložením ověří krátkým voláním.
4. Hotovo – asistent je zapnutý.

Klíč se uloží do `data/ai-nastaveni.json` (mimo web, mimo git, práva 0600). Zálohuje se s databází, takže zálohy chraňte. Kdo chce, může klíč místo toho dát do `config.php` (sekce `'ai' => ['api_key' => '…']`); klíč vložený v kartotéce má přednost.

## Pojistky

- `denni_limit` – max. volání API za den (výchozí 300).
- `max_kroku` – max. kol s nástroji na jeden dotaz (výchozí 8); poslední kolo musí model odpovědět.
- Log a spotřeba tokenů: **Nastavení a spotřeba** (jen admin). Cenu podle tokenů ukazuje Claude Console.

## Co zkušební verze zatím neumí

- Neběží sama v plánovači (ranní přehled je zatím tlačítko).
- Neumí zakládat nové zákazníky, poukazy ani posílat SMS (text SMS napíše do odpovědi).
- Konverzace se pamatuje jen v rámci přihlášení (posledních 30 zpráv) a jen jako text.
