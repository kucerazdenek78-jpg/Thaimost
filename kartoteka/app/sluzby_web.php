<?php
// Texty služeb pro web (název, popis, poznámka + překlady EN/DE). Upravují se v Nastavení → Ceník,
// web je bere z /api/stav (sluzby) místo textů v assets/js/data.js. Výchozí hodnoty = texty webu z 11/2026.

const SLUZBY_WEB_JAZYKY = ['en' => 'English', 'de' => 'Deutsch'];
const SLUZBY_WEB_POLE = ['nazev', 'popis', 'pozn'];

/** Výchozí texty (z assets/js/data.js a tools/i18n) – nahrají se do kartotéky při migraci v21. */
function sluzby_web_vychozi(): array
{
    return [
    'tradicni' => ['nazev' => 'Tradiční thajská masáž', 'popis' => 'Kombinace akupresury, práce na energetických drahách a protažení v jógových pozicích. Bez oleje, na matraci na podlaze, klient zůstává oblečen.', 'pozn' => 'Doporučené oblečení: spodní prádlo nebo slabé legíny a tričko.', 'en' => ['nazev' => 'Traditional Thai massage', 'popis' => 'A combination of acupressure, work on energy meridians and stretching in yoga poses. No oil is used; the session takes place on a mat on the floor, and the client remains fully clothed.', 'pozn' => 'Recommended clothing: underwear or thin leggings and a T-shirt.'], 'de' => ['nazev' => 'Traditionelle thailändische Massage', 'popis' => 'Eine Kombination aus Akupressur, Arbeit an den Energiebahnen und Dehnübungen in Yoga-Stellungen. Ohne Öl, auf einer Matte auf dem Boden; der Klient bleibt bekleidet.', 'pozn' => 'Empfohlene Kleidung: Unterwäsche oder dünne Leggings und ein T-Shirt.']],
    'hlava' => ['nazev' => 'Masáž hlavy a šíje', 'popis' => 'Masáž zaměřená na krční páteř, hlavu a ramena. Probíhá nasucho, stlačováním akupresurních bodů uvolňuje bloky v těchto partiích.', 'pozn' => '', 'en' => ['nazev' => 'Head and neck massage', 'popis' => 'A massage focusing on the cervical spine, head and shoulders. It is a dry massage which, by applying pressure to acupressure points, releases tension in these areas.'], 'de' => ['nazev' => 'Kopf- und Nackenmassage', 'popis' => 'Eine Massage, die sich auf die Halswirbelsäule, den Kopf und die Schultern konzentriert. Sie wird ohne Öl durchgeführt und löst durch Druck auf Akupressurpunkte Verspannungen in diesen Bereichen.']],
    'zada' => ['nazev' => 'Masáž zad a krku', 'popis' => 'Speciální techniky zaměřené na šíji, ramena, bederní a křížovou oblast. Pravidelné opakování může zmírnit bolesti zad ze sedavého zaměstnání.', 'pozn' => '', 'en' => ['nazev' => 'Back and neck massage', 'popis' => 'Special techniques focusing on the neck, shoulders, lower back and sacrum. Regular practice can help relieve back pain caused by a sedentary job.'], 'de' => ['nazev' => 'Rücken- und Nackenmassage', 'popis' => 'Spezielle Techniken für den Nacken, die Schultern sowie den Lenden- und Kreuzbereich. Regelmäßiges Wiederholen kann Rückenschmerzen lindern, die durch eine sitzende Tätigkeit verursacht werden.']],
    'olej' => ['nazev' => 'Thajská olejová masáž', 'popis' => 'Velmi relaxační forma thajské masáže – jemná až střední technika spojená s účinky aromatických olejů z thajských bylin.', 'pozn' => '', 'en' => ['nazev' => 'Thai oil massage', 'popis' => 'A very relaxing form of Thai massage – using gentle to moderate pressure combined with the benefits of aromatic oils made from Thai herbs.'], 'de' => ['nazev' => 'Thailändische Ölmassage', 'popis' => 'Eine sehr entspannende Form der Thai-Massage – sanfte bis mittelstarke Techniken, kombiniert mit der Wirkung aromatischer Öle aus thailändischen Kräutern.']],
    'aroma' => ['nazev' => 'Aroma masáž', 'popis' => 'Olejová masáž celého těla s vonnými oleji dle výběru, doplněná inhalací esencí. Na výběr limetka + bambus nebo citronová tráva + pomeranč.', 'pozn' => '', 'en' => ['nazev' => 'Aromatherapy massage', 'popis' => 'A full-body oil massage using aromatic oils of your choice, complemented by the inhalation of essential oils. Choose from lime + bamboo or lemongrass + orange.'], 'de' => ['nazev' => 'Aromamassage', 'popis' => 'Ganzkörper-Ölmassage mit Duftölen Ihrer Wahl, ergänzt durch eine Inhalation mit ätherischen Ölen. Zur Auswahl stehen Limette + Bambus oder Zitronengras + Orange.']],
    'detska' => ['nazev' => 'Dětská masáž', 'popis' => 'Olejová masáž celého těla pro děti od 2 do 10 let, přizpůsobená jejich věku a fyzickému stavu.', 'pozn' => '', 'en' => ['nazev' => 'Children’s massage', 'popis' => 'A full-body oil massage for children aged 2 to 10, tailored to their age and physical condition.'], 'de' => ['nazev' => 'Kindermassage', 'popis' => 'Ganzkörper-Ölmassage für Kinder im Alter von 2 bis 10 Jahren, angepasst an ihr Alter und ihre körperliche Verfassung.']],
    'lymfa-oblicej' => ['nazev' => 'Lymfatická masáž obličeje a dekoltu', 'popis' => 'Velmi jemné a pomalé hmaty na suchou pokožku bez olejů a krémů. Zbavuje podkoží přebytečné tekutiny a podporuje regeneraci pleti.', 'pozn' => '', 'en' => ['nazev' => 'Lymphatic massage of the face and décolletage', 'popis' => 'Very gentle, slow strokes on dry skin, without any oils or creams. It removes excess fluid from the subcutaneous tissue and promotes skin regeneration.'], 'de' => ['nazev' => 'Lymphdrainage für Gesicht und Dekolleté', 'popis' => 'Sehr sanfte und langsame Streichbewegungen auf trockener Haut ohne Öle und Cremes. Befreit das Unterhautgewebe von überschüssiger Flüssigkeit und fördert die Regeneration der Haut.']],
    'poporodni' => ['nazev' => 'Poporodní masáž', 'popis' => 'Spojení tradiční thajské masáže a bylinné léčby s přikládáním bylinných váčků na problematické partie ženského těla po porodu.', 'pozn' => '', 'en' => ['nazev' => 'Postnatal massage', 'popis' => 'A combination of traditional Thai massage and herbal therapy, involving the application of herbal pouches to problem areas of a woman’s body following childbirth.'], 'de' => ['nazev' => 'Massage nach der Geburt', 'popis' => 'Eine Kombination aus traditioneller thailändischer Massage und Kräuterbehandlung, bei der Kräuterbeutel auf die problematischen Körperbereiche der Frau nach der Geburt aufgelegt werden.']],
    'oblicej' => ['nazev' => 'Masáž obličeje', 'popis' => 'Spojení kosmetického ošetření pleti a akupresurní masáže – peeling, pleťová maska a regenerační krémy. Během masky i masáž krční páteře.', 'pozn' => '', 'en' => ['nazev' => 'Facial massage', 'popis' => 'A combination of a cosmetic skin treatment and an acupressure massage – exfoliation, a face mask and regenerating creams. The treatment also includes a neck massage whilst the mask is on.'], 'de' => ['nazev' => 'Gesichtsmassage', 'popis' => 'Eine Kombination aus kosmetischer Hautpflege und Akupressur-Massage – Peeling, Gesichtsmaske und regenerierende Cremes. Während der Maske erfolgt zudem eine Massage der Halswirbelsäule.']],
    'nohy' => ['nazev' => 'Masáž nohou', 'popis' => 'Část tradiční thajské masáže pro unavené a opuchlé nohy, kombinovaná s reflexologií. S thajským balzámem nebo nahřívanými bylinnými sáčky.', 'pozn' => '', 'en' => ['nazev' => 'Foot massage', 'popis' => 'A traditional Thai massage treatment for tired and swollen legs, combined with reflexology. With Thai balm or heated herbal pouches.'], 'de' => ['nazev' => 'Fußmassage', 'popis' => 'Ein Teil der traditionellen thailändischen Massage für müde und geschwollene Beine, kombiniert mit Reflexzonenmassage. Mit thailändischem Balsam oder erwärmten Kräutersäckchen.']],
    'bylinna' => ['nazev' => 'Bylinná masáž', 'popis' => 'Povzbuzující herbal massage – tradiční thajská masáž doplněná horkými bylinnými houbičkami pro hlubší uvolnění svalů.', 'pozn' => '', 'en' => ['nazev' => 'Herbal massage', 'popis' => 'Invigorating herbal massage – a traditional Thai massage complemented by hot herbal pouches for deeper muscle relaxation.'], 'de' => ['nazev' => 'Kräutermassage', 'popis' => 'Belebende Kräutermassage – traditionelle thailändische Massage, ergänzt durch heiße Kräuterbällchen für eine tiefere Muskelentspannung.']],
    'siam' => ['nazev' => 'Siam massage', 'popis' => 'Horká bylinná masáž z tradiční thajské medicíny. Nahřáté bylinné váčky uvolňují svalovou bolest a změkčují pokožku.', 'pozn' => '', 'en' => ['nazev' => 'Siam massage', 'popis' => 'A hot herbal massage based on traditional Thai medicine. The heated herbal pouches relieve muscle pain and soften the skin.'], 'de' => ['nazev' => 'Siam massage', 'popis' => 'Eine heiße Kräutermassage aus der traditionellen thailändischen Medizin. Die erwärmten Kräutersäckchen lindern Muskelschmerzen und machen die Haut geschmeidig.']],
    'ventosa' => ['nazev' => 'Ventosa masáž (baňkování)', 'popis' => 'Tradiční filipínská masáž: olejová masáž celého těla, skleněné baňky vytvářející podtlak a ušní svíčky.', 'pozn' => '', 'en' => ['nazev' => 'Cupping massage (cupping therapy)', 'popis' => 'Traditional Filipino massage: a full-body oil massage, glass cupping to create a vacuum, and ear candling.'], 'de' => ['nazev' => 'Schröpfmassage (Schröpfen)', 'popis' => 'Traditionelle philippinische Massage: Ganzkörper-Ölmassage, Glaskuppeln zur Erzeugung eines Unterdrucks und Ohrkerzen.']],
    'lava' => ['nazev' => 'Masáž obličeje lávovými kameny', 'popis' => 'Teplo a vibrace lávových kamenů pro regeneraci pleti, relaxaci a uvolnění. Vhodné pro všechny typy pokožky.', 'pozn' => '', 'en' => ['nazev' => 'Hot stone facial massage', 'popis' => 'The warmth and vibrations of lava stones to rejuvenate the skin, promote relaxation and relieve tension. Suitable for all skin types.'], 'de' => ['nazev' => 'Gesichtsmassage mit Lavasteinen', 'popis' => 'Die Wärme und die Schwingungen der Lavasteine sorgen für Hautregeneration, Entspannung und Erholung. Für alle Hauttypen geeignet.']],
    'lymfa-telo' => ['nazev' => 'Ruční lymfatická masáž celého těla', 'popis' => 'Lymfatická masáž celého těla prováděná ručně.', 'pozn' => '', 'en' => ['nazev' => 'Full-body manual lymphatic massage', 'popis' => 'A full-body lymphatic massage performed by hand.'], 'de' => ['nazev' => 'Manuelle Lymphdrainage am ganzen Körper', 'popis' => 'Eine manuell durchgeführte Ganzkörper-Lymphdrainage.']],
    'tehotenska' => ['nazev' => 'Těhotenská masáž', 'popis' => 'Jemná tlaková masáž v pohodlném oblečení pro budoucí maminky. Pomáhá při bolestech zad a hlavy, nespavosti, otocích i stresu.', 'pozn' => '', 'en' => ['nazev' => 'Pregnancy massage', 'popis' => 'A gentle pressure massage in comfortable clothing for expectant mums. It helps with backache, headaches, insomnia, swelling and stress.'], 'de' => ['nazev' => 'Schwangerschaftsmassage', 'popis' => 'Eine sanfte Druckmassage in bequemer Kleidung für werdende Mütter. Sie hilft bei Rücken- und Kopfschmerzen, Schlaflosigkeit, Schwellungen und Stress.']],
    'whirlpool' => ['nazev' => 'Whirlpool a sauna', 'popis' => 'Privátní relaxace ve whirlpoolu a sauně. Karafa vody ke všem procedurám zdarma, šampaňské 150 Kč.', 'pozn' => '', 'en' => ['nazev' => 'Whirlpool and sauna', 'popis' => 'Private relaxation in the whirlpool and sauna. A carafe of water is provided free of charge with all treatments; champagne costs 150 CZK.'], 'de' => ['nazev' => 'Whirlpool und Sauna', 'popis' => 'Private Entspannung im Whirlpool und in der Sauna. Eine Karaffe Wasser zu allen Behandlungen kostenlos, Champagner 150 CZK.']],
    'fiji' => ['nazev' => 'Fiji rituál', 'popis' => 'Kokosový krémový peeling, zábal s tělovým máslem, přikládání lávových kamenů a na závěr lehká olejová masáž.', 'pozn' => '', 'en' => ['nazev' => 'Fijian ritual', 'popis' => 'A coconut cream scrub, a body butter wrap, hot stone therapy, and, to finish, a light oil massage.'], 'de' => ['nazev' => 'Fidschi-Ritual', 'popis' => 'Ein cremiges Kokos-Peeling, eine Körperbutter-Packung, die Anwendung von Lavasteinen und zum Abschluss eine sanfte Ölmassage.']],
    'cukr' => ['nazev' => 'Cukrový vánek z ráje', 'popis' => 'Luxusní peeling cukrovou třtinou z Fidži s tropickými oleji a kokosovým mlékem. Odstraní odumřelé buňky a hluboce hydratuje.', 'pozn' => '', 'en' => ['nazev' => 'A sugary breeze from paradise', 'popis' => 'A luxurious sugar scrub made from Fijian sugar cane, enriched with tropical oils and coconut milk. It removes dead skin cells and deeply moisturises.'], 'de' => ['nazev' => 'Ein süßer Hauch aus dem Paradies', 'popis' => 'Ein luxuriöses Peeling mit Zuckerrohr aus Fidschi, tropischen Ölen und Kokosmilch. Es entfernt abgestorbene Hautzellen und spendet tiefgehende Feuchtigkeit.']],
    ];
}

