# Étrend modul – részletes működési leírás

## Cél

Ez a dokumentum a `meal-menu` modul teljes adatútját írja le:

- hogyan kerül be az adat a rendszerbe,
- a kijelző mikor melyik backendből kér,
- milyen fallback ágak vannak,
- és miért előnyös ez a felépítés üzemeltetésben.

---

## 1) Fő komponensek

Az étrend modul több rétegből áll, nem egyetlen fájl oldja meg a teljes működést.

1. **Adatgyűjtés / szinkron (szerveroldal)**
   - Jedalen szinkron és karbantartás: `cron/maintenance/maintenance_task.php`
   - kézi mentés adminból (`manual` forrás): `api/meal_plan.php` (`action=save_menu`)

2. **API adatszolgáltatás**
   - publikus/renderer olvasás: `api/meal_plan.php` (`action=menu`)

3. **Kiosk szinkron-optimalizáció**
   - loop payload normalizálás: `api/kiosk_loop.php`
   - meal prefetch JSON készítés a kliensre: `install/init/edudisplej-download-modules.sh`

4. **Renderer (kijelzőoldali futás)**
   - runtime megjelenítés és fallback logika: `modules/meal-menu/m_meal_menu.html`

5. **Admin testreszabás**
   - modul beállítások UI és mentés: `dashboard/group_loop/assets/js/app.js`

---

## 2) Adatmodell és forrástípusok

Az alap táblák:

- `meal_plan_sites`
- `meal_plan_institutions`
- `meal_plan_items`

`meal_plan_items` rekordok kulcsa:

- `institution_id`
- `menu_date`
- `source_type` (`server` / `manual`, legacy: `auto_jedalen`)

Tartalom mezők:

- szöveges mezők (`breakfast`, `snack_am`, `lunch`, `snack_pm`, `dinner`)
- strukturált sorok (`*_rows_json`) a jobb sorrendezéshez és kijelzőoldali renderhez

---

## 3) Melyik backend mikor mit kér le?

### A) Szerveroldali gyűjtés (cron / maintenance)

**Ki hívja:** ütemezett maintenance

**Mit csinál:**
- Jedalen oldalakról menü letöltése,
- slotokra bontás,
- strukturált sorok generálása,
- mentés `source_type='server'`-rel.

Ez a háttérfolyamat tölti a "hivatalos" szerveres étrend adatot.

### B) Kézi admin mentés

**Ki hívja:** admin felület

**Mit csinál:**
- konkrét napi menü mentése,
- `source_type='manual'` rekord készül/frissül,
- strukturált sorok is mentődnek.

Ez fallbackként vagy override-ként hasznos.

### C) Kiosk prefetch (szinkron közben)

**Ki hívja:** `edudisplej-download-modules.sh`

**Mit kér le API-ból:**
- `today` (`exact_date=0`)
- `tomorrow` (`exact_date=1`, főleg square módnál)

**Eredmény:**
- lokális JSON fájlok (`today.json`, `tomorrow.json`),
- loop settings-be visszaírva:
  - `offlinePrefetchedTodayFile`
  - `offlinePrefetchedTomorrowFile`
  - `offlinePrefetchedMenuData`

### D) Renderer runtime API lekérés

**Ki hívja:** `m_meal_menu.html`

**Mikor:** ha `runtimeApiFetchEnabled=true`

**Mit kér le:** `api/meal_plan.php?action=menu` paraméterezve (site/institution/date/meal visibility/source)

---

## 4) `action=menu` API döntési logika

Az API nem csak "pont egy napot olvas"; van több lépcsős visszakeresés:

1. **Első kör:** pontos dátum lekérés (`menu_date = date`)
2. Ha nincs és `exact_date=0`:
   - jövőbeli legközelebbi,
   - majd múltbeli legközelebbi
3. 14 napnál távolabbi találatot eldob

Forrás-logika:

- `source_type=server` esetén `server + auto_jedalen` együtt kezelve
- `source_type=manual` esetén manual preferált

