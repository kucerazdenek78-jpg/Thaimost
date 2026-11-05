(function () {
  const D = window.THAI, V = window.Voucher, T = window.T, TK = window.TK, $ = s => document.querySelector(s);
  const LOTUS = '<svg viewBox="0 0 120 80"><g fill="none" stroke="currentColor" stroke-width="1.5"><path d="M60 70 C52 52 52 30 60 10 C68 30 68 52 60 70Z"/><path d="M60 70 C46 60 36 44 34 26 C48 32 58 48 60 70Z"/><path d="M60 70 C74 60 84 44 86 26 C72 32 62 48 60 70Z"/><path d="M60 70 C40 68 22 58 12 42 C30 42 48 52 60 70Z"/><path d="M60 70 C80 68 98 58 108 42 C90 42 72 52 60 70Z"/></g></svg>';
  /* Odpovědi pro salon (e-mail, kartotéka) jsou vždy česky; to, co vidí zákazník, se překládá. */
  const CS = { t: s => s, m: T.moneyCs }, LO = { t: T, m: T.money };
  const JAZYK = { en: "English", de: "Deutsch" }[T.lang] || "";

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

  /* služby */
  const grid = $("#svGrid");
  function renderSluzby(kat) {
    grid.innerHTML = D.sluzby.filter(s => kat === "vse" || s.kat === kat).map(s => {
      const od = Math.min(...s.ceny.map(c => c[1]));
      const nazev = T(s.nazev);
      const img = s.foto
        ? `<img src="${/^https?:/.test(s.foto) ? s.foto : T.root + D.img + s.foto}" alt="${V.esc(T("{nazev} – thajské masáže Most, Thai Most", { nazev }))}" loading="lazy" onerror="this.remove()">${s.ilustracni ? '<span class="sv-ilu">' + V.esc(T("VZOR")) + "</span>" : ""}`
        : "";
      return `<article class="sv reveal in">
        <div class="sv-img"><div class="ph">${LOTUS}</div>${img}${s.top ? '<span class="sv-tag">' + V.esc(T("Oblíbené")) + "</span>" : ""}</div>
        <div class="sv-body">
          <h3>${V.esc(nazev)}</h3>
          <p>${V.esc(T(s.popis))}</p>
          ${s.pozn ? `<div class="sv-note">${V.esc(T(s.pozn))}</div>` : ""}
          <ul class="prices">${s.ceny.map(c => `<li><span>${V.esc(T(c[0]))}</span><b>${V.kc(c[1])}</b></li>`).join("")}</ul>
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
  const iban = D.platba.iban || V.ibanZUctu(D.platba.cislo_uctu);
  if (iban) $("#platba").insertAdjacentHTML("beforeend", '<option value="prevod">' + V.esc(T("Převodem / QR platbou předem")) + "</option>");

  const st = { typ: "hodnota", hodnota: 1000, sluzbaId: D.sluzby[0].id, varIdx: 0 };
  const HODNOTY = [500, 790, 1000, 1500, 2000];
  /* texty voleb, které se odesílají salonu (vždy česky; zákazník vidí svůj jazyk) */
  const PREVZETI_CS = { salon: TK("Vyzvednu osobně v salonu"), email: TK("Pošlete mi ho e-mailem k vytištění") };
  const PLATBA_CS = { salon: TK("V salonu při vyzvednutí"), prevod: TK("Převodem / QR platbou předem") };

  $("#chips").innerHTML = HODNOTY.map(h => `<button type="button" data-h="${h}">${V.kc(h)}</button>`).join("") + `<button type="button" data-h="x">${V.esc(T("Jiná částka"))}</button>`;
  $("#selSluzba").innerHTML = ["masaze", "wellness"].map(k =>
    `<optgroup label="${k === "masaze" ? V.esc(T("Masáže")) : V.esc(T("Wellness a rituály"))}">` +
    D.sluzby.filter(s => s.kat === k).map(s => `<option value="${s.id}">${V.esc(T(s.nazev))}</option>`).join("") + "</optgroup>").join("");

  const sluzba = () => D.sluzby.find(s => s.id === st.sluzbaId);
  function fillVar() {
    $("#selVar").innerHTML = sluzba().ceny.map((c, i) => `<option value="${i}">${V.esc(T(c[0]))} – ${V.kc(c[1])}</option>`).join("");
    st.varIdx = 0;
  }
  fillVar();

  function cena() {
    if (st.typ === "sluzba") return sluzba().ceny[st.varIdx][1];
    return st.hodnota || 0;
  }
  /* d = údaje poukazu česky (pro náhled, který odpovídá poukazu vystavenému salonem, a pro objednávku) */
  function data() {
    const s = sluzba();
    return {
      typ: st.typ, hodnota: st.hodnota,
      sluzba: s.nazev, varianta: s.ceny[st.varIdx][0],
      komu: $("#komu").value.trim(), od: $("#od").value.trim(), venovani: $("#venovani").value.trim()
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
    V.mount($("#vPreview"), data());
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
    const vs = String(n.getFullYear()).slice(2) + p2(n.getMonth() + 1) + p2(n.getDate()) + p2(n.getHours()) + p2(n.getMinutes());
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
    const text = souhrn(CS), textZak = souhrn(LO);

    const mailto = `mailto:${D.salon.email}?subject=${encodeURIComponent("Objednávka poukazu č. " + vs)}&body=${encodeURIComponent(text)}`;
    let qr = "";
    if (platba === "prevod" && iban) {
      const svg = V.qrSvg(V.spd({ iban, castka: cena(), vs, zprava: "Poukaz " + vs }), 140);
      qr = `<div class="qrpay">${svg}<div><b>${V.esc(T("QR platba"))}</b><br>${V.esc(T("Částka"))}: ${V.kc(cena())}<br>${V.esc(T("Účet"))}: ${V.esc(D.platba.cislo_uctu || iban)}<br>${V.esc(T("VS"))}: ${vs}<br><span class="hint">${V.esc(T("Naskenujte v aplikaci banky."))}</span></div></div>`;
    }
    const done = $("#done");
    const btn = e.submitter || $("#vForm button[type=submit]");
    if (btn) { btn.disabled = true; btn.textContent = T("Odesílám…"); }

    /* 1) do kartotéky salonu (NAS); 2) když to nejde, nouzově e-mailem */
    const poznamka = ($("#pozn").value.trim() + (JAZYK ? " [" + JAZYK + "]" : "")).trim();
    const payload = {
      jmeno: $("#jmeno").value.trim(), telefon: $("#tel").value.trim(), email: $("#mail").value.trim(),
      hodnota: cena(), sluzba: d.typ === "sluzba" ? d.sluzba + " (" + d.varianta + ")" : "",
      komu: d.komu, od: d.od, venovani: d.venovani, prevzeti: PREVZETI_CS[prevzeti] || "",
      platba: PLATBA_CS[platba] || "", poznamka, cislo: vs, gdpr: 1,
      web: $("#web") ? $("#web").value : ""
    };
    window.ThaiApi.post("/api/poukaz", payload).then(r => {
      if (!r.ok) throw new Error(r.chyba || "chyba");
      const kontakt = payload.email
        ? T("Děkujeme! Salon se vám ozve na telefon <b>{tel}</b> nebo e-mail <b>{mail}</b> a domluví převzetí.", { tel: V.esc(payload.telefon), mail: V.esc(payload.email) })
        : T("Děkujeme! Salon se vám ozve na telefon <b>{tel}</b> a domluví převzetí.", { tel: V.esc(payload.telefon) });
      done.innerHTML = `<span class="eyebrow">${V.esc(T("Hotovo"))}</span><h3>${V.esc(T("Objednávka č. {vs} přijata", { vs }))}</h3>
        <p>${kontakt}${platba === "prevod" ? " " + T("Platbu proveďte podle QR kódu níže.") : ""}</p>
        ${qr}
        <div class="sum">${V.esc(textZak)}</div>
        <div class="btns"><a class="btn btn-gold" href="tel:+420778429329">${V.esc(T("Zavolat"))}</a></div>`;
    }).catch(() => {
      done.innerHTML = `<span class="eyebrow">${V.esc(T("Hotovo"))}</span><h3>${V.esc(T("Objednávka č. {vs}", { vs }))}</h3>
        <p>${T("Objednávku se nepodařilo odeslat automaticky. Otevřeli jsme vám e-mail s objednávkou – stačí ho <b>odeslat</b>. Pokud se neotevřel, použijte tlačítko níže nebo objednávku zkopírujte, případně zavolejte.")}</p>
        ${qr}
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
      o.textContent = `${T(s.nazev)} – ${T(pop)} – ${T.money(c)}`; sel.appendChild(o);
    }));
  }
  window.ThaiApi.get("/api/sluzby").then(r => {
    if (!r.ok) throw 0;
    r.varianty.forEach(v => {
      const o = document.createElement("option"); o.value = v.id;
      o.textContent = `${T(v.nazev)} – ${T(v.popis)} – ${T.money(v.cena)}`; sel.appendChild(o);
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
    window.ThaiApi.post("/api/zajem", {
      varianta_id: sel.value, den: den.value, okno: $("#bOkno").value, atmosfera: ($("#bAtm") || {}).value || "",
      jmeno: $("#bJmeno").value.trim(), prijmeni: $("#bPrijmeni").value.trim(), telefon: $("#bTel").value.trim(),
      email: $("#bMail").value.trim(), poznamka: ($("#bPozn").value.trim() + (JAZYK ? " [" + JAZYK + "]" : "")).trim(), gdpr: 1, web: $("#bWeb").value
    }).then(r => {
      if (!r.ok) throw new Error(r.chyba || "chyba");
      form.querySelectorAll(".step").forEach(x => x.style.display = "none");
      hotovo(`<span class="eyebrow">${window.Voucher.esc(T("Přijato"))}</span><h3>${window.Voucher.esc(T("Děkujeme, ozveme se vám"))}</h3>
        <p>${T("Recepce vám zavolá na <b>{tel}</b> a domluví přesný čas. Termín je závazný až po našem potvrzení.", { tel: window.Voucher.esc($("#bTel").value.trim()) })}</p>
        <div class="btns"><a class="btn btn-gold" href="tel:+420778429329">${window.Voucher.esc(T("Zavolat hned"))}</a></div>`);
    }).catch(ex => {
      btn.disabled = false; btn.textContent = T("Odeslat");
      const nedostupne = ex.message === "timeout" || ex.message === "Failed to fetch";
      hotovo(`<span class="eyebrow">${window.Voucher.esc(T("Nepodařilo se"))}</span><h3>${window.Voucher.esc(nedostupne ? T("Objednávání je dočasně nedostupné") : serverMsg(ex.message))}</h3>
        <p>${T("Zavolejte nám prosím na <a href=\"tel:+420778429329\">+420 778 429 329</a>, rádi vám termín domluvíme.")}</p>`);
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
