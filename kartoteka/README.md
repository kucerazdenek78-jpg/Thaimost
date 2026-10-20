# Kartotéka Thai Most

Interní systém pro recepci: rezervace, kalendář, zákazníci s preferencemi a štítky, bonus (každá N‑tá masáž zdarma), dárkové poukazy. Běží na Synology v kontejneru `masaze`, veřejně na **https://masage.dvere.app** přes Cloudflare tunel `formulare-nas`.

Web na GitHub Pages posílá žádosti o termín a objednávky poukazů na `/api/...` – nic jiného API neumí a nic z kartotéky nevrací.

## Technika
PHP 8.3 + SQLite, bez frameworku a bez závislostí. Schéma se vytvoří samo při prvním spuštění (`app/db.php`), ceník se naplní z `app/seed-sluzby.json` (vygenerováno z `assets/js/data.js` webu).

```
kartoteka/
  public/      index.php (vstup), router.php, style.css
  app/         lib.php (pomocné), db.php (schéma), api.php (web), layout.php, stranky*.php, smtp.php
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
