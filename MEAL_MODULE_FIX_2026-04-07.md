# Étrend modul javítás - 2026-04-07

## Probléma analízis

### Észlelt problémák:
1. Az étrendmodul "nem töltödik le rendesen"
2. Az adatbázisban "hiányos információk vannak"
3. Csak 11 rekord van az adatbázisban
4. WebKredit intézmény reggelije üres (breakfast_len = 0)

### Valós OK-OK:

#### 1. SYNC csak használt intézményekre fut (HELYES MŰKÖDÉS)
- A rendszer **szándékosan** csak azokat az intézményeket szinkronizálja, amelyek aktívan használatban vannak
- Ez optimalizáció: nem tölt le felesleges adatot
- Jelenleg csak 1 group_id (30) használja az étrendmodult
- Ez a group az institution_id 182-t (W. UJS Konferencia-központ, WebKredit) használja

#### 2. WebKredit NEM szolgáltat reggelit (ADATFORRÁS LIMIT)
```xml
<h2>Obed (Pol.1)</h2>  <!-- Csak ebéd -->
<h2>Obed (Pol.2)</h2>  <!-- Csak ebéd -->
```
- A WebKredit RSS feed (`https://food.ujs.sk/webkredit/Api/Ordering/Rss?canteenId=1&locale=sk`)
- **CSAK** "Obed" (ebéd) adatokat tartalmaz
- Ez nem programozási hiba, az intézmény egyszerűen nem ad ki reggeli adatot a feed-ben

#### 3. A parsing HELYESEN működik
- A jedalen.sk parsing működik és letölt breakfast/snack_am adatokat
- Institution_id 49 (NOVÉ ZÁMKY, jedalen.sk) breakfast_len = 2361, tehát VAN adat
- A WebKredit parsing is helyesen működik, de a forrás nem ad reggelit

### Sync működése JELENLEGI:

```
2026-04-07 00:10:29: 
- Targets processed: 2
- Menu days stored: 11  
- Institutions success: 2
  - Institution 182 (WebKredit): lunch OK, breakfast NINCS (forrásban sincs)
  - Institution 49 (Jedalen.sk): lunch OK, breakfast OK
```

## JAVÍTÁSI JAVASLATOK

### 1. Több intézmény szinkronizálása (OPCIONÁLIS)

Ha szeretnéd, hogy **MINDEN** jedalen.sk intézmény le legyen töltve (nem csak a használtak):

**Megoldás A:** Maintenance settings beállítása
```php
// maintenance_settings táblában:
jedalen_sync_every_cycle = 1  // Minden cikluson fut
```

**Megoldás B:** Bootstrap force
Futtatd ezt a parancsot egyszer, hogy betöltse az összes intézményt:
```bash
cd /volume1/web/edudisplejsk/edudisplej/webserver/control_edudisplej_sk
php -d extension=mysqli.so cron/maintenance/run_maintenance.php jedalen_bootstrap
```

### 2. WebKredit reggeli probléma MEGOLDÁSA

**A WebKredit NEM ad reggelit!** Ezt nem lehet "javítani", mert az adatforrás nem szolgáltatja.

**Lehetséges megoldások:**
a) **Manuális bevitel:** Admin felületen adj hozzá reggeli adatokat az institution_id 182-höz
b) **Alternatív forrás:** Használj jedalen.sk alapú intézményt reggeli adatokhoz
c) **Hybrid:** Kombináld a WebKredit ebédet egy jedalen.sk reggelijével

### 3. Rendszer optimalizáció

#### A) Sync intervallum optimalizáció

A jelenlegi sync jó, de módosítható:

```sql
-- 15 perces helyett 30 perces sync
UPDATE maintenance_settings 
SET setting_value = '1800' 
WHERE setting_key = 'maintenance_interval_sec';

-- Jedalen sync csak éjszaka
UPDATE maintenance_settings 
SET setting_value = '0',  -- éjfél
    setting_key = 'jedalen_sync_window_start'
WHERE setting_key = 'jedalen_sync_window_start';

UPDATE maintenance_settings 
SET setting_value = '5',  -- hajnal 5
    setting_key = 'jedalen_sync_window_end'
WHERE setting_key = 'jedalen_sync_window_end';
```

#### B) Group Plan verzió refresh optimalizáció

A kód már optimalizált: csak akkor frissíti a loop plan verziót, ha új adat érkezett.

