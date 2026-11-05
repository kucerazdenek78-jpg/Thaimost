<?php
// Správa → Reklamní materiály: hotové reklamy (obrázky, videa, texty) na jednom místě, ke stažení a zkopírování.
// Soubory leží v repu webu (assets/reklamy/, assets/video/) a načítají se z GitHub Pages, takže se nemusí nahrávat na NAS.

const REKLAMY_WEB = 'https://kucerazdenek78-jpg.github.io/Thaimost/';

function reklamy_seznam(): array
{
    $w = REKLAMY_WEB;
    return [
        [
            'nazev' => 'Reel – Dárkové poukazy (svíčky)', 'typ' => 'video', 'format' => '9:16 · 1080×1920 · 12 s',
            'kde' => 'Facebook a Instagram Reels, Stories', 'pridano' => '5. 11. 2026',
            'soubor' => $w . 'assets/reklamy/poukaz-reel-9x16.mp4', 'nahled' => $w . 'assets/reklamy/poukaz-reel-9x16.jpg',
            'pozn' => 'Video je bez hudby. Hudbu přidejte při zveřejnění z knihovny Facebooku nebo Instagramu (tam má licenci).',
            'texty' => ['Hlavní text' => reklamy_text_poukaz(), 'Nadpis' => 'Dárkový poukaz na libovolnou částku',
                        'Popis' => 'Thajské masáže Most · od roku 2004', 'Tlačítko' => 'Zavolat (778 429 329)'],
        ],
        [
            'nazev' => 'Příspěvek – Dárkové poukazy', 'typ' => 'obrazek', 'format' => '4:5 · 1080×1350',
            'kde' => 'Facebook a Instagram feed, Meta reklama', 'pridano' => '7. 10. 2026',
            'soubor' => $w . 'assets/reklamy/darkove-poukazy-1080x1350.jpg',
            'texty' => ['Hlavní text' => reklamy_text_poukaz(), 'Nadpis' => 'Dárkový poukaz na libovolnou částku',
                        'Popis' => 'Thajské masáže Most · od roku 2004', 'Tlačítko' => 'Zavolat (778 429 329)'],
        ],
        [
            'nazev' => 'Příspěvek – Bonus program', 'typ' => 'obrazek', 'format' => '4:5 · 1080×1350',
            'kde' => 'Facebook a Instagram feed, Meta reklama', 'pridano' => '7. 10. 2026',
            'soubor' => $w . 'assets/reklamy/bonus-program-1080x1350.jpg',
            'pozn' => 'Cena „od 790 Kč“ odpovídá ceníku na webu k 5. 11. 2026. Při změně ceníku obrázek upravit.',
        ],
        [
            'nazev' => 'Canva – Dárkový poukaz (foto se svíčkami)', 'typ' => 'odkaz', 'format' => '9:16 · Stories',
            'kde' => 'Stories, úprava a stažení v Canvě', 'pridano' => '7. 10. 2026',
            'odkaz' => 'https://canva.link/6p3xdez40g7ow5k', 'odkaz_text' => 'Otevřít v Canvě',
            'pozn' => 'Návrh je v Canvě (účet Zdeněk). Stáhnout jako PNG: Sdílet → Stáhnout.',
        ],
        [
            'nazev' => 'Úvodní video webu', 'typ' => 'video', 'format' => '16:9 · 15 s',
            'kde' => 'Web (úvod), Facebook příspěvek', 'pridano' => '3. 11. 2026',
            'soubor' => $w . 'assets/video/thaimost-uvod.mp4', 'nahled' => $w . 'assets/video/thaimost-uvod-poster.jpg',
        ],
        [
            'nazev' => 'Reel – Tematická masérna (4 atmosféry)', 'typ' => 'video', 'format' => '720p · s titulky',
            'kde' => 'Web (sekce Atmosféry), Facebook, Instagram', 'pridano' => '4. 11. 2026',
            'soubor' => $w . 'assets/video/tematicka-maserna.mp4', 'nahled' => $w . 'assets/video/tematicka-maserna-poster.jpg',
            'pozn' => 'Delší reel pro recepci (84 s) a verze 9:16 jsou zatím jen na notebooku ve složce Videos\\ThaiMost-maserny.',
        ],
    ];
}

function reklamy_text_poukaz(): string
{
    return 'Hledáte dárek, který opravdu potěší? Darujte dárkový poukaz do Thai Most, a to na libovolnou částku. '
         . 'Tradiční thajská masáž, aroma masáž, rituály nebo vířivka se saunou. '
         . 'Masírují rodilé Thajky a Filipínky s certifikací chrámové školy Wat Pho. '
         . 'Poukaz objednáte osobně v salonu, poštou i e-mailem. Informace na tel. 778 429 329.';
}

