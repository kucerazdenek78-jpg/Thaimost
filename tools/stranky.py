#!/usr/bin/env python3
"""
Vygeneruje statické podstránky webu pro vyhledávače – vše z assets/js/data.js + tools/stranky_obsah.json:

  sluzba/<slug>/index.html   19 stránek služeb (adresy stejné jako na původním webu thaimost.cz)
  sluzby/index.html          přehled masáží a ceník
  darkove-poukazy/index.html dárkové poukazy
  parova-masaz/index.html    párová masáž
  o-nas/, kontakt/, bonusovy-program/   přesměrování starých adres na úvodní stránku
  404.html                   stránka „nenalezeno“
  sitemap.xml                mapa webu (úvod cs/en/de + podstránky)

Po změně cen/textů v data.js nebo textů ve stranky_obsah.json spusť znovu:  python3 tools/stranky.py
Potřebuje Node.js (načtení data.js).
"""
import html
import json
import subprocess
import sys
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SITE = "https://www.thaimost.cz"
TODAY = date.today().isoformat()
GMAPS = "https://www.google.com/maps?cid=13481975087391624487"
RATING, REVIEWS = "4,8", "240"          # stejné hodnoty jako v index.html (aggregateRating)

LOTUS = ('<svg viewBox="0 0 120 80" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="3" stroke-linejoin="round">'
         '<path d="M60 70 C52 52 52 30 60 10 C68 30 68 52 60 70Z"/><path d="M60 70 C46 60 36 44 34 26 C48 32 58 48 60 70Z"/>'
         '<path d="M60 70 C74 60 84 44 86 26 C72 32 62 48 60 70Z"/><path d="M60 70 C40 68 22 58 12 42 C30 42 48 52 60 70Z"/>'
         '<path d="M60 70 C80 68 98 58 108 42 C90 42 72 52 60 70Z"/></g></svg>')


def e(s):
    return html.escape(str(s), quote=True)


def kc(n):
    return f"{int(n):,}".replace(",", " ") + " Kč"


def load_data():
    js = "global.window={};require(process.argv[1]);process.stdout.write(JSON.stringify(window.THAI))"
    try:
        out = subprocess.run(["node", "-e", js, str(ROOT / "assets/js/data.js")], capture_output=True, text=True, check=True).stdout
    except FileNotFoundError:
        sys.exit("Chybí Node.js (příkaz node) – je potřeba k načtení assets/js/data.js.")
    return json.loads(out)


D = load_data()
OBSAH = json.loads((ROOT / "tools/stranky_obsah.json").read_text(encoding="utf-8"))
S = D["salon"]
TEL, TEL_HREF = S["telefon"], S["telefonHref"]
SLUZBY = D["sluzby"]
for s in SLUZBY:
    if s["id"] not in OBSAH:
        sys.exit(f"Ve stranky_obsah.json chybí služba {s['id']} ({s['nazev']}) – doplň ji (aspoň slug, h1, title, desc).")
    s["o"] = OBSAH[s["id"]]
    s["url"] = f"sluzba/{s['o']['slug']}/"
    s["od"] = min(c[1] for c in s["ceny"])


def provider():
    return {"@type": ["HealthAndBeautyBusiness", "DaySpa"], "@id": SITE + "/#salon", "name": "Thai Most – thajské masáže Most",
            "url": SITE + "/", "telephone": TEL.replace(" ", ""), "image": SITE + "/assets/og-image.jpg",
            "address": {"@type": "PostalAddress", "streetAddress": S["adresa"][0], "addressLocality": "Most", "postalCode": "434 01", "addressCountry": "CZ"},
            "geo": {"@type": "GeoCoordinates", "latitude": 50.5091022, "longitude": 13.6297946}, "hasMap": GMAPS, "priceRange": "450–2600 Kč"}


def crumbs_ld(items):
    return {"@type": "BreadcrumbList", "itemListElement": [
        {"@type": "ListItem", "position": i + 1, "name": n, "item": SITE + "/" + u} for i, (n, u) in enumerate(items)]}


def faq_ld(faq):
    return {"@type": "FAQPage", "mainEntity": [{"@type": "Question", "name": q, "acceptedAnswer": {"@type": "Answer", "text": a}} for q, a in faq]}