/** Kód nové služby z názvu (bez diakritiky, malá písmena, pomlčky) – jedinečný. */
function sluzba_novy_kod(string $nazev): string
{
    $k = strtolower(trim(preg_replace('~[^a-z0-9]+~i', '-', strtr($nazev, ['á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z',
        'Á'=>'a','Č'=>'c','Ď'=>'d','É'=>'e','Ě'=>'e','Í'=>'i','Ň'=>'n','Ó'=>'o','Ř'=>'r','Š'=>'s','Ť'=>'t','Ú'=>'u','Ů'=>'u','Ý'=>'y','Ž'=>'z'])), '-'));
    $k = substr($k !== '' ? $k : 'sluzba', 0, 30);
    $z = $k; $i = 2;
    while (qv('SELECT 1 FROM sluzby WHERE kod=?', [$z])) $z = $k . '-' . $i++;
    return $z;
}

/** Přidá položku ceníku ke službě (prázdný popis = nic). */
function sluzba_pridej_variantu(int $sid, string $popis, $minuty, $cena): bool
{
    $popis = mb_substr(trim($popis), 0, 120);
    if ($popis === '' || (int)$cena <= 0) return false;
    q('INSERT INTO varianty(sluzba_id,popis,minuty,cena,poradi) VALUES(?,?,?,?,(SELECT COALESCE(MAX(poradi),0)+1 FROM varianty WHERE sluzba_id=?))',
        [$sid, $popis, (string)$minuty !== '' ? max(0, (int)$minuty) : null, (int)$cena, $sid]);
    return true;
}

