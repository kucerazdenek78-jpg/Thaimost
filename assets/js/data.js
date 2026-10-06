/* =========================================================
   DATA WEBU – vše převzato z původního thaimost.cz (10/2026)
   Ceny / texty měňte tady, web se přegeneruje sám.
   ========================================================= */
window.THAI = {
  salon: {
    nazev: "Thai Most",
    podtitul: "Thajské masáže Most",
    telefon: "+420 778 429 329",
    telefonHref: "tel:+420778429329",
    email: "stepanova.hanka@centrum.cz",
    adresa: ["Slovenského národního povstání 2654/26", "434 01 Most"],
    provozovatel: "Hana Follprechtová, J. A. Komenského 534/3, 434 01 Most",
    ico: "87149931",
    oteviraci: [
      ["Po–Pá", "9:00–20:00"],
      ["So–Ne", "pouze wellness / dle domluvy s masérkami (příplatek 150 Kč/os.)"]
    ],
    facebook: "https://www.facebook.com/thaimost",
    prohlidka: "https://www.google.cz/maps/@50.5091022,13.6297946,3a,90y,63.93h,71.2t,1.1r/data=!3m6!1e1!3m4!1sAF1QipO1f8iBY91Br7rOrJZZ6xbhF6DJpPeIUz5ivMpt!2e10!7i5376!8i2688?hl=cs",
    mapa: "https://www.google.com/maps?q=Slovensk%C3%A9ho+n%C3%A1rodn%C3%ADho+povst%C3%A1n%C3%AD+2654%2F26,+Most&output=embed"
  },

  /* Platby poukazů převodem – ÚČET DOPLNIT.
     Dokud je cislo_uctu prázdné, web nabízí jen platbu v salonu
     a QR platbu nezobrazuje. Formát: "123456789/0100" */
  platba: {
    cislo_uctu: "",
    iban: ""
  },

  /* Obrázky – zatím odkazy na původní web. Před přesměrováním domény
     je potřeba je stáhnout do assets/img/ a cesty tady přepsat. */
  img: "https://www.thaimost.cz/media/",

  sluzby: [
    { id: "tradicni", kat: "masaze", nazev: "Tradiční thajská masáž",
      popis: "Kombinace akupresury, práce na energetických drahách a protažení v jógových pozicích. Bez oleje, na matraci na podlaze, klient zůstává oblečen.",
      pozn: "Doporučené oblečení: spodní prádlo nebo slabé legíny a tričko.",
      ceny: [["60 min", 790], ["90 min", 1100], ["120 min", 1600]], foto: "tradicni-3.jpg", top: true },
    { id: "hlava", kat: "masaze", nazev: "Masáž hlavy a šíje",
      popis: "Masáž zaměřená na krční páteř, hlavu a ramena. Probíhá nasucho, stlačováním akupresurních bodů uvolňuje bloky v těchto partiích.",
      ceny: [["30 min", 430], ["60 min", 790]], foto: "hlava-3.jpg" },
    { id: "zada", kat: "masaze", nazev: "Masáž zad a krku",
      popis: "Speciální techniky zaměřené na šíji, ramena, bederní a křížovou oblast. Pravidelné opakování může zmírnit bolesti zad ze sedavého zaměstnání.",
      ceny: [["30 min", 430], ["60 min", 790]], foto: "zada-special-1.jpg" },
    { id: "olej", kat: "masaze", nazev: "Thajská olejová masáž",
      popis: "Velmi relaxační forma thajské masáže – jemná až střední technika spojená s účinky aromatických olejů z thajských bylin.",
      ceny: [["60 min", 790], ["90 min", 1100]], foto: "olej-1.jpg", top: true },
    { id: "aroma", kat: "masaze", nazev: "Aroma masáž",
      popis: "Olejová masáž celého těla s vonnými oleji dle výběru, doplněná inhalací esencí. Na výběr limetka + bambus nebo citronová tráva + pomeranč.",
      ceny: [["60 min", 850]], foto: "aroma-1.jpg" },
    { id: "detska", kat: "masaze", nazev: "Dětská masáž",
      popis: "Olejová masáž celého těla pro děti od 2 do 10 let, přizpůsobená jejich věku a fyzickému stavu.",
      ceny: [["40 min", 450]] },
    { id: "lymfa-oblicej", kat: "masaze", nazev: "Lymfatická masáž obličeje a dekoltu",
      popis: "Velmi jemné a pomalé hmaty na suchou pokožku bez olejů a krémů. Zbavuje podkoží přebytečné tekutiny a podporuje regeneraci pleti.",
      ceny: [["30 min", 430], ["60 min", 790]] },
    { id: "poporodni", kat: "masaze", nazev: "Poporodní masáž",
      popis: "Spojení tradiční thajské masáže a bylinné léčby s přikládáním bylinných váčků na problematické partie ženského těla po porodu.",
      ceny: [["60 min", 790]] },
    { id: "oblicej", kat: "masaze", nazev: "Masáž obličeje",
      popis: "Spojení kosmetického ošetření pleti a akupresurní masáže – peeling, pleťová maska a regenerační krémy. Během masky i masáž krční páteře.",
      ceny: [["60 min", 900]], foto: "masaz-obliceje-1.jpg" },
    { id: "nohy", kat: "masaze", nazev: "Masáž nohou",
      popis: "Část tradiční thajské masáže pro unavené a opuchlé nohy, kombinovaná s reflexologií. S thajským balzámem nebo nahřívanými bylinnými sáčky.",
      ceny: [["60 min", 790], ["60 min s bylinnými sáčky", 1100]], foto: "cinska-nohy-1.jpg" },
    { id: "bylinna", kat: "masaze", nazev: "Bylinná masáž",
      popis: "Povzbuzující herbal massage – tradiční thajská masáž doplněná horkými bylinnými houbičkami pro hlubší uvolnění svalů.",
      ceny: [["90 min", 1600], ["120 min", 1850]], foto: "bylinna-1.jpg" },
    { id: "siam", kat: "masaze", nazev: "Siam massage",
      popis: "Horká bylinná masáž z tradiční thajské medicíny. Nahřáté bylinné váčky uvolňují svalovou bolest a změkčují pokožku.",
      ceny: [["120 min", 1880]], foto: "masaz-siam-1.jpg", top: true },
    { id: "ventosa", kat: "masaze", nazev: "Ventosa masáž (baňkování)",
      popis: "Tradiční filipínská masáž: olejová masáž celého těla, skleněné baňky vytvářející podtlak a ušní svíčky.",
      ceny: [["90 min", 1250]] },
    { id: "lava", kat: "masaze", nazev: "Masáž obličeje lávovými kameny",
      popis: "Teplo a vibrace lávových kamenů pro regeneraci pleti, relaxaci a uvolnění. Vhodné pro všechny typy pokožky.",
      ceny: [["30 min", 570], ["60 min", 950]] },
    { id: "lymfa-telo", kat: "masaze", nazev: "Ruční lymfatická masáž celého těla",
      popis: "Lymfatická masáž celého těla prováděná ručně.",
      ceny: [["60 min", 790]] },
    { id: "tehotenska", kat: "masaze", nazev: "Těhotenská masáž",
      popis: "Jemná tlaková masáž v pohodlném oblečení pro budoucí maminky. Pomáhá při bolestech zad a hlavy, nespavosti, otocích i stresu.",
      ceny: [["60 min", 790]] },
    { id: "whirlpool", kat: "wellness", nazev: "Whirlpool a sauna",
      popis: "Privátní relaxace ve whirlpoolu a sauně. Karafa vody ke všem procedurám zdarma, šampaňské 150 Kč.",
      ceny: [["Sauna + sprcha", 400], ["90 min whirlpool", 1100], ["90 min whirlpool + sauna", 1150],
             ["120 min párová (2 asistentky + šampaňské + whirlpool + sauna)", 2600]], foto: "was2.jpg", top: true },
    { id: "fiji", kat: "wellness", nazev: "Fiji rituál",
      popis: "Kokosový krémový peeling, zábal s tělovým máslem, přikládání lávových kamenů a na závěr lehká olejová masáž.",
      ceny: [["90 min", 1500]], foto: "fiji-1.jpg", top: true },
    { id: "cukr", kat: "wellness", nazev: "Cukrový vánek z ráje",
      popis: "Luxusní peeling cukrovou třtinou z Fidži s tropickými oleji a kokosovým mlékem. Odstraní odumřelé buňky a hluboce hydratuje.",
      ceny: [["90 min", 1500]], foto: "cukr-1.jpg" }
  ]
};