def head(R, title, desc, path, image="assets/og-image.jpg", graph=None, robots="index, follow, max-image-preview:large"):
    url = SITE + "/" + path
    ld = ""
    if graph:
        ld = '<script type="application/ld+json">\n' + json.dumps({"@context": "https://schema.org", "@graph": graph}, ensure_ascii=False, indent=1) + "\n</script>\n"
    return f"""<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{e(title)}</title>
<meta name="description" content="{e(desc)}">
<meta name="robots" content="{robots}">
<meta name="theme-color" content="#2b1e16">
<meta name="geo.region" content="CZ-42">
<meta name="geo.placename" content="Most">
<link rel="canonical" href="{url}">
<link rel="icon" href="{R}favicon.ico" sizes="48x48">
<link rel="icon" href="{R}assets/img/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="{R}assets/icons/apple-touch-icon.png">
<meta property="og:type" content="website">
<meta property="og:locale" content="cs_CZ">
<meta property="og:site_name" content="Thai Most">
<meta property="og:url" content="{url}">
<meta property="og:title" content="{e(title)}">
<meta property="og:description" content="{e(desc)}">
<meta property="og:image" content="{SITE}/{image}">
<meta name="twitter:card" content="summary_large_image">
<link rel="preload" href="{R}assets/fonts/manrope-latin-ext-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{R}assets/css/fonts.css">
<link rel="stylesheet" href="{R}assets/css/style.css?v=20261115s">
<link rel="stylesheet" href="{R}assets/css/sub.css?v=20261115s">
{ld}</head>
<body class="sub">
"""


def header(R):
    return f"""<div class="topbar">Objednávky přijímáme telefonicky na <a href="{TEL_HREF}">{TEL}</a> nebo <a href="{R}#objednat">online</a>. Storno prosím vždy telefonicky.</div>
<header class="nav">
  <div class="wrap nav-in">
    <a href="{R}" class="logo" aria-label="Thai Most – thajské masáže Most, úvod">{LOTUS}<span><b>Thai Most</b><small>thajské masáže</small></span></a>
    <ul class="menu" id="menu">
      <li><a href="{R}sluzby/">Masáže a ceník</a></li>
      <li><a href="{R}sluzba/whirlpool-a-sauna/">Wellness</a></li>
      <li><a href="{R}parova-masaz/">Párová masáž</a></li>
      <li><a href="{R}darkove-poukazy/">Dárkové poukazy</a></li>
      <li><a href="{R}#recenze">Recenze</a></li>
      <li><a href="{R}#kontakt">Kontakt</a></li>
    </ul>
    <a href="{R}#objednat" class="btn btn-dark">Objednat se</a>
    <button class="burger" id="burger" aria-label="Menu" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7h18M3 12h18M3 17h18"/></svg></button>
  </div>
</header>
"""


def footer(R, sid=None):
    links = "".join(f'<li><a href="{R}{s["url"]}">{e(s["nazev"])}</a></li>' for s in SLUZBY)
    links += f'<li><a href="{R}parova-masaz/">Párová masáž</a></li><li><a href="{R}darkove-poukazy/">Dárkové poukazy</a></li>'
    return f"""<footer>
  <div class="wrap">
    <div class="f-grid f-sub">
      <div>
        <a href="{R}" class="logo">{LOTUS.replace('aria-hidden="true"', 'aria-hidden="true" style="width:42px;height:28px;color:#c69c5d"')}<span><b>Thai Most</b><small style="color:rgba(248,242,233,.6)">thajské masáže</small></span></a>
        <p style="margin-top:12px;max-width:320px">Thajské masáže a privátní wellness v Mostě od roku 2004. <a href="{GMAPS}" target="_blank" rel="noopener">{RATING} ★ z {REVIEWS} recenzí na Google</a>.</p>
      </div>
      <div><h4>Kontakt</h4><a href="{TEL_HREF}">{TEL}</a><br>{e(S["adresa"][0])}<br>{e(S["adresa"][1])}<br><br>Po–Pá 9–20 h<br>So–Ne pouze wellness<br><a href="{GMAPS}" target="_blank" rel="noopener">Mapa a recenze</a></div>
      <div><h4>Masáže a rituály</h4><ul class="f-serv">{links}</ul></div>
    </div>
    <div class="f-bottom"><span>© {date.today().year} Thajské masáže Most · IČO {e(S["ico"])}</span><span class="f-links"><a href="{R}">Úvodní stránka</a><a href="{R}sluzby/">Ceník</a></span></div>
  </div>
</footer>
<div class="callbar">
  <a href="{TEL_HREF}" class="btn btn-gold">Zavolat</a>
  <a href="https://wa.me/420778429329" class="btn btn-ghost" style="color:#f8f2e9" target="_blank" rel="noopener">WhatsApp</a>
  <a href="{R}#objednat" class="btn btn-ghost" style="color:#f8f2e9">Objednat</a>
</div>
{cenik_js(R, sid)}<script>
(function(){{var m=document.getElementById('menu'),b=document.getElementById('burger');if(m&&b)b.addEventListener('click',function(){{var o=m.classList.toggle('open');b.setAttribute('aria-expanded',o);}});}})();
</script>
</body>
</html>
"""


