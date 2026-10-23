<?php
// AI asistent recepce (zkušební verze).
// Princip: asistent jen čte data a navrhuje. Do rezervací nikdy nezapisuje – návrh rezervace
// otevře recepce jako předvyplněný formulář a uloží ho sama běžnou cestou.
// Model běží u Anthropicu (Claude API); tady je jen smyčka „dotaz → nástroje → odpověď“.
// Zákazníky asistent v odpovědi označuje [Z:id]; jméno doplní až kartotéka (ven jde co nejméně dat).

const AI_API_URL = 'https://api.anthropic.com/v1/messages';
const AI_API_VERZE = '2023-06-01';

const AI_MODELY = [
    'claude-haiku-5-5' => 'Haiku 5.5 – levnější (výchozí)',
    'claude-sonnet-5-5' => 'Sonnet 5.5 – chytřejší, dražší',
];

/** Klíč a model vložené adminem v kartotéce (Asistent → Nastavení). Soubor je v data/, mimo web, do gitu nejde. */
function ai_soubor(): string { return dirname(cfg()['db_path']) . '/ai-nastaveni.json'; }
function ai_soubor_cti(): array
{
    $f = ai_soubor();
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($d) ? $d : [];
}
function ai_soubor_uloz(array $d): void
{
    $f = ai_soubor();
    file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($f, 0600);
}

function ai_cfg(): array
{
    static $c = null;
    if ($c) return $c;
    $c = (cfg()['ai'] ?? []) + [
        'api_key' => '',
        'model' => 'claude-haiku-5-5',
        'max_tokens' => 1500,
        'max_kroku' => 8,          // kolikrát smí model za jeden dotaz zavolat nástroje
        'denni_limit' => 300,      // max. volání API za den (pojistka proti útratě)
        'timeout' => 60,           // s na jedno volání API
        'api_url' => AI_API_URL,
    ];
    $f = ai_soubor_cti();
    if (trim((string)($f['api_key'] ?? '')) !== '') { $c['api_key'] = trim($f['api_key']); $c['zdroj_klice'] = 'kartoteka'; }
    elseif (trim((string)$c['api_key']) !== '') $c['zdroj_klice'] = 'config.php';
    if (isset(AI_MODELY[$f['model'] ?? ''])) $c['model'] = $f['model'];
    return $c;
}

/** Krátké zkušební volání bez nástrojů: ověří klíč a model. Vrací null (OK) nebo text chyby. */
function ai_test(?string $klic = null, ?string $model = null): ?string
{
    $c = ai_cfg();
    try {
        $odp = ai_api(['model' => $model ?? $c['model'], 'max_tokens' => 20, 'messages' => [['role' => 'user', 'content' => 'Odpověz jedním slovem: funguješ?']]], $klic);
        ai_log('test', ($model ?? $c['model']) . ' OK', (int)($odp['usage']['input_tokens'] ?? 0), (int)($odp['usage']['output_tokens'] ?? 0));
        return null;
    } catch (Throwable $e) {
        ai_log('test', 'chyba: ' . $e->getMessage());
        return $e->getMessage();
    }
}
function ai_zapnuto(): bool { return trim((string)ai_cfg()['api_key']) !== ''; }

function ai_log(string $typ, string $detail = '', int $in = 0, int $out = 0): void
{
    q('INSERT INTO ai_log(kdo,typ,detail,tokeny_in,tokeny_out) VALUES(?,?,?,?,?)', [kdo(), $typ, mb_substr($detail, 0, 2000), $in, $out]);
}
function ai_volani_dnes(): int { return (int)qv("SELECT COUNT(*) FROM ai_log WHERE typ='api' AND cas >= ?", [date('Y-m-d 00:00:00')]); }

// ---------------- Pseudonymizace ----------------
function ai_z(int|string|null $id): ?string { return $id ? '[Z:' . (int)$id . ']' : null; }
function ai_tel_maska(string $t): string { return '…' . substr(preg_replace('~\D~', '', $t), -3); }

