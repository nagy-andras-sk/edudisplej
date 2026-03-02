# Jedalen.sk adatleszedés – technikai feltárás és döntési napló

## Cél

Ez a dokumentum azt írja le, **hogyan jöttünk rá a Jedalen működésére**, milyen technológiát azonosítottunk a forrásoldalon, és miért pontosan erre a scraping-stratégiára épült az EduDisplej oldali megoldás.

---

## Rövid válasz a fő kérdésre

- Igen, ez **web scraping** (nincs publikus, stabil JSON API használva).
- A céloldal viselkedése erősen **ASP.NET WebForms** jellegű.
- Emiatt a sima `GET` lekérés önmagában nem mindig elég; néha **postback** kell a heti nézet lapozásához.

---

## Hogyan fedeztük fel, hogy ez WebForms jellegű?

A következő jelek alapján:

1. URL minta: `https://www.jedalen.sk/Pages/EatMenu?Ident=...`
2. Az oldal rejtett inputokat ad vissza, amelyek postbackhez kellenek.
3. A parser csak akkor kap menüt bizonyos intézményeknél, ha az oldalra postbacket küldünk `__EVENTTARGET` mezővel.
4. A használt esemény-target stringek tipikus WebForms szerkezetűek:
   - `ctl00$MainPanel$DayItems1$lnkNextWeek`
   - `ctl00$MainPanel$DayItems1$lnkPreviousWeek`

A rendszerben ez explicit meg is jelenik a karbantartó kódban (`edudisplej_maintenance_jedalen_week_postback_html` és `edudisplej_maintenance_parse_jedalen_menu`).

**Megjegyzés:** a backend pontos implementációját (szerveroldali framework verzió) nem látjuk, de a működési minta egyértelműen WebForms kompatibilis.

---

## Feltárási folyamat lépésről lépésre

## 1) Forrásoldalak azonosítása

Először a régiós listákat azonosítottuk:

- `https://www.jedalen.sk/?RC=TT`
- ... és további régiók (`NR`, `TN`, `BB`, `PO`, `KE`, `BA`, `ZA`)

Innen olyan hivatkozásokat kerestünk, amelyek `Pages/EatMenu?Ident=` mintát tartalmaznak.

**Miért így?**
- Ez volt a legstabilabb jelölő az intézményi menüoldalakhoz.
- A CSS osztálynevek változhatnak, de az `Ident` URL minta üzleti szempontból stabilabb.

## 2) Intézménylista normalizálás

A talált linkekből:

- intézmény név,
- `Ident`,
- kanonikus menü URL (`https://www.jedalen.sk/Pages/EatMenu?Ident=...`)

készül.

Deduplikálás `Ident` alapján történik.

**Miért így?**
- Ugyanaz az intézmény több régiós/listás kontextusban is előfordulhat.
- A deduplikált lista kell a stabil upsertekhez és célpontválasztáshoz.

## 3) Menüoldal első körös lekérése (`GET`)

Minden intézménynél indul egy sima `GET`.

Ha azonnal található menüstruktúra, akkor parse megtörténik.

**Miért nem csak ez?**
- Több intézménynél az első válasz üres vagy nem tartalmaz közvetlenül parse-olható menüsort.

## 4) Postback alapú hét-lapozás

Ha a `GET` után nincs menü:

1. A HTML-ből összegyűjtjük a hidden inputokat.
2. Beállítjuk:
   - `__EVENTTARGET`
   - `__EVENTARGUMENT`
3. POST-oljuk ugyanarra az EatMenu URL-re.
4. Megpróbáljuk a következő / előző hetet is.

**Miért így?**
- A forrásoldal egy része eseményvezérelt, szerveroldali state-ből renderelődik.
- Ezt a state-et a hidden mezők és a postback target hordozza.

## 5) Slot-okra bontás és tisztítás

A nyers menüsorokból a rendszer slotokat képez:

- `breakfast`
- `snack_am`
- `lunch`
- `snack_pm`
- `dinner`

A besorolás több nyelvi mintára épül (szlovák/magyar/angol kulcsszavak), és vannak fallbackek receptkód alapján is.

**Miért így?**
- A forrás adatformátuma nem teljesen egységes intézményenként.
- A kijelzőoldalon strukturált, konzisztens mezők kellenek.

## 6) Tárolás és frissítési ablak

A mentés `meal_plan_items` táblába történik (`source_type='server'`).

A Jedalen sync normál esetben:

- napi futási kaput használ,
- időablakhoz kötött,
- opciósan „minden ciklusban” is futtatható.

**Miért így?**
- Elkerülhető a túl gyakori külső lekérés.
- Stabilabb terhelés a saját és a külső oldalon is.

---

## Miért HTTPS és miért emeljük ki külön?

A gyakorlati tesztek alapján több intézménynél HTTP alatt üres / „noeat” jellegű tartalom érkezett, míg HTTPS-en és postback után már volt menü.

Ezért a rendszer URL-normalizálása kifejezetten HTTPS-re terel.

---

## Miért kétlépcsős a kézi admin folyamat?

Az admin oldalon a kézi futás két részre van bontva:

1. intézménylista frissítés,
2. menületöltés kiválasztott intézményekre.

**Indok:**
- Ha az intézménylista változik, nem kell minden alkalommal minden menüt azonnal bejárni.
- Célzott hibakeresésnél gyorsabb: előbb lista, utána csak problémás intézmény(ek) menüje.

---

## „Mi ez pontosan?” – technikai besorolás

- **Nem hivatalos API integráció**, hanem HTML alapú adatkinyerés.
- **Web scraping + WebForms-kompatibilis postback emuláció**.
- Szerveroldali pipeline, amely a kijelző modul számára már normalizált adatot ad.

---

## Korlátok és kockázatok

1. A scraping érzékeny a forrás HTML változásaira.
2. A postback event targetek változhatnak.
3. Intézményi oldalak publikációs hiánya esetén a parser korrekt módon „nincs menü” állapotot ad.

Ezért kell:
- monitorozott maintenance log,
- kézi újrafuttatás lehetősége,
- fallback és deduplikáció.

---

## Érintett implementációs pontok a kódban

- `webserver/control_edudisplej_sk/cron/maintenance/maintenance_task.php`
  - intézménylista gyűjtés (`edudisplej_maintenance_fetch_jedalen_institutions`)
  - menü parse (`edudisplej_maintenance_parse_jedalen_menu`)
  - postback (`edudisplej_maintenance_jedalen_week_postback_html`)
  - napi sync futás (`edudisplej_maintenance_run_jedalen_daily_sync`)

- `webserver/control_edudisplej_sk/cron/maintenance/run_maintenance.php`
  - Jedalen runtime flag-ek (CLI és HTTP)

- `webserver/control_edudisplej_sk/admin/meal_menu_maintenance.php`
  - kézi 2-lépéses vezérlés (intézménylista / kiválasztott menük)

---

## Összegzés

A Jedalen integráció azért ilyen, mert a forrás oldal működése nem egyszerű statikus lista-API, hanem részben állapotfüggő, eseményvezérelt HTML render. A megoldás emiatt kombinálja a klasszikus scrapinget a WebForms-szerű postback emulációval, majd normalizált adatmodellt készít a kijelzőrendszer számára.