def cenik_js(R, sid):
    """Ceny na stránce služby se po načtení přepíšou aktuálními z data.js (statické jsou pro vyhledávače a jako záloha)."""
    if not sid:
        return ""
    return f"""<script src="{R}assets/js/data.js"></script>
<script>
(function(){{try{{var s=(window.THAI.sluzby||[]).filter(function(x){{return x.id==="{sid}"}})[0],u=document.getElementById('cenik');if(!s||!u)return;
var kc=function(n){{return Number(n).toLocaleString('cs-CZ')+'\\u00a0Kč'}},esc=function(t){{var d=document.createElement('i');d.textContent=t;return d.innerHTML}};
u.innerHTML=s.ceny.map(function(c){{return '<li><span>'+esc(c[0])+'</span><b>'+kc(c[1])+'</b></li>'}}).join('');
var od=document.getElementById('cenaOd');if(od)od.textContent='od '+kc(Math.min.apply(null,s.ceny.map(function(c){{return c[1]}})));}}catch(e){{}}}})();
</script>
"""


def crumbs_html(R, items):
    out = [f'<a href="{R}">Thai Most</a>']
    for n, u in items[:-1]:
        out.append(f'<a href="{R}{u}">{e(n)}</a>')
    out.append(e(items[-1][0]))
    return '<nav class="crumbs wrap" aria-label="Drobečková navigace">' + "<span>›</span>".join(out) + "</nav>\n"


def faq_html(faq, h2):
    if not faq:
        return ""
    items = "".join(f'<details class="faq-i"{" open" if i == 0 else ""}><summary>{e(q)}</summary><p>{e(a)}</p></details>' for i, (q, a) in enumerate(faq))
    return f'<section class="sub-faq"><div class="wrap"><span class="eyebrow">Časté otázky</span><h2 class="h2">{e(h2)}</h2><div class="faq-grid">{items}</div></div></section>\n'


def card(R, s):
    ilu = "" if s.get("vlastni") else ""
    return (f'<a class="rel-card" href="{R}{s["url"]}"><img src="{R}{D["img"]}{s["foto"]}" alt="{e(s["nazev"])} – thajské masáže Most" loading="lazy" width="1200" height="800">{ilu}'
            f'<div><b>{e(s["nazev"])}</b><span>od {kc(s["od"])}</span></div></a>')


def related(s):
    rest = [x for x in SLUZBY if x["id"] != s["id"]]
    rest.sort(key=lambda x: (x["kat"] != s["kat"], not x.get("top", False)))
    return rest[:4]


def side_contact():
    return f"""<div class="sub-card"><h3>Objednání</h3>
      <a class="c-big" href="{TEL_HREF}">{TEL}</a>
      <p>Po–Pá 9:00–20:00 · So–Ne pouze wellness</p>
      <p>{e(S["adresa"][0])}, {e(S["adresa"][1])}<br>Parkování přímo u salonu.</p>
      <p><a href="{GMAPS}" target="_blank" rel="noopener">{RATING} ★ z {REVIEWS} recenzí na Google</a></p></div>"""


