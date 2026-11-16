<?php
// Zpětná vazba zákazníka: po proběhlé masáži dostane e-mail s odkazem na hodnocení
// spokojenosti (1–5 hvězd + komentář). Výsledek se uloží k zákazníkovi do kartotéky.
// Veřejná stránka (bez přihlášení) se pozná podle tajného tokenu: /?r=hodnoceni&t=<token>

/** Absolutní veřejná adresa hodnoticí stránky pro daný token. */
function hodnoceni_url(string $token): string
{
    return rtrim(cfg()['url'] ?? '', '/') . '/?r=hodnoceni&t=' . $token;
}

/** Text e-mailu s žádostí o hodnocení. */
function hodnoceni_email_text(array $z, string $token): string
{
    $jm = trim((string)($z['jmeno'] ?? ''));
    $osl = $jm !== '' ? 'Dobrý den, ' . $jm . ',' : 'Dobrý den,';
    $url = hodnoceni_url($token);
    return $osl . "\n\n"
        . "děkujeme, že jste si u nás dopřál(a) masáž. Budeme moc rádi, když nám dáte vědět, jak jste byl(a) spokojen(a) – zabere to pár vteřin a moc nám to pomůže se zlepšovat.\n\n"
        . "Ohodnotit návštěvu můžete tady:\n" . $url . "\n\n"
        . "Těšíme se na vás příště.\n\nThai Most – thajské masáže Most";
}

/**
 * Pošle žádost o hodnocení pro jednu proběhlou rezervaci (jen když má zákazník e-mail
 * a hodnocení pro tuto rezervaci ještě nevzniklo). Vrací true, když e-mail odešel.
 */