/** Překlady služby: ['en' => ['nazev' => …, 'popis' => …, 'pozn' => …], 'de' => …]. */
function sluzba_preklady(array $s): array
{
    $p = json_decode((string)($s['preklady'] ?? ''), true);
    $o = [];
    foreach (array_keys(SLUZBY_WEB_JAZYKY) as $j) foreach (SLUZBY_WEB_POLE as $f) $o[$j][$f] = trim((string)($p[$j][$f] ?? ''));
    return $o;
}

/** Služby pro web: texty, překlady a ceny aktivních variant (pořadí jako v kartotéce). */
function sluzby_web(): array
{
    $var = [];
    foreach (q('SELECT sluzba_id, popis, cena FROM varianty WHERE aktivni=1 ORDER BY poradi, id')->fetchAll() as $v) $var[$v['sluzba_id']][] = [$v['popis'], (int)$v['cena']];
    $o = [];
    foreach (q('SELECT * FROM sluzby WHERE kod IS NOT NULL ORDER BY poradi, id')->fetchAll() as $s) {
        $o[$s['kod']] = ['aktivni' => (int)$s['aktivni'] === 1 && !empty($var[$s['id']]), 'kat' => $s['kat'] === 'wellness' ? 'wellness' : 'masaze', 'nazev' => $s['nazev'], 'popis' => $s['popis_web'] ?? '', 'pozn' => $s['pozn_web'] ?? '',
            'preklady' => sluzba_preklady($s), 'ceny' => $var[$s['id']] ?? []];
    }
    return $o;
}

