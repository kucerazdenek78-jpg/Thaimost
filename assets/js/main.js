(function () {
  const D = window.THAI, V = window.Voucher, T = window.T, TK = window.TK, $ = s => document.querySelector(s);
  const LOTUS = '<svg viewBox="0 0 120 80"><g fill="none" stroke="currentColor" stroke-width="1.5"><path d="M60 70 C52 52 52 30 60 10 C68 30 68 52 60 70Z"/><path d="M60 70 C46 60 36 44 34 26 C48 32 58 48 60 70Z"/><path d="M60 70 C74 60 84 44 86 26 C72 32 62 48 60 70Z"/><path d="M60 70 C40 68 22 58 12 42 C30 42 48 52 60 70Z"/><path d="M60 70 C80 68 98 58 108 42 C90 42 72 52 60 70Z"/></g></svg>';
  /* Odpovědi pro salon (e-mail, kartotéka) jsou vždy česky; to, co vidí zákazník, se překládá. */
  const CS = { t: s => s, m: T.moneyCs }, LO = { t: T, m: T.money };
  const JAZYK = { en: "English", de: "Deutsch" }[T.lang] || "";
  /* služby s rozpohybovaným videem (assets/video/sluzby/<id>.mp4 + .jpg) */
  const VID_SLUZBY = new Set(["tradicni","hlava","zada","olej","aroma","detska","lymfa-oblicej","poporodni","oblicej","nohy","bylinna","siam","ventosa","lava","lymfa-telo","tehotenska","whirlpool","fiji","cukr"]);

  $("#rok").textContent = new Date().getFullYear();

  /* menu */
  const menu = $("#menu"), burger = $("#burger");
  burger.addEventListener("click", () => { const o = menu.classList.toggle("open"); burger.setAttribute("aria-expanded", o); });
  menu.addEventListener("click", e => { if (e.target.tagName === "A") menu.classList.remove("open"); });

  /* výběr jazyka */
  const lb = $("#langBtn"), lm = $("#langMenu");
  if (lb && lm) {
    const cur = lm.querySelector("a[aria-current]");
    if (cur) { lb.querySelector("img").src = cur.querySelector("img").src; lb.querySelector("span").textContent = cur.getAttribute("hreflang").toUpperCase(); }
    const close = () => { lm.classList.remove("open"); lb.setAttribute("aria-expanded", "false"); };
    lb.addEventListener("click", e => { e.stopPropagation(); const o = lm.classList.toggle("open"); lb.setAttribute("aria-expanded", o); });
    document.addEventListener("click", e => { if (!lm.contains(e.target)) close(); });
    document.addEventListener("keydown", e => { if (e.key === "Escape") close(); });
  }

  /* video – když se nenačte, zůstane gradient */
  const vid = document.querySelector(".hero video");
  if (vid) vid.addEventListener("error", () => vid.remove(), true);

  /* služby; tx = text služby v jazyce stránky (překlad z kartotéky, jinak slovník webu); s.nazev/popis/pozn zůstávají česky (salon, poukaz) */
  /* ceny z data.js při načtení – když kartotéka pošle jiné, přepíšou se i v textech označených data-ceny (úvod, otázky) */
  const CENY_PUVODNI = new Map(D.sluzby.map(s => [s.id, s.ceny.map(c => c[1])]));
  function prepisCenyVTextech() {
    const zmeny = new Map(), sporne = new Set();
    D.sluzby.forEach(s => (CENY_PUVODNI.get(s.id) || []).forEach((stara, i) => {
      const nova = s.ceny[i] && s.ceny[i][1]; if (!nova || nova === stara) return;
      if (zmeny.has(stara) && zmeny.get(stara) !== nova) sporne.add(stara); else zmeny.set(stara, nova);
    }));
    sporne.forEach(c => zmeny.delete(c));
    if (!zmeny.size) return;
    const fmt = n => Number(n).toLocaleString(T.loc || "cs-CZ");
    document.querySelectorAll("[data-ceny]").forEach(el => {
      const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT); let n;
      while ((n = w.nextNode())) n.nodeValue = n.nodeValue.replace(/(\d{1,3}(?:[ \u00a0\u202f.,]\d{3})*)(\s*)(Kč|CZK)/g, (m, cis, mez, mena) => {
        const v = +cis.replace(/\D/g, ""); return zmeny.has(v) ? fmt(zmeny.get(v)).replace(/\u202f/g, "\u00a0") + mez + mena : m;
      });
    });
  }
  const tx = (s, f) => (T.lang !== "cs" && s.tr && s.tr[f]) || T(s[f] || "");
  const grid = $("#svGrid");
  let katAkt = "vse";
  function renderSluzby(kat) {
    katAkt = kat;
    grid.innerHTML = D.sluzby.filter(s => kat === "vse" || s.kat === kat).map(s => {
      const od = Math.min(...s.ceny.map(c => c[1]));
      const nazev = tx(s, "nazev");
      const alt = V.esc(T("{nazev} – thajské masáže Most, Thai Most", { nazev }));
      const maRemote = /^https?:/.test(s.foto || "");
      const maVideo = VID_SLUZBY.has(s.id) && !maRemote;
      let img;
      if (maVideo) {
        const vb = T.root + "assets/video/sluzby/" + s.id;
        img = `<video class="rc-media" autoplay loop muted playsinline preload="metadata" poster="${vb}.jpg" width="1200" height="800" aria-label="${alt}"><source src="${vb}.mp4" type="video/mp4"><img src="${T.root + D.img + s.foto}" alt="${alt}" width="1200" height="800"></video>${!s.vlastni ? '<span class="sv-ilu">' + V.esc(T("Ilustrační video")) + "</span>" : ""}`;
      } else {
        img = s.foto
          ? `<img src="${maRemote ? s.foto : T.root + D.img + s.foto}" alt="${alt}" loading="lazy" width="1200" height="800" onerror="this.remove()">${!s.vlastni && !maRemote ? '<span class="sv-ilu">' + V.esc(T("Ilustrační foto")) + "</span>" : ""}`
          : "";
      }
      return `<article class="sv reveal in">
        <div class="sv-img"><div class="ph">${LOTUS}</div>${img}${s.top ? '<span class="sv-tag">' + V.esc(T("Oblíbené")) + "</span>" : ""}</div>
        <div class="sv-body">
          <h3>${V.esc(nazev)}</h3>
          <p>${V.esc(tx(s, "popis"))}</p>
          ${s.pozn ? `<div class="sv-note">${V.esc(tx(s, "pozn"))}</div>` : ""}
          <ul class="prices">${s.ceny.map(c => `<li><span>${V.esc(T(c[0]))}</span><b>${V.kc(c[1])}</b></li>`).join("")}</ul>
          ${T.lang === "cs" && window.THAI_STRANKY && THAI_STRANKY[s.id] ? `<a class="sv-more" href="${THAI_STRANKY[s.id]}">Více informací →</a>` : ""}
          <button class="sv-cta" data-poukaz="${s.id}">${V.esc(T("Darovat jako poukaz →"))}</button>
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
    + `<div class="stamp">5</div><div class="stamp free">${T("masáž<br>zdarma")}</div>`;

  /* reveal */
  const io = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { e.target.classList.add("in"); io.unobserve(e.target); } }), { threshold: .15 });
  document.querySelectorAll(".reveal:not(.in)").forEach(el => io.observe(el));

  /* ================= POUKAZY ================= */
  /* Platba převodem a platnost poukazu: z kartotéky (Nastavení → Poukazy a platba převodem), data.js je jen záloha */
  const PL = { ucet: D.platba.cislo_uctu || "", iban: D.platba.iban || V.ibanZUctu(D.platba.cislo_uctu), mes: 6, motivy: {}, vzhledy: {} };
  const ROOT = (window.T && T.root) || "";
  /* předtištěné poukazy (na prodejně) – staré návrhy salonu z webu */
  PL.predtistene = { darkovy: ROOT + "assets/img/poukaz-pozadi.jpg", vanocni: ROOT + "assets/img/poukaz-pozadi-vanocni.jpg" };
  /* Platba podle převzetí: osobně v salonu = jen hotově při vyzvednutí; e-mailem = převodem s QR (když má kartotéka účet) */
  function nabidniPrevod() {
    const sel = $("#platba"), osobne = $("#prevzeti").value === "salon", prevod = !!PL.iban && !osobne;
    const opt = prevod ? [["prevod", T("Převodem / QR platbou předem")]] : [["salon", T("Hotově v salonu při vyzvednutí")]];
    sel.innerHTML = opt.map(o => `<option value="${o[0]}">${V.esc(o[1])}</option>`).join("");
    if ($("#platbaPozn")) $("#platbaPozn").textContent = prevod
      ? T("Objednávka se odešle do salonu. Poukaz zaplatíte převodem s QR kódem, který se ukáže po odeslání – platba se s objednávkou spáruje sama.")
      : osobne ? T("Objednávka se odešle do salonu. Poukaz zaplatíte hotově při vyzvednutí v salonu.")
      : T("Objednávka se odešle e-mailem do salonu – nic se neplatí online.");
  }
  nabidniPrevod();
  $("#prevzeti").addEventListener("change", () => { nabidniPrevod(); if (typeof buildPicker === "function") buildPicker(); if (typeof update === "function") update(); });
  const platnostDo = () => { const d = new Date(); d.setMonth(d.getMonth() + PL.mes); return d.getDate() + ". " + (d.getMonth() + 1) + ". " + d.getFullYear(); };
  function platnostInfo() {
    const el = $("#platnostInfo"); if (!el) return;
    el.textContent = T("Poukaz platí {mes} měs. od nákupu (při objednání dnes do {datum}). Do té doby je potřeba se objednat na masáž – stačí zavolat.", { mes: PL.mes, datum: platnostDo() });
  }
  platnostInfo();

  /* Výběr vzhledu poukazu: e-mailem = nové vzhledy (8), na prodejně = předtištěné (dárkový / vánoční). */
  function pickerData(prevzeti) {
    if (prevzeti === "email") {
      const grp = { darkovy: [], vanocni: [] };
      Object.keys(PL.vzhledy || {}).forEach(k => { const v = PL.vzhledy[k]; if (v && grp[v.skupina]) grp[v.skupina].push([k, v.nazev]); });
      return { groups: [["darkovy", T("Dárkové")], ["vanocni", T("Vánoční")]], grp, obr: PL.motivy,
        note: T("Vyberte vzhled – pošleme vám ho e-mailem k vytištění.") };
    }
    return { groups: [["", ""]], grp: { "": [["darkovy", T("Dárkový")], ["vanocni", T("Vánoční")]] }, obr: PL.predtistene,
      note: T("Na prodejně dostanete náš předtištěný poukaz. Chcete vybrat z nových vzhledů? Zvolte níže zaslání e-mailem.") };
  }
  function buildPicker() {
    if (!PL.motivy || !Object.keys(PL.motivy).length) { $("#motivSeg").style.display = "none"; return; }
    const prevzeti = $("#prevzeti").value, pd = pickerData(prevzeti), vybrany = prevzeti === "email" ? st.motiv : st.predtisteny;
    let h = "";
    pd.groups.forEach(([gk, gnaz]) => {
      const items = pd.grp[gk] || []; if (!items.length) return;
      if (gnaz) h += `<div class="motiv-gh" style="font-size:.8em;letter-spacing:.1em;text-transform:uppercase;color:#9c7a3e;margin:6px 0 4px">${V.esc(gnaz)}</div>`;
      h += items.map(([k, naz]) => {
        const on = k === vybrany;
        return `<label class="motiv-opt" style="display:inline-block;width:31%;min-width:118px;margin:0 1% 8px;cursor:pointer;vertical-align:top">
          <input type="radio" name="motiv" value="${V.esc(k)}"${on ? " checked" : ""} style="position:absolute;opacity:0;width:0;height:0">
          <span style="display:block;border:2px solid ${on ? "#c69c5d" : "#e6d9c4"};border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.12)"><img src="${V.esc(pd.obr[k] || "")}" alt="${V.esc(naz)}" loading="lazy" style="display:block;width:100%;height:auto"></span>
          <span style="display:block;text-align:center;font-size:.82em;margin-top:3px;color:${on ? "#2b1e16" : "#7a6a5c"}">${V.esc(naz)}</span>
        </label>`;
      }).join("");
    });
    $("#motivGrid").innerHTML = h;
    $("#motivNote").textContent = pd.note;
    $("#motivSeg").style.display = "";
  }
  /* změna výběru vzhledu (delegovaně – dlaždice se překreslují) */
  $("#motivGrid").addEventListener("change", e => {
    const r = e.target.closest('input[name="motiv"]'); if (!r) return;
    if ($("#prevzeti").value === "email") st.motiv = r.value; else st.predtisteny = r.value;
    buildPicker(); update();
  });

  if (window.ThaiApi && ThaiApi.get) ThaiApi.get("/api/stav").then(r => {
    if (!r || !r.ok) return;
    if (r.iban) { PL.ucet = r.ucet; PL.iban = r.iban; nabidniPrevod(); }
    if (r.platnost_mes) PL.mes = +r.platnost_mes;
    /* vzhledy poukazu: e-mailem nové vzhledy (8), na prodejně předtištěné; výchozí dle sezóny */
    if (r.motivy) PL.motivy = r.motivy;
    if (r.vzhledy) PL.vzhledy = r.vzhledy;
    if (r.motiv) { st.motiv = r.motiv; st.predtisteny = String(r.motiv).indexOf("vanocni") === 0 ? "vanocni" : "darkovy"; }
    buildPicker();
    /* služby z kartotéky (Nastavení → Ceník): texty, překlady, ceny a vlastní fotky místo data.js */
    let zmena = false;
    if (r.sluzby && typeof r.sluzby === "object") {
      D.sluzby = D.sluzby.filter(s => {
        const k = r.sluzby[s.id]; if (!k) return true;
        if (!k.aktivni) { zmena = true; return false; }
        const tr = (k.preklady && k.preklady[T.lang]) || {};
        s.tr = {};
        for (const f of ["nazev", "popis", "pozn"]) {
          if (typeof k[f] === "string" && (k[f] || f === "pozn")) s[f] = k[f];   // česky (pro salon a poukaz)
          if (tr[f]) s.tr[f] = tr[f];                                              // bez překladu se ukáže česky
        }
        zmena = true;
        if (Array.isArray(k.ceny) && k.ceny.length) { s.ceny = k.ceny.map(c => [String(c[0]), +c[1]]); zmena = true; }
        return true;
      });
      /* nové služby přidané v kartotéce (nejsou v data.js): na konec své kategorie, bez fotky se ukáže lotos */
      Object.keys(r.sluzby).forEach(id => {
        const k = r.sluzby[id];
        if (!k || !k.aktivni || D.sluzby.some(s => s.id === id) || !Array.isArray(k.ceny) || !k.ceny.length) return;
        const tr = (k.preklady && k.preklady[T.lang]) || {}, s = { id, kat: k.kat === "wellness" ? "wellness" : "masaze", nazev: k.nazev, popis: k.popis || "", pozn: k.pozn || "", ceny: k.ceny.map(c => [String(c[0]), +c[1]]), tr: {} };
        for (const f of ["nazev", "popis", "pozn"]) if (tr[f]) s.tr[f] = tr[f];
        const posl = D.sluzby.map(x => x.kat).lastIndexOf(s.kat);
        D.sluzby.splice(posl + 1, 0, s); zmena = true;
      });
    }
    if (r.fotky && typeof r.fotky === "object") {
      D.sluzby.forEach(s => { const u = r.fotky[s.id]; if (u && /^https:\/\//.test(u)) { s.foto = u; s.vlastni = true; zmena = true; } });
    }
    if (zmena) { renderSluzby(katAkt); naplnSluzbyPoukazu(); naplnHodnoty(); prepisCenyVTextech(); }
    platnostInfo(); update();
  }).catch(() => {});

  const st = { typ: "hodnota", hodnota: 1000, sluzbaId: D.sluzby[0].id, varIdx: 0, motiv: "darkovy-1", predtisteny: "darkovy" };
  /* částky poukazu; druhá = cena tradiční thajské masáže 60 min z ceníku (kartotéka), ať sedí s ceníkem */
  const HODNOTY = [500, 800, 1000, 1500, 2000];
  function naplnHodnoty() {
    const t = D.sluzby.find(s => s.id === "tradicni");
    if (t && t.ceny[0]) HODNOTY[1] = t.ceny[0][1];
    $("#chips").innerHTML = HODNOTY.map(h => `<button type="button" data-h="${h}">${V.kc(h)}</button>`).join("") + `<button type="button" data-h="x">${V.esc(T("Jiná částka"))}</button>`;
  }
  /* texty voleb, které se odesílají salonu (vždy česky; zákazník vidí svůj jazyk) */
  const PREVZETI_CS = { salon: TK("Vyzvednu osobně v salonu"), email: TK("Pošlete mi ho e-mailem k vytištění") };
  const PLATBA_CS = { salon: TK("Hotově v salonu při vyzvednutí"), prevod: TK("Převodem / QR platbou předem") };

  naplnHodnoty();
  function naplnSluzbyPoukazu() {
    if (!D.sluzby.some(s => s.id === st.sluzbaId)) st.sluzbaId = D.sluzby[0].id;
    $("#selSluzba").innerHTML = ["masaze", "wellness"].map(k =>
      `<optgroup label="${k === "masaze" ? V.esc(T("Masáže")) : V.esc(T("Wellness a rituály"))}">` +
      D.sluzby.filter(s => s.kat === k).map(s => `<option value="${s.id}">${V.esc(tx(s, "nazev"))}</option>`).join("") + "</optgroup>").join("");
    $("#selSluzba").value = st.sluzbaId;
    if (typeof fillVar === "function" && $("#selVar")) { const i = st.varIdx; fillVar(); if (sluzba().ceny[i]) { st.varIdx = i; $("#selVar").value = i; } }
  }

  const sluzba = () => D.sluzby.find(s => s.id === st.sluzbaId);
  function fillVar() {
    $("#selVar").innerHTML = sluzba().ceny.map((c, i) => `<option value="${i}">${V.esc(T(c[0]))} – ${V.kc(c[1])}</option>`).join("");
    st.varIdx = 0;
  }
  naplnSluzbyPoukazu();

  function cena() {
    if (st.typ === "sluzba") return sluzba().ceny[st.varIdx][1];
    return st.hodnota || 0;
  }
  /* d = údaje poukazu česky (pro náhled, který odpovídá poukazu vystavenému salonem, a pro objednávku) */
  function data() {
    const s = sluzba(), email = $("#prevzeti").value === "email";
    return {
      typ: st.typ, hodnota: st.hodnota,
      sluzba: s.nazev, varianta: s.ceny[st.varIdx][0],
      komu: $("#komu").value.trim(), od: $("#od").value.trim(), venovani: $("#venovani").value.trim(),
      platnost: platnostDo(),
      motiv: email ? st.motiv : st.predtisteny,
      pozadi: email ? (PL.motivy[st.motiv] || "") : (PL.predtistene[st.predtisteny] || "")
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
    const d = data();
    /* e-mailem: živý náhled s vepsaným jménem; na prodejně: předtištěný poukaz (vyplní se ručně v salonu) */
    if ($("#prevzeti").value === "email") V.mount($("#vPreview"), d);
    else $("#vPreview").innerHTML = `<div class="pk"><img src="${V.esc(d.pozadi)}" alt="${V.esc(T("Předtištěný dárkový poukaz"))}"></div>`;
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

  /* „Darovat jako poukaz“ u služby */
  grid.addEventListener("click", e => {
    const b = e.target.closest("[data-poukaz]"); if (!b) return;
    st.typ = "sluzba"; st.sluzbaId = b.dataset.poukaz;
    document.querySelector('input[name="typ"][value="sluzba"]').checked = true;
    $("#selSluzba").value = st.sluzbaId; fillVar(); update();
    $("#poukazy").scrollIntoView({ behavior: "smooth" });
  });

  update();

  /* hlášky ze serveru kartotéky jsou česky – přeložíme je, pokud je známe */
  const serverMsg = m => (T.lang === "cs" ? m : T(m));
  /* česky „Prosím vyplňte jméno, vyplňte telefon.“; v en/de jsou překlady celé věty („Please enter your name“) → za sebou */
  const chybyText = chyby => (T.lang === "cs" ? T("Prosím {seznam}.", { seznam: chyby.join(", ") }) : chyby.map(x => x + ".").join(" "));

  /* odeslání */
  $("#vForm").addEventListener("submit", e => {
    e.preventDefault();
    const err = $("#err"), chyby = [];
    if (!cena() || cena() < 100) chyby.push(T("zvolte hodnotu poukazu (min. 100 Kč)"));
    if (!$("#jmeno").value.trim()) chyby.push(T("vyplňte jméno"));
    if ($("#tel").value.replace(/\D/g, "").length < 9) chyby.push(T("vyplňte telefon"));
    if ($("#prevzeti").value === "email" && !/^\S+@\S+\.\S+$/.test($("#mail").value)) chyby.push(T("pro zaslání e-mailem vyplňte e-mail"));
    if (!$("#gdpr").checked) chyby.push(T("potvrďte souhlas se zpracováním údajů"));
    if (chyby.length) { err.textContent = chybyText(chyby); err.style.display = "block"; return; }
    err.style.display = "none";

    const d = data(), n = new Date(), p2 = x => String(x).padStart(2, "0");
    /* číslo objednávky = číslo poukazu z kartotéky (= variabilní symbol); dočasné číslo jen pro nouzový e-mail, když kartotéka neodpoví */
    let vs = String(n.getFullYear()).slice(2) + p2(n.getMonth() + 1) + p2(n.getDate()) + p2(n.getHours()) + p2(n.getMinutes());
    const platba = $("#platba").value, prevzeti = $("#prevzeti").value;

    /* souhrn objednávky: tr = CS (pro salon, česky) nebo LO (pro zákazníka, jeho jazykem) */
    const souhrn = tr => {
      const t = tr.t, m = tr.m;
      const radky = [
        t("OBJEDNÁVKA DÁRKOVÉHO POUKAZU č. {vs}").replace("{vs}", vs), "",
        t("Poukaz") + ": " + (d.typ === "sluzba" ? t(d.sluzba) + " (" + t(d.varianta) + ")" : t("na částku")),
        t("Cena") + ": " + m(cena()),
        t("Pro") + ": " + (d.komu || "-"),
        t("Od") + ": " + (d.od || "-"),
        t("Přání") + ": " + (d.venovani || "-"), "",
        t("Objednatel") + ": " + $("#jmeno").value.trim(),
        t("Telefon") + ": " + $("#tel").value.trim(),
        t("E-mail") + ": " + ($("#mail").value.trim() || "-"),
        t("Převzetí") + ": " + t(PREVZETI_CS[prevzeti] || ""),
        t("Platba") + ": " + t(PLATBA_CS[platba] || ""),
        t("Poznámka") + ": " + ($("#pozn").value.trim() || "-")
      ];
      if (tr === CS && JAZYK) radky.push("Jazyk zákazníka: " + JAZYK);
      return radky.join("\n");
    };
    let text = souhrn(CS), textZak = souhrn(LO);

    const mailto = `mailto:${D.salon.email}?subject=${encodeURIComponent("Objednávka poukazu č. " + vs)}&body=${encodeURIComponent(text)}`;
    /* jak dlouho trvá spárování platby: podle nastavení kartotéky (Správa → Platby, /api/stav → platby_min), výchozí 1 hodina */
    const parovaniText = () => {
      const m = window.TM_PLATBY_MIN === undefined ? 60 : window.TM_PLATBY_MIN;
      const kdy = m === 15 ? T("Platbu s objednávkou spárujeme v otevírací době obvykle do 15 minut od připsání na náš účet.")
        : m === 30 ? T("Platbu s objednávkou spárujeme v otevírací době obvykle do 30 minut od připsání na náš účet.")
        : m === 120 ? T("Platbu s objednávkou spárujeme v otevírací době obvykle do 2 hodin od připsání na náš účet.")
        : m === 240 ? T("Platbu s objednávkou spárujeme v otevírací době obvykle do 4 hodin od připsání na náš účet.")
        : m === 0 ? T("Platbu s objednávkou spárujeme na recepci v otevírací době.")
        : T("Platbu s objednávkou spárujeme v otevírací době obvykle do 1 hodiny od připsání na náš účet.");
      return kdy + " " + T("Převod z jiné banky může trvat až do následujícího pracovního dne.");
    };
    /* QR platba až s číslem poukazu od kartotéky (VS = číslo poukazu, podle něj se platba sama spáruje) */
    const qrBlok = (r) => {
      if (platba !== "prevod" || !r || !r.iban || !r.vs) return "";
      const svg = V.qrSvg(V.spd({ iban: r.iban, castka: r.castka || cena(), vs: r.vs, zprava: "Poukaz " + r.vs }), 140);
      return `<div class="qrpay">${svg}<div><b>${V.esc(T("QR platba"))}</b><br>${V.esc(T("Částka"))}: ${V.kc(r.castka || cena())}<br>${V.esc(T("Účet"))}: ${V.esc(r.ucet || r.iban)}<br>${V.esc(T("VS"))}: ${V.esc(r.vs)} <span class="hint">(${V.esc(T("číslo poukazu"))})</span><br><span class="hint">${V.esc(T("Naskenujte v aplikaci banky."))}</span><br><span class="hint">${V.esc(parovaniText())}</span></div></div>`;
    };
    const platnostBlok = (r) => r && r.platnost_txt
      ? `<p class="hint">${V.esc(T("Poukaz platí do {datum}. Do té doby je potřeba se objednat na masáž – stačí zavolat na 778 429 329.", { datum: r.platnost_txt }))}</p>` : "";
    const done = $("#done");
    const btn = e.submitter || $("#vForm button[type=submit]");
    if (btn) { btn.disabled = true; btn.textContent = T("Odesílám…"); }

    /* 1) do kartotéky salonu (NAS); 2) když to nejde, nouzově e-mailem */
    const poznamka = ($("#pozn").value.trim() + (JAZYK ? " [" + JAZYK + "]" : "")).trim();
    const payload = {
      jmeno: $("#jmeno").value.trim(), telefon: $("#tel").value.trim(), email: $("#mail").value.trim(),
      hodnota: cena(), sluzba: d.typ === "sluzba" ? d.sluzba + " (" + d.varianta + ")" : "",
      komu: d.komu, od: d.od, venovani: d.venovani, motiv: d.motiv, prevzeti: PREVZETI_CS[prevzeti] || "",
      platba: PLATBA_CS[platba] || "", poznamka, cislo: vs, gdpr: 1,
      web: $("#web") ? $("#web").value : ""
    };
    window.ThaiApi.post("/api/poukaz", payload).then(r => {
      if (!r.ok) throw new Error(r.chyba || "chyba");
      if (r.cislo) { vs = String(r.cislo); textZak = souhrn(LO); }
      const qr = qrBlok(r);
      /* převodem + e-mailem: poukaz přijde sám po připsání platby; osobně: vyzvednout a zaplatit hotově na recepci */
      const mail = V.esc(payload.email || "");
      const kontakt = platba === "prevod" && payload.email && prevzeti === "email"
        ? T("Děkujeme! Potvrzení s údaji k platbě jsme poslali na e-mail <b>{mail}</b>. Poukaz vám po připsání platby pošleme e-mailem – obvykle do dvou hodin.", { mail })
        : platba !== "prevod"
          ? T("Děkujeme! Pro poukaz se prosím zastavte na recepci v otevírací době (Po–Pá 9–20 h) a zaplaťte ho v hotovosti.") + (payload.email ? " " + T("Potvrzení objednávky jsme poslali na e-mail <b>{mail}</b>.", { mail }) : "")
          : payload.email
            ? T("Děkujeme! Salon se vám ozve na telefon <b>{tel}</b> nebo e-mail <b>{mail}</b> a domluví převzetí.", { tel: V.esc(payload.telefon), mail })
            : T("Děkujeme! Salon se vám ozve na telefon <b>{tel}</b> a domluví převzetí.", { tel: V.esc(payload.telefon) });
      done.innerHTML = `<span class="eyebrow">${V.esc(T("Hotovo"))}</span><h3>${V.esc(T("Objednávka č. {vs} přijata", { vs }))}</h3>
        <p>${kontakt}${qr ? " " + T("Platbu proveďte podle QR kódu níže.") : ""}</p>
        ${platnostBlok(r)}
        ${qr}
        <div class="sum">${V.esc(textZak)}</div>
        <div class="btns"><a class="btn btn-gold" href="tel:+420778429329">${V.esc(T("Zavolat"))}</a></div>`;
    }).catch(() => {
      done.innerHTML = `<span class="eyebrow">${V.esc(T("Hotovo"))}</span><h3>${V.esc(T("Objednávka č. {vs}", { vs }))}</h3>
        <p>${T("Objednávku se nepodařilo odeslat automaticky. Otevřeli jsme vám e-mail s objednávkou – stačí ho <b>odeslat</b>. Pokud se neotevřel, použijte tlačítko níže nebo objednávku zkopírujte, případně zavolejte.")}</p>
        <div class="sum">${V.esc(textZak)}</div>
        <div class="btns">
          <a class="btn btn-dark" href="${mailto}">${V.esc(T("Odeslat e-mailem"))}</a>
          <button class="btn btn-ghost" type="button" id="copy">${V.esc(T("Kopírovat"))}</button>
          <a class="btn btn-gold" href="tel:+420778429329">${V.esc(T("Zavolat"))}</a>
        </div>`;
      $("#copy").onclick = () => navigator.clipboard?.writeText(text).then(() => { $("#copy").textContent = T("Zkopírováno ✓"); });
      window.location.href = mailto;
    }).finally(() => {
      $("#vPreviewWrap").style.display = "none";
      done.style.display = "block";
      done.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  });
})();

/* Objednat se online – žádost o termín do kartotéky salonu */
(function () {
  const $ = s => document.querySelector(s);
  const form = $("#bForm"); if (!form) return;
  const D = window.THAI, T = window.T, sel = $("#bSluzba");
  const JAZYK = { en: "English", de: "Deutsch" }[T.lang] || "";
  const serverMsg = m => (T.lang === "cs" ? m : T(m));
  const chybyText = chyby => (T.lang === "cs" ? T("Prosím {seznam}.", { seznam: chyby.join(", ") }) : chyby.map(x => x + ".").join(" "));

  // nabídka služeb: z kartotéky (aktuální ceník), při výpadku z data.js
  function naplnZData() {
    D.sluzby.forEach(s => s.ceny.forEach(([pop, c], i) => {
      const o = document.createElement("option"); o.value = ""; o.dataset.local = s.id + ":" + i;
      o.textContent = `${(T.lang !== "cs" && s.tr && s.tr.nazev) || T(s.nazev)} – ${T(pop)} – ${T.money(c)}`; sel.appendChild(o);
    }));
  }
  window.ThaiApi.get("/api/sluzby").then(r => {
    if (!r.ok) throw 0;
    r.varianty.forEach(v => {
      const o = document.createElement("option"); o.value = v.id;
      o.textContent = `${(v.nazev_t && v.nazev_t[T.lang]) || T(v.nazev)} – ${T(v.popis)} – ${T.money(v.cena)}`; sel.appendChild(o);
    });
  }).catch(() => { naplnZData(); form.dataset.offline = "1"; });

  const den = $("#bDen"); if (den) den.min = new Date().toISOString().slice(0, 10);

  form.addEventListener("submit", e => {
    e.preventDefault();
    const err = $("#bErr"), chyby = [];
    if (!sel.value && !form.dataset.offline) chyby.push(T("vyberte masáž"));
    if (!$("#bJmeno").value.trim()) chyby.push(T("vyplňte jméno"));
    if (!$("#bPrijmeni").value.trim()) chyby.push(T("vyplňte příjmení"));
    if ($("#bTel").value.replace(/\D/g, "").length < 9) chyby.push(T("vyplňte telefon"));
    if (!$("#bGdpr").checked) chyby.push(T("potvrďte souhlas"));
    if (chyby.length) { err.textContent = chybyText(chyby); err.style.display = "block"; return; }
    err.style.display = "none";
    const btn = form.querySelector("button[type=submit]"); btn.disabled = true; btn.textContent = T("Odesílám…");
    const done = $("#bDone");
    const hotovo = html => { done.innerHTML = html; done.style.display = "block"; done.scrollIntoView({ behavior: "smooth", block: "start" }); };
    const data = {
      varianta_id: sel.value, den: den.value, okno: $("#bOkno").value, atmosfera: ($("#bAtm") || {}).value || "",
      jmeno: $("#bJmeno").value.trim(), prijmeni: $("#bPrijmeni").value.trim(), telefon: $("#bTel").value.trim(),
      email: $("#bMail").value.trim(), poznamka: ($("#bPozn").value.trim() + (JAZYK ? " [" + JAZYK + "]" : "")).trim(),
      poukaz: (($("#bPoukaz") || {}).value || "").trim(), gdpr: 1, web: $("#bWeb").value
    };
    const prijato = () => { form.querySelectorAll(".step").forEach(x => x.style.display = "none");
      hotovo(`<span class="eyebrow">${window.Voucher.esc(T("Přijato"))}</span><h3>${window.Voucher.esc(T("Děkujeme, ozveme se vám"))}</h3>
        <p>${T("Recepce vám zavolá na <b>{tel}</b> a domluví přesný čas. Termín je závazný až po našem potvrzení.", { tel: window.Voucher.esc(data.telefon) })}</p>
        <div class="btns"><a class="btn btn-gold" href="tel:+420778429329">${window.Voucher.esc(T("Zavolat hned"))}</a></div>`); };
    const zavolejte = nadpis => { btn.disabled = false; btn.textContent = T("Odeslat");
      hotovo(`<span class="eyebrow">${window.Voucher.esc(T("Nepodařilo se"))}</span><h3>${window.Voucher.esc(nadpis)}</h3>
        <p>${T("Zavolejte nám prosím na <a href=\"tel:+420778429329\">+420 778 429 329</a>, rádi vám termín domluvíme.")}</p>`); };
    // Záloha pro výpadek rezervačního systému: když je masage.dvere.app nedostupný, pošle poptávku
    // nezávislou cestou (Formsubmit) na e-mail salonu, ať se neztratí. Neběží u chyb validace.
    const zalohaEmail = () => {
      const masaz = (sel.options[sel.selectedIndex] || {}).text || ("#" + data.varianta_id);
      const oknoEl = $("#bOkno"), okno = (oknoEl.options[oknoEl.selectedIndex] || {}).text || data.okno;
      return fetch("https://formsubmit.co/ajax/salon@thaimost.cz", {
        method: "POST", headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify({
          _subject: "Záložní poptávka z webu (rezervační systém byl nedostupný)", _template: "table", _captcha: "false",
          "Jméno a příjmení": (data.jmeno + " " + data.prijmeni).trim(), "Telefon": data.telefon, "E-mail": data.email || "—",
          "Masáž": masaz, "Kdy by se hodilo": [data.den, okno].filter(Boolean).join(" · "), "Poznámka": data.poznamka || "—", "Poukaz k uplatnění": data.poukaz || "—",
          "Upozornění": "Tato poptávka dorazila záložní cestou, protože rezervační systém masage.dvere.app byl v tu chvíli nedostupný. NENÍ uložená v kartotéce – ozvěte se zákazníkovi a termín zapište ručně."
        })
      }).then(r => r.ok).catch(() => false);
    };
    window.ThaiApi.post("/api/zajem", data).then(r => {
      if (!r.ok) throw new Error(r.chyba || "chyba");
      prijato();
    }).catch(ex => {
      const nedostupne = ex.message === "timeout" || ex.message === "Failed to fetch";
      if (nedostupne) zalohaEmail().then(ok => ok ? prijato() : zavolejte(T("Objednávání je dočasně nedostupné")));
      else zavolejte(serverMsg(ex.message));
    });
  });
})();

/* Hudba na pozadí: hraje sama hned po načtení, pokud to prohlížeč dovolí; jinak se spustí při prvním
   klepnutí, kliknutí nebo stisku klávesy (prohlížeče zvuk bez akce návštěvníka blokují). Tlačítko Hudba ji vypne / zapne,
   volba se pamatuje. Úvodní video je bez zvuku. */
(function(){
  var a=document.getElementById('bgMusic'), b=document.getElementById('heroSound');
  if(!a||!b) return;
  var T=window.T, KEY='tmHudba', VOL=0.35, fadeT=null;
  function vypnuto(){ try{ return localStorage.getItem(KEY)==='0'; }catch(e){ return false; } }
  function uloz(v){ try{ localStorage.setItem(KEY,v); }catch(e){} }
  function stav(on){
    b.setAttribute('aria-pressed',on?'true':'false');
    b.setAttribute('aria-label',on?T('Vypnout hudbu'):T('Zapnout hudbu'));
  }
  function fade(to,done){
    clearInterval(fadeT);
    fadeT=setInterval(function(){
      var v=a.volume+(to>a.volume?0.03:-0.05);
      if((to>a.volume&&v>=to)||(to<=a.volume&&v<=to)){ a.volume=Math.max(0,Math.min(1,to)); clearInterval(fadeT); if(done)done(); }
      else a.volume=Math.max(0,Math.min(1,v));
    },80);
  }
  function hraj(){
    a.volume=0; var p=a.play();
    if(p&&p.then) return p.then(function(){ stav(true); fade(VOL); odpoj(); }, function(){ stav(false); });
    stav(true); fade(VOL); odpoj(); return Promise.resolve();
  }
  function zastav(){ fade(0,function(){ a.pause(); }); stav(false); }
  var udalosti=['pointerdown','touchend','keydown','click'];
  function prvni(e){ if(e&&e.target&&e.target.closest&&e.target.closest('#heroSound')) return; if(!vypnuto()&&a.paused) hraj(); }
  function odpoj(){ udalosti.forEach(function(u){ document.removeEventListener(u,prvni,true); }); }
  stav(false);
  if(!vypnuto()){
    hraj();
    udalosti.forEach(function(u){ document.addEventListener(u,prvni,true); });
  }
  b.addEventListener('click',function(){
    if(a.paused){ uloz('1'); hraj(); } else { uloz('0'); zastav(); }
  });
  // při zapnutí zvuku videa atmosfér hudbu ztlumit, skrytá karta prohlížeče = pauza
  window.tmHudbaPauza=function(){ if(!a.paused){ zastav(); } };
  document.addEventListener('visibilitychange',function(){
    if(document.hidden){ if(!a.paused){ a.pause(); a.dataset.skryto='1'; } }
    else if(a.dataset.skryto){ delete a.dataset.skryto; a.play().catch(function(){}); }
  });
})();

/* Plovoucí dárkový poukaz (vánoční nabídka): po klepnutí na × se do konce návštěvy skryje */
(function(){
  var g=document.getElementById('giftFloat'), x=document.getElementById('giftX');
  if(!g||!x) return;
  try{ if(sessionStorage.getItem('tmPoukazSkryt')==='1'){ g.remove(); return; } }catch(e){}
  setTimeout(function(){ g.classList.add('on'); },1200);
  x.addEventListener('click',function(){ g.classList.remove('on'); try{ sessionStorage.setItem('tmPoukazSkryt','1'); }catch(e){} setTimeout(function(){ g.remove(); },400); });
  // u sekce poukazů samotné ho schovat, ať nepřekáží
  var sec=document.getElementById('poukazy');
  if(sec&&'IntersectionObserver' in window) new IntersectionObserver(function(es){ es.forEach(function(e){ g.classList.toggle('pryc',e.isIntersecting); }); },{threshold:.15}).observe(sec);
})();

/* Tematická masérna: zapíná/vypíná se v kartotéce (Správa → Nastavení); web se zeptá /api/stav a při vypnutí skryje sekci, kartu i výběr v objednávce.
   Poslední známý stav si pamatuje v prohlížeči, aby po vypnutí sekce při dalším načtení neprobliknula. */
(function(){
  var H=document.documentElement, K='tm_tematicka';
  try{ if(localStorage.getItem(K)==='0') H.classList.add('tm-off'); }catch(e){}
  function nastav(on){ H.classList.toggle('tm-off',!on); try{ localStorage.setItem(K,on?'1':'0'); }catch(e){}
    var sel=document.getElementById('bAtm'); if(!on&&sel) sel.value=''; }
  if(window.ThaiApi&&ThaiApi.get) ThaiApi.get('/api/stav').then(function(r){ if(r&&r.ok&&typeof r.tematicka==='boolean') nastav(r.tematicka); if(r&&r.ok&&typeof r.platby_min==='number') window.TM_PLATBY_MIN=r.platby_min; }).catch(function(){});
})();

/* Tematická masérna: video se spustí, až je vidět; klepnutí na atmosféru ji předvyplní v objednávce */
(function(){
  var v=document.getElementById('atmoVideo'), b=document.getElementById('atmoSound'), sel=document.getElementById('bAtm');
  if(v){
    var T=window.T;
    if('IntersectionObserver' in window){
      new IntersectionObserver(function(es){es.forEach(function(e){ if(e.isIntersecting){ v.play().catch(function(){}); } else { v.pause(); } });},{threshold:.35}).observe(v);
    } else { v.play().catch(function(){}); }
    if(b) b.addEventListener('click',function(){
      var on=v.muted; v.muted=!on; if(on){v.volume=0.6; v.play().catch(function(){}); if(window.tmHudbaPauza) window.tmHudbaPauza();}
      b.setAttribute('aria-pressed',on?'true':'false');
      b.setAttribute('aria-label',on?T('Vypnout zvuk videa'):T('Zapnout zvuk videa'));
      b.querySelector('span').textContent=on?T('Ztišit'):T('Zvuk');
    });
  }
  if(sel) document.querySelectorAll('.atmo-card[data-atm]').forEach(function(a){
    a.addEventListener('click',function(){ sel.value=a.dataset.atm; });
  });
})();
