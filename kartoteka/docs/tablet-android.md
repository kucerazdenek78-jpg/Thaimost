# Thai Most Tablet – aplikace pro Android

Aplikace otevře tablet masérny (`/?r=tablet&t=…`) **na celou obrazovku**, drží displej zapnutý, pustí znělku bez klepnutí a **zamkne se v popředí** (nelze ji opustit tlačítky Domů/Zpět/Poslední). Bez spojení zobrazí pruh a každých 5 s zkouší znovu.

Stažení APK: https://github.com/kucerazdenek78-jpg/thaimost/releases/latest/download/thai-most-tablet.apk
(stejný odkaz i s QR kódem je v kartotéce: Tým → Masérny a tablety). Sestavuje se automaticky v GitHub Actions po každé změně složky `kartoteka/android-tablet`.

## Nastavení jiného tabletu / telefonu (bez přepisování)
1. Nainstalujte APK (povolte instalaci z neznámých zdrojů pro prohlížeč).
2. V kartotéce u masérny rozbalte **QR kód pro nastavení jiného tabletu / telefonu**.
3. Naskenujte ho běžným fotoaparátem a vyberte otevření v **Thai Most Tablet** → odkaz se uloží a tablet naběhne.
Když by aplikace nešla, stejný odkaz funguje v běžném prohlížeči (telefon i tablet).
Ručně: první spuštění nabídne vložení odkazu + PIN správce.

## Plný kiosek (doporučeno)
Aplikace se stane „vlastníkem zařízení“ – zamkne se bez potvrzení, skryje stavový řádek, nelze ji opustit.
Podmínky: tablet po továrním nastavení, **bez přidaného Google účtu**, zapnuté ladění USB.
```
adb install thai-most-tablet.apk
adb shell dpm set-device-owner cz.thaimost.tablet/.AdminReceiver
```
Poté aplikaci spusťte; po restartu se zamkne sama (po nastavení jako výchozí domovské aplikace i naběhne sama).
Zrušení kiosku: skrytý vstup správce (níže) → Odemknout; úplně: `adb shell dpm remove-active-admin cz.thaimost.tablet/.AdminReceiver`.

## Bez adb (jednodušší, slabší zámek)
- Nastavení Androidu → Aplikace → Výchozí aplikace → **Domovská aplikace** → Thai Most Tablet (naběhne po zapnutí a tlačítku Domů).
- Při prvním spuštění potvrďte „připnutí aplikace“ (screen pinning); odepnutí: podržet Zpět + Přehled.

## Skrytý vstup správce
7× klepnout do levého horního rohu během 5 s → PIN → obnovit stránku / změnit odkaz a PIN / odemknout a otevřít nastavení Androidu.
Při nastavení přes QR (bez zadání PINu) vstup správce není dostupný; změna = znovu naskenovat QR nebo odinstalovat aplikaci.

## Upozornění
- Podpisový klíč (`keystore/thaimost-tablet.jks`) je záměrně v repozitáři – slouží jen k tomu, aby šly aplikace přes sebe aktualizovat; aplikace není určena pro Google Play.
- Aplikace jde jen na adresu `https://masage.dvere.app` (QR odkazy z jiné domény nepřijme); při změně domény upravte `AndroidManifest.xml`.
