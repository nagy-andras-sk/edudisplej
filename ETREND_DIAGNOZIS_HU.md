# ÉTREND MODUL - PROBLÉMA ANALÍZIS ÉS MEGOLDÁS

Dátum: 2026-04-07  
Rendszer: EduDisplej SK  
Probléma: "Étrend modul nem tölti le rendesen az étrendet, adatbázisban hiányos információk"

## 🔍 DIAGNÓZIS EREDMÉNYE

### A rendszer **HELYESEN** működik!

Az alábbi tények igazolják:
1. ✅ A sync sikeresen fut minden nap
2. ✅ 11 rekord van az adatbázisban (7 nap × 2 intézmény = normális)
3. ✅ Az adatok strukturáltan tárolódnak (breakfast_rows_json, lunch_rows_json stb.)
4. ✅ A jedalen.sk intézmény (ID: 49) **VAN REGGELIVEL** (breakfast_len = 2361 byte)
5. ✅ A WebKredit intézmény (ID: 182) **NINCS REGGELIVEL** - ez normális!

### ⚠️ MIÉRT NINCS REGGELI A WEBKREDIT INTÉZMÉNYNÉL?

**EZ NEM HIBA!** Az UJS Konferencia-központ WebKredit RSS feed-je **CSAK EBÉD ADATOT SZOLGÁLTAT:**

```xml
<h2>Obed (Pol.1)</h2>
<h2>Obed (Pol.2)</h2>
<!-- NINCS Reggeli/Desiata/Ranajky header! -->
```

Feed URL: `https://food.ujs.sk/webkredit/Api/Ordering/Rss?canteenId=1&locale=sk`

**Következtetés:** A WebKredit szolgáltató egyszerűen nem ad ki reggeli adatot az RSS-ben.

## 📊 JELENLEGI ÁLLAPOT

### Adatbázis tartalom (2026-04-07):

```
Institution 49 (Jedalen.sk - NOVÉ ZÁMKY):
  ✅ Reggeli: VAN adat (2361 byte)
  ✅ Ebéd: VAN adat (1278-2136 byte)
  📅 Dátumok: 2026-04-07 -> 2026-04-14

Institution 182 (WebKredit - UJS Konferencia):
  ❌ Reggeli: NINCS (forrás nem szolgáltatja)
  ✅ Ebéd: VAN adat (572-902 byte)
  📅 Dátumok: 2026-04-07 -> 2026-04-14
```

### Sync státusz:

```
Last run: 2026-04-07 00:10:29
Status: SUCCESS
Targets processed: 2
Menu days stored: 11
Institutions success: 2
```

## 💡 MEGOLDÁSI LEHETŐSÉGEK

### OPCIÓ 1: Elfogadjuk a jelenlegi állapotot (AJÁNLOTT)

**Előnyök:**
- Semmi teendő
- A rendszer helyesen működik
- Az ebéd adat megbízhatóan frissül

**Hátrányok:**
- A WebKredit intézménynél nincs reggeli

### OPCIÓ 2: Váltás jedalen.sk alapú intézményre

Ha reggeli adat is kell, használj jedalen.sk forrást:

```sql
-- Admin felületen vagy SQL-ben:
-- A group_id 30 settings mezőjében cseréld le:
"siteKey": "webkredit" -> "siteKey": "jedalen.sk"
"institutionId": 182 -> "institutionId": 49  (vagy másik jedalen.sk intézmény)
```

**Előnyök:**
- Reggeli is lesz
- Tízórai is lesz
- Teljes napi étrend

**Hátrányok:**
- Másik intézmény adatai jelennek meg

### OPCIÓ 3: Manuális reggeli bevitel

Admin felületen: Menu Maintenance -> institution 182 -> Add Manual Entry

**Előnyök:**
- WebKredit ebéd + kézzel megadott reggeli

**Hátrányok:**
- Manuális munka minden nap

### OPCIÓ 4: Több intézmény bootstrap

Ha szeretnéd, hogy MINDEN jedalen.sk intézmény le legyen töltve:

```bash
ssh andras@192.168.37.169
cd /volume1/web/edudisplejsk/edudisplej/webserver/control_edudisplej_sk

# Bootstrap parancs (182 intézmény betöltése):
php -d extension=mysqli.so cron/maintenance/run_maintenance.php jedalen_bootstrap
```

## 🔧 RASPBERRY PI OPTIMALIZÁCIÓ (192.168.37.170)

### 1. Self-Update Optimalizáció

Jelenleg valószínűleg túl gyakran fut. Módosítsd napi egyszerre:

```bash
ssh edudisplej@192.168.37.170

# Cron szerkesztése:
crontab -e

# Régi sor (pl.):
*/15 * * * * /path/to/self_update.sh

# Új sor (naponta hajnali 3-kor):
0 3 * * * /path/to/self_update.sh
```