// ---------------- Systémový prompt ----------------
function ai_system(array $u): string
{
    $dny = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];
    $pravidla = '';
    foreach (q("SELECT * FROM ai_pravidla WHERE aktivni=1 AND stav='schvaleno' ORDER BY kod, id")->fetchAll() as $p)
        $pravidla .= '- ' . ($p['kod'] ?: 'P-' . $p['id']) . ' (' . ($p['typ'] === 'tvrde' ? 'tvrdé – nesmíš porušit' : 'měkké – preference') . '): ' . $p['text'] . "\n";
    $ted = $dny[(int)date('w')] . ' ' . date('j. n. Y H:i');
    $ot = nastaveni('oteviraci_od', '09:00') . '–' . nastaveni('oteviraci_do', '20:00');
    return <<<TXT
Jsi asistent recepce masážního salonu Thai Most (Most). Pomáháš recepci plánovat rezervace a hlídat provoz.
Teď je $ted. Otevírací doba v pracovní dny: $ot (o víkendu a svátcích jen přesčasy masérek a wellness). Píše ti: {$u['jmeno']} (role {$u['role']}).

Zásady:
- Jen navrhuješ. Rezervace sám nevytváříš, neměníš ani nerušíš. Rezervaci navrhni nástrojem navrhnout_rezervaci – recepce ji pak zkontroluje a uloží.
- Fakta ber jen z nástrojů. Nic si nevymýšlej: když data chybí, řekni to a zeptej se.
- Volné termíny vždy ověř nástrojem volne_terminy, nikdy je neodhaduj.
- Zákazníky v odpovědi označuj přesně tvarem [Z:číslo] (např. [Z:42]); kartotéka tam doplní jméno. Telefony ani zdravotní údaje nevypisuj.
- Když hledání zákazníka vrátí víc lidí, zeptej se, o koho jde (nabídni je jako [Z:id] s krátkým rozlišením).
- U návrhu termínu ber ohled na oblíbenou masérku, minulou masáž a poznámky zákazníka; když nejde, řekni proč.
- Odpovídej česky, stručně a věcně. Seznamy jen když pomohou. Časy piš jako 14:30, data jako út 28. 10.

Pravidla salonu (schválil admin):
$pravidla
TXT;
}