// ---------- Ceník A4 k tisku (Nastavení → Ceník → „Ceník A4“) ----------

/** Pevné texty ceníku a položky ceníku v angličtině / němčině (z překladů webu, tools/i18n). */
const CENIK_TISK_PREKLADY = ['en' => ['Sauna + sprcha' => 'Sauna + shower', '90 min whirlpool' => '90-minute whirlpool', '90 min whirlpool + sauna' => '90-minute whirlpool + sauna', '120 min párová (2 asistentky + šampaňské + whirlpool + sauna)' => '120-minute couples’ treatment (2 assistants + champagne + whirlpool + sauna)', '60 min s bylinnými sáčky' => '60 minutes with herbal sachets', 'Víkendové masáže dle domluvy s masérkami – příplatek 150 Kč/osoba.' => 'Weekend massages by arrangement with the masseuses – surcharge of 150 CZK per person.', 'Masáže' => 'Massages', 'Wellness a rituály' => 'Wellness and rituals', 'Otevřeno Po–Pá 9–20 h, víkendy po domluvě. Změny a storno prosím telefonicky.' => 'Open Monday–Friday 9 am–8 pm; weekends by arrangement. Please contact us by telephone to make any changes or cancellations.', 'Dárkové poukazy' => 'Gift vouchers', 'Ceník' => 'Price List', 'Thajské masáže Most · od roku 2004' => 'Thai Massages Most · since 2004', 'Platí od' => 'Valid from', 'Každá šestá masáž zdarma s věrnostní kartičkou.' => 'Every sixth massage is free with a loyalty card.', 'Dárkové poukazy na částku i na konkrétní masáž – na recepci nebo na webu.' => 'Gift vouchers for a specific amount or for a particular massage – available at reception or online.', 'Objednávky' => 'Orders'], 'de' => ['Sauna + sprcha' => 'Sauna + Dusche', '90 min whirlpool' => '90 Minuten Whirlpool', '90 min whirlpool + sauna' => '90 Minuten Whirlpool + Sauna', '120 min párová (2 asistentky + šampaňské + whirlpool + sauna)' => '120 Min. für Paare (2 Assistentinnen + Champagner + Whirlpool + Sauna)', '60 min s bylinnými sáčky' => '60 Minuten mit Kräutersäckchen', 'Víkendové masáže dle domluvy s masérkami – příplatek 150 Kč/osoba.' => 'Massagen am Wochenende nach Absprache mit den Masseurinnen – Aufpreis 150 CZK pro Person.', 'Masáže' => 'Massagen', 'Wellness a rituály' => 'Wellness und Rituale', 'Otevřeno Po–Pá 9–20 h, víkendy po domluvě. Změny a storno prosím telefonicky.' => 'Geöffnet Montag–Freitag 9–20 Uhr, am Wochenende nach Vereinbarung. Änderungen und Stornierungen bitte telefonisch.', 'Dárkové poukazy' => 'Geschenkgutscheine', 'Ceník' => 'Preisliste', 'Thajské masáže Most · od roku 2004' => 'Thai-Massagen Most · seit 2004', 'Platí od' => 'Gültig ab', 'Každá šestá masáž zdarma s věrnostní kartičkou.' => 'Jede sechste Massage ist mit der Treuekarte kostenlos.', 'Dárkové poukazy na částku i na konkrétní masáž – na recepci nebo na webu.' => 'Geschenkgutscheine für einen bestimmten Betrag oder für eine bestimmte Massage – an der Rezeption oder auf der Website erhältlich.', 'Objednávky' => 'Bestellungen']];

