# Jedalen.sk adatok begyűjtése – részletes technikai dokumentáció

## Cél

A cél a `jedalen.sk` étlapadatainak stabil, ismételhető, szerveroldali begyűjtése és tárolása az EduDisplej rendszerben úgy, hogy:

- az intézménylista külön frissíthető legyen,
- a menük külön frissíthetők legyenek kiválasztott intézményekre,
- a megjelenítő modul (meal-menu) megbízhatóan tudjon adatot olvasni.

---

## 1) Forrás és adatmodell

### Forrásoldal

- Fő oldal régió szerint: `https://www.jedalen.sk/?RC={REGION}`
- Intézményi menü oldal: `https://www.jedalen.sk/Pages/EatMenu?Ident={IDENT}`

### Fontos megállapítás

A rendszer **HTTPS** használatával működik megbízhatóan. Bizonyos intézményeknél HTTP alatt üres / `noeat` nézet érkezhet, miközben HTTPS + lapozás után van menü.

### Belső táblák

A begyűjtés a következő táblákra épül:

- `meal_plan_sites`
- `meal_plan_institutions`
- `meal_plan_items`
- `maintenance_settings`
- `maintenance_job_runs`

A Jedalenből érkező menü `meal_plan_items.source_type = 'server'` értékkel mentődik.

---

## 2) Hierarchikus begyűjtési folyamat

### 2.1 Régiók bejárása

Alapértelmezett régiók:

- `TT, NR, TN, BB, PO, KE, BA, ZA`

Ezek lekérdezése régiónként történik:

- `https://www.jedalen.sk/?RC=TT` stb.

### 2.2 Intézmények kinyerése

Nem CSS class-re támaszkodunk kizárólagosan, hanem stabil linkmintára:

- `Pages/EatMenu?Ident=...`

Minden találatból:

- intézmény név,
- `Ident`,
- kanonikus menü URL (`https://www.jedalen.sk/Pages/EatMenu?Ident=...`)

A lista deduplikálása `Ident` szerint történik.

### 2.3 Menüoldal feldolgozása

Egy intézmény menüoldala nem mindig tartalmaz azonnal menüsort az első GET válaszban.

Ezért a parser:

1. Lekéri az alapoldalt (GET)
2. Megpróbál menüt olvasni
3. Ha nincs menü:
   - ASP.NET postbackkel heti lapozást indít (`lnkNextWeek`, szükség szerint további lépések)
4. Új oldalakból ismét menüt olvas

A dátum- és sorfelismerés többféle HTML mintát támogat (fallback ágakkal).

### 2.4 Napi és étel szint

Egy naphoz az alábbi mezők épülnek:

- `breakfast`
- `snack_am`
- `lunch`
- `snack_pm`
- `dinner`

A kategória alapján történik a slot-besorolás (szlovák/magyar/angol kulcsszavakkal). Allergén információ képből (`title`) és szöveges blokkból is feldolgozható.

---

## 3) Kézi admin folyamat (meal_menu_maintenance)

Oldal: `admin/meal_menu_maintenance.php`

A kézi vezérlés két külön lépésre van bontva:

1. **Jedalen intézménylista letöltése most**
   - csak intézmények frissítése
2. **Etrend letöltése a kiválasztott intézményekre**
   - csak a kijelölt intézmények menüfrissítése

Kimenet:

- CLI / HTTP fallback futtató info,
- teljes maintenance log kimenet,
- figyelmeztetés, ha tényleg nincs publikált menü a bejárt heteken sem.

További admin nézet:

- „Tárolt menünapok (ma és jövő)” táblázat (`menu_date >= CURDATE()`).

---

## 4) Runtime flag-ek

A `cron/maintenance/run_maintenance.php` támogatott Jedalen flag-jei:

- `--force-jedalen-sync`
- `--only-jedalen-sync`
- `--jedalen-fetch-institutions-only`
- `--jedalen-fetch-menus-only`
- `--jedalen-institution-ids=1,2,3`

HTTP fallback paraméterek:

- `force_jedalen_sync=1`
- `only_jedalen_sync=1`
- `jedalen_fetch_institutions_only=1`
- `jedalen_fetch_menus_only=1`
- `jedalen_institution_ids=...`

---

## 5) Hibakeresési eljárás

### Tipikus hiba: „no menu rows parsed”

Ellenőrzési sorrend:

1. A logban szereplő konkrét EatMenu URL megnyitása
2. HTTP vs HTTPS összehasonlítás
3. GET és postback (NextWeek) tartalom vizsgálata
4. `menu-tdmenu-title` előfordulás ellenőrzése

### Diagnosztikai PowerShell minta

```powershell
$u='https://www.jedalen.sk/Pages/EatMenu?Ident=...'
$r=Invoke-WebRequest -UseBasicParsing $u -TimeoutSec 25
$h=$r.Content
([regex]::Matches($h,'menu-tdmenu-title','IgnoreCase')).Count
```

Postback (következő hét) teszt:

```powershell
$pairs=[regex]::Matches($h,'<input type="hidden" name="([^"]+)" id="[^"]*" value="([^"]*)"','IgnoreCase')
$f=@{}
foreach($m in $pairs){ $f[$m.Groups[1].Value]=$m.Groups[2].Value }
$f['__EVENTTARGET']='ctl00$MainPanel$DayItems1$lnkNextWeek'
$f['__EVENTARGUMENT']=''
$f['ctl00$MainPanel$tbxFilter']=''
$p=Invoke-WebRequest -UseBasicParsing $u -Method Post -Body $f -ContentType 'application/x-www-form-urlencoded' -TimeoutSec 25
([regex]::Matches($p.Content,'menu-tdmenu-title','IgnoreCase')).Count
```

---

## 6) Adatminőség és működési szabályok

- Intézménylista deduplikálás `Ident` alapján.
- Menü URL mindig HTTPS-re normalizált.
- Üres napok elfogadottak; az intézmény lehet időszakosan menü nélkül.
- A kézi menüfrissítés csak a kiválasztott intézményeket érinti.
- A rendszer idempotens: ismételt futás felülírja/egységesíti a napi rekordot.

---

## 7) Megjelenítő (meal-menu) összhang

A meal-menu modul oldalon az alapértelmezett forrás `server`.

Kompatibilitás miatt API oldalon:

- `source_type=server` kérésnél a `server` és a legacy `auto_jedalen` is olvasható,
- `manual` kérésnél, ha nincs találat, fallbackként `server` adatok is próbálhatók.

---

## 8) Főbb érintett fájlok

- `cron/maintenance/maintenance_task.php`
- `cron/maintenance/run_maintenance.php`
- `admin/meal_menu_maintenance.php`
- `api/meal_plan.php`
- `modules/meal-menu/m_meal_menu.html`
- `modules/meal-menu/config/default_settings.json`
- `dashboard/group_loop/assets/js/app.js`

---

## 9) Üzemeltetési javaslat

- Intézménylista frissítés: napi 1x vagy igény szerint.
- Menüfrissítés: időzítve + kézi trigger lehetőséggel.
- Hibalog mentése és rendszeres ellenőrzése a maintenance output alapján.
- Ha egy intézménynél tartósan nincs menü, manuális EatMenu oldal ellenőrzés szükséges (publikált-e egyáltalán).