def page_sluzba(s):
    R = "../../"
    o = s["o"]
    path = s["url"]
    img = D["img"] + s["foto"]
    crumbs = [("Masáže a ceník", "sluzby/"), (s["nazev"], path)]
    graph = [
        {"@type": "Service", "@id": SITE + "/" + path + "#sluzba", "name": s["nazev"], "description": s["popis"], "serviceType": "Masáž" if s["kat"] == "masaze" else "Wellness",
         "url": SITE + "/" + path, "image": SITE + "/" + img, "areaServed": {"@type": "City", "name": "Most"}, "provider": provider(),
         "offers": [{"@type": "Offer", "name": c[0], "price": c[1], "priceCurrency": "CZK", "availability": "https://schema.org/InStock", "url": SITE + "/" + path} for c in s["ceny"]]},
        crumbs_ld(crumbs),
    ]
    if o.get("faq"):
        graph.append(faq_ld(o["faq"]))
    text = ""
    for h2, ps in o.get("sekce", []):
        text += f"<h2>{e(h2)}</h2>" + "".join(f"<p>{e(p)}</p>" for p in ps)
    prices = "".join(f"<li><span>{e(c[0])}</span><b>{kc(c[1])}</b></li>" for c in s["ceny"])
    ilu = "" if s.get("vlastni") else '<span class="sv-ilu">Ilustrační foto</span>'
    pozn = f'<p class="note">{e(s["pozn"])}</p>' if s.get("pozn") else ""
    rel = "".join(card(R, x) for x in related(s))
    body = f"""{crumbs_html(R, crumbs)}<main>
<section class="sub-hero"><div class="wrap">
  <div>
    <span class="eyebrow">{"Masáže" if s["kat"] == "masaze" else "Wellness a rituály"} · Thai Most</span>
    <h1>{e(o["h1"])}</h1>
    <p class="lead">{e(s["popis"])}</p>{pozn}
    <div class="sub-btns">
      <a class="btn btn-dark" href="{R}#objednat">Objednat termín</a>
      <a class="btn btn-gold" href="{TEL_HREF}">Zavolat {TEL}</a>
      <a class="btn btn-ghost" href="{R}#poukazy">Darovat jako poukaz</a>
    </div>
    <div class="sub-facts"><span><b id="cenaOd">od {kc(s["od"])}</b></span><span>rodilé Thajky a Filipínky, certifikace Wat Pho</span><span><a href="{GMAPS}" target="_blank" rel="noopener">{RATING} ★ z {REVIEWS} recenzí</a></span></div>
  </div>
  <div class="sub-img"><img src="{R}{img}" alt="{e(s["nazev"])} – thajské masáže Most, Thai Most" width="1200" height="800" fetchpriority="high">{ilu}</div>
</div></section>
<section class="sub-body"><div class="wrap">
  <div class="sub-text">{text}
    <h2>Kde nás najdete</h2>
    <p>Thai Most – thajské masáže Most, {e(S["adresa"][0])}, {e(S["adresa"][1])}. Parkování je přímo u salonu. Objednat se můžete <a href="{R}#objednat">online</a> nebo na <a href="{TEL_HREF}">{TEL}</a>; ke všem procedurám je karafa vody zdarma.</p>
  </div>
  <aside class="sub-side">
    <div class="sub-card"><h2>Ceník – {e(s["nazev"])}</h2><ul class="prices" id="cenik">{prices}</ul>
      <a class="btn btn-dark" href="{R}#objednat">Objednat termín</a>
      <a class="btn btn-ghost" style="color:var(--teak)" href="{R}sluzby/">Celý ceník masáží</a></div>
    {side_contact()}
  </aside>
</div></section>
{faq_html(o.get("faq"), s["nazev"] + " – na co se ptáte")}<section class="rel"><div class="wrap"><span class="eyebrow">Další z nabídky</span><h2 class="h2">Mohlo by vás zajímat</h2><div class="rel-grid">{rel}</div></div></section>
</main>
"""
    write(path, head(R, o["title"], o["desc"], path, img, graph) + header(R) + body + footer(R, s["id"]))


