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
    email: "salon@thaimost.cz",
    adresa: ["Slovenského národního povstání 2654/26", "434 01 Most"],
    provozovatel: "Hana Kučerová, J. A. Komenského 534/3, 434 01 Most",
    ico: "87149931",
    oteviraci: [
      ["Po–Pá", "9:00–20:00"],
      ["So–Ne", "pouze wellness / masáže dle domluvy s masérkami"]
    ],
    facebook: "https://www.facebook.com/thaimost",
    prohlidka: "https://www.google.cz/maps/@50.5091022,13.6297946,3a,90y,63.93h,71.2t,1.1r/data=!3m6!1e1!3m4!1sAF1QipO1f8iBY91Br7rOrJZZ6xbhF6DJpPeIUz5ivMpt!2e10!7i5376!8i2688?hl=cs",
    mapa: "https://www.google.com/maps?q=Slovensk%C3%A9ho+n%C3%A1rodn%C3%ADho+povst%C3%A1n%C3%AD+2654%2F26,+Most&output=embed"
  },

  /* Kartotéka salonu (NAS). Sem web posílá žádosti o termín a objednávky poukazů. */
  api: "https://masage.dvere.app",

  /* Platby poukazů převodem: číslo účtu se nastavuje v kartotéce (masage.dvere.app → Nastavení →
     Poukazy a platba převodem) a web si ho bere odtud (/api/stav). Tady jen záloha, když kartotéka
     neodpoví – prázdné = bez QR platby. VS = číslo poukazu z kartotéky. */
  platba: {
    cislo_uctu: "",
    iban: ""
  },

  /* Obrázky služeb: assets/img/ai/<id>.jpg (vyrobené v Magnific, 11/2026).
     Původní fotky ze starého webu jsou v assets/img/sluzby/.
     Na fotce je štítek „Ilustrační foto“, dokud nejsou nafocené vlastní: u služby s vlastní fotkou přidejte vlastni: true. */
  img: "assets/img/ai/",

  sluzby: [
    { id: "tradicni", kat: "masaze", nazev: "Tradiční thajská masáž",
      popis: "Jedinečné spojení akupresury, pasivního protahování a práce s energetickými drahami – tzv. „jóga pro líné“. Uvolní ztuhlé svaly, vrátí pohyblivost a dodá energii; masíruje se bez oleje, v oblečení, na matraci na zemi.",
      pozn: "Doporučené oblečení: spodní prádlo nebo slabé legíny a tričko.",
      ceny: [["60 min", 800], ["90 min", 1100], ["120 min", 1600]], foto: "tradicni.jpg", top: true },
    { id: "hlava", kat: "masaze", nazev: "Masáž hlavy a šíje",
      popis: "Masáž krční páteře, hlavy a ramen, kde se drží napětí ze sezení a stresu. Akupresurní body uvolní bloky, zmírní bolest a přinesou rychlou úlevu; probíhá nasucho.",
      ceny: [["30 min", 450], ["60 min", 800]], foto: "hlava.jpg" },
    { id: "zada", kat: "masaze", nazev: "Masáž zad a krku",
      popis: "Masáž nejvíce namáhaných zad, šíje a bederní oblasti. Tlakové techniky a protažení rozpustí svalové napětí ze sedavého zaměstnání a vrátí zádům volný pohyb.",
      ceny: [["30 min", 450], ["60 min", 800]], foto: "zada.jpg" },
    { id: "olej", kat: "masaze", nazev: "Thajská olejová masáž",
      popis: "Hřejivá relaxační masáž o plynulých tazích v rytmu teplého oleje. Pomáhá od bolesti, zmírňuje napětí i otoky a vrací tělu lehkost a rovnováhu.",
      ceny: [["60 min", 800], ["90 min", 1100]], foto: "olej.jpg", top: true },
    { id: "aroma", kat: "masaze", nazev: "Aroma masáž",
      popis: "Nadstavba olejové masáže propojující dotek s účinky vůní – esenci si vyberete podle nálady. Jemně zahřátý aromatický olej umocní uvolnění těla i mysli.",
      ceny: [["60 min", 850]], foto: "aroma.jpg" },
    { id: "detska", kat: "masaze", nazev: "Dětská masáž",
      popis: "Jemná olejová masáž pro děti od 2 do 10 let, přizpůsobená věku a kondici dítěte. Uvolní tělo i mysl, podpoří regeneraci a hodí se i pro sportovně aktivní děti.",
      ceny: [["40 min", 450]], foto: "detska.jpg" },
    { id: "lymfa-oblicej", kat: "masaze", nazev: "Lymfatická masáž obličeje a dekoltu",
      popis: "Velmi jemné pomalé hmaty na suchou pleť bez olejů, vedené ve směru mízních cest. Odvádějí přebytečnou tekutinu, rozjasní pleť a zanechají obličej i dekolt svěží a odpočatý.",
      ceny: [["30 min", 450], ["60 min", 800]], foto: "lymfa-oblicej.jpg" },
    { id: "poporodni", kat: "masaze", nazev: "Poporodní masáž",
      popis: "Jemná masáž pro novopečené maminky spojující thajské hmaty s nahřátými bylinnými váčky. Uvolní namáhané partie, podpoří regeneraci a dopřeje chvíli klidu jen pro sebe.",
      ceny: [["60 min", 800]], foto: "poporodni.jpg" },
    { id: "oblicej", kat: "masaze", nazev: "Masáž obličeje",
      popis: "Spojení kosmetického ošetření pleti a akupresurní masáže obličeje – peeling, maska a regenerace. Rozjasní a prokrví pleť, uvolní mimické napětí a zklidní mysl.",
      ceny: [["60 min", 900]], foto: "oblicej.jpg" },
    { id: "nohy", kat: "masaze", nazev: "Masáž nohou",
      popis: "Péče o unavené a oteklé nohy spojená s reflexologií – tlak na body chodidel podporuje harmonizaci celého těla. Součástí je i ošetření lýtek, kolen a závěrečné uvolnění šíje.",
      ceny: [["60 min", 800], ["60 min s bylinnými sáčky", 1100]], foto: "nohy.jpg" },
    { id: "bylinna", kat: "masaze", nazev: "Bylinná masáž",
      popis: "Detoxikační masáž spojující tradiční thajskou techniku s nahřívanými bylinnými měšci. Uvolní svaly, podpoří lymfu a odplavování toxinů; po masáži doporučujeme zvýšit pitný režim.",
      ceny: [["90 min", 1600], ["120 min", 1880]], foto: "bylinna.jpg" },
    { id: "siam", kat: "masaze", nazev: "Siam massage",
      popis: "Kombinace tradiční thajské masáže, nahřívaných bylinných měšců a relaxační olejové masáže. Uvolní svaly, podpoří regeneraci a přinese hlubokou relaxaci – komplexní péče o tělo i mysl.",
      ceny: [["120 min", 1880]], foto: "siam.jpg", top: true },
    { id: "ventosa", kat: "masaze", nazev: "Ventosa masáž (baňkování)",
      popis: "Tradiční filipínská masáž: olejová masáž celého těla a skleněné baňky, které podtlakem prokrvují tkáně. Uvolní zatuhlé svaly a podpoří regeneraci; po baňkách mohou dočasně zůstat kruhy.",
      ceny: [["90 min", 1250]], foto: "ventosa.jpg" },
    { id: "lava", kat: "masaze", nazev: "Masáž obličeje lávovými kameny",
      popis: "Teplo a jemné vibrace lávových kamenů, které regenerují pleť a uvolňují mimické svaly. Příjemné prohřátí a hluboké zklidnění vhodné pro všechny typy pleti.",
      ceny: [["30 min", 580], ["60 min", 950]], foto: "lava.jpg" },
    { id: "lymfa-telo", kat: "masaze", nazev: "Ruční lymfatická masáž celého těla",
      popis: "Jemná ruční lymfatická masáž celého těla vedená pomalými hmaty ve směru mízních cest. Podpoří odvod přebytečné tekutiny, prokrvení a pocit lehkosti v nohách i celém těle.",
      ceny: [["60 min", 800]], foto: "lymfa-telo.jpg" },
    { id: "tehotenska", kat: "masaze", nazev: "Těhotenská masáž",
      popis: "Jemná tlaková masáž v pohodlném oblečení pro nastávající maminky, vždy podle aktuálního stavu. Uleví od bolestí zad a hlavy, napětí a otoků a pomůže lépe spát.",
      ceny: [["60 min", 800]], foto: "tehotenska.jpg" },
    { id: "whirlpool", kat: "wellness", nazev: "Whirlpool a sauna",
      popis: "Privátní relaxace jen pro vás ve whirlpoolu a sauně. Vířivá lázeň uvolní svaly, sauna prohřeje; ke všem procedurám karafa vody zdarma, k dispozici i sklenka šampaňského.",
      ceny: [["Sauna + sprcha", 400], ["90 min whirlpool", 1100], ["90 min whirlpool + sauna", 1150], ["120 min párová (2 asistentky + šampaňské + whirlpool + sauna)", 2600]], foto: "whirlpool.jpg", top: true },
    { id: "fiji", kat: "wellness", nazev: "Fiji rituál",
      popis: "Smyslný tělový rituál inspirovaný Fidži – kokosový peeling, hřejivý zábal s tělovým máslem a lávovými kameny a lehká olejová masáž. Pokožka zůstane hebká, vyživená a provoněná tropy.",
      ceny: [["90 min", 1500]], foto: "fiji.jpg", top: true },
    { id: "cukr", kat: "wellness", nazev: "Cukrový vánek z ráje",
      popis: "Luxusní rituál: peeling z fidžijské cukrové třtiny, lávové kameny, vyživující zábal a relaxační olejová masáž i s masáží hlavy. Odstraní odumřelé buňky, hydratuje pokožku a přinese hluboký klid.",
      ceny: [["90 min", 1500]], foto: "cukr.jpg" }
  ]
};
