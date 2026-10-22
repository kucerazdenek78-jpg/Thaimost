/* Ověření razítek a nejbližšího termínu bez přihlášení: kód karty NEBO telefon + první 3 písmena příjmení.
   Odpověď neobsahuje jméno ani kontakt (jen razítka a datum termínu). */
(function () {
  const f = document.getElementById("mForm");
  if (!f || !window.ThaiApi) return;
  const $ = s => f.querySelector(s), err = $("#mErr"), vys = $("#mVys");
  let mod = "karta";

  f.querySelectorAll(".m-tabs button").forEach(b => b.addEventListener("click", () => {
    mod = b.dataset.m;
    f.querySelectorAll(".m-tabs button").forEach(x => x.classList.toggle("on", x === b));
    f.querySelectorAll("[data-p]").forEach(p => { p.hidden = p.dataset.p !== mod; });
    err.textContent = ""; vys.innerHTML = "";
  }));

  const DNY = ["neděle", "pondělí", "úterý", "středa", "čtvrtek", "pátek", "sobota"];
  function termin(t) {
    const d = new Date(t.replace(" ", "T"));
    if (isNaN(d)) return "";
    const p = n => String(n).padStart(2, "0");
    return `${DNY[d.getDay()]} ${d.getDate()}. ${d.getMonth() + 1}. ${d.getFullYear()} v ${d.getHours()}:${p(d.getMinutes())}`;
  }
  function stamps(r) {
    let h = "";
    for (let i = 0; i < r.po; i++) h += `<i class="${i < r.razitka ? "on" : ""}"></i>`;
    return h;
  }

  f.addEventListener("submit", async e => {
    e.preventDefault();
    err.textContent = ""; vys.innerHTML = "";
    const data = mod === "karta" ? { karta: $("#mKarta").value } : { telefon: $("#mTel").value, prijmeni: $("#mPrij").value };
    if (mod === "karta" ? data.karta.trim().length < 10 : (data.telefon.replace(/\D/g, "").length < 9 || data.prijmeni.replace(/[^\p{L}]/gu, "").length < 3)) {
      err.textContent = mod === "karta" ? "Zadejte kód z karty (10 znaků)." : "Zadejte telefon a první tři písmena příjmení.";
      return;
    }
    const btn = f.querySelector(".btn"); btn.disabled = true;
    try {
      const r = await window.ThaiApi.post("/api/moje", data);
      if (!r.ok) { err.textContent = r.chyba || "Nepodařilo se ověřit."; return; }
      vys.innerHTML = `<div class="m-stamps">${stamps(r)}</div>
        <p><b>${r.razitka} / ${r.po}</b> razítek${r.zdarma > 0 ? ` · <b>🎉 máte nárok na masáž zdarma${r.zdarma > 1 ? " (" + r.zdarma + "×)" : ""}</b>` : ""}</p>
        <p>${r.termin ? "Nejbližší termín: <b>" + termin(r.termin) + "</b>" : "Zatím nemáte domluvený žádný termín."}</p>`;
    } catch (x) {
      err.textContent = "Teď se nepodařilo spojit se salonem. Zkuste to později nebo zavolejte.";
    } finally { btn.disabled = false; }
  });
})();
