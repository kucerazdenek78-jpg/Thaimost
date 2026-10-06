(function () {
  const D = window.THAI, V = window.Voucher, $ = s => document.querySelector(s);
  const LOTUS = '<svg viewBox="0 0 120 80"><g fill="none" stroke="currentColor" stroke-width="1.5"><path d="M60 70 C52 52 52 30 60 10 C68 30 68 52 60 70Z"/><path d="M60 70 C46 60 36 44 34 26 C48 32 58 48 60 70Z"/><path d="M60 70 C74 60 84 44 86 26 C72 32 62 48 60 70Z"/><path d="M60 70 C40 68 22 58 12 42 C30 42 48 52 60 70Z"/><path d="M60 70 C80 68 98 58 108 42 C90 42 72 52 60 70Z"/></g></svg>';

  $("#rok").textContent = new Date().getFullYear();

  /* menu */
  const menu = $("#menu"), burger = $("#burger");
  burger.addEventListener("click", () => { const o = menu.classList.toggle("open"); burger.setAttribute("aria-expanded", o); });
  menu.addEventListener("click", e => { if (e.target.tagName === "A") menu.classList.remove("open"); });

  /* video – když se nenačte, zůstane gradient */
  const vid = document.querySelector(".hero video");
  if (vid) vid.addEventListener("error", () => vid.remove(), true);

  /* služby */
  const grid = $("#svGrid");
  function renderSluzby(kat) {
    grid.innerHTML = D.sluzby.filter(s => kat === "vse" || s.kat === kat).map(s => {
      const od = Math.min(...s.ceny.map(c => c[1]));
      const img = s.foto
        ? `<img src="${D.img}${s.foto}" alt="${V.esc(s.nazev)}" loading="lazy" onerror="this.remove()">`
        : "";
      return `<article class="sv reveal in">
        <div class="sv-img"><div class="ph">${LOTUS}</div>${img}${s.top ? '<span class="sv-tag">Oblíbené</span>' : ""}</div>
        <div class="sv-body">
          <h3>${V.esc(s.nazev)}</h3>
          <p>${V.esc(s.popis)}</p>
          ${s.pozn ? `<div class="sv-note">${V.esc(s.pozn)}</div>` : ""}
          <ul class="prices">${s.ceny.map(c => `<li><span>${V.esc(c[0])}</span><b>${V.kc(c[1])}</b></li>`).join("")}</ul>
          <button class="sv-cta" data-poukaz="${s.id}">Darovat jako poukaz →</button>
        </div></article>`;
    }).join("");
  }
  renderSluzby("vse");
  document.querySelectorAll(".tabs button").forEach(b => b.addEventListener("click", () => {
    document.querySelectorAll(".tabs button").forEach(x => x.classList.toggle("on", x === b));
    renderSluzby(b.dataset.kat);
  }));

  /* razítka */
  $("#stamps").innerHTML = [1, 2, 3, 4].map(() => `<div class="stamp on">${LOTUS}</div>`).join("")
    + `<div class="stamp">5</div><div class="stamp free">masáž<br>zdarma</div>`;

  /* reveal */
  const io = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { e.target.classList.add("in"); io.unobserve(e.target); } }), { threshold: .15 });
  document.querySelectorAll(".reveal:not(.in)").forEach(el => io.observe(el));

  /* ================= POUKAZY ================= */
  const iban = D.platba.iban || V.ibanZUctu(D.platba.cislo_uctu);
  if (iban) $("#platba").insertAdjacentHTML("beforeend", '<option value="prevod">Převodem / QR platbou předem</option>');

  const st = { typ: "hodnota", hodnota: 1000, sluzbaId: D.sluzby[0].id, varIdx: 0, motiv: "teak" };
  const HODNOTY = [500, 790, 1000, 1500, 2000];

  $("#chips").innerHTML = HODNOTY.map(h => `<button type="button" data-h="${h}">${V.kc(h)}</button>`).join("") + `<button type="button" data-h="x">Jiná částka</button>`;
  $("#selSluzba").innerHTML = ["masaze", "wellness"].map(k =>
    `<optgroup label="${k === "masaze" ? "Masáže" : "Wellness a rituály"}">` +
    D.sluzby.filter(s => s.kat === k).map(s => `<option value="${s.id}">${V.esc(s.nazev)}</option>`).join("") + "</optgroup>").join("");

  const sluzba = () => D.sluzby.find(s => s.id === st.sluzbaId);
  function fillVar() {
    $("#selVar").innerHTML = sluzba().ceny.map((c, i) => `<option value="${i}">${V.esc(c[0])} – ${V.kc(c[1])}</option>`).join("");
    st.varIdx = 0;
  }
  fillVar();

  function cena() {
    if (st.typ === "sluzba") return sluzba().ceny[st.varIdx][1];
    return st.hodnota || 0;
  }
  function data() {
    const s = sluzba();
    return {
      typ: st.typ, hodnota: st.hodnota,
      sluzba: s.nazev, varianta: s.ceny[st.varIdx][0],
      komu: $("#komu").value.trim(), od: $("#od").value.trim(), venovani: $("#venovani").value.trim(),
      motiv: st.motiv
    };
  }
  function update() {
    document.querySelectorAll("#chips button").forEach(b => {
      const on = b.dataset.h === "x" ? st.vlastni : (!st.vlastni && +b.dataset.h === st.hodnota);
      b.classList.toggle("on", on);
    });
    $("#vlastniBox").style.display = st.vlastni ? "" : "none";
    $("#boxHodnota").style.display = st.typ === "hodnota" ? "" : "none";
    $("#boxSluzba").style.display = st.typ === "sluzba" ? "" : "none";
    $("#vPreview").innerHTML = V.render(data());
    $("#vTotal").textContent = cena() ? V.kc(cena()) : "—";
  }

  document.querySelectorAll('input[name="typ"]').forEach(r => r.addEventListener("change", () => { st.typ = r.value; update(); }));
  $("#chips").addEventListener("click", e => {
    const b = e.target.closest("button"); if (!b) return;
    if (b.dataset.h === "x") { st.vlastni = true; st.hodnota = +$("#vlastni").value || 0; setTimeout(() => $("#vlastni").focus(), 0); }
    else { st.vlastni = false; st.hodnota = +b.dataset.h; }
    update();
  });
  $("#vlastni").addEventListener("input", e => { st.hodnota = Math.max(0, Math.round(+e.target.value || 0)); update(); });
  $("#selSluzba").addEventListener("change", e => { st.sluzbaId = e.target.value; fillVar(); update(); });
  $("#selVar").addEventListener("change", e => { st.varIdx = +e.target.value; update(); });
  ["#komu", "#od", "#venovani"].forEach(id => $(id).addEventListener("input", update));
  $("#motivy").addEventListener("click", e => {
    const b = e.target.closest("button"); if (!b) return;
    st.motiv = b.dataset.m;
    document.querySelectorAll("#motivy button").forEach(x => x.classList.toggle("on", x === b));
    update();
  });

  /* „Darovat jako poukaz“ u služby */
  grid.addEventListener("click", e => {
    const b = e.target.closest("[data-poukaz]"); if (!b) return;
    st.typ = "sluzba"; st.sluzbaId = b.dataset.poukaz;
    document.querySelector('input[name="typ"][value="sluzba"]').checked = true;
    $("#selSluzba").value = st.sluzbaId; fillVar(); update();
    $("#poukazy").scrollIntoView({ behavior: "smooth" });
  });

  update();

  /* odeslání */
  $("#vForm").addEventListener("submit", e => {
    e.preventDefault();
    const err = $("#err"), chyby = [];
    if (!cena() || cena() < 100) chyby.push("zvolte hodnotu poukazu (min. 100 Kč)");
    if (!$("#jmeno").value.trim()) chyby.push("vyplňte jméno");
    if ($("#tel").value.replace(/\D/g, "").length < 9) chyby.push("vyplňte telefon");
    if ($("#prevzeti").value === "email" && !/^\S+@\S+\.\S+$/.test($("#mail").value)) chyby.push("pro zaslání e-mailem vyplňte e-mail");
    if (!$("#gdpr").checked) chyby.push("potvrďte souhlas se zpracováním údajů");
    if (chyby.length) { err.textContent = "Prosím " + chyby.join(", ") + "."; err.style.display = "block"; return; }
    err.style.display = "none";

    const d = data(), n = new Date(), p2 = x => String(x).padStart(2, "0");
    const vs = String(n.getFullYear()).slice(2) + p2(n.getMonth() + 1) + p2(n.getDate()) + p2(n.getHours()) + p2(n.getMinutes());
    const platba = $("#platba").value;
    const text =
`OBJEDNÁVKA DÁRKOVÉHO POUKAZU č. ${vs}

Poukaz: ${d.typ === "sluzba" ? d.sluzba + " (" + d.varianta + ")" : "na částku"}
Cena: ${V.kc(cena())}
Pro: ${d.komu || "-"}
Od: ${d.od || "-"}
Přání: ${d.venovani || "-"}
Motiv: ${d.motiv}

Objednatel: ${$("#jmeno").value.trim()}
Telefon: ${$("#tel").value.trim()}
E-mail: ${$("#mail").value.trim() || "-"}
Převzetí: ${$("#prevzeti").selectedOptions[0].text}
Platba: ${$("#platba").selectedOptions[0].text}
Poznámka: ${$("#pozn").value.trim() || "-"}`;

    const mailto = `mailto:${D.salon.email}?subject=${encodeURIComponent("Objednávka poukazu č. " + vs)}&body=${encodeURIComponent(text)}`;
    let qr = "";
    if (platba === "prevod" && iban) {
      const svg = V.qrSvg(V.spd({ iban, castka: cena(), vs, zprava: "Poukaz " + vs }), 140);
      qr = `<div class="qrpay">${svg}<div><b>QR platba</b><br>Částka: ${V.kc(cena())}<br>Účet: ${V.esc(D.platba.cislo_uctu || iban)}<br>VS: ${vs}<br><span class="hint">Naskenujte v aplikaci banky.</span></div></div>`;
    }
    const done = $("#done");
    done.innerHTML = `<span class="eyebrow">Hotovo</span><h3>Objednávka č. ${vs}</h3>
      <p>Otevřeli jsme vám e-mail s objednávkou – stačí ho <b>odeslat</b>. Pokud se e-mail neotevřel, použijte tlačítko níže nebo objednávku zkopírujte, případně zavolejte.</p>
      ${qr}
      <div class="sum">${V.esc(text)}</div>
      <div class="btns">
        <a class="btn btn-dark" href="${mailto}">Odeslat e-mailem</a>
        <button class="btn btn-ghost" type="button" id="copy">Kopírovat</button>
        <a class="btn btn-gold" href="tel:+420778429329">Zavolat</a>
      </div>`;
    $("#copy").onclick = () => navigator.clipboard?.writeText(text).then(() => { $("#copy").textContent = "Zkopírováno ✓"; });
    $("#vPreviewWrap").style.display = "none";
    done.style.display = "block";
    done.scrollIntoView({ behavior: "smooth", block: "start" });
    window.location.href = mailto;
  });
})();
