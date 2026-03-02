# EduDisplej modul sync adatfolyam (képek, étrend, PDF, videó)

> Verzió: 2026-03-02  
> Cél: részletes, implementáció-közeli leírás arról, hogyan jutnak el a modul tartalmak (képek, étrend, PDF, videó és egyéb module settings) a Control panelből a kioszk lokális futtatásáig.

---

## 1) Rövid áttekintés

A jelenlegi (éles) folyamat két fő részre oszlik:

1. **V1 heartbeat + update döntés**
   - Kliens: `webserver/install/init/edudisplej_sync_service.sh`
   - API: `webserver/control_edudisplej_sk/api/v1/device/sync.php`
   - Feladat: heartbeat, screenshot/log upload, `needs_update` eldöntése.

2. **Teljes loop + modul letöltés** (ha frissítés kell)
   - Kliens: `webserver/install/init/edudisplej-download-modules.sh`
   - API-k: 
     - `webserver/control_edudisplej_sk/api/kiosk_loop.php`
     - `webserver/control_edudisplej_sk/api/download_module.php`
     - étrend prefetchhez: `webserver/control_edudisplej_sk/api/meal_plan.php`
   - Feladat: loop terv mentése (`loop.json`), modulfájlok letöltése, asset/offline adatok előkészítése.

A `modules_sync.php` endpoint továbbra is létezik (`webserver/control_edudisplej_sk/api/modules_sync.php`), de a kliens oldali aktuális fő letöltési pipeline a `kiosk_loop.php + download_module.php` páros.

---

## 2) Trigger: mikor indul modul újratöltés

### 2.1 V1 sync oldali jelzés

`/api/v1/device/sync.php` a kioszk `last_update` (lokális loop timestamp) és a szerver oldali modul konfig timestamp alapján adja vissza:

- `needs_update: true/false`
- `update_reason`

Ha `true`, a kliens `force_full_loop_refresh` ágon:

- törli a lokális `modules/` tartalmat,
- lefuttatja az `edudisplej-download-modules.sh` scriptet,
- újraindítja a kijelző szolgáltatást.

### 2.2 Extra loop verzió ellenőrzés

A `sync_service` emellett hívja a `check_group_loop_update.php` endpointot (`api/group_loop/check_update.php`):

- összehasonlítja a lokális `loop.json.last_update` vs szerver `loop_updated_at` értéket,
- figyeli a `loop_plan_version` változást,
- meal modul esetén a `meal_plan_items.updated_at` alapján is tud frissítést triggerelni.

---

## 3) Szerver oldali payload előkészítés (`kiosk_loop.php`)

A `kiosk_loop.php` visszaadja:

- `loop_config` (aktuálisan aktív loop),
- `offline_plan` (`base_loop` + `time_blocks`),
- `preload_modules`,
- `loop_last_update`, `loop_plan_version`, `active_scope`, stb.

### 3.1 Settings optimalizálás modulonként

`edudisplej_optimize_module_settings_for_sync()` modul-specifikusan átírja a settingset:

- **PDF**: `pdfAssetId`/`pdfAssetUrl` -> `module_asset_file.php` API URL; ha van URL, a `pdfDataBase64` nullázódik.
- **Gallery**: `imageUrlsJson` elemei normalizálódnak API asset URL-re (max 10 elem).
- **Video**: `videoAssetId`/`videoAssetUrl` -> API asset URL.
- **Text**: text collectionből (`text_collections`) hydrate-ol tartalmat.
- **Meal menu**:
  - `runtimeApiFetchEnabled = false` (sync payloadban),
  - szerver oldali prefetch: `offlinePrefetchedMenuData`, `offlinePrefetchedMenuSavedAt`.

### 3.2 Meal payload felépítése

`edudisplej_sync_prefetch_meal_menu_payload()`:

- `institutionId`, `sourceType`, slot láthatóság (`showBreakfast`, stb.) alapján lekérdezi a `meal_plan_items` táblát,
- `classic` módban napi payloadot ad,
- `square_dual_day` módban `today` + opcionális `tomorrow` payloadot ad.

---

## 4) Modulfájlok fizikai átvitele (`download_module.php`)

A kioszk minden szükséges modulra meghívja a `download_module.php` endpointot.

### 4.1 Engedélyezés logika

A modul letöltése csak akkor engedett, ha a modul megtalálható:

- a kioszk group moduljaiban (`kiosk_group_modules`), vagy
- a kioszk direkt moduljaiban (`kiosk_modules`), vagy
- a planner JSON (`kiosk_group_loop_plans.plan_json`) loop style elemeiben.

### 4.2 Válasz formátum

Az endpoint a modul mappa minden fájlját rekurzívan küldi:

- `files[].path`
- `files[].content` (base64)
- `files[].size`, `files[].modified`

A kliens ezt dekódolja és lokálisan menti a megfelelő modul könyvtárba.

---

## 5) Asset (kép/PDF/videó) adatút

## 5.1 Feltöltés admin oldalon

Feltöltés endpointok:

- `api/group_loop/module_asset_upload.php`
- `api/group_loop/module_asset_library.php`

Tárolás:

- DB: `module_asset_store`
- Fájl: `uploads/companies/company_<id>/modules/<module_key>/...`

Validációk:

- PDF: max 50 MB, PDF MIME/EXT
- Gallery kép: max 15 MB, kép MIME
- Video: max 25 MB, MP4/H.264(+AAC), max 1280x720, max 120s

### 5.2 Sync payloadban URL normalizálás

A settingsben nyers elérési út helyett API URL kerül:

- `../../api/group_loop/module_asset_file.php?asset_id=...&token=...`
- vagy `...?path=uploads/companies/...&token=...`

Ezt a `module_asset_service.php` segédfüggvényei intézik.

### 5.3 Kioszk oldali felhasználás

A modul rendererek (`modules/gallery/m_gallery.html`, `modules/pdf/m_pdf.html`, `modules/video/m_video.html`) futáskor:

- elfogadják a már normalizált URL-t,
- vagy URL/path alapján maguk is `module_asset_file.php`-ra terelik.

A fájlt a `module_asset_file.php` streameli:

- company-check (`api_require_company_match`),
- path traversal védelem,
- MIME detektálás,
- no-store cache header.

---

## 6) Étrend (meal-menu) adatút részletesen

A meal modulnál kettős stratégia fut: **inline prefetched adat + offline JSON fájl cache**.

### 6.1 Szerver oldali prefetch

`kiosk_loop.php` már beleteszi a loop settingsbe:

- `offlinePrefetchedMenuData`
- `offlinePrefetchedMenuSavedAt`
- és `runtimeApiFetchEnabled = false`

### 6.2 Kliens oldali extra prefetch fájlba

`edudisplej-download-modules.sh` -> `prefetch_loop_assets_and_meal_json()`:

- végigmegy a loop + offline_plan meal elemein,
- csoportosít (`institutionId`, `sourceType`, slot kapcsolók, layout stb.),
- hívja a `meal_plan.php?action=menu` endpointot,
- fájlba írja:
  - `localweb/assets/meal-menu/<hash>/today.json`
  - `localweb/assets/meal-menu/<hash>/tomorrow.json` (ha kell)
- visszaírja a `loop.json` settingsbe:
  - `offlinePrefetchedTodayFile`
  - `offlinePrefetchedTomorrowFile`
  - `offlinePrefetchedMenuSavedAt`
  - opcionálisan frissített `offlinePrefetchedMenuData`

### 6.3 Meal modul fallback lánc futáskor

`modules/meal-menu/m_meal_menu.html` inicializációs sorrend:

1. inline prefetched (`offlinePrefetchedMenuData`)
2. localStorage cache
3. API refresh (csak ha `runtimeApiFetchEnabled === true`)
4. offline fájlok (`offlinePrefetchedTodayFile`, `offlinePrefetchedTomorrowFile`)
5. inline prefetched újra
6. local cache újra
7. hard fallback (üres render)

Mivel sync payloadban a szerver explicit `runtimeApiFetchEnabled = false`-ra állítja, normál offline-sync módban a modul API nélkül is működik.

---

## 7) Lokális fájlstruktúra a kioszkon

Tipikus eredmény:

- `/opt/edudisplej/localweb/modules/loop.json`
- `/opt/edudisplej/localweb/modules/<module>/...` (letöltött modulfájlok)
- `/opt/edudisplej/localweb/assets/meal-menu/<hash>/today.json`
- `/opt/edudisplej/localweb/assets/meal-menu/<hash>/tomorrow.json`
- `/opt/edudisplej/localweb/modules/.download_info.json`
- `/opt/edudisplej/localweb/loop_player.html`

---

## 8) Biztonsági kontrollpontok

- Minden kioszk API tokennel hitelesített (`validate_api_token`).
- Company izoláció: `api_require_company_match` (asset streamnél is).
- Group izoláció: `api_require_group_company` / group ownership check.
- Modul letöltés jogosultság-ellenőrzött (`download_module.php`).
- Asset elérés path normalizált + uploads root alá kényszerített.
- Meal adatoknál source és company scope kontrollált SQL szűréssel.

---

## 9) Gyors hibakeresési checklist

1. `sync_service` log: kap-e `needs_update=true`-t? (`v1/device/sync.php`)
2. Lefut-e a `force_full_loop_refresh` és az `edudisplej-download-modules.sh`?
3. Frissült-e a `modules/loop.json` (`last_update`, `offline_plan`)?
4. Letöltődtek-e a modul könyvtárak a `modules/` alá?
5. Van-e meal JSON a `assets/meal-menu/...` alatt?
6. A loop settings tartalmazza-e `offlinePrefetchedTodayFile` mezőt?
7. Asset URL-ek `module_asset_file.php`-ra mutatnak-e tokennel?

---

## 10) Kapcsolódó forrásfájlok

### Szerver API
- `webserver/control_edudisplej_sk/api/v1/device/sync.php`
- `webserver/control_edudisplej_sk/api/kiosk_loop.php`
- `webserver/control_edudisplej_sk/api/download_module.php`
- `webserver/control_edudisplej_sk/api/meal_plan.php`
- `webserver/control_edudisplej_sk/api/group_loop/check_update.php`
- `webserver/control_edudisplej_sk/api/group_loop/module_asset_upload.php`
- `webserver/control_edudisplej_sk/api/group_loop/module_asset_file.php`
- `webserver/control_edudisplej_sk/modules/module_asset_service.php`

### Kliens (kioszk)
- `webserver/install/init/edudisplej_sync_service.sh`
- `webserver/install/init/edudisplej-download-modules.sh`
- `webserver/install/init/edudisplej-command-executor.sh`

### Modul rendererek
- `webserver/control_edudisplej_sk/modules/meal-menu/m_meal_menu.html`
- `webserver/control_edudisplej_sk/modules/gallery/m_gallery.html`
- `webserver/control_edudisplej_sk/modules/pdf/m_pdf.html`
- `webserver/control_edudisplej_sk/modules/video/m_video.html`
