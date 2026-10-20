/* Spojení webu s kartotékou salonu (NAS, masage.dvere.app).
   Adresa je v data.js → THAI.api. Když kartotéka neodpovídá, web funguje dál
   (poukaz nouzově e-mailem, u objednání se ukáže telefon). */
(function () {
  const BASE = (window.THAI && window.THAI.api) || "";
  const timeout = (ms, p) => Promise.race([p, new Promise((_, rej) => setTimeout(() => rej(new Error("timeout")), ms))]);

  async function post(cesta, data) {
    if (!BASE) throw new Error("api není nastaveno");
    const r = await timeout(12000, fetch(BASE + cesta, {
      method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(data)
    }));
    return r.json();
  }
  async function get(cesta) {
    if (!BASE) throw new Error("api není nastaveno");
    const r = await timeout(8000, fetch(BASE + cesta));
    return r.json();
  }
  window.ThaiApi = { post, get, BASE };
})();