def page_sluzby():
    R, path = "../", "sluzby/"
    title = "Thajské masáže Most – ceník masáží a wellness | Thai Most"
    desc = f"Ceník thajských masáží v Mostě: {len(SLUZBY)} masáží a rituálů od {kc(min(s['od'] for s in SLUZBY))}. Tradiční thajská, olejová, bylinná masáž, privátní wellness. Tel. 778 429 329.".replace(" ", " ")
    crumbs = [("Masáže a ceník", path)]
    graph = [{"@type": "ItemList", "name": "Masáže a rituály – Thai Most", "itemListElement": [
        {"@type": "ListItem", "position": i + 1, "name": s["nazev"], "url": SITE + "/" + s["url"]} for i, s in enumerate(SLUZBY)]}, crumbs_ld(crumbs)]

    def blok(kat):
        return "".join(
            f'<a class="rel-card" href="{R}{s["url"]}"><img src="{R}{D["img"]}{s["foto"]}" alt="{e(s["nazev"])} – thajské masáže Most" loading="lazy" width="1200" height="800">'
            f'<div><b>{e(s["nazev"])}</b><span>{e(s["popis"])}</span><em>{" · ".join(e(c[0]) + " " + kc(c[1]) for c in s["ceny"])}</em></div></a>'
            for s in SLUZBY if s["kat"] == kat)
    body = f"""{crumbs_html(R, crumbs)}<main><section class="hub"><div class="wrap">
  <span class="eyebrow">Služby a ceník</span>
  <h1>Thajské masáže v Mostě – masáže a ceník</h1>
  <p class="lead">Kompletní nabídka salonu Thai Most: {len(SLUZBY)} masáží a rituálů v rukou rodilých Thajek a Filipínek s certifikací školy Wat Pho. Ke všem procedurám karafa vody zdarma, každá šestá masáž s věrnostní kartičkou zdarma. Objednat se můžete <a href="{R}#objednat">online</a> nebo na <a href="{TEL_HREF}">{TEL}</a>.</p>
  <div class="sub-btns"><a class="btn btn-dark" href="{R}#objednat">Objednat termín</a><a class="btn btn-gold" href="{R}nabidka-masazi.pdf">Stáhnout nabídku masáží (PDF)</a><a class="btn btn-ghost" style="color:var(--teak)" href="{R}darkove-poukazy/">Dárkové poukazy</a></div>
  <h2>Masáže</h2>
  <div class="rel-grid">{blok("masaze")}</div>
  <h2>Wellness a rituály</h2>
  <div class="rel-grid">{blok("wellness")}<a class="rel-card" href="{R}parova-masaz/"><img src="{R}assets/img/ai/parova.jpg" alt="Párová masáž – thajské masáže Most" loading="lazy" width="1200" height="800"><div><b>Párová masáž</b><span>Dvě masérky zároveň ve dvoulůžkové masérně, samostatně nebo jako balíček s whirlpoolem a saunou.</span><em>více o párové masáži</em></div></a></div>
</div></section></main>
"""
    write(path, head(R, title, desc, path, graph=graph) + header(R) + body + footer(R))