function cenik_t(string $t, string $jz): string
{
    if ($jz === 'cs') return $t;
    if (isset(CENIK_TISK_PREKLADY[$jz][$t])) return CENIK_TISK_PREKLADY[$jz][$t];
    if (preg_match('~^(\d+) min$~', $t, $m)) return $jz === 'en' ? "$m[1] mins" : "$m[1] Min.";
    return $t;
}

function cenik_kc(int $n, string $jz): string
{
    return $jz === 'cs' ? number_format($n, 0, ',', "\u{00A0}") . "\u{00A0}Kč" : number_format($n, 0, ',', $jz === 'de' ? '.' : ',') . "\u{00A0}CZK";
}

/** Ceník na jednu stranu A4 – tisk z prohlížeče (Tisk → Uložit jako PDF). Volby: jazyk, popisy, datum platnosti. */
function stranka_cenik_tisk(): void
{
    $jz = in_array($_GET['jazyk'] ?? 'cs', ['cs', 'en', 'de'], true) ? $_GET['jazyk'] : 'cs';
    $popisy = ($_GET['popisy'] ?? '1') === '1';
    $od = preg_match('~^\d{4}-\d{2}-\d{2}$~', $_GET['od'] ?? '') ? $_GET['od'] : date('Y-m-d');
    $kat = q('SELECT kod, kat FROM sluzby')->fetchAll(PDO::FETCH_KEY_PAIR);
    $skupiny = ['masaze' => [], 'wellness' => []];
    foreach (sluzby_web() as $kod => $s) {
        if (!$s['aktivni']) continue;
        $tr = $s['preklady'][$jz] ?? [];
        $s['t_nazev'] = $jz !== 'cs' && ($tr['nazev'] ?? '') !== '' ? $tr['nazev'] : $s['nazev'];
        $s['t_popis'] = $jz !== 'cs' && ($tr['popis'] ?? '') !== '' ? $tr['popis'] : $s['popis'];
        $s['t_pozn'] = $jz !== 'cs' && ($tr['pozn'] ?? '') !== '' ? $tr['pozn'] : $s['pozn'];
        $skupiny[($kat[$kod] ?? 'masaze') === 'wellness' ? 'wellness' : 'masaze'][] = $s;
    }
    $datum = $jz === 'en' ? date('j F Y', strtotime($od)) : date('j. n. Y', strtotime($od));
    $bonus = (int)nastaveni('bonus_po', 5) === 5;
    $url = function (array $zmena) use ($jz, $popisy, $od) { return '/?' . http_build_query(array_merge(['r' => 'nastaveni', 'sekce' => 'cenik_tisk', 'jazyk' => $jz, 'popisy' => $popisy ? '1' : '0', 'od' => $od], $zmena)); };
    ?><!doctype html><html lang="<?= $jz ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(cenik_t('Ceník', $jz)) ?> – Thai Most</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=EB+Garamond:wght@500;600&family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
<style>
@page{size:A4;margin:0}
*{box-sizing:border-box}
body{margin:0;background:#e9e2d8;font:400 9.4pt/1.38 Manrope,Arial,sans-serif;color:#2b1e16;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.list{width:210mm;height:297mm;margin:12px auto;background:#fbf7f1;padding:12mm 13mm 10mm;position:relative;box-shadow:0 4px 24px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden;font-size:9.4pt}
.obsah{display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden;font-size:1em}
.hl{display:flex;align-items:flex-end;justify-content:space-between;border-bottom:1.5pt solid #b07a3a;padding-bottom:.42em;margin-bottom:.6em}
.hl h1{margin:0;font:600 3em/1 "EB Garamond",Georgia,serif;color:#6b4a2b;letter-spacing:.01em}
.hl .pod{font:700 .78em Manrope;letter-spacing:.22em;text-transform:uppercase;color:#b07a3a;margin-top:.3em}
.hl .kontakt{text-align:right;font-size:.92em;line-height:1.5}.hl .kontakt b{font-size:1.25em}
h2{font:600 1.6em "EB Garamond",Georgia,serif;color:#6b4a2b;margin:0 0 .3em;padding-bottom:.05em;border-bottom:.6pt solid #d9c3a3}
.sk{columns:2;column-gap:8mm;margin-bottom:.5em}
.sl{break-inside:avoid;margin:0 0 .5em}
.sl h3{margin:0;font:600 1.22em/1.2 "EB Garamond",Georgia,serif;color:#2b1e16}
.sl p{margin:.1em 0 .15em;font-size:.86em;line-height:1.33;color:#5a4a3f}.sl p.pozn{font-style:italic;color:#7d6a5c;margin-top:0}
.c{display:flex;align-items:baseline;gap:.4em;font-size:.95em;line-height:1.35}.c span{flex:0 1 auto}.c i{flex:1;border-bottom:.8pt dotted #b9a58c;transform:translateY(-.25em)}.c b{white-space:nowrap;font-weight:700}
.pata{margin-top:auto;border-top:.6pt solid #d9c3a3;padding-top:.4em;font-size:.86em;color:#5a4a3f;display:flex;justify-content:space-between;gap:8mm}
.pata ul{margin:0;padding-left:1.1em}
.pata .plati{white-space:nowrap;text-align:right}
.ovl{max-width:210mm;margin:12px auto 0;font:14px Manrope,sans-serif;display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:0 8px}
.ovl a,.ovl button{padding:7px 12px;border-radius:8px;border:1px solid #b07a3a;background:#fff;color:#6b4a2b;text-decoration:none;font:600 14px Manrope,sans-serif;cursor:pointer}
.ovl a.on{background:#6b4a2b;color:#fff;border-color:#6b4a2b}.ovl button{background:#b07a3a;color:#fff}.ovl input{font:14px Manrope;padding:5px}
@media print{body{background:#fff}.ovl{display:none}.list{margin:0;box-shadow:none}}
</style></head><body>
<div class="ovl">
  <button onclick="print()">🖨 Vytisknout / uložit jako PDF</button>
  <?php foreach (['cs' => 'Česky', 'en' => 'English', 'de' => 'Deutsch'] as $k => $n): ?><a class="<?= $k === $jz ? 'on' : '' ?>" href="<?= h($url(['jazyk' => $k])) ?>"><?= $n ?></a><?php endforeach ?>
  <a class="<?= $popisy ? 'on' : '' ?>" href="<?= h($url(['popisy' => $popisy ? '0' : '1'])) ?>">Popisy služeb <?= $popisy ? 'ano' : 'ne' ?></a>
  <form style="display:inline-flex;gap:6px;align-items:center" method="get"><input type="hidden" name="r" value="nastaveni"><input type="hidden" name="sekce" value="cenik_tisk"><input type="hidden" name="jazyk" value="<?= $jz ?>"><input type="hidden" name="popisy" value="<?= $popisy ? '1' : '0' ?>">Platí od <input type="date" name="od" value="<?= h($od) ?>" onchange="this.form.submit()"></form>
  <span style="color:#7d6a5c;font-size:12px" id="vejde"></span>
</div>
<div class="list" id="list"><div class="obsah" id="obsah">
  <div class="hl">
    <div><h1>Thai Most · <?= h(cenik_t('Ceník', $jz)) ?></h1><div class="pod"><?= h(cenik_t('Thajské masáže Most · od roku 2004', $jz)) ?></div></div>
    <div class="kontakt"><b>+420 778 429 329</b><br>SNP 2654/26, 434 01 Most<br>www.thaimost.cz</div>
  </div>
  <?php foreach (['masaze' => 'Masáže', 'wellness' => 'Wellness a rituály'] as $k => $nadpis): if (!$skupiny[$k]) continue; ?>
    <h2><?= h(cenik_t($nadpis, $jz)) ?></h2>
    <div class="sk">
    <?php foreach ($skupiny[$k] as $s): ?>
      <div class="sl"><h3><?= h($s['t_nazev']) ?></h3>
        <?php if ($popisy && $s['t_popis'] !== ''): ?><p><?= h($s['t_popis']) ?></p><?php endif ?>
        <?php if ($popisy && $s['t_pozn'] !== ''): ?><p class="pozn"><?= h($s['t_pozn']) ?></p><?php endif ?>
        <?php foreach ($s['ceny'] as [$pol, $cena]): ?><div class="c"><span><?= h(cenik_t($pol, $jz)) ?></span><i></i><b><?= h(cenik_kc($cena, $jz)) ?></b></div><?php endforeach ?>
      </div>
    <?php endforeach ?>
    </div>
  <?php endforeach ?>
  <div class="pata">
    <ul>
      <?php if ($bonus): ?><li><?= h(cenik_t('Každá šestá masáž zdarma s věrnostní kartičkou.', $jz)) ?></li><?php endif ?>
      <li><?= h(cenik_t('Víkendové masáže dle domluvy s masérkami – příplatek 150 Kč/osoba.', $jz)) ?></li>
      <li><?= h(cenik_t('Dárkové poukazy na částku i na konkrétní masáž – na recepci nebo na webu.', $jz)) ?></li>
      <li><?= h(cenik_t('Otevřeno Po–Pá 9–20 h, víkendy po domluvě. Změny a storno prosím telefonicky.', $jz)) ?></li>
    </ul>
    <div class="plati"><?= h(cenik_t('Platí od', $jz)) ?> <?= h($datum) ?></div>
  </div>
</div></div>
<script>
// přizpůsobení na jednu stranu A4: písmo se zmenšuje, dokud se obsah nevejde (nejmíň na 72 %)
(function () {
  var l = document.getElementById('list'), o = document.getElementById('obsah'), s = document.getElementById('vejde');
  function fit() {
    var v = 9.4; l.style.fontSize = v + 'pt';
    while (o.scrollHeight > o.clientHeight + 1 && v > 6.8) { v -= 0.1; l.style.fontSize = v.toFixed(2) + 'pt'; }
    s.textContent = o.scrollHeight > o.clientHeight + 1 ? '⚠ Nevejde se na jednu stranu A4 ani zmenšené – vypněte popisy služeb.' : '✓ Jedna strana A4' + (v < 9.39 ? ' (písmo ' + Math.round(v / 9.4 * 100) + ' %)' : '') + '.';
  }
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit); else fit();
  window.addEventListener('beforeprint', fit);
})();
</script>
</body></html><?php
    exit;
}
