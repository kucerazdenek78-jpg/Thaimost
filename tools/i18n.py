#!/usr/bin/env python3
"""Jazykové verze webu (angličtina, němčina) z české index.html.

Zdrojem pravdy zůstává česká stránka (index.html + assets/js/data.js). Nástroj
  * vytáhne z nich všechny české texty (HTML, atributy, meta, JSON-LD, texty ve skriptech, data.js),
  * z hotových překladů (tools/i18n/en.json, de.json: {"český text": "překlad"}) vygeneruje
      en/index.html, de/index.html          – statické stránky pro vyhledávače i návštěvníky
      assets/i18n/en.js, de.js              – slovník pro skripty (formuláře, ceník, hlášky)
  * zkontroluje překlady (zástupce {x}, HTML značky, čísla).

Použití (z kořene repa):
  python3 tools/i18n.py todo [--size 40]   vypíše dávky českých textů, které ještě nejsou přeložené
  python3 tools/i18n.py merge en|de preklady.json davka-N.json   přidá překlady (pole ve stejném pořadí jako dávka)
  python3 tools/i18n.py check              zkontroluje slovníky
  python3 tools/i18n.py build              vygeneruje stránky a slovníky pro skripty
Potřebuje: pip install beautifulsoup4
"""
import json, re, subprocess, sys
from pathlib import Path
from urllib.parse import quote, unquote
from bs4 import BeautifulSoup, NavigableString, Comment, Tag

ROOT = Path(__file__).resolve().parent.parent
DICT_DIR = ROOT / "tools" / "i18n"
SITE = "https://www.thaimost.cz"
LANGS = {
    "en": {"og": "en_GB", "deepl": "EN-GB", "name": "English"},
    "de": {"og": "de_DE", "deepl": "DE", "name": "Deutsch"},
}
OG_ALL = {"cs": "cs_CZ", "en": "en_GB", "de": "de_DE"}

SKIP = {"script", "style", "svg", "noscript", "template", "cite"}   # cite = jména autorů recenzí
INLINE = {"a", "strong", "b", "em", "i", "span", "br", "small", "u", "mark", "abbr", "code"}
ATTRS = ("alt", "title", "placeholder", "aria-label")
META = {("name", "description"), ("property", "og:title"), ("property", "og:description"),
        ("property", "og:image:alt"), ("name", "twitter:title"), ("name", "twitter:description")}
# texty, které se nepřekládají (názvy značek, adresy…)
UPPER_KEYS = {"VZOR"}                 # českým klíčem je text velkými písmeny (štítek na fotce) → překlad také
NO_TRANSLATE = {"stepanova.hanka@centrum.cz", "SNP 2654/26, 434 01 Most", "Slovenského národního povstání 2654/26<br/>434 01 Most", "Most", "Nuat phaen boran, นวดแผนโบราณ", "Thai Most", "WhatsApp", "Facebook", "Wat Pho", "Google", "Bezpečné dveře – bezpecne-dvere.cz",
                "Jazyk / Language / Sprache", "Thai Most – thajské masáže Most", "Thajské masáže Most"}
ALT_SUFFIX = " – thajské masáže Most, Thai Most"
PRICE = re.compile(r"^(\d[\d\s ]*)\s*Kč$")
JS_FILES = ["main.js", "moje.js", "skener.js", "i18n.js", "voucher.js"]
ROOT_FILES = ("assets/", "favicon.ico", "site.webmanifest", "salon.html", "firma.html", "index.html", "kartoteka")


def norm(s):
    return re.sub(r"\s+", " ", s).strip()


def has_letters(s):
    return re.search(r"[^\W\d_]", s) is not None


def money(n, lang):
    n = int(re.sub(r"\D", "", n))
    s = f"{n:,}"
    return (s.replace(",", ".") if lang == "de" else s) + " CZK"