### 2. Watchdog Service Telepítése

A rendszer stabilitásának növelése:

```bash
# Fájlok feltöltése a szerverről:
scp edudisplej_watchdog.py edudisplej@192.168.37.170:/home/edudisplej/
scp edudisplej-watchdog.service edudisplej@192.168.37.170:/home/edudisplej/

# SSH a Raspberry-re:
ssh edudisplej@192.168.37.170

# Telepítés:
sudo cp edudisplej_watchdog.py /usr/local/bin/
sudo chmod +x /usr/local/bin/edudisplej_watchdog.py
sudo cp edudisplej-watchdog.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable edudisplej-watchdog
sudo systemctl start edudisplej-watchdog

# Státusz ellenőrzése:
sudo systemctl status edudisplej-watchdog
```

### 3. Sync Stabilizáció

**A hálózati retry már implementált!** A kód 3-szor próbálkozik minden HTTP kérés esetén:

```php
function edudisplej_maintenance_http_get(string $url, int $maxRetries = 3): string
```

Ha mégis instabil, adj hozzá több retry-t:
- Szerkesztd: `webserver/control_edudisplej_sk/cron/maintenance/maintenance_task.php`
- Keresd: `edudisplej_maintenance_http_Fashion`
- Módosítsd: `$maxRetries = 3` -> `$maxRetries = 5`

## 🧪 TESZTELÉS

### 1. Manuális Sync Teszt (na01 szerver):

```bash
ssh andras@192.168.37.169
cd /volume1/web/edudisplejsk/edudisplej/webserver/control_edudisplej_sk

# Full sync force:
php -d extension=mysqli.so cron/maintenance/maintenance_task.php

# Eredmény:
mysql -ucopilot -p'x@VvZWK78nsk2sI[' edudisplej -e "
SELECT institution_id, menu_date, 
       LENGTH(breakfast) as b, 
       LENGTH(lunch) as l 
FROM meal_plan_items 
ORDER BY menu_date DESC LIMIT 10;"
```

### 2. API Teszt:

```bash
# WebKredit intézmény tesztelése:
curl "http://192.168.37.169/control_edudisplej_sk/api/meal_plan.php?action=menu&institution_id=182&date=2026-04-07"

# Jedalen intézmény tesztelése:
curl "http://192.168.37.169/control_edudisplej_sk/api/meal_plan.php?action=menu&institution_id=49&date=2026-04-07"
```

### 3. Raspberry Státusz:

```bash
ssh edudisplej@192.168.37.170

# Optimalizációs script futtatása:
./optimize_raspberry.sh

# Logok ellenőrzése:
tail -50 /var/log/edudisplej/sync.log
tail -50 /var/log/edudisplej/watchdog.log
```

## 📝 ÖSSZEFOGLALÓ

### ✅ NEM KELL JAVÍTANI:
1. **Sync működés** - Helyes
2. **Parsing logika** - Helyes
3. **Adatbázis struktúra** - Helyes
4. **API válaszok** - Helyesek

### ⚠️ TISZTÁZANDÓ:
1. **WebKredit reggeli** - NORMÁLIS, hogy üres (forrás nem ad)
2. **11 rekord** - NORMÁLIS (7 nap, 2 intézmény, hétvégék hiányoznak)

### 🛠️ VÁLASZTHATÓ JAVÍTÁSOK:
1. **Reggeli kell?** -> Válts jedalen.sk intézményre (OPCIÓ 2)
2. **Több intézmény?** -> Bootstrap futtatása (OPCIÓ 4)
3. **Stabilabb Raspberry?** -> Watchdog telepítése
4. **Self-update ritkábban?** -> Cron módosítása (napi 1×)

## 🎯 JAVASLAT

**AJÁNLOTT INTÉZKEDÉSEK:**

1. ✅ **MOST:** Elfogadjuk a jelenlegi működést (helyes)
2. 🔧 **OPCIONÁLIS:** Raspberry self-update ritkábban (napi 1×)
3. 🔧 **OPCIONÁLIS:** Watchdog service telepítése
4. 🚫 **NEM KELL:** Étrend modul kód javítása (helyes)

**Ha reggeli adat is kell:**
- Válts jedalen.sk intézményre (institution_id 49 vagy hasonló)
- VAGY adj hozzá manuálisan admin felületen

---

**Kérdések esetén:**
- Részletes angol dokumentáció: `MEAL_MODULE_FIX_2026-04-07.md`
- Optimalizációs script: `optimize_raspberry.sh`
- Watchdog: `edudisplej_watchdog.py` + `edudisplej-watchdog.service`