def page_poukazy():
    R, path = "../", "darkove-poukazy/"
    title = "Dárkový poukaz na masáž Most – Thai Most"
    desc = "Dárkový poukaz na thajskou masáž v Mostě: na libovolnou částku nebo konkrétní masáž. Objednáte online, vyzvednete v salonu nebo pošleme PDF e-mailem. Thai Most."
    crumbs = [("Dárkové poukazy", path)]
    faq = [
        ["Na co dárkový poukaz platí?", "Na všechny masáže, rituály i privátní wellness v Thai Most. Vystavíme ho na libovolnou částku, nebo na konkrétní masáž."],
        ["Jak dlouho poukaz platí?", "Půl roku od nákupu. Do té doby je potřeba se objednat na masáž – stačí zavolat na +420 778 429 329."],
        ["Jak poukaz dostanu?", "Objednáte ho online a vyzvednete v salonu, nebo ho pošleme jako PDF e-mailem."],
        ["Jak si obdarovaný domluví termín?", "Telefonicky na +420 778 429 329 nebo přes online objednávku na webu. Při objednání stačí říct, že má poukaz."],
    ]
    graph = [crumbs_ld(crumbs), faq_ld(faq)]
    top = [s for s in SLUZBY if s.get("top")][:4]
    body = f"""{crumbs_html(R, crumbs)}<main>
<section class="sub-hero"><div class="wrap">
  <div>
    <span class="eyebrow">Dárkové poukazy · Thai Most</span>
    <h1>Dárkový poukaz na masáž v Mostě</h1>
    <p class="lead">Darujte hodinu klidu v rukou rodilých Thajek. Poukaz vystavíme na libovolnou částku, nebo na konkrétní masáž či wellness – obdarovaný si termín domluví sám.</p>
    <div class="sub-btns">
      <a class="btn btn-dark" href="{R}#poukazy">Objednat poukaz online</a>
      <a class="btn btn-gold" href="{TEL_HREF}">Zavolat {TEL}</a>
    </div>
    <div class="sub-facts"><span><b>libovolná částka</b> nebo konkrétní masáž</span><span>platnost půl roku od nákupu</span><span><a href="{GMAPS}" target="_blank" rel="noopener">{RATING} ★ z {REVIEWS} recenzí</a></span></div>
  </div>
  <div class="sub-img"><img src="{R}assets/img/poukaz-pozadi.jpg" alt="Dárkový poukaz Thai Most – thajské masáže Most" width="1795" height="855" style="object-fit:contain;background:#f8f2e9" fetchpriority="high"></div>
</div></section>
<section class="sub-body"><div class="wrap">
  <div class="sub-text">
    <h2>Jak poukaz objednat</h2>
    <p>Poukaz si navrhnete <a href="{R}#poukazy">online na úvodní stránce</a>: zvolíte částku nebo masáž, doplníte jméno obdarovaného a uvidíte náhled. Objednávku pošlete do salonu a recepce se vám ozve. Poukaz si vyzvednete v salonu, nebo ho pošleme jako PDF e-mailem.</p>
    <h2>Na co poukaz platí</h2>
    <p>Na všechny masáže a rituály z <a href="{R}sluzby/">ceníku</a> i na <a href="{R}sluzba/whirlpool-a-sauna/">privátní wellness</a> a <a href="{R}parova-masaz/">párovou masáž</a>. Poukaz platí půl roku od nákupu – do té doby je potřeba se na masáž objednat.</p>
    <h2>Tipy na dárek</h2>
    <ul>{"".join(f'<li><a href="{R}{s["url"]}">{e(s["nazev"])}</a> – od {kc(s["od"])}</li>' for s in top)}</ul>
  </div>
  <aside class="sub-side">
    <div class="sub-card"><h2>Poukaz na míru</h2><p>Na libovolnou částku nebo konkrétní masáž. Náhled poukazu uvidíte hned.</p><a class="btn btn-dark" href="{R}#poukazy">Navrhnout poukaz</a></div>
    {side_contact()}
  </aside>
</div></section>
{faq_html(faq, "Dárkové poukazy – na co se ptáte")}</main>
"""
    write(path, head(R, title, desc, path, "assets/img/poukaz-pozadi.jpg", graph) + header(R) + body + footer(R))


