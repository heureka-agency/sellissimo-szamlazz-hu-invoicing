# Sellissimo-rendelés számlázása

A Free kiadásban a munkatársak belföldi, forintalapú rendeléseket számlázhatnak, letölthetik az eredeti PDF-et, és kezelhetik a módosításokat. A Pro automatikus számlázást, webhelyenkénti feladatlistát és választható számlaértesítőt ad hozzá. A Számlázz.hu mindkét kiadás mellett külön számolja el a Számla Agent díját. A havi díjsáv az előző hónapban kiállított bizonylatok számától függ. [A Számla Agent aktuális díjai](https://www.szamlazz.hu/szamla-agent-api)

## A webhely beállítása

1. A Sellissimo áruház beállításaiban nyisd meg a **Számlázz.hu** menüpontot, majd válaszd ki a Statamic-webhelyet. Az oldal tetején láthatod az aktív kiadást (Free vagy Pro), valamint azt, hogy a webhely tesztfiókot vagy éles fiókot használ.
2. Válaszd a **Tesztfiók** lehetőséget, és add meg a külön erre létrehozott Számlázz.hu-tesztfiók Agent-kulcsát. Mindkét mód ugyanazt a szolgáltatói címet használja. Éles értékesítéskor az **Éles fiók** alatt a külön éles fiók kulcsát add meg. Ha a kiválasztott módhoz már mentettél kulcsot, a következő mentéskor üresen hagyhatod a kulcs mezőjét. A korábbi számlák lekéréséhez őrizd meg a hozzáférést az eredeti kibocsátó fiókhoz. [Tesztfiók beállítása](https://tudastar.szamlazz.hu/gyik/teszt-fiok-fejleszteshez)
3. Állítsd be a számla nyelvét, a fizetési határidőt napokban, valamint a kibocsátó fiókban használható számlaformátumot.
4. Az egyes Sellissimo-áfaosztályok mellett válaszd ki a számlán alkalmazandó áfakódot. Egy 27%-os osztályhoz például a **27** tartozik. A mentességi kódot egyeztesd a könyvelőddel. Amíg a besorolás kérdéses, hagyd üresen a mezőt. A rendszer a vásárláskor alkalmazott kulcsot ellenőrzi a szállításnál és az egyéb díjaknál is. [A szolgáltató áfakódjai](https://docs.szamlazz.hu/agent/generating_invoice/vat-rates)
5. Minden fizetési mód mellett add meg a számlára kerülő megnevezést, például **Bankkártya** vagy **Utánvét**. A korábban mentett fizetési módokat és áfaosztályokat is ellenőrizheted. Az első próbarendelés előtt mentsd el a beállításokat.

A jogosultságok külön szabályozzák a számlák és PDF-ek megtekintését, a számlázást és egyeztetést, illetve a beállítások és kulcsok kezelését. A számlázó munkatársnak az adott webhelyhez is hozzáférésre van szüksége.

## A pénztártól a PDF-ig

Belföldi, forintos vásárlásnál a vevő megadja a teljes számlázási címet és kiválasztja, hogy magánszemélyként vagy vállalkozásként vásárol. Céges számlához a hivatalos név és a magyar adószám is szükséges. Ezeket a Sellissimo pénztára és a rendelési piszkozathoz küldött fizetési oldal is bekéri. Egyedi pénztár esetén jelenítsd meg az ajánlattal érkező mezőket. A `checkout_steps.szamlazz_buyer` alatt küldd vissza a `buyer_type` (`person` vagy `business`), `business_name` és `tax_number` értékeket, majd a végleges ajánlat azonosítóját.

A rendelésben nyisd meg a **Számlázz.hu → Számlák és módosító bizonylatok** részt. Add meg a teljesítés napját és a fizetési határidőt. Ellenőrizd az előnézetben a kibocsátó fiókot, a vevő adatait, a fizetettséget, a tételek áfáját és a végösszeget. A kedvezmény az érintett tételeken szerepel. Hagyd jóvá a kiállítást. A számlaszámhoz tartozó eredeti PDF letölthető, nyomtatható és átadható a vevőnek.

Forintos számlán a szolgáltató egész forintra kerekített tételértékeket kér. Ha a rendelés összege így nem reprodukálható, az előnézet megmutatja az eltérést. Az utalványok, a külföldi ügyletek, a tisztázatlan adózás és a hiányos korábbi rendelések egyedi elbírálást igényelnek. Ha a megfelelő számlát a Számlázz.hu-ban állítod ki, tüntesd fel rajta a Sellissimo rendelésszámát. Ezután a pontos számlaszámmal ellenőrizheted és a rendeléshez kapcsolhatod a bizonylatot. [A szolgáltató kerekítési szabályai](https://docs.szamlazz.hu/agent/generating_invoice/settings_and_rules/rounding)

## Helyesbítés, sztornó és egyeztetés

Beérkezett visszáru vagy véglegesített rendelésmódosítás esetén az eredeti számlánál nyisd meg a módosítás előnézetét. Hagyd jóvá az eredeti hivatkozást és az előjeles összegeket. Korábbi helyesbítés nélküli teljes visszavonásnál sztornószámla készülhet. Ha volt már helyesbítés, a fennmaradó egyenleget újabb helyesbítő számla vezeti ki. Minden módosítást munkatárs hagy jóvá.

Ha a helyesbítő vagy sztornószámla a Számlázz.hu-ban készült, ellenőrizd a rendelésnél látható eredeti számlaszámot, válaszd ki a módosítás okát, majd add meg a módosító bizonylat számát és a rajta szereplő előjeles nettó, áfa- és bruttó összeget. Ha az átvezetett bizonylat tételszintű adatai hiányoznak, a további módosításokat a kibocsátó fiókban kezeld.

Egyeztetésre váró műveletnél előfordulhat, hogy a számla már elkészült. Az adott műveletnél indíts lekérdezést. Sztornónál a sztornószámla saját számát add meg. Elutasításnál ellenőrizd az okot, majd az engedélyezett újrapróbálkozás előtt nézd át újra a bizonylatot. Ha az adatok nem egyeztethetők, a kibocsátó fiók számlalistája alapján járj el.

## Pro automatizálás

Az automatizálást webhelyenként lehet bekapcsolni. A „fizetéskor” beállítás az igazolt online fizetésre és az utánvét beszedésének rögzítésére is érvényes. A „végső feladáskor” beállítás az utolsó fizikai csomagot várja meg, és a feladáskori fizetettséget rögzíti. Kizárólag digitális rendelésnél a fizetés a kiváltó esemény. A szolgáltatói számlaértesítő külön kapcsolható be. A kiállításról kapott válasz mellett a vevő átvételét külön bizonyíték igazolhatja.

A feladatlistán látszik az érintett rendelés, az ellenőrzés oka és az utolsó kísérlet ideje. Itt vizsgálhatók a tisztázandó visszatérítések, cserék és rendelésmódosítások is. Free kiadásra váltva a még el nem küldött automatikus feladatok megállnak. A Pro újbóli bekapcsolása új időszakot nyit. A korábbi rendelések egyenként áttekinthetők. A háttérműveletekhez az áruház időzítőjének és feladatfeldolgozójának futnia kell.

## Az első tesztszámla ellenőrzése

Hasonlítsd össze az új próbarendelést, az eredeti PDF-et és a tesztfiók számlalistájában szereplő bizonylatot. Ellenőrizd a vevő adatait, az összeget, az áfát, a dátumokat és a rendelésszámot. Helyesbítésnél nézd meg az eredeti számlára mutató hivatkozást és a módosítás utáni egyenleget is.