#### C) Offline prefetch optimalizáció

A modul már támogatja az offline működést:
- `offlinePrefetchedTodayFile`
- `offlinePrefetchedTomorrowFile`  
- `offlinePrefetchedMenuData`

Ezek automatikusan generálódnak sync közben.

### 4. Raspberry Pi (192.168.37.170) optimalizáció

#### A) Self-update optimalizáció

Jelenlegi probléma: túl gyakori lehet az update check.

**Javítás:**
```bash
# SSH: edudisplej@192.168.37.170

# Cron job módosítása - csak naponta egyszer
crontab -e

# Régi: */15 * * * * /path/to/check_update.sh
# Új: 0 3 * * * /path/to/check_update.sh   # Hajnali 3-kor
```

#### B) Synchronization stabilizáció

**Hálózat retry mechanizmus már van a kódban:**
```php
function edudisplej_maintenance_http_get(string $url, int $maxRetries = 3): string
```

**További stabilizáció SSH-n keresztül:**
```bash
# Watchdog service létrehozása
sudo nano /etc/systemd/system/edudisplej-watchdog.service

[Unit]
Description=EduDisplej Watchdog
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/python3 /path/to/watchdog.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

## MEGOLDÁS ÖSSZEGZÉSE

### ✅ NEM kell javítani:
1. **Sync logika** - Helyes, csak használt intézményekre fut
2. **Parsing logika** - Helyes, mindent megfelelően dolgoz fel
3. **Adatbázis struktúra** - Helyes, structured_rows_json mezők is vannak

### ⚠️ TISZTÁZÁS szükséges:
1. **WebKredit reggeli** - Ez NORMÁLIS, hogy üres, mert a forrás nem szolgáltatja
2. **11 rekord** - Ez NORMÁLIS, 7 napra + 2 intézmény = ~11 rekord (hétvégék kiesnek)

### 🔧 OPCIONÁLIS javítások:
1. **Bootstrap** futtatása, ha szeretnéd az összes jedalen.sk intézményt
2. **Sync időzítés** módosítása igény szerint
3. **Raspberry watchdog** a stabilabb működéshez

## KÖVETKEZŐ LÉPÉSEK

### 1. Döntés: Szükséges-e több intézmény?
Ha IGEN:
```bash
ssh andras@192.168.37.169
cd /volume1/web/edudisplejsk/edudisplej/webserver/control_edudisplej_sk
php -d extension=mysqli.so cron/maintenance/run_maintenance.php jedalen_bootstrap
```

### 2. Döntés: WebKredit reggeli kezelése
- **A)** Elfogadjuk, hogy nincs reggeli (jelenleg így működik)
- **B)** Manuálisan visszük fel az adatokat admin felületen
- **C)** Váltunk jedalen.sk alapú intézményre

### 3. Raspberry optimalizáció
```bash
ssh edudisplej@192.168.37.170
# Ellenőrizd a cron job-okat
crontab -l
# Ellenőrizd a sync logokat
tail -100 /var/log/edudisplej/sync.log
```

## TESZTELÉS

### Manuális sync test (na01 szerveren):
```bash
ssh andras@192.168.37.169
cd /volume1/web/edudisplejsk/edudisplej/webserver/control_edudisplej_sk

# Teljes sync force
php -d extension=mysqli.so cron/maintenance/maintenance_task.php

# Eredmény ellenőrzése
mysql -ucopilot -p'x@VvZWK78nsk2sI[' edudisplej -e "
SELECT institution_id, menu_date, 
       LENGTH(breakfast) as b_len, 
       LENGTH(lunch) as l_len 
FROM meal_plan_items 
ORDER BY menu_date DESC 
LIMIT 20;"
```

### API test:
```bash
curl "http://192.168.37.169/control_edudisplej_sk/api/meal_plan.php?action=menu&institution_id=182&date=2026-04-07"
```

## SUMMARY

**A rendszer HELYESEN működik!**
- A sync megfelelően letölti az adatokat
- A parsing helyesen dolgozza fel őket
- Az adatbázisban strukturáltan tárolódnak

**Az "üres reggeli" nem hiba, hanem adatforrás-korlátozás.**

Ha szeretnéd, hogy reggeli is legyen:
1. Használj jedalen.sk alapú intézményt (pl. institution_id 49)
2. Vagy adj hozzá manuálisan admin felületen
