<?php
// Nastavení: masérky, ceník, prahy štítků, uživatelé, fronta e-mailů.

function stranka_nastaveni(array $u): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $akce = $_POST['akce'] ?? '';
        if ($akce === 'nastaveni') {
            foreach (['rezim_webu', 'oteviraci_od', 'oteviraci_do', 'prah_staly_navstev', 'prah_spici_dni', 'prah_nespolehlivy', 'bonus_po', 'bonus_pocita_poukaz'] as $k)
                if (isset($_POST[$k])) q('INSERT INTO nastaveni(klic,hodnota) VALUES(?,?) ON CONFLICT(klic) DO UPDATE SET hodnota=excluded.hodnota', [$k, trim($_POST[$k])]);
            flash('Nastavení uloženo.');
        } elseif ($akce === 'maserky') {
            foreach ($_POST['m'] ?? [] as $id => $m) {
                if ((int)$id > 0) q('UPDATE maserky SET jmeno=?, aktivni=?, poradi=?, barva=?, ucet=? WHERE id=?',
                    [trim($m['jmeno']), !empty($m['aktivni']) ? 1 : 0, (int)$m['poradi'], $m['barva'] ?: '#b07a3a', trim($m['ucet'] ?? ''), (int)$id]);
            }
            if (trim($_POST['nova_maserka'] ?? '') !== '')
                q('INSERT INTO maserky(jmeno,poradi) VALUES(?, (SELECT COALESCE(MAX(poradi),0)+1 FROM maserky))', [trim($_POST['nova_maserka'])]);
            flash('Masérky uloženy.');
        } elseif ($akce === 'cenik') {
            foreach ($_POST['v'] ?? [] as $id => $v)
                q('UPDATE varianty SET cena=?, minuty=?, aktivni=?, maserky=?, maserky_min=?, wellness_mistnost=? WHERE id=?', [(int)$v['cena'], $v['minuty'] !== '' ? (int)$v['minuty'] : null, !empty($v['aktivni']) ? 1 : 0,
                    max(0, min(2, (int)($v['maserky'] ?? 1))), ($v['maserky_min'] ?? '') !== '' ? (int)$v['maserky_min'] : null, !empty($v['wellness_mistnost']) ? 1 : 0, (int)$id]);
            flash('Ceník uložen.');
        } elseif ($akce === 'uzivatel' && $u['role'] === 'admin') {
            $login = trim($_POST['login'] ?? ''); $heslo = $_POST['heslo'] ?? '';
            if ($login !== '' && strlen($heslo) >= 8) {
                q('INSERT INTO uzivatele(login,jmeno,heslo_hash,role) VALUES(?,?,?,?) ON CONFLICT(login) DO UPDATE SET heslo_hash=excluded.heslo_hash, jmeno=excluded.jmeno, role=excluded.role',
                    [$login, trim($_POST['jmeno'] ?? '') ?: $login, password_hash($heslo, PASSWORD_DEFAULT), array_key_exists($_POST['role'] ?? '', ROLE) ? $_POST['role'] : 'recepce']);
                flash("Uživatel $login uložen.");
            } else flash('Login a heslo (aspoň 8 znaků).', 'err');
        } elseif ($akce === 'demo_nahrat' && $u['role'] === 'admin') {
            try { flash(demo_nahrat()); } catch (Throwable $e) { flash('Vzorová data se nenahrála: ' . $e->getMessage(), 'err'); }
        } elseif ($akce === 'demo_smazat' && $u['role'] === 'admin') {
            if (($_POST['potvrzeni'] ?? '') !== 'SMAZAT') flash('Pro smazání napište SMAZAT.', 'err');
            else { try { flash(demo_smazat()); } catch (Throwable $e) { flash('Smazání selhalo: ' . $e->getMessage(), 'err'); } }
        } elseif ($akce === 'odeslat_emaily') {
            odesli_frontu(); flash('Fronta e-mailů zpracována.');
        }
        presmeruj('/?r=nastaveni');
    }
    $n = q('SELECT klic,hodnota FROM nastaveni')->fetchAll(PDO::FETCH_KEY_PAIR);
    $maserky = q('SELECT * FROM maserky ORDER BY poradi')->fetchAll();
    $cenik = q('SELECT v.*, s.nazev FROM varianty v JOIN sluzby s ON s.id=v.sluzba_id ORDER BY s.poradi, v.poradi')->fetchAll();
    $uziv = q('SELECT id,login,jmeno,role,aktivni FROM uzivatele ORDER BY login')->fetchAll();
    $smtp = !empty(cfg()['smtp']['host']);

    hlavicka('Nastavení', 'nastaveni'); ?>
    <h1>Nastavení</h1>
    <?php aplikace_karta(); ?>
    <div class="cols">
      <div>
        <form method="post" class="card">
          <?= csrf_pole() ?><input type="hidden" name="akce" value="nastaveni">
          <h2>Web a provoz</h2>
          <label>Rezervační formulář na webu<select name="rezim_webu"><?= moznosti(['zajem' => 'Zájem – zákazník napíše, kdy by se hodilo, recepce domluví', 'terminy' => 'Volné termíny – zákazník vidí volné časy (zatím nepoužívat)'], $n['rezim_webu']) ?></select></label>
          <div class="grid3">
            <label>Otevřeno od<input type="time" name="oteviraci_od" value="<?= h($n['oteviraci_od']) ?>"></label>
            <label>do<input type="time" name="oteviraci_do" value="<?= h($n['oteviraci_do']) ?>"></label>
          </div>
          <h2>Štítky zákazníků</h2>
          <div class="grid3">
            <label>⭐ Stálý: návštěv za 6 měs.<input type="number" name="prah_staly_navstev" value="<?= h($n['prah_staly_navstev']) ?>"></label>
            <label>💤 Spící: dní bez návštěvy<input type="number" name="prah_spici_dni" value="<?= h($n['prah_spici_dni']) ?>"></label>
            <label>⚠️ Nespolehlivý: nepřišel ×<input type="number" name="prah_nespolehlivy" value="<?= h($n['prah_nespolehlivy']) ?>"></label>
          </div>
          <h2>Bonus</h2>
          <div class="grid3">
            <label>Zdarma po kolika zaplacených<input type="number" name="bonus_po" value="<?= h($n['bonus_po']) ?>"></label>
            <label>Masáž na poukaz se počítá<select name="bonus_pocita_poukaz"><?= moznosti(['0' => 'ne', '1' => 'ano'], $n['bonus_pocita_poukaz']) ?></select></label>
          </div>
          <button class="btn primary">Uložit</button>
        </form>

        <section class="card"><h2>Masérky a recepční</h2><p>Správa masérek (zaměření, výkonnost, spokojenost), recepčních a směn je v menu <a href="/?r=maserky">Tým</a> a <a href="/?r=smeny">Směny</a>.</p></section>

        <form method="post" class="card">
          <?= csrf_pole() ?><input type="hidden" name="akce" value="cenik">
          <details class="rozbal"><summary><h2>Ceník <span class="muted small">(<?= count($cenik) ?> položek – klepnutím rozbalíte; názvy a popisy se mění na webu v data.js)</span></h2></summary>
          <p class="muted small">Masérek: 0 = bez masérky (whirlpool, sauna), 2 = párová. „Masérky min“ = jak dlouho jsou masérky potřeba (balíček párová + whirlpool: 60, zbytek jsou zákazníci sami – klidně i po zavírací době s recepční). „Celá wellness masérna“ = zabere obě lůžka dvoulůžkové masérny.</p>
          <table class="tbl plain">
            <tr><th>Služba</th><th>Varianta</th><th>Minut</th><th>Kč</th><th title="Kolik masérek služba potřebuje">Masérek</th><th title="Jak dlouho od začátku jsou masérky potřeba – prázdné = celou dobu (párová + whirlpool: 60)">Masérky min</th><th title="Zabírá celou dvoulůžkovou / wellness masérnu">Celá wellness masérna</th><th>Aktivní</th></tr>
            <?php foreach ($cenik as $c): ?>
              <tr><td><?= h($c['nazev']) ?></td><td><?= h($c['popis']) ?></td>
                  <td><input type="number" name="v[<?= $c['id'] ?>][minuty]" value="<?= h($c['minuty'] ?? '') ?>" style="width:4.5em"></td>
                  <td><input type="number" name="v[<?= $c['id'] ?>][cena]" value="<?= $c['cena'] ?>" style="width:5.5em"></td>
                  <td><select name="v[<?= $c['id'] ?>][maserky]"><?= moznosti([0 => '0', 1 => '1', 2 => '2'], (int)$c['maserky']) ?></select></td>
                  <td><input type="number" name="v[<?= $c['id'] ?>][maserky_min]" value="<?= h($c['maserky_min'] ?? '') ?>" style="width:4.5em" placeholder="celou"></td>
                  <td><input type="checkbox" name="v[<?= $c['id'] ?>][wellness_mistnost]" value="1" <?= $c['wellness_mistnost'] ? 'checked' : '' ?>></td>
                  <td><input type="checkbox" name="v[<?= $c['id'] ?>][aktivni]" value="1" <?= $c['aktivni'] ? 'checked' : '' ?>></td></tr>
            <?php endforeach ?>
          </table>
          <button class="btn">Uložit ceník</button>
          </details>
        </form>
      </div>
      <div>
        <?php demo_karta($u) ?>
        <section class="card side">
          <h3>E-maily</h3>
          <p class="small"><?= $smtp ? 'SMTP nastaveno: ' . h(cfg()['smtp']['host']) : '<b class="warn">SMTP není nastaveno</b> – e-maily se jen ukládají do fronty.' ?><br>
          Upozornění chodí na <b><?= h(cfg()['email_salon']) ?></b> (config.php).</p>
          <form method="post" class="inline"><?= csrf_pole() ?><input type="hidden" name="akce" value="odeslat_emaily"><button class="btn">Odeslat frontu</button> <a class="btn" href="/?r=emaily">Zobrazit frontu</a></form>
        </section>
        <section class="card side">
          <h3>Uživatelé <a class="small" href="/?r=uzivatele">správa →</a></h3>
          <?php foreach ($uziv as $x): ?><p><a href="/?r=uzivatele&id=<?= (int)($x['id'] ?? 0) ?>"><?= h($x['jmeno']) ?></a> <span class="muted small">(<?= h($x['login']) ?>, <?= h($x['role']) ?>)</span></p><?php endforeach ?>
          <?php if ($u['role'] === 'admin'): ?>
          <form method="post"><?= csrf_pole() ?><input type="hidden" name="akce" value="uzivatel">
            <label>Login<input name="login" required></label>
            <label>Jméno<input name="jmeno"></label>
            <label>Heslo (nové / změna)<input name="heslo" type="password" minlength="8" required></label>
            <label>Role<select name="role"><?= moznosti(ROLE, 'recepce') ?></select></label>
            <button class="btn">Uložit uživatele</button>
          </form>
          <?php endif ?>
        </section>
      </div>
    </div>
    <?php paticka();
}