def page_parova():
    R, path = "../", "parova-masaz/"
    title = "Párová masáž Most – masáž pro dva | Thai Most"
    desc = "Párová masáž v Mostě: dvě masérky zároveň ve dvoulůžkové masérně. Samostatně, nebo jako balíček s privátním whirlpoolem, saunou a šampaňským. Tel. 778 429 329."
    crumbs = [("Masáže a ceník", "sluzby/"), ("Párová masáž", path)]
    wh = next(s for s in SLUZBY if s["id"] == "whirlpool")
    bal = next((c for c in wh["ceny"] if "párov" in c[0].lower()), None)
    faq = [
        ["Jak párová masáž probíhá?", "Ležíte vedle sebe ve dvoulůžkové masérně a každého masíruje jedna masérka – obě masáže začínají i končí ve stejný čas."],
        ["Dá se párová masáž spojit s wellness?", "Ano. Párový balíček zahrnuje masáž pro dva, šampaňské a privátní whirlpool se saunou."],
        ["Dá se párová masáž darovat?", "Ano. Dárkový poukaz vystavíme na párový balíček nebo na libovolnou částku."],
    ]
    graph = [{"@type": "Service", "@id": SITE + "/" + path + "#sluzba", "name": "Párová masáž", "serviceType": "Masáž",
              "description": "Masáž pro dva ve dvoulůžkové masérně se dvěma masérkami zároveň; samostatně nebo jako balíček s privátním whirlpoolem a saunou.",
              "url": SITE + "/" + path, "image": SITE + "/assets/img/ai/parova.jpg", "areaServed": {"@type": "City", "name": "Most"}, "provider": provider(),
              **({"offers": [{"@type": "Offer", "name": "Párový balíček 120 min (masáž pro dva, šampaňské, whirlpool, sauna)", "price": bal[1], "priceCurrency": "CZK", "url": SITE + "/" + path}]} if bal else {})},
             crumbs_ld(crumbs), faq_ld(faq)]
    bal_li = f"<li><span>Párový balíček 120 min<br><small>masáž pro dva, šampaňské, whirlpool a sauna</small></span><b>{kc(bal[1])}</b></li>" if bal else ""
    body = f"""{crumbs_html(R, crumbs)}<main>
<section class="sub-hero"><div class="wrap">
  <div>
    <span class="eyebrow">Masáž pro dva · Thai Most</span>
    <h1>Párová masáž v Mostě</h1>
    <p class="lead">Masáž pro dva ve dvoulůžkové masérně – dvě masérky, stejný čas, jedna místnost. Samostatně, nebo jako večer pro dva s privátním whirlpoolem a saunou.</p>
    <div class="sub-btns">
      <a class="btn btn-dark" href="{R}#objednat">Objednat termín</a>
      <a class="btn btn-gold" href="{TEL_HREF}">Zavolat {TEL}</a>
      <a class="btn btn-ghost" href="{R}darkove-poukazy/">Darovat jako poukaz</a>
    </div>
    <div class="sub-facts"><span><b>5 masérek, 3 masérny</b></span><span>dvoulůžková masérna a privátní wellness</span><span><a href="{GMAPS}" target="_blank" rel="noopener">{RATING} ★ z {REVIEWS} recenzí</a></span></div>
  </div>
  <div class="sub-img"><img src="{R}assets/img/ai/parova.jpg" alt="Párová masáž – thajské masáže Most, Thai Most" width="1200" height="800" fetchpriority="high"><span class="sv-ilu">Ilustrační foto</span></div>
</div></section>
<section class="sub-body"><div class="wrap">
  <div class="sub-text">
    <h2>Jak párová masáž probíhá</h2>
    <p>Díky pěti masérkám a dvoulůžkové masérně vás můžeme masírovat oba zároveň. Ležíte vedle sebe, každého masíruje jedna masérka a obě masáže začínají i končí ve stejný čas. Jakou masáž si vyberete, domluvíte s recepcí při objednání – například <a href="{R}sluzba/tradicni-thajska-masaz/">tradiční thajská</a> nebo <a href="{R}sluzba/thajska-olejova-masaz/">thajská olejová masáž</a>.</p>
    <h2>Večer pro dva: masáž, whirlpool a sauna</h2>
    <p>Nejoblíbenější je dvouhodinový párový balíček: masáž pro dva, šampaňské a potom <a href="{R}sluzba/whirlpool-a-sauna/">privátní whirlpool a sauna</a> jen pro vás. Hodí se k výročí, k narozeninám nebo jako dárek.</p>
    <h2>Objednání</h2>
    <p>Párovou masáž prosím objednávejte s předstihem na <a href="{TEL_HREF}">{TEL}</a> nebo <a href="{R}#objednat">online</a> – do poznámky napište, že jde o masáž pro dva. Cenu samostatné párové masáže podle zvolených masáží vám recepce potvrdí při objednání.</p>
  </div>
  <aside class="sub-side">
    <div class="sub-card"><h2>Ceník</h2><ul class="prices">{bal_li}<li><span>Samostatná párová masáž</span><b>dle zvolené masáže</b></li></ul>
      <a class="btn btn-dark" href="{R}#objednat">Objednat termín</a>
      <a class="btn btn-ghost" style="color:var(--teak)" href="{R}sluzby/">Celý ceník masáží</a></div>
    {side_contact()}
  </aside>
</div></section>
{faq_html(faq, "Párová masáž – na co se ptáte")}</main>
"""
    write(path, head(R, title, desc, path, "assets/img/ai/parova.jpg", graph) + header(R) + body + footer(R))


