/* Sdílené vykreslení dárkového poukazu (web i stránka pro salon) */
(function () {
  const LOTUS = `<svg viewBox="0 0 120 80" aria-hidden="true" class="v-lotus">
    <g fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round">
      <path d="M60 70 C52 52 52 30 60 10 C68 30 68 52 60 70Z"/>
      <path d="M60 70 C46 60 36 44 34 26 C48 32 58 48 60 70Z"/>
      <path d="M60 70 C74 60 84 44 86 26 C72 32 62 48 60 70Z"/>
      <path d="M60 70 C40 68 22 58 12 42 C30 42 48 52 60 70Z"/>
      <path d="M60 70 C80 68 98 58 108 42 C90 42 72 52 60 70Z"/>
      <path d="M30 74 Q60 80 90 74"/>
    </g></svg>`;

  const kc = n => Number(n).toLocaleString("cs-CZ") + " Kč";
  const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

  function qrSvg(text, size) {
    if (!window.qrcode || !text) return "";
    const q = qrcode(0, "M"); q.addData(text); q.make();
    return q.createSvgTag({ cellSize: Math.max(2, Math.floor(size / q.getModuleCount())), margin: 0, scalable: true });
  }

  /* d = {typ:'hodnota'|'sluzba', hodnota, sluzba, varianta, komu, od, venovani, motiv, kod, platnost} */
  function render(d) {
    const hlavni = d.typ === "sluzba" && d.sluzba
      ? `<div class="v-what">${esc(d.sluzba)}</div><div class="v-sub">${esc(d.varianta || "")}</div>`
      : `<div class="v-value">${d.hodnota ? kc(d.hodnota) : "— Kč"}</div><div class="v-sub">na libovolnou masáž nebo wellness</div>`;
    const qr = d.kod ? qrSvg(d.kod, 72) : "";
    return `<div class="voucher motiv-${esc(d.motiv || "teak")}">
      <div class="v-frame"></div>
      <div class="v-left">
        <div class="v-brand">${LOTUS}<span>Thai Most</span></div>
        <div class="v-title">Dárkový poukaz</div>
        ${hlavni}
      </div>
      <div class="v-right">
        <div class="v-row"><span>Pro</span><b>${esc(d.komu) || "&nbsp;"}</b></div>
        <div class="v-row"><span>Od</span><b>${esc(d.od) || "&nbsp;"}</b></div>
        ${d.venovani ? `<p class="v-msg">„${esc(d.venovani)}“</p>` : `<p class="v-msg v-empty"></p>`}
        <div class="v-foot">
          <div>
            ${d.kod ? `<div class="v-code">${esc(d.kod)}</div>` : `<div class="v-code v-muted">kód doplní salon</div>`}
            ${d.platnost ? `<div class="v-valid">Platnost do ${esc(d.platnost)}</div>` : ""}
            <div class="v-contact">Objednání: +420 778 429 329 · SNP 2654/26, Most</div>
          </div>
          ${qr ? `<div class="v-qr">${qr}</div>` : ""}
        </div>
      </div>
    </div>`;
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

  window.Voucher = { render, qrSvg, spd, kc, esc, ibanZUctu };
})();