// ---------------- Nástroje (jen čtení + návrh) ----------------
function ai_nastroje(): array
{
    $datum = ['type' => 'string', 'description' => 'Datum YYYY-MM-DD'];
    return [
        ['name' => 'rozvrh', 'description' => 'Rezervace v rozsahu dní (nejvýš 7): čas, stav, služba, masérka, zákazník [Z:id], platba. Stavy: zadost = žádost z webu čeká na potvrzení, potvrzeno, probehlo, neprisel, zruseno_vcas, zruseno_pozde, odmitnuto.',
         'input_schema' => ['type' => 'object', 'properties' => ['datum_od' => $datum, 'datum_do' => $datum], 'required' => ['datum_od']]],
        ['name' => 'najdi_zakaznika', 'description' => 'Najde zákazníky podle příjmení, jména, části telefonu nebo kódu karty. Vrací [Z:id], jméno, příjmení a poslední 3 číslice telefonu.',
         'input_schema' => ['type' => 'object', 'properties' => ['dotaz' => ['type' => 'string']], 'required' => ['dotaz']]],
        ['name' => 'zakaznik_detail', 'description' => 'Karta zákazníka bez kontaktů a zdravotních údajů: návštěvy, poslední masáž, oblíbená služba a masérka, preference, štítky, věrnostní razítka, poukazy, budoucí rezervace.',
         'input_schema' => ['type' => 'object', 'properties' => ['zakaznik_id' => ['type' => 'integer']], 'required' => ['zakaznik_id']]],
        ['name' => 'sluzby', 'description' => 'Ceník: varianty služeb s id, délkou v minutách, cenou, počtem potřebných masérek a zda zabírají wellness masérnu.',
         'input_schema' => ['type' => 'object', 'properties' => new stdClass()]],
        ['name' => 'maserky', 'description' => 'Aktivní masérky (id, jméno, zaměření, preference) a jejich pracovní doba po dnech v rozsahu (nejvýš 14 dní) včetně volna a přesčasů.',
         'input_schema' => ['type' => 'object', 'properties' => ['datum_od' => $datum, 'datum_do' => $datum], 'required' => ['datum_od']]],
        ['name' => 'volne_terminy', 'description' => 'Skutečně volné začátky pro variantu služby (ověřuje pracovní dobu masérek, kolize a kapacitu masérn). Krok 30 min, nejvýš 7 dní.',
         'input_schema' => ['type' => 'object', 'properties' => [
             'varianta_id' => ['type' => 'integer'], 'datum_od' => $datum, 'pocet_dni' => ['type' => 'integer', 'description' => '1–7, výchozí 1'],
             'maserka_id' => ['type' => 'integer', 'description' => 'jen tahle masérka (nepovinné)'],
             'cas_od' => ['type' => 'string', 'description' => 'nejdřívější začátek HH:MM (nepovinné)'], 'cas_do' => ['type' => 'string', 'description' => 'nejpozdější začátek HH:MM (nepovinné)'],
             'zakaznik_id' => ['type' => 'integer', 'description' => 'kvůli řazení: jeho oblíbená masérka první (nepovinné)']],
             'required' => ['varianta_id', 'datum_od']]],
        ['name' => 'kontroly', 'description' => 'Co potřebuje pozornost recepce: žádosti z webu, rezervace u masérky, která v tu dobu nepracuje (volno), proběhlé termíny bez označení přišel/nepřišel.',
         'input_schema' => ['type' => 'object', 'properties' => new stdClass()]],
        ['name' => 'zakaznici_last_minute', 'description' => 'Zákazníci se štítkem „vezme i volný termín“ (vhodní k obvolání na uvolněnou hodinu), s poznámkou kdy můžou a oblíbenou masérkou.',
         'input_schema' => ['type' => 'object', 'properties' => new stdClass()]],
        ['name' => 'hodnoceni', 'description' => 'Hodnocení masérek ze smajlíků na tabletech (průměr 1–5, počet) a počet masáží v rozsahu dní.',
         'input_schema' => ['type' => 'object', 'properties' => ['datum_od' => $datum, 'datum_do' => $datum], 'required' => ['datum_od', 'datum_do']]],
        ['name' => 'navrhnout_rezervaci', 'description' => 'Uloží NÁVRH rezervace pro recepci (nic se nerezervuje). Systém návrh ověří (pracovní doba, kolize, kapacita); když nesedí, vrátí chybu. U párové služby zadej obě masérky.',
         'input_schema' => ['type' => 'object', 'properties' => [
             'zakaznik_id' => ['type' => 'integer'], 'varianta_id' => ['type' => 'integer'], 'datum' => $datum,
             'cas' => ['type' => 'string', 'description' => 'HH:MM'], 'maserka_id' => ['type' => 'integer'], 'maserka2_id' => ['type' => 'integer'],
             'zduvodneni' => ['type' => 'string', 'description' => 'proč tento termín (1 věta)']],
             'required' => ['zakaznik_id', 'varianta_id', 'datum', 'cas', 'zduvodneni']]],
    ];
}

function ai_datum($v, ?string $def = null): string
{
    $v = (string)($v ?? '');
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $v) && strtotime($v)) return $v;
    if ($def !== null) return $def;
    throw new InvalidArgumentException('Neplatné datum, čekám YYYY-MM-DD.');
}

