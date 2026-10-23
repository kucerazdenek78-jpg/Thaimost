# AI asistent recepce (zkušební verze)

Asistent je stránka **🤖 Asistent** v kartotéce. Recepce se ho ptá běžnou češtinou, on si sám dohledá data v kartotéce a odpoví. Rezervace **jen navrhuje** – nic neukládá, nemění ani neruší.

## Jak to funguje

1. Recepce napíše dotaz (nebo klikne na rychlé tlačítko, např. „Ranní přehled“).
2. Kartotéka pošle dotaz + schválená pravidla salonu + popis nástrojů do Claude API (Anthropic).
3. Model si říká o data přes nástroje. Kartotéka je vyřizuje sama nad svou databází a vrací jen to nutné.
4. Návrh rezervace kartotéka před uložením ověří (pracovní doba masérky, kolize, kapacita masérn). Když nesedí, vrátí modelu chybu.
5. Pod odpovědí je karta návrhu: **Přijmout** otevře předvyplněnou rezervaci, recepce ji zkontroluje a uloží. **Zamítnout** chce důvod.

Asistent běží přímo v kontejneru `masaze` (PHP), žádný další kontejner na DS223 není potřeba.

## Nástroje asistenta (jen čtení + návrh)

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
| `navrhnout_rezervaci` | uloží návrh pro recepci (po ověření) |

## Co jde ven do Claude API

- Telefony nikdy celé (jen poslední 3 číslice ve výsledku hledání), e-maily vůbec.
- Zdravotní poznámky nikdy – jen informace, že zákazník nějakou má.
- V přehledech (rozvrh, kontroly, last-minute) je zákazník jen jako `[Z:číslo]`; jméno do odpovědi doplní až kartotéka.
- Interní poznámka a preference zákazníka ven jdou (jsou potřeba pro plánování).

## Pravidla salonu („učení“)

Stránka **Asistent → Pravidla salonu**. Každý dotaz dostane všechna schválená a zapnutá pravidla.
- Admin a hlavní pracovník pravidla přidávají, upravují, vypínají a schvalují.
- Recepce může pravidlo jen navrhnout – čeká na schválení.
- Vpravo jsou důvody zamítnutých návrhů za 30 dní – podklad pro nová pravidla.
- Preference konkrétního zákazníka patří na jeho kartu, ne do pravidel.

Při prvním spuštění se založí pravidla R-01 až R-08 (z provozu salonu).

## Zapnutí na NAS

1. V Claude Console (console.anthropic.com) vytvořit API klíč a nastavit měsíční limit útraty.
2. Na NAS v `config.php` ve složce kartotéky (File Station → upravit) doplnit na konec pole:
   ```php
   'ai' => [
       'api_key' => 'sk-ant-…',
       'model' => 'claude-sonnet-5-5',
       'max_tokens' => 1500,
       'max_kroku' => 8,
       'denni_limit' => 300,
   ],
   ```
3. Nahrát nové soubory z repa (`app/ai.php`, `app/stranky_ai.php` a změněné soubory). Restart kontejneru není potřeba – PHP si soubory načte samo; databáze se doplní sama (verze 9).
4. Otevřít https://masage.dvere.app/?r=ai.

Klíč je jen v `config.php`, který se do gitu nenahrává.

## Pojistky

- `denni_limit` – max. volání API za den (výchozí 300).
- `max_kroku` – max. kol s nástroji na jeden dotaz (výchozí 8); poslední kolo musí model odpovědět.
- Log a spotřeba tokenů: **Asistent → Log a spotřeba** (jen admin). Cenu podle tokenů ukazuje Claude Console.

## Co zkušební verze zatím neumí

- Neběží sama v plánovači (ranní přehled je zatím tlačítko).
- Návrhy jsou jen nové rezervace; přesuny existujících rezervací a texty SMS napíše do odpovědi, ale kartu k nim nevytvoří.
- Konverzace se pamatuje jen v rámci přihlášení (posledních 30 zpráv) a jen jako text.