function hodnoceni_posli_pro_rezervaci(int $rezervaceId): bool
{
    $r = q1("SELECT * FROM rezervace WHERE id=?", [$rezervaceId]);
    if (!$r || $r['stav'] !== 'probehlo' || empty($r['zakaznik_id'])) return false;
    if (q1("SELECT 1 FROM hodnoceni_zakaznik WHERE rezervace_id=?", [$rezervaceId])) return false;
    $z = q1("SELECT * FROM zakaznici WHERE id=?", [$r['zakaznik_id']]);
    if (!$z) return false;
    $email = trim((string)($z['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    $token = bin2hex(random_bytes(16));
    q("INSERT INTO hodnoceni_zakaznik(zakaznik_id,rezervace_id,token,email_odeslan) VALUES(?,?,?,?)",
        [$z['id'], $rezervaceId, $token, nyni()]);
    $id = posli_email($email, 'Jak jste byli spokojeni s masáží? · Thai Most', hodnoceni_email_text($z, $token));
    if (!$id) return false;
    zapis_log('hodnoceni_dotaz', "zákazník #{$z['id']} rezervace #$rezervaceId");
    return true;
}

/**
 * Dávka: najde proběhlé masáže, které už skončily (a nejsou úplně čerstvé), mají u zákazníka
 * e-mail a ještě nedostaly dotaz na hodnocení, a rozešle je. Spouští se opportunně z veřejného
 * indexu, max jednou za 30 minut. Řídí se nastavením hodnoceni_zapnuto (výchozí zapnuto).
 */
function hodnoceni_davka(): void
{
    if ((int)nastaveni('hodnoceni_zapnuto', 1) !== 1) return;
    // gate: nespouštět častěji než jednou za 30 minut
    $posl = nastaveni('hodnoceni_posledni_beh', '');
    if ($posl !== '' && strtotime($posl) > time() - 1800) return;
    q("INSERT INTO nastaveni(klic,hodnota) VALUES('hodnoceni_posledni_beh',?)
       ON CONFLICT(klic) DO UPDATE SET hodnota=excluded.hodnota", [nyni()]);

    // masáže, které skončily před 2 hodinami až 3 dny, mají e-mail a ještě bez hodnocení
    $odKdy = date('Y-m-d H:i:s', time() - 3 * 86400);
    $doKdy = date('Y-m-d H:i:s', time() - 2 * 3600);
    try {
        $rows = q("SELECT r.id FROM rezervace r JOIN zakaznici z ON z.id=r.zakaznik_id
            WHERE r.stav='probehlo' AND r.konec>=? AND r.konec<=?
              AND TRIM(COALESCE(z.email,''))<>''
              AND NOT EXISTS (SELECT 1 FROM hodnoceni_zakaznik h WHERE h.rezervace_id=r.id)
            ORDER BY r.konec LIMIT 20", [$odKdy, $doKdy])->fetchAll();
    } catch (Throwable $e) { return; }
    foreach ($rows as $r) {
        try { hodnoceni_posli_pro_rezervaci((int)$r['id']); } catch (Throwable $e) { /* pokračuj */ }
    }
}

/** Souhrn hodnocení zákazníka pro kartu zákazníka. */
function hodnoceni_zakaznika(int $zakaznikId): array
{
    try {
        return q("SELECT h.*, s.nazev AS sluzba FROM hodnoceni_zakaznik h
            LEFT JOIN rezervace r ON r.id=h.rezervace_id
            LEFT JOIN varianty v ON v.id=r.varianta_id
            LEFT JOIN sluzby s ON s.id=v.sluzba_id
            WHERE h.zakaznik_id=? AND h.hvezdy IS NOT NULL
            ORDER BY h.hodnoceno DESC", [$zakaznikId])->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Veřejná hodnoticí stránka (bez přihlášení). */
function stranka_hodnoceni(): void
{
    header('Cache-Control: no-store');
    $token = preg_replace('~[^a-f0-9]~', '', (string)($_GET['t'] ?? ''));
    if (!limit_ip('hodnoceni', 120)) { http_response_code(429); exit('Příliš mnoho dotazů, zkuste to později.'); }
    $h = $token !== '' ? q1("SELECT * FROM hodnoceni_zakaznik WHERE token=?", [$token]) : null;
    if (!$h) { http_response_code(404); exit('Odkaz nenalezen nebo vypršel.'); }
    $z = q1("SELECT * FROM zakaznici WHERE id=?", [$h['zakaznik_id']]);

    $hotovo = false;
    $jiz = $h['hvezdy'] !== null;
    if (!$jiz && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $hv = (int)($_POST['hvezdy'] ?? 0);
        $kom = trim((string)($_POST['komentar'] ?? ''));
        if ($hv >= 1 && $hv <= 5) {
            q("UPDATE hodnoceni_zakaznik SET hvezdy=?, komentar=?, hodnoceno=? WHERE id=? AND hvezdy IS NULL",
                [$hv, mb_substr($kom, 0, 2000), nyni(), $h['id']]);
            zapis_log('hodnoceni_prijato', "zákazník #{$h['zakaznik_id']} ({$hv}★)");
            $hotovo = true; $jiz = true;
            $h = q1("SELECT * FROM hodnoceni_zakaznik WHERE id=?", [$h['id']]);
        }
    }

    $jm = $z ? trim((string)($z['jmeno'] ?? '')) : '';
    ?><!doctype html>
<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>Hodnocení návštěvy · Thai Most</title>
<style>
body{margin:0;font:17px/1.5 -apple-system,"Segoe UI",Roboto,sans-serif;background:#f6f1ea;color:#2b2520}
.w{max-width:460px;margin:0 auto;padding:22px}
.c{background:#fff;border-radius:16px;padding:26px;box-shadow:0 2px 12px rgba(80,50,20,.12)}
h1{font-size:1.4rem;margin:0 0 6px}
.m{color:#6b5d4f}
.stars{display:flex;flex-direction:row-reverse;justify-content:center;gap:6px;margin:22px 0}
.stars input{display:none}
.stars label{font-size:44px;color:#d9cab4;cursor:pointer;line-height:1;transition:color .1s}
.stars label:hover,.stars label:hover ~ label,.stars input:checked ~ label{color:#e0b64e}
textarea{width:100%;box-sizing:border-box;margin-top:8px;border:1px solid #e0d4c0;border-radius:12px;padding:12px;font:inherit;min-height:90px;resize:vertical}
button{width:100%;margin-top:16px;padding:14px;border:0;border-radius:12px;background:#2b1e16;color:#f8f2e9;font:inherit;font-weight:600;cursor:pointer}
.ok{background:#e7f3ec;color:#2e7d4f;padding:14px;border-radius:12px;text-align:center}
.big{font-size:40px;text-align:center;margin:6px 0}
.lotus{text-align:center;color:#c69c5d;font-size:28px;margin-bottom:4px}
</style></head><body><div class="w"><div class="c">
<div class="lotus">✿</div>
<?php if ($jiz): ?>
  <?php if ($hotovo): ?><div class="ok">Děkujeme za hodnocení!</div><?php endif ?>
  <h1 style="text-align:center;margin-top:14px">Děkujeme<?= $jm !== '' ? ', ' . h($jm) : '' ?>!</h1>
  <div class="big"><?php for ($i = 1; $i <= 5; $i++) echo $i <= (int)$h['hvezdy'] ? '★' : '☆'; ?></div>
  <?php if (trim((string)$h['komentar']) !== ''): ?><p class="m" style="text-align:center">„<?= h($h['komentar']) ?>"</p><?php endif ?>
  <p class="m" style="text-align:center">Vaší zpětné vazby si vážíme. Těšíme se na vás příště.</p>
<?php else: ?>
  <h1>Jak jste byli spokojeni?<?= $jm !== '' ? ' ' . h($jm) : '' ?></h1>
  <p class="m">Ohodnoťte prosím svou poslední návštěvu v Thai Most. Zabere to pár vteřin.</p>
  <form method="post">
    <div class="stars">
      <?php for ($i = 5; $i >= 1; $i--): ?>
        <input type="radio" name="hvezdy" id="h<?= $i ?>" value="<?= $i ?>" <?= $i === 5 ? 'required' : '' ?>><label for="h<?= $i ?>" title="<?= $i ?>★">★</label>
      <?php endfor ?>
    </div>
    <textarea name="komentar" maxlength="2000" placeholder="Co se vám líbilo nebo co bychom mohli zlepšit? (nepovinné)"></textarea>
    <button type="submit">Odeslat hodnocení</button>
  </form>
<?php endif ?>
</div></div></body></html><?php
    exit;
}