Kiegészítő fallbackek:

- ha `manual` kérésre nincs találat: szerveres fallback
- ha `server` kérésre nincs renderelhető tartalom: manual fallback

Meta zászlók:

- `server_data_available`
- `pending_server_sync`

Ezeket a renderer debug/üresállapot kezeléshez használja.

---

## 5) Renderer indulási sorrend (single/normal mód)

Az indulási lánc tudatosan többlépcsős, hogy gyenge hálón se legyen üres kijelző:

1. inline prefetched adat
2. localStorage cache
3. runtime API (ha engedélyezett)
4. offline fájl (`today.json`, `tomorrow.json`)
5. ismét inline/cache fallback
6. hard fallback (üres, de kontrollált UI)

Ha van runtime API, background refresh is indul (`runtimeRefreshIntervalSec`).

---

## 6) Időalapú láthatóság és holnapi előnézet

A kis kijelzős logika meal-kulcsonként cutoff időt kezel:

- `scheduleBreakfastUntil`
- `scheduleSnackAmUntil`
- `scheduleLunchUntil`
- `scheduleSnackPmUntil`
- `scheduleDinnerUntil`

Viselkedés:

- cutoff előtt: aznapi étel,
- cutoff után: ha van renderelhető holnapi megfelelő étel, akkor azt mutatja,
- holnapi címke: lokalizált `tomorrowMealLabels` (óra nélkül).

Plusz: a loop kezdősorrend az aktuális idősávhoz igazodik (nem fix reggelivel indul).

---

## 7) Miért jó ez az architektúra?

1. **Gyors indulás a kijelzőn**
   - prefetch + cache miatt nem kell minden induláskor hálót várni.

2. **Hibatűrés**
   - API hiba esetén offline/cache ágak továbbra is adnak tartalmat.

3. **Rugalmas forráskezelés**
   - `server` és `manual` együtt kezelhető, egyik kiesésekor a másik átveheti a szerepet.

4. **Kontrollált terhelés**
   - nem minden render ciklusban kér API-t,
   - háttérfrissítés állítható intervallummal történik.

5. **Karbantarthatóság**
   - admin UI / API / sync / renderer külön rétegekben vannak,
   - hibakeresés gyorsabb, regressziók könnyebben lokalizálhatók.

---

## 8) Gyakori üzemeltetési helyzetek

### "Miért nem jelenik meg holnapi adat cutoff után?"

Tipikus okok:

- nincs `offlinePrefetchedTomorrowFile`,
- `runtimeApiFetchEnabled=false` és nincs holnapi prefetch,
- API oldalról holnapi menü üres/nem renderelhető.

Ellenőrizendő:

- loop settings-ben prefetch mezők,
- `meal_plan.php?action=menu` válasz a holnapi dátumra,
- `structured_rows` és/vagy szövegmezők tényleges tartalma.

### "Miért régi adat jelenik meg?"

Lehetséges ok:

- runtime API tiltva + régi cache/prefetch aktív.

Megoldás:

- modul újraszinkron + prefetch újragenerálás,
- szükség esetén runtime API engedélyezése.

---

## 9) Rövid folyamatábra

```text
Admin/cron -> meal_plan_items (server/manual)
            -> kiosk loop sync normalizálás
            -> prefetch today/tomorrow JSON
            -> renderer indulás (prefetch/cache/api/offline fallback)
            -> időalapú meal váltás + holnapi preview
```

---

## 10) Érintett fájlok

- `webserver/control_edudisplej_sk/modules/meal-menu/m_meal_menu.html`
- `webserver/control_edudisplej_sk/api/meal_plan.php`
- `webserver/control_edudisplej_sk/api/kiosk_loop.php`
- `webserver/install/init/edudisplej-download-modules.sh`
- `webserver/control_edudisplej_sk/cron/maintenance/maintenance_task.php`
- `webserver/control_edudisplej_sk/dashboard/group_loop/assets/js/app.js`