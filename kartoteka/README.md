# Kartotéka Thai Most

Interní systém pro recepci: rezervace, kalendář, zákazníci s preferencemi a štítky, bonus (každá N‑tá masáž zdarma), dárkové poukazy. Běží na Synology v kontejneru `masaze`, veřejně na **https://masage.dvere.app** přes Cloudflare tunel `formulare-nas`.

Web na GitHub Pages posílá žádosti o termín a objednávky poukazů na `/api/...` – nic jiného API neumí a nic z kartotéky nevrací.

## AI asistent
Plovoucí ikona **🤖** vpravo dole na každé stránce (hlasem i psaním): recepce se ptá česky, asistent (Claude API) dohledá data a navrhne termíny; rezervaci vždy ukládá recepce. Zapnutí a pravidla: `docs/ai-asistent.md`.

## Zákazníci v telefonu
**Správa → Google kontakty** zapisuje zákazníky (se značkami ⭐ stálý, 🎁 poukaz, 🎉 masáž zdarma, masérka…) do Google kontaktů, takže telefon při hovoru ukáže, kdo volá. Postup a pravidla: `docs/zakaznici-karty.md` (kapitola „Zákazníci v telefonu“).

## Technika
PHP 8.3 + SQLite, bez frameworku a bez závislostí. Schéma se vytvoří samo při prvním spuštění (`app/db.php`), ceník se naplní z `app/seed-sluzby.json` (vygenerováno z `assets/js/data.js` webu).

```
kartoteka/
  public/      index.php (vstup), router.php, style.css
  app/         lib.php (pomocné), db.php (schéma), api.php (web), layout.php, stranky*.php, smtp.php, google_kontakty.php (Google kontakty)
  bin/         uzivatel.php – založení uživatele
  data/        kartoteka.sqlite + session (NEcommituje se – zálohovat!)
  config.php   z config.example.php (NEcommituje se)
```

## Nasazení na Synology
1. Složku `kartoteka/` nahrát na NAS, např. `/volume1/docker/masaze/`.
2. `cp config.example.php config.php` a doplnit: e‑mail salonu, SMTP, povolené originy webu.
3. V `docker-compose.yml` doplnit název Docker sítě, ve které běží `cloudflared` (Container Manager → Síť). Kontejner `masaze` musí být ve stejné síti – tunel ho hledá jako `http://masaze:3000`.
4. Container Manager → Projekt → vytvořit z `docker-compose.yml` → spustit.
5. Založit prvního uživatele (terminál kontejneru, nebo SSH na NAS):
   `docker exec -it masaze php bin/uzivatel.php admin "Zdeněk" TajneHeslo123 admin`
6. Otevřít https://masage.dvere.app a přihlásit se. Další uživatele (recepce) jde přidat v Nastavení.
7. Doporučeno: schovat kartotéku za Cloudflare Access stejně jako kartoteka.dvere.app (API `/api/*` z Accessu vyjmout – volá ho web).

Zálohy: složka `data/` (Hyper Backup).

## Lokální spuštění (vývoj)
```
cd kartoteka && cp config.example.php config.php
php bin/uzivatel.php admin "Admin" heslo1234
php -S localhost:3000 -t public public/router.php
```

## Web → API
- `POST /api/zajem` – žádost o termín: `jmeno, prijmeni, telefon, email?, varianta_id, den?, okno (dopoledne|odpoledne|vecer|kdykoli), poznamka?, gdpr=1`
- `POST /api/poukaz` – objednávka poukazu: `jmeno, telefon, email?, hodnota, sluzba?, komu?, od?, venovani?, prevzeti, platba, poznamka?, cislo?, gdpr=1`
- `GET /api/sluzby` – aktuální ceník (varianty s id)
- `GET /api/stav` – `{ok:true, rezim:"zajem"}`; když neodpoví, web ukáže telefon

Ochrana: honeypot pole `web`, limit 10 požadavků z IP za hodinu, CORS jen z povolených originů.

## Vzorová data (VZOR) na zkoušení
**Nastavení → 🧪 Vzorová data → Nahrát** (jen admin). Nahraje 120 zákazníků s historií (~4 měsíce), preferencemi, zdravotními upozorněními a štítky (stálý, nový, spící, nespolehlivý, vezme volný termín), plný kalendář na **dnes + 13 dní** pro všechny aktivní masérky (víkend jen wellness), 4 žádosti z webu, 4 poukazy, 6 věrnostních karet a hodnocení masérek. Pokud nejsou žádné masérny, založí 3 pro tablety.
- Vzoroví zákazníci mají štítek **VZOR**, telefon `+420 999 …` (neexistující rozsah) a **žádný e-mail** – nic se jim neodešle. Nahoře v aplikaci svítí žlutý pruh, dokud v systému jsou.
- **Smazat:** Nastavení → napsat `SMAZAT` → „Smazat všechna vzorová data“. Smaže vzorové zákazníky a vše, co u nich vzniklo (i během zkoušení). Skutečných zákazníků, masérek ani masérn se nedotkne.
- Z příkazové řádky: `php bin/vzor.php nahrat` / `php bin/vzor.php smazat`.
- Bez přihlášení a bez terminálu (např. přes QuickConnect, kde terminál kontejneru nefunguje): ve File Station nahrát do `docker/masaze/data/` prázdný soubor `vzor-nahrat.txt` (nebo `vzor-smazat.txt`) a otevřít libovolnou stránku kartotéky. Akce proběhne, soubor zmizí a výsledek je v `data/vzor-vysledek.txt`.