# ---------------------------------------------------------------- HTML
def inline_ok(el):
    for c in el.children:
        if isinstance(c, Tag):
            if c.name not in INLINE:
                return False
            if c.name != "br":
                if not c.get_text(strip=True) or (c.name != "a" and not has_letters(c.get_text())):
                    return False
                if c.find(lambda t: t.name not in INLINE):
                    return False
    return True


def walk(el, emit):
    """emit(kind, cil, klic) → překlad nebo None. kind: html (obsah prvku), text (textový uzel), attr."""
    if el.name in SKIP or el.get("translate") == "no":
        return
    for a in ATTRS:
        v = el.get(a)
        if isinstance(v, str) and has_letters(v):
            emit("attr", (el, a), norm(v))
    if el.name == "meta":
        for k in ("name", "property"):
            if (k, el.get(k)) in META and el.get("content"):
                emit("attr", (el, "content"), norm(el["content"]))
    texts = [c for c in el.children if type(c) is NavigableString and c.strip()]
    tags = [c for c in el.children if isinstance(c, Tag)]
    if texts and tags and el.name not in ("html", "head", "body") and inline_ok(el):
        emit("html", el, norm(el.decode_contents()))
        return
    for c in list(el.children):
        if isinstance(c, Tag):
            walk(c, emit)
        elif type(c) is NavigableString and has_letters(c):
            emit("text", c, norm(c))


def ld_walk(o, emit, lang):
    if isinstance(o, dict):
        t = o.get("@type")
        for k, v in list(o.items()):
            if isinstance(v, str):
                if k in ("description", "text") or (k == "name" and t in ("Question", "Service", "OfferCatalog")):
                    r = emit("ld", o, v)
                    if r:
                        o[k] = r
                elif k == "priceRange" and lang:
                    o[k] = v.replace("Kč", "CZK")
            else:
                ld_walk(v, emit, lang)
    elif isinstance(o, list):
        for x in o:
            ld_walk(x, emit, lang)


def wa_text(href):
    m = re.match(r"^(https://wa\.me/\d+)\?text=(.*)$", href)
    return (m.group(1), unquote(m.group(2))) if m else None


def transform(soup, tr, lang=None):
    """Projde stránku; tr(klic) → překlad nebo None. Vrací seznam všech klíčů (v pořadí)."""
    seen = []

    def emit(kind, target, key):
        if key in NO_TRANSLATE or not has_letters(key):
            return None
        if kind == "text":
            m = PRICE.match(key)
            if m:
                if lang:
                    lead, tail = re.match(r"^(\s*).*?(\s*)$", str(target), re.S).groups()
                    target.replace_with(NavigableString(lead + money(m.group(1), lang) + tail))
                return None
        if kind == "attr" and target[1] == "alt" and key.endswith(ALT_SUFFIX):
            # alt obrázků služeb: „<název> – thajské masáže Most, Thai Most“ se složí z přeložených částí
            name, suf = key[:-len(ALT_SUFFIX)], ALT_SUFFIX.lstrip(" –")
            seen.extend([name, suf])
            x, y = tr(name), tr(suf)
            if x is None or y is None:
                return None
            target[0]["alt"] = f"{x} – {y}"
            return None
        seen.append(key)
        t = tr(key)
        if t is None:
            return None
        if kind == "text":
            lead, tail = re.match(r"^(\s*).*?(\s*)$", str(target), re.S).groups()
            target.replace_with(NavigableString(lead + t + tail))
        elif kind == "html":
            frag = BeautifulSoup(t, "html.parser")
            target.clear()
            for c in list(frag.contents):
                target.append(c)
        elif kind == "attr":
            el, a = target
            el[a] = t
        return t

    walk(soup, emit)
    # WhatsApp: předvyplněný text zprávy je v odkazu
    for a in soup.find_all("a", href=True):
        w = wa_text(a["href"])
        if w:
            seen.append(w[1])
            t = tr(w[1])
            if t:
                a["href"] = w[0] + "?text=" + quote(t, safe="")
    # JSON-LD
    for s in soup.find_all("script", type="application/ld+json"):
        data = json.loads(s.string)
        ld_walk(data, lambda kind, o, key: (seen.append(norm(key)) or tr(norm(key))) if has_letters(key) and norm(key) not in NO_TRANSLATE else None, lang)
        if lang:
            data["inLanguage"] = lang
            data["url"] = data.get("url") and (SITE + "/" + lang + "/")
            if data["url"] is None:
                del data["url"]
        s.string = "\n" + json.dumps(data, ensure_ascii=False, indent=1) + "\n"
    return seen


