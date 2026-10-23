<?php
// Vzorová data (VZOR) na odladění: zákazníci, historie, plný kalendář na 14 dní, poukazy, karty, hodnocení.
// Všechno, co se nahraje, se zapíše do tabulky `demo` a jedním tlačítkem v Nastavení se zase smaže.
// Vzoroví zákazníci mají zdroj = 'vzor', telefon +420 999 xxx xxx (neexistující rozsah) a žádný e-mail,
// takže jim systém nikdy nic nepošle.

function demo_tabulka(): void
{
    q('CREATE TABLE IF NOT EXISTS demo (tab TEXT NOT NULL, rid INTEGER NOT NULL, PRIMARY KEY(tab, rid))');
}

function demo_zapis(string $tab, int $id): void { q('INSERT OR IGNORE INTO demo(tab,rid) VALUES(?,?)', [$tab, $id]); }

function demo_pocet(): int
{
    static $n = null;
    if ($n !== null) return $n;
    demo_tabulka();
    return $n = (int)qv("SELECT COUNT(*) FROM zakaznici WHERE zdroj='vzor'");
}

/** Nahraje vzorová data. Vrací text pro recepci. */
function demo_nahrat(): string
{
    demo_tabulka();
    if (demo_pocet() > 0) throw new RuntimeException('Vzorová data už v systému jsou. Nejdřív je smažte.');
    mt_srand(20261023); // pokaždé stejná data

    $maserky = q('SELECT id, jmeno FROM maserky WHERE aktivni=1 ORDER BY poradi, id')->fetchAll();
    if (!$maserky) throw new RuntimeException('Nejsou žádné aktivní masérky (Tým).');
    $var = q("SELECT v.id, v.popis, v.minuty, v.cena, s.kod, s.kat FROM varianty v JOIN sluzby s ON s.id=v.sluzba_id
              WHERE v.aktivni=1 AND s.aktivni=1 AND v.minuty IS NOT NULL")->fetchAll();
    $vKod = [];
    foreach ($var as $v) $vKod[$v['kod']][] = $v;
    // jak často se co prodává (váhy) – thajská a olejová nejvíc
    $vahy = ['tradicni' => 30, 'olej' => 18, 'hlava' => 10, 'zada' => 12, 'aroma' => 6, 'nohy' => 6, 'bylinna' => 4,
             'siam' => 3, 'oblicej' => 2, 'lava' => 2, 'tehotenska' => 1, 'ventosa' => 2, 'lymfa-telo' => 1, 'lymfa-oblicej' => 1, 'poporodni' => 1];
    $vahy = array_filter($vahy, fn($k) => isset($vKod[$k]), ARRAY_FILTER_USE_KEY);
    $wellness = array_merge($vKod['whirlpool'] ?? [], $vKod['fiji'] ?? [], $vKod['cukr'] ?? []);
    $nahodna = function () use ($vahy, $vKod) {
        $r = mt_rand(1, array_sum($vahy));
        foreach ($vahy as $k => $w) { if (($r -= $w) <= 0) { $vv = $vKod[$k]; return $vv[mt_rand(0, count($vv) - 1)]; } }
        return $vKod['tradicni'][0];
    };

    db()->beginTransaction();
    try {
        // ---------- masérny (jen pokud žádné nejsou – zůstanou i po smazání vzoru, kvůli tabletům) ----------
        $noveMistnosti = 0;
        if (!qv('SELECT COUNT(*) FROM mistnosti')) {
            for ($i = 1; $i <= 3; $i++) {
                q('INSERT INTO mistnosti(nazev,token,poradi) VALUES(?,?,?)', ["Masérna $i", bin2hex(random_bytes(16)), $i]);
                $noveMistnosti++;
            }
        }

        // ---------- zákazníci ----------
        $jmenaZ = ['Jana', 'Petra', 'Lucie', 'Kateřina', 'Eva', 'Hana', 'Martina', 'Veronika', 'Tereza', 'Lenka', 'Michaela', 'Zuzana', 'Alena', 'Monika', 'Barbora', 'Ivana', 'Simona', 'Markéta', 'Dana', 'Klára'];
        $prijZ = ['Nováková', 'Svobodová', 'Dvořáková', 'Černá', 'Procházková', 'Kučerová', 'Veselá', 'Horáková', 'Němcová', 'Marková', 'Pokorná', 'Pospíšilová', 'Hájková', 'Králová', 'Jelínková', 'Růžičková', 'Benešová', 'Fialová', 'Sedláčková', 'Doležalová'];
        $jmenaM = ['Petr', 'Jan', 'Tomáš', 'Martin', 'Jiří', 'Pavel', 'Lukáš', 'Jakub', 'Michal', 'David', 'Ondřej', 'Josef', 'Karel', 'Roman', 'Filip', 'Radek', 'Marek', 'Vojtěch', 'Aleš', 'Zdeněk'];
        $prijM = ['Novák', 'Svoboda', 'Dvořák', 'Černý', 'Procházka', 'Kučera', 'Veselý', 'Horák', 'Němec', 'Marek', 'Pokorný', 'Pospíšil', 'Hájek', 'Král', 'Jelínek', 'Růžička', 'Beneš', 'Fiala', 'Sedláček', 'Doležal'];
        $prefs = [
            ['silne', 'Víc šíje a ramena, na konci protažení.', 'More neck and shoulders, stretching at the end.'],
            ['stredne', 'Chce šlapání po zádech na konci.', 'Likes walking on the back at the end.'],
            ['jemne', 'Citlivá na tlak, hlavně jemně kolem beder.', 'Sensitive to pressure, gently around the lower back.'],
            ['silne', 'Zaměřit se na nohy – hodně běhá.', 'Focus on legs – runs a lot.'],
            ['stredne', 'Bez masáže hlavy, nemá ráda olej ve vlasech.', 'No head massage, does not want oil in hair.'],
            ['', '', ''], ['', '', ''], ['', '', ''],
        ];
        $zdravi = [
            ['Vysoký krevní tlak – opatrně, žádné horké kameny.', 'High blood pressure – careful, no hot stones.'],
            ['Po operaci kolene (pravé) – koleno vynechat.', 'Right knee surgery – skip the knee.'],
            ['Výhřez ploténky L5 – žádné silné protahování zad.', 'Herniated disc L5 – no strong back stretching.'],
            ['Alergie na levandulový olej.', 'Allergic to lavender oil.'],
        ];
        $zak = [];
        $stredniCena = 0;
        for ($i = 0; $i < 120; $i++) {
            $zena = $i % 3 !== 2;
            $jm = $zena ? $jmenaZ[($i + intdiv($i, 20)) % 20] : $jmenaM[($i + intdiv($i, 20)) % 20];
            $pr = $zena ? $prijZ[($i * 7) % 20] : $prijM[($i * 7) % 20];
            $tel = '+420999' . str_pad((string)(100000 + $i * 137), 6, '0', STR_PAD_LEFT);
            $p = $prefs[$i % count($prefs)];
            $zd = $i % 9 === 4 ? $zdravi[($i / 9) % count($zdravi)] : null;
            $enPref = $i % 4 === 0 ? '' : $p[2];       // u některých chybí angličtina – ať jde vyzkoušet upozornění
            $mas = $i % 5 === 0 ? $maserky[$i % count($maserky)]['id'] : null;
            q("INSERT INTO zakaznici(telefon,jmeno,prijmeni,email,poznamka_interni,pref_tlak,pref_maserka_id,pref_text,pref_text_en,
                 zdravi_text,zdravi_text_en,zdravi_souhlas,volny_termin,volny_kdy,pocatecni_razitka,gdpr_souhlas,zdroj)
               VALUES(?,?,?,'',?,?,?,?,?,?,?,?,?,?,?,?, 'vzor')",
                [$tel, $jm, $pr, $i % 10 === 7 ? 'Chodí vždy o 5 minut dřív, ráda si popovídá.' : ($i % 17 === 5 ? 'Platí vždy kartou.' : ''),
                 $p[0], $mas, $p[1], $enPref, $zd[0] ?? '', $zd ? ($i % 2 ? $zd[1] : '') : '', $zd ? nyni() : null,
                 $i % 6 === 1 ? 1 : 0, $i % 6 === 1 ? ($i % 12 === 1 ? 'všední dny dopoledne' : 'kdykoli, stačí zavolat hodinu předem') : '',
                 $i % 8 === 3 ? 2 : 0, nyni()]);
            $id = (int)db()->lastInsertId();
            demo_zapis('zakaznici', $id);
            // typ zákazníka: stálý (chodí často), běžný, nový, nespolehlivý, spící
            $typ = $i < 25 ? 'staly' : ($i < 80 ? 'bezny' : ($i < 100 ? 'novy' : ($i < 110 ? 'nesp' : 'spici')));
            $zak[] = ['id' => $id, 'typ' => $typ, 'mas' => $mas];
        }

        // ---------- rozvrh: vložení rezervace bez kolize u masérky ----------
        $obsazeno = []; // [maserka][datum] => [[od,do],...] v minutách
        $zakDen = [];   // zákazník max. jednou za den
        $vloz = function (int $zid, array $v, int $mid, string $den, int $odMin, string $stav, string $platba, string $zdroj = 'recepce') use (&$obsazeno, &$zakDen) {
            if (isset($zakDen[$zid][$den])) return null;
            $do = $odMin + (int)$v['minuty'];
            foreach ($obsazeno[$mid][$den] ?? [] as [$a, $b]) if ($odMin < $b && $do > $a) return null;
            $obsazeno[$mid][$den][] = [$odMin, $do];
            $zakDen[$zid][$den] = true;
            $zac = sprintf('%s %02d:%02d:00', $den, intdiv($odMin, 60), $odMin % 60);
            $kon = sprintf('%s %02d:%02d:00', $den, intdiv($do, 60), $do % 60);
            q("INSERT INTO rezervace(zakaznik_id,varianta_id,maserka_id,zacatek,konec,stav,zdroj,platba,cena,zmenil,vytvoreno)
               VALUES(?,?,?,?,?,?,?,?,?,'VZOR',?)",
                [$zid, $v['id'], $mid, $zac, $kon, $stav, $zdroj, $platba, $platba === 'bonus' ? 0 : $v['cena'],
                 date('Y-m-d H:i:s', strtotime($zac . ' -' . mt_rand(1, 10) . ' days'))]);
            $rid = (int)db()->lastInsertId();
            demo_zapis('rezervace', $rid);
            return $rid;
        };
        $od = (int)substr(nastaveni('oteviraci_od', '09:00'), 0, 2) * 60;
        $doOt = (int)substr(nastaveni('oteviraci_do', '20:00'), 0, 2) * 60;
        $dnes = date('Y-m-d');

        // ---------- historie: posledních ~120 dní ----------
        $pocetHist = ['staly' => [8, 12], 'bezny' => [2, 5], 'novy' => [0, 1], 'nesp' => [2, 4], 'spici' => [6, 8]];
        $probehle = [];
        foreach ($zak as $z) {
            [$a, $b] = $pocetHist[$z['typ']];
            $n = mt_rand($a, $b);
            for ($k = 0; $k < $n; $k++) {
                $dniZpet = $z['typ'] === 'spici' ? mt_rand(100, 200) : ($z['typ'] === 'staly' ? mt_rand(1, 170) : mt_rand(1, 120));
                $den = date('Y-m-d', strtotime("-$dniZpet days"));
                if ((int)date('N', strtotime($den)) >= 6) continue;
                $v = $nahodna();
                $mid = $z['mas'] ?: $maserky[mt_rand(0, count($maserky) - 1)]['id'];
                $zac = mt_rand(intdiv($od, 30), intdiv($doOt - (int)$v['minuty'], 30)) * 30;
                $stav = 'probehlo';
                if ($z['typ'] === 'nesp' && $k < 2) $stav = $k === 0 ? 'neprisel' : 'zruseno_pozde';
                elseif (mt_rand(1, 30) === 1) $stav = 'zruseno_vcas';
                $platba = $stav === 'probehlo' ? (mt_rand(1, 10) <= 6 ? 'hotove' : 'karta') : '';
                $rid = $vloz($z['id'], $v, (int)$mid, $den, $zac, $stav, $platba);
                if ($rid && $stav === 'probehlo') $probehle[] = [$rid, (int)$mid, $den];
            }
        }

        // hodnocení masérek (jako z tabletu) k části proběhlých masáží
        $komentare = ['', '', '', 'Skvělá masáž, děkuji!', 'Příště trochu silněji.', 'Jako vždy výborné.', 'Moc příjemné.'];
        foreach ($probehle as $x => [$rid, $mid, $den]) {
            if ($x % 3) continue;
            $zn = [5, 5, 5, 4, 4, 5, 3][mt_rand(0, 6)];
            q("INSERT INTO hodnoceni_maserek(maserka_id,rezervace_id,znamka,komentar,zdroj,kdo,cas) VALUES(?,?,?,?, 'tablet', 'VZOR', ?)",
                [$mid, $rid, $zn, $komentare[mt_rand(0, count($komentare) - 1)], $den . ' 18:00:00']);
            demo_zapis('hodnoceni_maserek', (int)db()->lastInsertId());
        }

        // ---------- plán: dnes + 13 dní, kalendář skoro plný ----------
        $budouci = 0;
        for ($d = 0; $d < 14; $d++) {
            $den = date('Y-m-d', strtotime("+$d days"));
            $vikend = (int)date('N', strtotime($den)) >= 6;
            foreach ($maserky as $mi => $m) {
                if ($vikend) {
                    if (!$wellness || $mi > 1) continue;   // o víkendu jen wellness, 2 masérky
                    for ($t = 10 * 60; $t + 90 <= 18 * 60; $t += mt_rand(0, 1) ? 120 : 150) {
                        if (mt_rand(1, 10) > 6) continue;
                        for ($pokus = 0; $pokus < 8; $pokus++) {
                            $z = $zak[mt_rand(0, count($zak) - 1)];
                            if ($vloz($z['id'], $wellness[mt_rand(0, count($wellness) - 1)], (int)$m['id'], $den, $t, 'potvrzeno', '')) { $budouci++; break; }
                        }
                    }
                    continue;
                }
                $t = $od + (mt_rand(0, 2) * 30);
                while ($t < $doOt - 30) {
                    $v = $nahodna();
                    if ($t + (int)$v['minuty'] > $doOt) { $t += 30; continue; }
                    if (mt_rand(1, 100) <= 12 + $d * 2) { $t += 30; continue; } // volné okno (dál v budoucnu víc volna)
                    // stálí zákazníci a ti s oblíbenou masérkou chodí častěji
                    $kand = array_values(array_filter($zak, fn($z) => $z['typ'] !== 'spici' && (!$z['mas'] || (int)$z['mas'] === (int)$m['id'])));
                    $platba = mt_rand(1, 25) === 1 ? 'bonus' : '';
                    for ($pokus = 0; $pokus < 8; $pokus++) {
                        $z = $kand[mt_rand(0, count($kand) - 1)];
                        if ($vloz($z['id'], $v, (int)$m['id'], $den, $t, 'potvrzeno', $platba)) { $budouci++; break; }
                    }
                    $t += (int)$v['minuty'] + (mt_rand(1, 3) === 1 ? 30 : 0); // pauza na přípravu
                }
            }
        }

        // ---------- žádosti z webu (čekají na recepci) ----------
        $okna = ['zítra odpoledne', 'pátek dopoledne', 'kdykoli tento týden', 'příští úterý večer'];
        $varTrad = $vKod['tradicni'][0] ?? $var[0];
        foreach ([85, 88, 92, 96] as $x => $ix) {
            q("INSERT INTO rezervace(zakaznik_id,varianta_id,stav,zdroj,preferovano,poznamka_zakaznik,cena,zmenil)
               VALUES(?,?, 'zadost', 'web', ?, ?, ?, 'VZOR')",
                [$zak[$ix]['id'], $varTrad['id'], $okna[$x], $x === 1 ? 'Poprvé, chtěla bych spíš jemněji.' : '', $varTrad['cena']]);
            demo_zapis('rezervace', (int)db()->lastInsertId());
        }

        // ---------- poukazy ----------
        $pouk = [
            ['hodnota' => 1000, 'stav' => 'zaplaceno', 'z' => 2, 'pro' => 'Mamince', 'od' => 'Petra', 'ven' => 'Všechno nejlepší k narozeninám!'],
            ['hodnota' => 1600, 'stav' => 'vydano', 'z' => 7, 'pro' => 'Honzovi', 'od' => 'Jana', 'ven' => 'Ať se ti uleví od zad.', 'sl' => 'Tradiční thajská masáž – 120 min'],
            ['hodnota' => 790, 'stav' => 'objednano', 'z' => null, 'pro' => 'Kláře', 'od' => 'kolegové', 'ven' => 'Díky za všechno!'],
            ['hodnota' => 2000, 'stav' => 'vydano', 'z' => 12, 'pro' => '', 'od' => '', 'ven' => '', 'zust' => 900],
        ];
        foreach ($pouk as $p) {
            $zid = $p['z'] !== null ? $zak[$p['z']]['id'] : null;
            $zz = $zid ? q1('SELECT * FROM zakaznici WHERE id=?', [$zid]) : null;
            q("INSERT INTO poukazy(kod,hodnota,zustatek,sluzba_text,platnost_do,stav,objednal_jmeno,objednal_tel,pro_koho,od_koho,venovani,prevzeti,platba,poznamka,zakaznik_id,zdroj)
               VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'vzor')",
                [poukaz_kod(), $p['hodnota'], $p['zust'] ?? $p['hodnota'], $p['sl'] ?? '', date('Y-m-d', strtotime('+6 months')), $p['stav'],
                 $zz ? trim($zz['jmeno'] . ' ' . $zz['prijmeni']) : 'Pavel Testovací', $zz['telefon'] ?? '+420999999001',
                 $p['pro'], $p['od'], $p['ven'], 'salon', $p['stav'] === 'objednano' ? '' : 'hotove', 'VZOR', $zid]);
            demo_zapis('poukazy', (int)db()->lastInsertId());
        }

        // ---------- věrnostní karty (QR) pro stálé zákazníky ----------
        $karty = [];
        for ($i = 0; $i < 6; $i++) {
            do { $k = ''; for ($j = 0; $j < 10; $j++) $k .= KARTA_ZNAKY[random_int(0, strlen(KARTA_ZNAKY) - 1)]; }
            while (qv('SELECT 1 FROM karty WHERE kod=?', [$k]));
            q("INSERT INTO karty(kod,zakaznik_id,prirazeno,kdo) VALUES(?,?,?, 'VZOR')", [$k, $zak[$i]['id'], nyni()]);
            demo_zapis('karty', (int)db()->lastInsertId());
            $karty[] = $k;
        }

        q("INSERT INTO log(kdo,akce,detail) VALUES(?, 'vzor_nahrano', ?)", [kdo(), "zákazníků 120, plán $budouci"]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    return "Vzorová data nahrána: 120 zákazníků, " . count($probehle) . " proběhlých masáží v historii, $budouci rezervací na 14 dní, 4 žádosti z webu, 4 poukazy, 6 věrnostních karet."
        . ($noveMistnosti ? " Založeny $noveMistnosti masérny pro tablety (po smazání vzoru zůstanou)." : '');
}

/** Smaže všechna vzorová data – i to, co recepce během testování u vzorových zákazníků vytvořila. */
function demo_smazat(): string
{
    demo_tabulka();
    db()->beginTransaction();
    try {
        $zak = 'SELECT id FROM zakaznici WHERE zdroj=\'vzor\'';
        $rez = "SELECT id FROM rezervace WHERE zakaznik_id IN ($zak) OR id IN (SELECT rid FROM demo WHERE tab='rezervace')";
        $pou = "SELECT id FROM poukazy WHERE zdroj='vzor' OR zakaznik_id IN ($zak) OR id IN (SELECT rid FROM demo WHERE tab='poukazy')";
        $nZak = (int)qv("SELECT COUNT(*) FROM ($zak)");
        $nRez = (int)qv("SELECT COUNT(*) FROM ($rez)");
        q("UPDATE sezeni SET hodnoceni_id=NULL WHERE rezervace_id IN ($rez)");
        q("DELETE FROM hodnoceni_maserek WHERE rezervace_id IN ($rez) OR id IN (SELECT rid FROM demo WHERE tab='hodnoceni_maserek')");
        q("DELETE FROM sezeni WHERE rezervace_id IN ($rez)");
        q("DELETE FROM poukaz_pohyby WHERE rezervace_id IN ($rez) OR poukaz_id IN ($pou)");
        q("UPDATE rezervace SET poukaz_id=NULL WHERE poukaz_id IN ($pou)");
        q("DELETE FROM rezervace WHERE id IN ($rez)");
        q("DELETE FROM poukazy WHERE id IN ($pou)");
        q("DELETE FROM karty WHERE zakaznik_id IN ($zak) OR id IN (SELECT rid FROM demo WHERE tab='karty')");
        q("DELETE FROM zakaznici WHERE zdroj='vzor'");
        q('DELETE FROM demo');
        q("INSERT INTO log(kdo,akce,detail) VALUES(?, 'vzor_smazano', ?)", [kdo(), "zákazníků $nZak, rezervací $nRez"]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    return "Vzorová data smazána: $nZak zákazníků a $nRez rezervací (včetně všeho, co se u nich během testování vytvořilo).";
}

/** Karta do Nastavení. */
function demo_karta(array $u): void
{
    $n = demo_pocet(); ?>
    <section class="card side demo-card">
      <h3>🧪 Vzorová data (VZOR)</h3>
      <?php if ($n): ?>
        <p class="small">Ve systému je <b><?= $n ?> vzorových zákazníků</b> s historií a plným kalendářem na 14 dní. Jsou označení štítkem <span class="tag vzor">VZOR</span>, mají telefon 999… a žádný e-mail.</p>
        <?php if (($u['role'] ?? '') === 'admin'): ?>
        <form method="post"><?= csrf_pole() ?><input type="hidden" name="akce" value="demo_smazat">
          <label class="small">Pro potvrzení napište <b>SMAZAT</b><input name="potvrzeni" autocomplete="off" required pattern="SMAZAT"></label>
          <button class="btn danger">Smazat všechna vzorová data</button>
        </form>
        <p class="muted small">Smaže vzorové zákazníky, jejich rezervace, poukazy, karty, hodnocení i to, co jste u nich během zkoušení vytvořili. Skutečných zákazníků se nedotkne.</p>
        <?php else: ?><p class="muted small">Smazat je může jen admin.</p><?php endif ?>
      <?php elseif (($u['role'] ?? '') === 'admin'): ?>
        <p class="small">Na vyzkoušení: 120 zákazníků s historií a preferencemi, plný kalendář na 14 dní pro všechny aktivní masérky, žádosti z webu, poukazy a věrnostní karty. Před ostrým provozem se jedním tlačítkem smažou.</p>
        <form method="post"><?= csrf_pole() ?><input type="hidden" name="akce" value="demo_nahrat">
          <button class="btn">Nahrát vzorová data</button>
        </form>
      <?php endif ?>
    </section>
<?php }
