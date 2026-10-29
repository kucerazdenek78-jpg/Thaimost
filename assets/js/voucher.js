/* Sdílené vykreslení dárkového poukazu (web i stránka pro salon).
   Podklad je originální návrh salonu (assets/img/poukaz-pozadi.jpg, z PDF „Thai – Dárkový poukaz“); do prázdných políček
   Jméno / Procedura / Platnost se doplní text, dole kód a QR. Rozměry jsou stejné jako v kartotéce
   (kartoteka/app/poukaz_pdf.php), polohy v bodech originálního PDF (595,276 × 283,465 pt). */
(function () {
  const kc = n => Number(n).toLocaleString("cs-CZ") + " Kč";
  const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

  function qrSvg(text, size) {
    if (!window.qrcode || !text) return "";
    const q = qrcode(0, "M"); q.addData(text); q.make();
    return q.createSvgTag({ cellSize: Math.max(2, Math.floor(size / q.getModuleCount())), margin: 0, scalable: true });
  }

  const W = 595.276, H = 283.465;
  const POLE = { jmeno: [25.4, 169.9], procedura: [184.3, 329.3], platnost: [343.7, 488.2] };
  const ZAKLADNI_LINKA = 235.0, VELIKOST = 11, VELIKOST_MIN = 6.5, ODSAZENI = 5.5;
  const KOD = { right: 518.0, top: 188.7, size: 6.8 };      // bílý kód na zeleném pruhu
  const QR = { x: 214.0, y: 147.0, size: 33.0 };            // QR v béžovém panelu vpravo od „MOST“
  // řádek s line-height:1 má základní linku 0,793 výšky písma pod horním okrajem (metriky Gelasio: ascent 1900, descent 700, 2048 j./em)
  const NAD_LINKOU = 0.793;

  const pct = (v, z) => (v / z * 100).toFixed(3) + "%";
  const cq = pt => (pt / W * 100).toFixed(3) + "cqw";

  /* d = {typ:'hodnota'|'sluzba', hodnota, sluzba, varianta, komu, kod, platnost} */
  function texty(d) {
    const procedura = d.typ === "sluzba" && d.sluzba
      ? d.sluzba + (d.varianta ? " (" + d.varianta + ")" : "")
      : (+d.hodnota > 0 ? "Hodnota " + kc(d.hodnota) : "");
    return { jmeno: (d.komu || "").trim(), procedura, platnost: d.platnost ? "do " + d.platnost : "" };
  }

  function render(d) {
    const t = texty(d);
    let h = `<div class="pk"><img src="assets/img/poukaz-pozadi.jpg" alt="Dárkový poukaz Thajské masáže Most">`;
    for (const k of Object.keys(POLE)) {
      if (!t[k]) continue;
      const [x0, x1] = POLE[k], max = x1 - x0 - 2 * ODSAZENI;
      h += `<span class="pk-t" data-max="${max}" style="left:${pct(x0 + ODSAZENI, W)};top:${pct(ZAKLADNI_LINKA - NAD_LINKOU * VELIKOST, H)};font-size:${cq(VELIKOST)};max-width:${pct(max, W)}">${esc(t[k])}</span>`;
    }
    if (d.kod) {
      h += `<span class="pk-k" style="right:${pct(W - KOD.right, W)};top:${pct(KOD.top - NAD_LINKOU * KOD.size, H)};font-size:${cq(KOD.size)}">KÓD&nbsp; ${esc(d.kod)}</span>`;
      const qr = qrSvg(d.kod, 120);
      if (qr) h += `<div class="pk-qr" style="left:${pct(QR.x, W)};top:${pct(QR.y, H)};width:${pct(QR.size, W)};padding:${pct(2, W)}">${qr}</div>`;
    }
    return h + `</div>`;
  }

  /* Zmenší písmo v políčku tak, aby se text vešel (stejně jako v PDF), nejmíň na 6,5 pt; pak ho zkrátí třemi tečkami. */
  function fit(root) {
    root.querySelectorAll(".pk-t").forEach(el => {
      const pk = el.parentElement, max = +el.dataset.max / W * pk.clientWidth;
      let v = VELIKOST;
      el.style.fontSize = cq(v);
      while (v > VELIKOST_MIN && el.scrollWidth > max + 0.5) { v -= 0.25; el.style.fontSize = cq(v); }
    });
  }

  /* Vloží poukaz do prvku a po načtení písma ho přizpůsobí (měření šířky textu potřebuje hotové písmo). */
  function mount(el, d) {
    el.innerHTML = render(d);
    fit(el);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => { if (el.isConnected) fit(el); });
    if (window.ResizeObserver && !el._pkRO) { el._pkRO = new ResizeObserver(() => fit(el)); el._pkRO.observe(el); }
  }

  /* QR Platba (formát SPD 1.0 – standard České bankovní asociace) */
  function spd({ iban, castka, vs, zprava }) {
    if (!iban) return "";
    const parts = ["SPD*1.0", "ACC:" + iban.replace(/\s/g, ""), "AM:" + Number(castka).toFixed(2), "CC:CZK"];
    if (vs) parts.push("X-VS:" + vs);
    if (zprava) parts.push("MSG:" + zprava.normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/\*/g, "").slice(0, 60).toUpperCase());
    return parts.join("*");
  }

  /* "19-123456789/0100" -> CZ IBAN */
  function ibanZUctu(ucet) {
    const m = String(ucet || "").replace(/\s/g, "").match(/^(?:(\d{1,6})-)?(\d{2,10})\/(\d{4})$/);
    if (!m) return "";
    const bban = m[3] + (m[1] || "").padStart(6, "0") + m[2].padStart(10, "0");
    const num = bban + "123500"; // C=12, Z=35, 00
    let r = 0; for (const ch of num) r = (r * 10 + +ch) % 97;
    return "CZ" + String(98 - r).padStart(2, "0") + bban;
  }

  window.Voucher = { render, mount, fit, qrSvg, spd, kc, esc, ibanZUctu };
})();