# ---------------------------------------------------------------- data.js a skripty
def data_strings():
    out = subprocess.run(["node", "-e", "global.window=global;require('./assets/js/data.js');console.log(JSON.stringify(THAI))"],
                         cwd=ROOT, capture_output=True, text=True, check=True).stdout
    d = json.loads(out)
    res = []
    for s in d["sluzby"]:
        res += [s["nazev"], s["popis"]] + ([s["pozn"]] if s.get("pozn") else []) + [c[0] for c in s["ceny"]]
    return [norm(x) for x in res]


def js_strings():
    rx = re.compile(r'(?:(?<![\w$.])|(?<=window\.))(?:T|TK|t)\(\s*("(?:[^"\\\n]|\\.)*")')
    res = []
    for f in JS_FILES:
        txt = (ROOT / "assets" / "js" / f).read_text(encoding="utf-8")
        for m in rx.finditer(txt):
            res.append(json.loads(m.group(1)))
    return res


def uniq(seq):
    seen, out = set(), []
    for x in seq:
        if x not in seen:
            seen.add(x)
            out.append(x)
    return out


def source():
    return BeautifulSoup((ROOT / "index.html").read_text(encoding="utf-8"), "html.parser")


def all_keys():
    html = uniq(transform(source(), lambda k: None))
    js = uniq(js_strings() + data_strings())
    return html, js, uniq(html + js)


def load(lang):
    p = DICT_DIR / f"{lang}.json"
    return json.loads(p.read_text(encoding="utf-8")) if p.exists() else {}