function stranka_emaily(array $u): void
{
    $rows = q('SELECT * FROM emaily ORDER BY id DESC LIMIT 100')->fetchAll();
    hlavicka('E-maily', 'nastaveni'); ?>
    <h1>Fronta e-mailů</h1>
    <table class="tbl">
      <tr><th>Kdy</th><th>Komu</th><th>Předmět</th><th>Stav</th></tr>
      <?php foreach ($rows as $e): ?>
        <tr><td class="small"><?= h(datum_cz($e['vytvoreno'])) ?></td><td><?= h($e['komu']) ?></td><td><?= h($e['predmet']) ?><details><summary class="muted small">text</summary><pre><?= h($e['text']) ?></pre></details></td>
            <td><?= h($e['stav']) ?><?= $e['chyba'] ? '<br><span class="warn small">' . h($e['chyba']) . '</span>' : '' ?></td></tr>
      <?php endforeach ?>
    </table>
    <?php paticka();
}

/** Sekce „Aplikace Asistent“: instalace do telefonu (Android/Chrome), QR kód pro otevření v telefonu. */
function aplikace_karta(): void
{
    $url = rtrim(cfg()['url'] ?? '', '/') . '/?r=ai&app=1'; ?>
    <section class="card aplikace">
      <h2>📲 Aplikace Asistent do telefonu</h2>
      <div class="aplikace-in">
        <img src="/asistent-192.png" alt="" width="72" height="72">
        <div class="grow">
          <p style="margin-top:0">Asistent jako samostatná aplikace s ikonou na ploše: ptáte se hlasem nebo psaním, asistent připraví rezervace, přesuny, potvrzení žádostí i volna masérek a vy je jen odklepnete „Provést“.</p>
          <p><button type="button" class="btn primary" id="appInstal" hidden>⬇ Nainstalovat aplikaci</button>
             <span id="appHotovo" class="tag zdarma" hidden>✅ Aplikace je nainstalovaná</span>
             <a class="btn" href="/?r=ai&amp;app=1">Otevřít aplikaci</a></p>
          <ol class="small" id="appNavod">
            <li>V telefonu s Androidem otevřete v <b>Chromu</b> odkaz níže (nebo naskenujte QR kód fotoaparátem).</li>
            <li>Přihlaste se a zaškrtněte <b>Zůstat přihlášen</b>.</li>
            <li>V Chromu klepněte na <b>⋮ → Nainstalovat aplikaci</b> (případně „Přidat na plochu“). Ikona 🤖 Asistent se objeví na ploše.</li>
            <li>Napoprvé u 🎤 povolte mikrofon.</li>
          </ol>
          <p class="small">Odkaz: <code><?= h($url) ?></code></p>
        </div>
        <div class="aplikace-qr" id="appQr" data-qr="<?= h($url) ?>" title="Naskenujte telefonem"></div>
      </div>
    </section>
    <script src="/qrcode.js"></script>
    <script>
    (function(){
      var e=document.getElementById('appQr');try{var q=qrcode(0,'M');q.addData(e.dataset.qr);q.make();e.innerHTML=q.createSvgTag({cellSize:4,margin:2,scalable:true})}catch(x){e.textContent=e.dataset.qr}
      if('serviceWorker' in navigator)navigator.serviceWorker.register('/sw-asistent.js').catch(function(){});
      var b=document.getElementById('appInstal'),ok=document.getElementById('appHotovo'),ev=null;
      if(matchMedia('(display-mode: standalone)').matches){ok.hidden=false}
      window.addEventListener('beforeinstallprompt',function(x){x.preventDefault();ev=x;b.hidden=false});
      window.addEventListener('appinstalled',function(){b.hidden=true;ok.hidden=false});
      b.addEventListener('click',function(){if(!ev)return;ev.prompt();ev.userChoice.then(function(){ev=null;b.hidden=true})});
    })();
    </script>
<?php
}