function stranka_reklamy(array $u): void
{
    if (!smi_upravit_tym($u)) { http_response_code(403); exit('Reklamní materiály vidí jen admin nebo hlavní pracovník.'); }
    hlavicka('Reklamní materiály', 'nastaveni'); ?>
    <p><a href="/?r=nastaveni">← Správa</a></p>
    <h1>📣 Reklamní materiály</h1>
    <p class="muted" style="max-width:760px">Hotové reklamy na jednom místě. Obrázek nebo video stáhnete tlačítkem <b>Stáhnout</b>,
      texty zkopírujete tlačítkem <b>Kopírovat</b> a vložíte do reklamy na Facebooku, Instagramu nebo do příspěvku.</p>
    <div class="rk-grid">
    <?php foreach (reklamy_seznam() as $i => $r): ?>
      <section class="card rk">
        <div class="rk-media">
          <?php if ($r['typ'] === 'video'): ?>
            <video src="<?= h($r['soubor']) ?>" poster="<?= h($r['nahled'] ?? '') ?>" controls muted playsinline preload="none"></video>
          <?php elseif ($r['typ'] === 'obrazek'): ?>
            <a href="<?= h($r['soubor']) ?>" target="_blank" rel="noopener"><img src="<?= h($r['soubor']) ?>" alt="<?= h($r['nazev']) ?>" loading="lazy"></a>
          <?php else: ?>
            <a class="rk-odkaz" href="<?= h($r['odkaz']) ?>" target="_blank" rel="noopener">🎨<span><?= h($r['odkaz_text']) ?></span></a>
          <?php endif ?>
        </div>
        <h2><?= h($r['nazev']) ?></h2>
        <p class="small muted" style="margin:.2rem 0 .6rem"><?= h($r['format']) ?> · <?= h($r['kde']) ?> · přidáno <?= h($r['pridano']) ?></p>
        <?php if (!empty($r['pozn'])): ?><p class="small" style="margin:.2rem 0 .6rem"><?= h($r['pozn']) ?></p><?php endif ?>
        <p>
          <?php if (!empty($r['soubor'])): ?><a class="btn primary" href="<?= h($r['soubor']) ?>" download target="_blank" rel="noopener">⬇ Stáhnout</a><?php endif ?>
          <?php if (!empty($r['odkaz'])): ?><a class="btn primary" href="<?= h($r['odkaz']) ?>" target="_blank" rel="noopener"><?= h($r['odkaz_text']) ?></a><?php endif ?>
        </p>
        <?php if (!empty($r['texty'])): ?>
          <details class="rozbal"><summary><b>Texty do reklamy</b></summary>
            <?php foreach ($r['texty'] as $k => $t): ?>
              <div class="rk-text"><div class="small muted"><?= h($k) ?></div><div class="rk-t"><?= h($t) ?></div>
                <button type="button" class="btn rk-kop" data-t="<?= h($t) ?>">Kopírovat</button></div>
            <?php endforeach ?>
          </details>
        <?php endif ?>
      </section>
    <?php endforeach ?>
    </div>
    <style>
      .rk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px}
      .rk h2{margin:.7rem 0 0;font-size:1.1rem}
      .rk-media{background:#1c130d;border-radius:10px;overflow:hidden;display:flex;justify-content:center;align-items:center;height:340px}
      .rk-media video,.rk-media img{max-width:100%;max-height:340px;display:block}
      .rk-odkaz{display:flex;flex-direction:column;align-items:center;gap:10px;color:#e8dccb;font-size:3rem;text-decoration:none}
      .rk-odkaz span{font-size:1rem}
      .rk-text{border-top:1px solid rgba(0,0,0,.08);padding:.6rem 0}
      .rk-t{margin:.2rem 0 .4rem;white-space:pre-line}
    </style>
    <script>
    document.querySelectorAll('.rk-kop').forEach(function(b){b.addEventListener('click',function(){
      var t=b.getAttribute('data-t');
      (navigator.clipboard?navigator.clipboard.writeText(t):Promise.reject()).then(function(){b.textContent='Zkopírováno ✓';setTimeout(function(){b.textContent='Kopírovat'},1800)},
        function(){var a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();document.execCommand('copy');a.remove();b.textContent='Zkopírováno ✓';});
    });});
    </script>
    <?php paticka();
}
