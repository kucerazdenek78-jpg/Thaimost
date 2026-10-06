(function () {
  const D = window.THAI, V = window.Voucher, $ = s => document.querySelector(s);
  const KEY = "thaimost_poukazy";
  const load = () => { try { return JSON.parse(localStorage.getItem(KEY)) || []; } catch { return []; } };
  const save = l => { try { localStorage.setItem(KEY, JSON.stringify(l)); } catch { alert("Evidenci se nepodařilo uložit (prohlížeč blokuje úložiště)."); } };

  const st = { typ: "hodnota", motiv: "teak" };
  const ABC = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
  function novyKod() {
    const used = new Set(load().map(p => p.kod));
    let k;
    do {
      const r = crypto.getRandomValues(new Uint32Array(5));
      k = "TM-" + String(new Date().getFullYear()).slice(2) + "-" + Array.from(r, x => ABC[x % ABC.length]).join("");
    } while (used.has(k));
    $("#kod").value = k;
  }
  novyKod();

  $("#sel").innerHTML = D.sluzby.map(s => `<option value="${s.id}">${V.esc(s.nazev)}</option>`).join("");
  const sl = () => D.sluzby.find(s => s.id === $("#sel").value);
  const fillVar = () => { $("#var").innerHTML = sl().ceny.map((c, i) => `<option value="${i}">${V.esc(c[0])} – ${V.kc(c[1])}</option>`).join(""); };
  fillVar();

  const czDate = iso => iso ? iso.split("-").reverse().map(x => +x).join(". ") : "";
  function data() {
    const s = sl(), c = s.ceny[+$("#var").value || 0];
    return {
      typ: st.typ, hodnota: +$("#hodnota").value || 0,
      sluzba: s.nazev, varianta: c[0], cena: st.typ === "sluzba" ? c[1] : (+$("#hodnota").value || 0),
      komu: $("#komu").value.trim(), od: $("#od").value.trim(), venovani: $("#ven").value.trim(),
      motiv: st.motiv, kod: $("#kod").value, platnostIso: $("#plat").value, platnost: czDate($("#plat").value),
      objednatel: $("#obj").value.trim(), zaplaceno: $("#zapl").value
    };
  }
  const update = () => {
    $("#bH").style.display = st.typ === "hodnota" ? "" : "none";
    $("#bS").style.display = st.typ === "sluzba" ? "" : "none";
    $("#tisk").innerHTML = V.render(data());
  };

  document.querySelectorAll('input[name="typ"]').forEach(r => r.addEventListener("change", () => { st.typ = r.value; update(); }));
  $("#sel").addEventListener("change", () => { fillVar(); update(); });
  ["#var", "#hodnota", "#komu", "#od", "#ven", "#plat"].forEach(id => $(id).addEventListener("input", update));
  document.querySelectorAll(".quick [data-m]").forEach(b => b.addEventListener("click", () => {
    const d = new Date(); d.setMonth(d.getMonth() + +b.dataset.m);
    $("#plat").value = d.toISOString().slice(0, 10); update();
  }));
  $("#novy").addEventListener("click", () => { novyKod(); update(); });
  $("#motivy").addEventListener("click", e => {
    const b = e.target.closest("button"); if (!b) return;
    st.motiv = b.dataset.m; document.querySelectorAll("#motivy button").forEach(x => x.classList.toggle("on", x === b)); update();
  });

  function check() {
    const d = data(), ch = [];
    if (!d.cena || d.cena < 100) ch.push("hodnota min. 100 Kč");
    if (!d.platnostIso) ch.push("platnost");
    $("#err").textContent = ch.length ? "Doplňte: " + ch.join(", ") : "";
    $("#err").style.display = ch.length ? "block" : "none";
    return ch.length ? null : d;
  }
  $("#f").addEventListener("submit", e => {
    e.preventDefault();
    const d = check(); if (!d) return;
    const l = load();
    l.unshift({ ...d, vystaven: new Date().toISOString().slice(0, 10), stav: "platny" });
    save(l); renderTab();
    window.print();
    novyKod(); update();
  });
  $("#jenTisk").addEventListener("click", () => { if (check()) window.print(); });

  /* evidence */
  function stav(p) {
    if (p.stav === "uplatnen") return `<span class="st st-use">uplatněn ${V.esc(p.uplatnen || "")}</span>`;
    if (p.platnostIso && p.platnostIso < new Date().toISOString().slice(0, 10)) return '<span class="st st-exp">propadlý</span>';
    return '<span class="st st-ok">platný</span>';
  }
  function renderTab() {
    const q = $("#hledat").value.trim().toLowerCase();
    const l = load().filter(p => !q || JSON.stringify(p).toLowerCase().includes(q));
    $("#tb").innerHTML = l.length ? l.map(p => `<tr>
      <td><code>${V.esc(p.kod)}</code></td><td>${czDate(p.vystaven)}</td>
      <td>${p.typ === "sluzba" ? V.esc(p.sluzba + " – " + p.varianta) : "na částku"}<br><b>${V.kc(p.cena)}</b></td>
      <td>${V.esc(p.komu || "-")} / ${V.esc(p.od || "-")}${p.objednatel ? `<br><small>${V.esc(p.objednatel)}</small>` : ""}</td>
      <td>${V.esc(p.platnost)}</td><td>${V.esc(p.zaplaceno)}</td><td>${stav(p)}</td>
      <td>${p.stav !== "uplatnen" ? `<button data-a="use" data-k="${p.kod}">Uplatnit</button>` : `<button data-a="undo" data-k="${p.kod}">Vrátit</button>`}
          <button data-a="print" data-k="${p.kod}">Tisk</button><button data-a="del" data-k="${p.kod}">Smazat</button></td></tr>`).join("")
      : `<tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px">${q ? "Nic nenalezeno – takový poukaz v evidenci není." : "Zatím žádné poukazy."}</td></tr>`;
  }
  $("#hledat").addEventListener("input", renderTab);
  $("#tb").addEventListener("click", e => {
    const b = e.target.closest("button"); if (!b) return;
    const l = load(), p = l.find(x => x.kod === b.dataset.k); if (!p) return;
    if (b.dataset.a === "use") { if (!confirm(`Označit poukaz ${p.kod} jako uplatněný?`)) return; p.stav = "uplatnen"; p.uplatnen = new Date().toLocaleDateString("cs-CZ"); }
    if (b.dataset.a === "undo") { p.stav = "platny"; delete p.uplatnen; }
    if (b.dataset.a === "del") { if (!confirm(`Smazat poukaz ${p.kod} z evidence?`)) return; l.splice(l.indexOf(p), 1); }
    if (b.dataset.a === "print") { $("#tisk").innerHTML = V.render(p); window.print(); setTimeout(update, 500); return; }
    save(l); renderTab();
  });

  const COLS = ["kod", "vystaven", "typ", "sluzba", "varianta", "cena", "komu", "od", "venovani", "motiv", "platnostIso", "platnost", "objednatel", "zaplaceno", "stav", "uplatnen"];
  $("#csv").addEventListener("click", () => {
    const rows = [COLS.join(";")].concat(load().map(p => COLS.map(c => `"${String(p[c] ?? "").replace(/"/g, '""')}"`).join(";")));
    const a = document.createElement("a");
    a.href = URL.createObjectURL(new Blob(["﻿" + rows.join("\n")], { type: "text/csv" }));
    a.download = "poukazy-thaimost-" + new Date().toISOString().slice(0, 10) + ".csv"; a.click();
  });
  $("#imp").addEventListener("change", async e => {
    const f = e.target.files[0]; if (!f) return;
    const lines = (await f.text()).replace(/^﻿/, "").split(/\r?\n/).filter(Boolean);
    const head = lines.shift().split(";");
    const parse = line => (line.match(/("([^"]|"")*"|[^;]*)(;|$)/g) || []).map(s => s.replace(/;$/, "").replace(/^"|"$/g, "").replace(/""/g, '"'));
    const l = load(), have = new Set(l.map(p => p.kod)); let n = 0;
    lines.forEach(line => { const v = parse(line), p = {}; head.forEach((h, i) => p[h] = v[i]); p.cena = +p.cena; if (p.kod && !have.has(p.kod)) { l.push(p); n++; } });
    save(l); renderTab(); alert(`Načteno ${n} nových poukazů.`); e.target.value = "";
  });

  update(); renderTab();
})();