/** Spustí nástroj; vrací data (pole) nebo vyhodí výjimku s textem chyby pro model. */
function ai_nastroj(string $jmeno, array $in, array &$navrhy): array
{
    switch ($jmeno) {
    case 'rozvrh':
        $od = ai_datum($in['datum_od'] ?? null);
        $do = ai_datum($in['datum_do'] ?? null, $od);
        if (strtotime($do) < strtotime($od)) [$od, $do] = [$do, $od];
        if ((strtotime($do) - strtotime($od)) / 86400 > 6) $do = date('Y-m-d', strtotime("$od +6 days"));
        $rows = q("SELECT r.id, r.zacatek, r.konec, r.stav, r.platba, r.zakaznik_id, r.preferovano, s.nazev AS sluzba, v.popis AS varianta,
                m.jmeno AS maserka, m2.jmeno AS maserka2
            FROM rezervace r LEFT JOIN varianty v ON v.id=r.varianta_id LEFT JOIN sluzby s ON s.id=v.sluzba_id
            LEFT JOIN maserky m ON m.id=r.maserka_id LEFT JOIN maserky m2 ON m2.id=r.maserka2_id
            WHERE (r.zacatek >= ? AND r.zacatek < ?) OR (r.zacatek IS NULL AND r.stav='zadost')
            ORDER BY r.zacatek", [$od . ' 00:00:00', date('Y-m-d', strtotime("$do +1 day")) . ' 00:00:00'])->fetchAll();
        return ['od' => $od, 'do' => $do, 'rezervace' => array_map(fn($r) => [
            'rezervace_id' => (int)$r['id'], 'zacatek' => $r['zacatek'], 'konec' => $r['konec'], 'stav' => $r['stav'],
            'sluzba' => trim(($r['sluzba'] ?? '') . ' ' . ($r['varianta'] ?? '')), 'maserka' => $r['maserka'], 'maserka2' => $r['maserka2'],
            'zakaznik' => ai_z($r['zakaznik_id']), 'platba' => $r['platba'] ?: null, 'preferovany_cas_zadosti' => $r['preferovano'] ?: null], $rows)];

    case 'najdi_zakaznika':
        $dotaz = trim((string)($in['dotaz'] ?? ''));
        if (mb_strlen($dotaz) < 2) throw new InvalidArgumentException('Dotaz je moc krátký.');
        $rows = zakaznici_hledej($dotaz, 15);
        return ['nalezeno' => count($rows), 'zakaznici' => array_map(fn($z) => [
            'zakaznik' => ai_z($z['id']), 'jmeno' => $z['jmeno'], 'prijmeni' => $z['prijmeni'], 'telefon_konec' => ai_tel_maska($z['telefon'])], $rows)];

    case 'zakaznik_detail':
        $z = q1('SELECT * FROM zakaznici WHERE id=?', [(int)($in['zakaznik_id'] ?? 0)]);
        if (!$z) throw new InvalidArgumentException('Zákazník neexistuje.');
        $st = zakaznik_statistika((int)$z['id']);
        $b = zakaznik_bonus($z);
        $p = $st['posledni'];
        $budouci = q("SELECT r.zacatek, r.stav, s.nazev AS sluzba, v.popis AS varianta, m.jmeno AS maserka FROM rezervace r
            LEFT JOIN varianty v ON v.id=r.varianta_id LEFT JOIN sluzby s ON s.id=v.sluzba_id LEFT JOIN maserky m ON m.id=r.maserka_id
            WHERE r.zakaznik_id=? AND r.stav IN ('zadost','potvrzeno') AND (r.zacatek IS NULL OR r.zacatek >= ?) ORDER BY r.zacatek", [$z['id'], nyni()])->fetchAll();
        $pouk = zakaznik_poukazy((int)$z['id']);
        return [
            'zakaznik' => ai_z($z['id']), 'jmeno' => $z['jmeno'],
            'stitky' => array_map(fn($s) => $s[0] . ' (' . $s[2] . ')', zakaznik_stitky($z, $st)),
            'navstev_celkem' => $st['pocet'], 'navstev_za_6_mesicu' => $st['za6m'], 'obvykly_interval_dni' => $st['interval'],
            'neprisel_nebo_pozde_zrusil_za_12_mesicu' => $st['neprisel'],
            'posledni_masaz' => $p ? ['datum' => $p['zacatek'], 'sluzba' => trim(($p['sluzba'] ?? '') . ' ' . ($p['var_popis'] ?? '')), 'varianta_id' => $p['varianta_id'] ? (int)$p['varianta_id'] : null,
                'maserka' => $p['maserka'], 'poznamka_k_masazi' => $p['poznamka_masaz'] ?: null] : null,
            'nejcastejsi_sluzba' => $st['nejSluzba'], 'nejcastejsi_maserka' => $st['nejMaserka'],
            'oblibena_maserka' => $z['pref_maserka_id'] ? ['id' => (int)$z['pref_maserka_id'], 'jmeno' => qv('SELECT jmeno FROM maserky WHERE id=?', [$z['pref_maserka_id']])] : null,
            'tlak' => $z['pref_tlak'] ?: null, 'preference' => $z['pref_text'] ?: null,
            'vezme_volny_termin' => (bool)$z['volny_termin'], 'kdy_muze' => $z['volny_kdy'] ?: null, 'blokovany' => (bool)$z['blokovany'],
            'interni_poznamka' => $z['poznamka_interni'] ?: null,
            'ma_zdravotni_poznamku' => trim($z['zdravi_text']) !== '',
            'vernost' => ['razitka' => $b['razitka'], 'z' => $b['po'], 'masaz_zdarma_k_uplatneni' => $b['zdarma']],
            'poukazy' => array_map(fn($x) => ['kod' => $x['kod'], 'zustatek_kc' => (int)$x['zustatek'], 'platnost_do' => $x['platnost_do']], $pouk),
            'budouci_rezervace' => $budouci,
        ];

    case 'sluzby':
        return ['varianty' => array_map(fn($r) => ['varianta_id' => (int)$r['id'], 'sluzba' => $r['nazev'], 'popis' => $r['popis'], 'kategorie' => $r['kat'],
            'minuty' => $r['minuty'] ? (int)$r['minuty'] : null, 'cena_kc' => (int)$r['cena'], 'maserek' => (int)$r['maserky'],
            'maserky_jen_prvnich_min' => $r['maserky_min'] ? (int)$r['maserky_min'] : null, 'wellness_maserna' => (bool)$r['wellness_mistnost']],
            q("SELECT v.*, s.nazev, s.kat FROM varianty v JOIN sluzby s ON s.id=v.sluzba_id WHERE v.aktivni=1 AND s.aktivni=1 ORDER BY s.poradi, v.poradi")->fetchAll())];

    case 'maserky':
        $od = ai_datum($in['datum_od'] ?? null);
        $do = ai_datum($in['datum_do'] ?? null, $od);
        if ((strtotime($do) - strtotime($od)) / 86400 > 13) $do = date('Y-m-d', strtotime("$od +13 days"));
        $out = [];
        foreach (q('SELECT * FROM maserky WHERE aktivni=1 ORDER BY poradi')->fetchAll() as $m) {
            $dny = [];
            for ($d = $od; $d <= $do; $d = date('Y-m-d', strtotime("$d +1 day"))) {
                $okna = array_map(fn($o) => hhmm($o[0]) . '–' . hhmm($o[1]), maserka_okna((int)$m['id'], $d));
                $pozn = array_map(fn($x) => $x[1], maserka_den_popis((int)$m['id'], $d));
                $dny[$d] = ['pracuje' => $okna ?: 'nepracuje'] + ($pozn ? ['pozn' => $pozn] : []);
            }
            $out[] = ['maserka_id' => (int)$m['id'], 'jmeno' => $m['jmeno'], 'zamereni' => $m['zamereni'] ?: null, 'preference' => $m['preference'] ?: null, 'dny' => $dny];
        }
        return ['maserky' => $out];

    case 'volne_terminy':
        $var = q1('SELECT v.*, s.nazev, s.kat FROM varianty v JOIN sluzby s ON s.id=v.sluzba_id WHERE v.id=?', [(int)($in['varianta_id'] ?? 0)]);
        if (!$var) throw new InvalidArgumentException('Varianta neexistuje – zjisti id nástrojem sluzby.');
        $od = ai_datum($in['datum_od'] ?? null);
        if ($od < date('Y-m-d')) $od = date('Y-m-d');
        $dny = max(1, min(7, (int)($in['pocet_dni'] ?? 1)));
        $mas = (int)($in['maserka_id'] ?? 0);
        $oblib = 0;
        if (!empty($in['zakaznik_id'])) $oblib = (int)qv('SELECT pref_maserka_id FROM zakaznici WHERE id=?', [(int)$in['zakaznik_id']]);
        $cOd = preg_match('~^\d{2}:\d{2}$~', (string)($in['cas_od'] ?? '')) ? $in['cas_od'] : '';
        $cDo = preg_match('~^\d{2}:\d{2}$~', (string)($in['cas_do'] ?? '')) ? $in['cas_do'] : '';
        $maserky = q('SELECT * FROM maserky WHERE aktivni=1 ORDER BY poradi')->fetchAll();
        $kand = array_values(array_filter($maserky, fn($m) => !$mas || (int)$m['id'] === $mas));
        usort($kand, fn($a, $b) => ((int)$b['id'] === $oblib) <=> ((int)$a['id'] === $oblib));
        $delka = (int)$var['minuty'];
        if ($delka <= 0) throw new InvalidArgumentException('Varianta nemá délku – termín vyber ručně.');
        $nazvy = array_column($maserky, 'jmeno', 'id');
        $sloty = []; $pocet = 0;
        foreach (volne_sloty($var, $delka, $kand, $od, $dny, 30, $cOd, $cDo) as $d => $casy)
            foreach ($casy as $hh => $ids) {
                if (++$pocet > 80) break 2;
                $sloty[] = ['datum' => $d, 'cas' => $hh, 'moznosti' => array_map(fn($x) => is_array($x)
                    ? ['maserka_id' => $x[0], 'maserka2_id' => $x[1], 'maserky' => ($nazvy[$x[0]] ?? '') . ' + ' . ($nazvy[$x[1]] ?? '')]
                    : ($x ? ['maserka_id' => $x, 'maserka' => $nazvy[$x] ?? ''] + ($x === $oblib ? ['oblibena' => true] : []) : ['bez_maserky' => true]), $ids)];
            }
        return ['sluzba' => $var['nazev'] . ' ' . $var['popis'], 'delka_min' => $delka, 'od' => $od, 'dni' => $dny,
            'zkraceno' => $pocet > 80, 'volne' => $sloty, 'pozn' => $sloty ? null : 'V rozsahu nic volného.'];

    case 'kontroly':
        $zad = q("SELECT r.id, r.zakaznik_id, r.preferovano, r.poznamka_zakaznik, r.vytvoreno, s.nazev AS sluzba, v.popis AS varianta FROM rezervace r
            LEFT JOIN varianty v ON v.id=r.varianta_id LEFT JOIN sluzby s ON s.id=v.sluzba_id WHERE r.stav='zadost' ORDER BY r.vytvoreno")->fetchAll();
        return [
            'zadosti_z_webu' => array_map(fn($r) => ['rezervace_id' => (int)$r['id'], 'zakaznik' => ai_z($r['zakaznik_id']), 'sluzba' => trim(($r['sluzba'] ?? '') . ' ' . ($r['varianta'] ?? '')),
                'preferuje' => $r['preferovano'], 'vytvoreno' => $r['vytvoreno']], $zad),
            'rezervace_u_nepracujici_maserky' => array_map(fn($r) => ['rezervace_id' => (int)$r['id'], 'zacatek' => $r['zacatek'], 'zakaznik' => ai_z($r['zakaznik_id']),
                'maserka' => $r['konf_jmeno'], 'sluzba' => trim(($r['sluzba'] ?? '') . ' ' . ($r['var_popis'] ?? ''))], konflikty_dostupnosti()),
            'neoznaceno_prisel_neprisel' => array_map(fn($r) => ['rezervace_id' => (int)$r['id'], 'zacatek' => $r['zacatek'], 'zakaznik' => ai_z($r['zakaznik_id']), 'maserka' => $r['maserka']], ke_kontrole()),
        ];

    case 'zakaznici_last_minute':
        $rows = q("SELECT z.*, m.jmeno AS oblibena FROM zakaznici z LEFT JOIN maserky m ON m.id=z.pref_maserka_id
            WHERE z.volny_termin=1 AND z.blokovany=0 ORDER BY z.prijmeni LIMIT 40")->fetchAll();
        return ['zakaznici' => array_map(function ($z) {
            $p = q1("SELECT zacatek FROM rezervace WHERE zakaznik_id=? AND stav='probehlo' ORDER BY zacatek DESC LIMIT 1", [$z['id']]);
            $b = q1("SELECT zacatek FROM rezervace WHERE zakaznik_id=? AND stav IN ('potvrzeno','zadost') AND zacatek >= ? ORDER BY zacatek LIMIT 1", [$z['id'], nyni()]);
            return ['zakaznik' => ai_z($z['id']), 'kdy_muze' => $z['volny_kdy'] ?: null, 'oblibena_maserka' => $z['oblibena'],
                'posledni_navsteva' => $p['zacatek'] ?? null, 'uz_ma_termin' => $b['zacatek'] ?? null];
        }, $rows)];

    case 'hodnoceni':
        $od = ai_datum($in['datum_od'] ?? null);
        $do = ai_datum($in['datum_do'] ?? null);
        $doX = date('Y-m-d', strtotime("$do +1 day"));
        $out = [];
        foreach (q('SELECT id, jmeno FROM maserky WHERE aktivni=1 ORDER BY poradi')->fetchAll() as $m) {
            $h = q1('SELECT COUNT(*) pocet, AVG(znamka) prumer FROM hodnoceni_maserek WHERE maserka_id=? AND cas>=? AND cas<?', [$m['id'], $od, $doX]);
            $out[] = ['maserka' => $m['jmeno'], 'hodnoceni_prumer' => $h['pocet'] ? round((float)$h['prumer'], 2) : null, 'hodnoceni_pocet' => (int)$h['pocet'],
                'masazi' => (int)qv("SELECT COUNT(*) FROM rezervace WHERE (maserka_id=? OR maserka2_id=?) AND stav='probehlo' AND zacatek>=? AND zacatek<?", [$m['id'], $m['id'], $od, $doX])];
        }
        return ['od' => $od, 'do' => $do, 'min_hodnoceni_pro_odmenu' => (int)nastaveni('odmena_min_hodnoceni', 3), 'maserky' => $out];

    case 'navrhnout_rezervaci':
        $z = q1('SELECT id FROM zakaznici WHERE id=?', [(int)($in['zakaznik_id'] ?? 0)]);
        if (!$z) throw new InvalidArgumentException('Zákazník neexistuje.');
        $var = q1('SELECT * FROM varianty WHERE id=? AND aktivni=1', [(int)($in['varianta_id'] ?? 0)]);
        if (!$var) throw new InvalidArgumentException('Varianta neexistuje.');
        $d = ai_datum($in['datum'] ?? null);
        $cas = (string)($in['cas'] ?? '');
        if (!preg_match('~^\d{2}:\d{2}$~', $cas)) throw new InvalidArgumentException('Čas čekám jako HH:MM.');
        $zac = "$d $cas:00";
        if (strtotime($zac) < time()) throw new InvalidArgumentException('Termín je v minulosti.');
        $delka = (int)$var['minuty'];
        if ($delka <= 0) throw new InvalidArgumentException('Varianta nemá délku.');
        $kon = date('Y-m-d H:i:s', strtotime($zac) + $delka * 60);
        $mk = $var['maserky_min'] && (int)$var['maserky_min'] < $delka ? date('Y-m-d H:i:s', strtotime($zac) + (int)$var['maserky_min'] * 60) : $kon;
        $potreba = (int)$var['maserky'];
        $m1 = (int)($in['maserka_id'] ?? 0) ?: null; $m2 = (int)($in['maserka2_id'] ?? 0) ?: null;
        if ($potreba === 0) { $m1 = $m2 = null; }
        if ($potreba >= 1 && !$m1) throw new InvalidArgumentException('Chybí masérka.');
        if ($potreba >= 2 && (!$m2 || $m2 === $m1)) throw new InvalidArgumentException('Párová služba potřebuje dvě různé masérky.');
        if ($potreba < 2) $m2 = null;
        foreach (array_filter([$m1, $m2]) as $mid) {
            $jm = qv('SELECT jmeno FROM maserky WHERE id=? AND aktivni=1', [$mid]);
            if (!$jm) throw new InvalidArgumentException("Masérka $mid neexistuje.");
            if (!maserka_pracuje($mid, $zac, $mk)) throw new InvalidArgumentException("$jm v tu dobu nepracuje.");
            if (kolize($mid, $zac, $mk)) throw new InvalidArgumentException("$jm má v tu dobu jinou rezervaci.");
        }
        if ($k = kapacita_problem($zac, $kon, (int)$var['wellness_mistnost'] === 1)) throw new InvalidArgumentException($k);
        q('INSERT INTO ai_navrhy(zakaznik_id,varianta_id,maserka_id,maserka2_id,zacatek,delka,zduvodneni,pro_uzivatele) VALUES(?,?,?,?,?,?,?,?)',
            [$z['id'], $var['id'], $m1, $m2, $zac, $delka, mb_substr(trim((string)($in['zduvodneni'] ?? '')), 0, 300), kdo()]);
        $id = (int)db()->lastInsertId();
        $navrhy[] = $id;
        return ['ok' => true, 'navrh_id' => $id, 'pozn' => 'Návrh čeká na recepci (karta pod odpovědí). Nic není zarezervováno.'];
    }
    throw new InvalidArgumentException("Neznámý nástroj $jmeno.");
}

// ---------------- Volání Claude API ----------------
function ai_api(array $telo, ?string $klic = null): array
{
    $c = ai_cfg();
    $json = json_encode($telo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $hlav = ['content-type: application/json', 'x-api-key: ' . trim($klic ?? $c['api_key']), 'anthropic-version: ' . AI_API_VERZE];
    if (function_exists('curl_init')) {
        $ch = curl_init($c['api_url']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $hlav,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => (int)$c['timeout'], CURLOPT_CONNECTTIMEOUT => 10]);
        $odp = curl_exec($ch);
        $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($odp === false) throw new RuntimeException('Spojení s AI selhalo: ' . $err);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $hlav), 'content' => $json,
            'timeout' => (int)$c['timeout'], 'ignore_errors' => true]]);
        $odp = @file_get_contents($c['api_url'], false, $ctx);
        if ($odp === false) throw new RuntimeException('Spojení s AI selhalo.');
        $kod = preg_match('~^HTTP/\S+ (\d{3})~', $http_response_header[0] ?? '', $m) ? (int)$m[1] : 0;
    }
    $data = json_decode($odp, true);
    if ($kod !== 200 || !is_array($data)) {
        $msg = $data['error']['message'] ?? mb_substr((string)$odp, 0, 200);
        throw new RuntimeException("AI vrátila chybu $kod: $msg");
    }
    return $data;
}