## Nepřišel / storno
- Po skončení termínu se rezervace sama přepne na **Proběhlo jen tehdy, když masérka masáž spustila na tabletu**. Ostatní se v přehledu **Dnes** objeví v sekci **„Přišel klient?“** s tlačítky Proběhlo / Nepřišel / Zrušeno pozdě – zapomenutý „nepřišel“ se tak nepočítá jako návštěva ani do bonusu.
- Stavy: *Zrušeno včas* (nepočítá se nikam, v kalendáři se skryje), *Zrušeno pozdě* a *Nepřišel* (počítají se do štítku ⚠️ Nespolehlivý – práh v Nastavení, výchozí 2× za 12 měsíců).
- Při stornu/nepřišel se masáž čekající na tabletu sama stáhne.
- Když se uvolní termín, který ještě neskončil, ukáže detail rezervace **„Uvolněný termín“** se seznamem zákazníků, kteří mají zaškrtnuto „Vezme i uvolněný termín“ (s telefonem a odkazem na zapsání).

## Fotka masáže na tabletu
Během masáže je na pozadí tabletu poloprůhledně fotka zvolené masáže (stejné fotky jako na webu – seznam `MASAZ_FOTO` v `app/stranky_tablet.php`). Vlastní fotku stačí nahrát jako `public/foto-<kód>.jpg` (např. `foto-tradicni.jpg`, kódy viz `app/seed-sluzby.json`) – má přednost.

## Rezervace: kontrola obsazenosti
Ve formuláři rezervace (nová, naplánování žádosti z webu, úprava) se pod termínem ukazuje **obsazenost dne pro všechny masérky** (šedé = obsazeno, zlatě = vybraný termín). Klepnutím do řádku masérky se vybere nejbližší volný začátek a ta masérka. Při kolizi se hned ukáže, kdo je v tu dobu volný, a nejbližší volné časy zvolené masérky. Při uložení server znovu kontroluje: kolizi masérky, stav „všechny masérky obsazené“ (bez zvolené masérky) a dvě masáže téhož zákazníka ve stejnou dobu – vždy jde vědomě „Přesto uložit“.
Stránka **Volné termíny** ukazuje ve výchozím stavu všechny masérky (oblíbená ★ první), nahoře jdou přepnout jednou klepnutí.

## Tablety: posílání z recepce v přehledu Dnes
V přehledu **Dnes** je karta **Masérny teď** (volná / čeká / běží do…) a u každé dnešní potvrzené masáže tlačítka masérn. Recepce pošle masáž na tablet až ve chvíli, kdy klient přijde – zvolí masérnu a případně na místě změní masérku (zapíše se do rezervace). Čekající masáž jde jedním klepnutím **přesunout** do jiné masérny nebo **stáhnout**. Masérnu, kde masáž běží, nejde obsadit (⛔); kde jen čeká jiný klient (⏳), se přepíše.

## Volna a přesčasy, svátky, kapacita masérn
- Masérka pracuje Po–Pá v otevírací době, ve státní svátky ne (svátky ČR včetně Velikonoc se počítají samy).
- **Volna a přesčasy** (menu): volno = nepracuje (celý den / více dní / část dne), přesčas = pracuje navíc (víkend, svátek, déle). Kalendář, Volné termíny i formulář rezervace s tím počítají.
- Když masérka dostane volno a má na tu dobu klienty, objeví se v **K vyřešení se zákazníkem** (a v přehledu Dnes a u položky menu je počet). U každé masáže tlačítka ostatních masérek: ✓ volná, ⚠ obsazená = přeplnit (s potvrzením), šedá = nepracuje; nebo „Jiný termín…“. Přeřazení stáhne masáž i z tabletu.
- U přesčasu je vidět, kolik hodin je už naplánováno („naplánováno 2 z 6 h“) a odkaz do kalendáře.
- **Kalendář (📅 Den)**: šrafovaně = masérka nepracuje, zeleně čárkovaně = volné okno ≥ 30 min (klepnutím nová rezervace), v hlavičce volno/přesčas a kolik má kdo volných hodin; ⚠ červeně = masáž u masérky, která nepracuje. Posun po dnech: ‹ ›, šipky na klávesnici, přejetí prstem.
- **Masérny a tablety** (Tým): jeden řádek = jedno lůžko s tabletem. Dvoulůžková masérna = dva tablety se stejným názvem masérny; zaškrtnutím „wellness“ zabere wellness celou masérnu. Kartotéka pak hlídá, aby se souběžně nenaplánovalo víc masáží, než je lůžek (2 + 2 = 4; během wellness 2). Kontrola se zapne po prvním uložení nastavení tabletů.
- Spouštěč vzorových dat má i `vzor-obnovit.txt` (smaže a nahraje znovu).

## Služby s více masérkami, bez masérky, wellness po zavírací době
V Nastavení → Ceník má každá položka: **Masérek** (0 = whirlpool/sauna bez masérky, 1, 2 = párová), **Masérky min** (jak dlouho od začátku jsou masérky potřeba) a **Celá wellness masérna** (zabere obě lůžka dvoulůžkové masérny).
Výchozí nastavení: whirlpool a sauna = 0 masérek, wellness masérna; balíček *120 min párová (2 asistentky + šampaňské + whirlpool + sauna)* = 2 masérky na 60 min + wellness masérna na celých 120 min. Fiji rituál a Cukrový vánek = 1 masérka na běžném lůžku.
- Formulář u párové ukáže druhou masérku, nabídne čas **na konec směny** (masáž končí se zavírací dobou, whirlpool a sauna běží po zavírací době s recepční) a kontroluje obě masérky.
- Kalendář: párová je u obou masérek jen na dobu masáže (👥), ve sloupci *Wellness masérna* na celou dobu; osa se prodlouží přes zavírací dobu.
- Dnes: tlačítko **📲👥 Dvoulůžková – obě lůžka** pošle masáž na oba tablety (každé lůžko svou masérku); na tabletu se odpočítává jen masáž (60 min).