def page_redirect(path, target, nazev):
    """Staré adresy původního webu → kotva na úvodní stránce (GitHub Pages neumí přesměrování 301)."""
    write(path, f"""<!doctype html>
<html lang="cs"><head><meta charset="utf-8">
<title>{e(nazev)} – Thai Most, thajské masáže Most</title>
<meta name="robots" content="noindex, follow">
<link rel="canonical" href="{SITE}/">
<meta http-equiv="refresh" content="0; url=../{target}">
<script>location.replace("../{target}");</script>
</head><body><p><a href="../{target}">{e(nazev)} – pokračovat na Thai Most, thajské masáže Most</a></p></body></html>
""")


def page_404():
    R = "/"
    top = "".join(f'<li><a href="/{s["url"]}">{e(s["nazev"])}</a></li>' for s in SLUZBY if s.get("top"))
    body = f"""<main><section class="e404"><div class="wrap">
  <span class="eyebrow">Stránka nenalezena</span>
  <h1>Tahle stránka tu není</h1>
  <p class="lead" style="margin-inline:auto">Adresa se možná změnila. Zkuste úvodní stránku, ceník masáží, nebo nám zavolejte na <a href="{TEL_HREF}">{TEL}</a>.</p>
  <div class="sub-btns" style="justify-content:center"><a class="btn btn-dark" href="/">Úvodní stránka</a><a class="btn btn-gold" href="/sluzby/">Masáže a ceník</a><a class="btn btn-ghost" style="color:var(--teak)" href="/darkove-poukazy/">Dárkové poukazy</a></div>
  <ul style="list-style:none;margin-top:26px;display:flex;gap:16px;flex-wrap:wrap;justify-content:center">{top}</ul>
</div></section></main>
"""
    (ROOT / "404.html").write_text(
        head(R, "Stránka nenalezena – Thai Most, thajské masáže Most", "Stránka nebyla nalezena. Pokračujte na úvodní stránku Thai Most – thajské masáže Most.", "404.html", robots="noindex, follow")
        + header(R) + body + footer(R), encoding="utf-8")


def sitemap(paths):
    langs = [("cs", ""), ("en", "en/"), ("de", "de/")]
    out = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">']
    for _, p in langs:
        out.append(f"  <url>\n    <loc>{SITE}/{p}</loc>")
        for l2, p2 in langs:
            out.append(f'    <xhtml:link rel="alternate" hreflang="{l2}" href="{SITE}/{p2}"/>')
        out.append(f'    <xhtml:link rel="alternate" hreflang="x-default" href="{SITE}/"/>')
        out.append(f"    <lastmod>{TODAY}</lastmod><changefreq>weekly</changefreq><priority>{'1.0' if not p else '0.8'}</priority>\n  </url>")
    for p, prio in paths:
        out.append(f"  <url><loc>{SITE}/{p}</loc><lastmod>{TODAY}</lastmod><changefreq>monthly</changefreq><priority>{prio}</priority></url>")
    out.append("</urlset>")
    (ROOT / "sitemap.xml").write_text("\n".join(out) + "\n", encoding="utf-8")


def write(path, text):
    d = ROOT / path
    d.mkdir(parents=True, exist_ok=True)
    (d / "index.html").write_text(text, encoding="utf-8")


def main():
    paths = [("sluzby/", "0.9"), ("darkove-poukazy/", "0.8"), ("parova-masaz/", "0.8")]
    for s in SLUZBY:
        page_sluzba(s)
        paths.append((s["url"], "0.8" if s.get("top") or s["id"] in ("zada", "tehotenska") else "0.6"))
    page_sluzby()
    page_poukazy()
    page_parova()
    page_redirect("o-nas/", "#o-nas", "O nás")
    page_redirect("kontakt/", "#kontakt", "Kontakt")
    page_redirect("bonusovy-program/", "#bonus", "Bonusový program")
    page_404()
    sitemap(paths)
    # mapa id → adresa pro úvodní stránku (assets/js/main.js: odkaz „Více o masáži“)
    (ROOT / "assets/js/stranky.js").write_text(
        "/* Generuje tools/stranky.py – adresy podstránek služeb. */\nwindow.THAI_STRANKY = "
        + json.dumps({s["id"]: s["url"] for s in SLUZBY}, ensure_ascii=False) + ";\n", encoding="utf-8")
    print(f"hotovo: {len(SLUZBY)} stránek služeb + sluzby/, darkove-poukazy/, parova-masaz/, přesměrování, 404.html, sitemap.xml ({len(paths) + 3} adres)")


if __name__ == "__main__":
    main()
