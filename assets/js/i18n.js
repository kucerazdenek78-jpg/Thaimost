/* Jazyky webu (cs / en / de).
   Jazyk se pozná z <html lang>. Čeština je zdroj: T('český text') ji vrací beze změny, v en/de hledá překlad ve
   slovníku window.THAI_I18N[lang] (assets/i18n/en.js, de.js – generuje je tools/i18n.py z tools/i18n/*.json).
   Chybějící překlad = zobrazí se česky (nic se nerozbije). {zástupce} se nahradí hodnotou z druhého argumentu.
   TK('…') jen označí text, který se překládá až za běhu (např. hlášky ze serveru kartotéky). */
(function () {
  const lang = (document.documentElement.lang || "cs").slice(0, 2).toLowerCase();
  const dict = (window.THAI_I18N && window.THAI_I18N[lang]) || {};
  const loc = { cs: "cs-CZ", en: "en-GB", de: "de-DE" }[lang] || "cs-CZ";
  const cur = lang === "cs" ? "Kč" : "CZK";
  const has = Object.prototype.hasOwnProperty;

  function T(s, v) {
    let r = lang !== "cs" && has.call(dict, s) ? dict[s] : s;
    if (v) r = r.replace(/\{(\w+)\}/g, (m, k) => (k in v ? v[k] : m));
    return r;
  }
  T.lang = lang;
  T.root = document.documentElement.getAttribute("data-root") || "";   // "" na české stránce, "../" na /en/ a /de/
  T.loc = loc;
  T.money = n => Number(n).toLocaleString(loc) + " " + cur;          // podle jazyka stránky (Kč / CZK)
  T.moneyCs = n => Number(n).toLocaleString("cs-CZ") + " Kč";        // vždy česky (poukaz vystavuje salon česky)

  window.T = T;
  window.TK = s => s;

  /* Hlášky, které vrací server kartotéky (česky) – překládají se podle textu. */
  window.THAI_SERVER_MSG = [
    TK("Příliš mnoho pokusů, zkuste to prosím později nebo zavolejte."),
    TK("Příliš mnoho pokusů, zkuste to prosím později."),
    TK("Příliš mnoho pokusů, zkuste to prosím za hodinu."),
    TK("Příliš mnoho pokusů pro toto číslo, zkuste to prosím za hodinu nebo nám zavolejte."),
    TK("Vyplňte jméno, příjmení a telefon."),
    TK("Vyplňte jméno a telefon."),
    TK("Potřebujeme souhlas se zpracováním údajů."),
    TK("Vyberte prosím masáž."),
    TK("Zkontrolujte hodnotu poukazu."),
    TK("Chyba serveru, zkuste to prosím později nebo zavolejte."),
    TK("Údaje nesedí. Zkontrolujte kartu, telefon a první tři písmena příjmení, nebo nám zavolejte.")
  ];
})();