def save(lang, d):
    DICT_DIR.mkdir(parents=True, exist_ok=True)
    (DICT_DIR / f"{lang}.json").write_text(json.dumps(d, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")


# ---------------------------------------------------------------- značky pro DeepL
# DeepL bez zapnutého zpracování HTML občas smaže text uvnitř značky <a>…</a>. Proto se do překladače posílá
# text se značkami [1]…[/1] a [br] (ty DeepL zachová a text uvnitř přeloží) a po překladu se vrátí zpět na HTML.
TAG = re.compile(r"<(/?)(\w+)([^>]*)>")


def mark(key):
    """HTML text → (text se značkami [n], seznam otevíracích značek)"""
    opens, stack = [], []

    def sub(m):
        close, name, rest = m.groups()
        if name == "br":
            return "[br]"
        if close:
            n = stack.pop()
            return f"[/{n}]"
        opens.append(m.group(0))
        stack.append(len(opens))
        return f"[{len(opens)}]"
    return TAG.sub(sub, key), opens


def unmark(text, opens):
    def sub(m):
        t = m.group(1)
        if t == "br":
            return "<br>"
        if t.startswith("/"):
            return "</" + TAG.match(opens[int(t[1:]) - 1]).group(2) + ">"
        return opens[int(t) - 1]
    return re.sub(r"\[(/?\d+|br)\]", sub, text)


def deepl_input(key, overrides):
    """Text, který se pošle do DeepL (volitelně upravený v tools/i18n/vstup.json kvůli jednoznačnosti)."""
    return mark(overrides.get(key, key))[0] if key in overrides else mark(key)[0]


def load_overrides():
    p = DICT_DIR / "vstup.json"
    return json.loads(p.read_text(encoding="utf-8")) if p.exists() else {}


# ---------------------------------------------------------------- kontrola
def tags_of(s):
    return sorted(re.findall(r"</?\w+[^>]*>", s))


def nums_of(s):
    s = re.sub(r"(?<=\d)[\s  ,.](?=\d{3}\b)", "", s)
    return sorted(re.findall(r"\d+", s))


def check_pair(cs, tr):
    problems = []
    if sorted(re.findall(r"\{\w+\}", cs)) != sorted(re.findall(r"\{\w+\}", tr)):
        problems.append("zástupce {…}")
    norm_tags = lambda s: sorted(re.sub(r"/?>", ">", re.sub(r"\s+", " ", t)).replace("<br />", "<br>") for t in tags_of(s))
    if norm_tags(cs) != norm_tags(tr):
        problems.append("HTML značky")
    if nums_of(cs) != nums_of(tr):
        problems.append("čísla")
    return problems


def cmd_check():
    bad = 0
    html, js, keys = all_keys()
    for lang in LANGS:
        d = load(lang)
        miss = [k for k in keys if k not in d]
        extra = [k for k in d if k not in keys]
        print(f"[{lang}] hotovo {len(keys) - len(miss)}/{len(keys)}, chybí {len(miss)}, nepoužito {len(extra)}")
        for k, v in d.items():
            p = check_pair(k, v)
            if p:
                bad += 1
                print(f"  ! {'+'.join(p)}\n    cs: {k}\n    {lang}: {v}")
    return bad


def cmd_todo(size):
    html, js, keys = all_keys()
    todo = [k for k in keys if any(k not in load(l) for l in LANGS)]
    print(f"celkem klíčů {len(keys)}, k překladu {len(todo)}")
    batches = [todo[i:i + size] for i in range(0, len(todo), size)]
    DICT_DIR.mkdir(parents=True, exist_ok=True)
    ov = load_overrides()
    for i, b in enumerate(batches):
        (DICT_DIR / f"davka-{i}.json").write_text(json.dumps(b, ensure_ascii=False, indent=1), encoding="utf-8")
        (DICT_DIR / f"davka-{i}.vstup.json").write_text(json.dumps([deepl_input(k, ov) for k in b], ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"dávky: {len(batches)} (tools/i18n/davka-N.json)")


def cmd_merge(lang, path, davka):
    keys = json.loads(Path(davka).read_text(encoding="utf-8"))
    vals = json.loads(Path(path).read_text(encoding="utf-8"))
    if len(keys) != len(vals):
        sys.exit(f"počet nesedí: {len(keys)} klíčů, {len(vals)} překladů")
    d = load(lang)
    for k, v in zip(keys, vals):
        v = unmark(v, mark(k)[1])
        v = re.sub(r"\(Kč\)", "(CZK)", re.sub(r"(\d) ?Kč", r"\1 CZK", v))      # částky v prose také jako CZK (jako ceník)
        # DeepL občas přeloží i název zástupce ({nazev} → {name}); vrátí se podle pořadí
        pk, pv = re.findall(r"\{\w+\}", k), re.findall(r"\{\w+\}", v)
        if pk != pv and len(pk) == len(pv) and len(set(pk)) == len(pk):
            for a, b in zip(pv, pk):
                v = v.replace(a, "\0" + b[1:-1] + "\1")
            v = v.replace("\0", "{").replace("\1", "}")
        if not k.rstrip().endswith(":") and v.endswith(":"):
            v = v[:-1]                                           # vstup mohl mít dvojtečku jen kvůli kontextu štítku
        if k in UPPER_KEYS:
            v = v.upper()                                        # český klíč je psaný velkými písmeny
        d[k] = v
        p = check_pair(k, v)
        if p:
            print(f"  ! {'+'.join(p)}: {k}\n      → {v}")
    save(lang, d)
    print(f"[{lang}] uloženo {len(d)} překladů")


# ---------------------------------------------------------------- build
def rel(cur, tgt):
    base = "" if cur == "cs" else "../"
    if tgt == "cs":
        return base or "./"
    return "./" if tgt == cur else base + tgt + "/"


def build_page(lang, d, missing):
    soup = source()
    html = soup.find("html")
    html["lang"] = lang
    html["data-root"] = "../"

    def tr(k):
        if k in d:
            return d[k]
        missing.add(k)
        return None

    transform(soup, tr, lang)
    for a in soup.find_all(True):
        for attr in ("href", "src", "poster"):
            v = a.get(attr)
            if isinstance(v, str) and v.startswith(ROOT_FILES):
                a[attr] = "../" + v
            elif isinstance(v, str) and "hl=cs" in v:
                a[attr] = v.replace("hl=cs", f"hl={lang}")
    for f in soup.find_all("iframe"):
        if f.get("src") and "output=embed" in f["src"] and "hl=" not in f["src"]:
            f["src"] += f"&hl={lang}"
    # jazykový přepínač
    nav = soup.select_one("nav.lang")
    for a in nav.find_all("a"):
        a["href"] = rel(lang, a["hreflang"])
        if a["hreflang"] == lang:
            a["aria-current"] = "true"
        elif a.has_attr("aria-current"):
            del a["aria-current"]
    btn = nav.select_one(".lang-btn")
    cur = nav.select_one("a[aria-current]")
    if btn and cur:
        btn.find("img")["src"] = cur.find("img")["src"]
        btn.find("span").string = lang.upper()
    # poznámka u recenzí (v české verzi skrytá)
    for n in soup.select("p.rv-note"):
        del n["hidden"]
    # adresy a značky pro sdílení
    url = f"{SITE}/{lang}/"
    soup.find("link", rel="canonical")["href"] = url
    soup.find("meta", property="og:url")["content"] = url
    soup.find("meta", property="og:locale")["content"] = OG_ALL[lang]
    for m in soup.find_all("meta", property="og:locale:alternate"):
        m.decompose()
    last = soup.find("meta", property="og:locale")
    for l in reversed(["cs", "en", "de"]):
        if l != lang:
            last.insert_after(soup.new_tag("meta", property="og:locale:alternate", content=OG_ALL[l]))
    # slovník pro skripty před i18n.js
    sc = soup.find("script", src=re.compile(r"i18n\.js"))
    new = soup.new_tag("script", src=f"../assets/i18n/{lang}.js")
    sc.insert_before(new)
    sc.insert_before(NavigableString("\n"))
    out = ROOT / lang
    out.mkdir(exist_ok=True)
    (out / "index.html").write_text(str(soup), encoding="utf-8")


def build_dict_js(lang, d, js_keys):
    sub = {k: d[k] for k in js_keys if k in d}
    body = json.dumps(sub, ensure_ascii=False, indent=1)
    (ROOT / "assets" / "i18n").mkdir(parents=True, exist_ok=True)
    (ROOT / "assets" / "i18n" / f"{lang}.js").write_text(
        "/* Vygenerováno nástrojem tools/i18n.py – needitovat (zdroj: tools/i18n/%s.json) */\n"
        "window.THAI_I18N = window.THAI_I18N || {};\nwindow.THAI_I18N.%s = %s;\n" % (lang, lang, body), encoding="utf-8")


def cmd_build():
    html, js, keys = all_keys()
    for lang in LANGS:
        d = load(lang)
        missing = set()
        build_page(lang, d, missing)
        build_dict_js(lang, d, js)
        print(f"[{lang}] {lang}/index.html + assets/i18n/{lang}.js, nepřeloženo {len(missing)}")
        for k in sorted(missing)[:10]:
            print("   -", k[:90])


if __name__ == "__main__":
    a = sys.argv[1:]
    if not a:
        print(__doc__)
    elif a[0] == "todo":
        cmd_todo(int(a[a.index("--size") + 1]) if "--size" in a else 40)
    elif a[0] == "merge":
        cmd_merge(a[1], a[2], a[3])
    elif a[0] == "check":
        sys.exit(1 if cmd_check() else 0)
    elif a[0] == "build":
        cmd_build()
    else:
        print(__doc__)