/**
 * Jeden dotaz recepce → odpověď asistenta. Historie konverzace = jen text (dotazy a odpovědi),
 * mezikroky s nástroji se neukládají. Vrací ['text', 'navrhy' => [id], 'nastroje' => [jména]].
 */
function ai_dotaz(string $dotaz, array $historie, array $u): array
{
    $c = ai_cfg();
    if (!ai_zapnuto()) throw new RuntimeException('Asistent není zapnutý: admin musí vložit API klíč v Asistent → Nastavení a spotřeba.');
    $zpravy = [];
    foreach (array_slice($historie, -12) as $h) $zpravy[] = ['role' => $h['role'], 'content' => $h['text']];
    $zpravy[] = ['role' => 'user', 'content' => $dotaz];
    ai_log('dotaz', $dotaz);

    $navrhy = []; $pouzite = [];
    for ($krok = 0; $krok <= (int)$c['max_kroku']; $krok++) {
        if (ai_volani_dnes() >= (int)$c['denni_limit']) throw new RuntimeException('Dnešní limit volání asistenta je vyčerpaný (config.php → ai → denni_limit).');
        $telo = ['model' => $c['model'], 'max_tokens' => (int)$c['max_tokens'], 'system' => ai_system($u), 'messages' => $zpravy];
        if ($krok < (int)$c['max_kroku']) $telo['tools'] = ai_nastroje();   // poslední kolo už bez nástrojů → musí odpovědět
        $odp = ai_api($telo);
        ai_log('api', $c['model'] . ' · ' . ($odp['stop_reason'] ?? ''), (int)($odp['usage']['input_tokens'] ?? 0), (int)($odp['usage']['output_tokens'] ?? 0));
        $obsah = $odp['content'] ?? [];
        $volani = array_values(array_filter($obsah, fn($b) => ($b['type'] ?? '') === 'tool_use'));
        if (($odp['stop_reason'] ?? '') !== 'tool_use' || !$volani) {
            $text = trim(implode("\n", array_map(fn($b) => $b['text'], array_filter($obsah, fn($b) => ($b['type'] ?? '') === 'text'))));
            return ['text' => $text !== '' ? $text : '(bez odpovědi)', 'navrhy' => $navrhy, 'nastroje' => $pouzite];
        }
        $zpravy[] = ['role' => 'assistant', 'content' => $obsah];
        $vysledky = [];
        foreach ($volani as $v) {
            $pouzite[] = $v['name'];
            $vstup = is_array($v['input'] ?? null) ? $v['input'] : [];
            try {
                $data = ai_nastroj($v['name'], $vstup, $navrhy);
                $vysledky[] = ['type' => 'tool_result', 'tool_use_id' => $v['id'], 'content' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)];
                ai_log('nastroj', $v['name'] . ' ' . json_encode($vstup, JSON_UNESCAPED_UNICODE));
            } catch (InvalidArgumentException $e) {
                $vysledky[] = ['type' => 'tool_result', 'tool_use_id' => $v['id'], 'content' => $e->getMessage(), 'is_error' => true];
                ai_log('nastroj_chyba', $v['name'] . ' ' . json_encode($vstup, JSON_UNESCAPED_UNICODE) . ' → ' . $e->getMessage());
            }
        }
        $zpravy[] = ['role' => 'user', 'content' => $vysledky];
    }
    throw new RuntimeException('Asistent nedošel k odpovědi v povoleném počtu kroků.');
}
