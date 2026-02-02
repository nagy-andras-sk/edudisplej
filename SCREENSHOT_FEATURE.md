# Screenshot Funkció - Telepítési Útmutató

## Leírás

A screenshot funkció lehetővé teszi, hogy a kijelzők rendszeresen képernyőképeket küldjenek a szervernek, amelyek megtekinthetők a dashboard-on.

## Főbb Funkciók

1. **Screenshot be/ki kapcsolás** - Kijelzőnként állítható
2. **Automatikus sync interval beállítás**:
   - Screenshot bekapcsolva: 15 másodperc
   - Screenshot kikapcsolva: 120 másodperc
3. **Manuális sync interval beállítás** - Tetszőleges érték állítható (10, 15, 30, 60, 120, 300, 600 másodperc)
4. **Screenshot megjelenítés** - A kijelző részleteiben
5. **Screenshot frissítés** - Kézi frissítés gomb

## Telepítés

### 1. Adatbázis Módosítás

Futtasd le az adatbázis migráció scriptet, hogy hozzáadja a `screenshot_enabled` mezőt a `kiosks` táblához:

```bash
# Böngészőben navigálj ide:
http://your-server/control_edudisplej_sk/api/db_add_screenshot_enabled.php
```

Vagy SQL-ben közvetlenül:
```sql
ALTER TABLE kiosks ADD COLUMN screenshot_enabled TINYINT(1) DEFAULT 0 AFTER screenshot_url;
```

### 2. Létrehozott/Módosított Fájlok

#### Új fájlok:
- `api/db_add_screenshot_enabled.php` - Adatbázis migráció script
- `api/toggle_screenshot.php` - Screenshot be/ki kapcsoló API
- `api/update_screenshot_settings.php` - Screenshot beállítások kezelése (opcionális használat)

#### Módosított fájlok:
- `api/kiosk_details.php` - Screenshot adatok lekérdezése
- `api/hw_data_sync.php` - Screenshot enabled flag visszaadása a kijelzőnek
- `dashboard/index.php` - Screenshot megjelenítés és vezérlés a UI-n

### 3. Kijelző Oldal (edudisplej_sync_service.sh)

A kijelző oldalon már létező `edudisplej_sync_service.sh` script-et használd, amely:
- Elküldi a screenshot-ot ha `screenshot_enabled = true`
- Használja a `sync_interval` értéket a szerverről

Példa cURL parancs a screenshot feltöltésére:
```bash
SCREENSHOT_DATA=$(scrot -o /tmp/screenshot.png && base64 -w 0 /tmp/screenshot.png)
curl -X POST "http://server/control_edudisplej_sk/api/screenshot_sync.php" \
     -H "Content-Type: application/json" \
     -d "{\"mac\":\"$MAC\",\"screenshot\":\"$SCREENSHOT_DATA\"}"
```

## Használat

### Dashboard-on

1. **Kattints egy kijelzőre** a listában
2. **Kijelző Részletek** ablak nyílik meg
3. **Képernyőkép szekció**:
   - **Be/ki kapcsoló**: Checkbox a screenshot funkció aktiválásához
   - **Frissítés gomb**: Újratölti a legfrissebb screenshot-ot
   - **Kép megjelenítés**: Ha van elérhető screenshot, automatikusan megjeleníti

### Sync Interval Beállítás

A **Szinkronizálás gyakorisága** dropdown menüben állítható:
- 10 másodperc
- 15 másodperc (alapértelmezett screenshot bekapcsolva esetén)
- 30 másodperc
- 1 perc (60s)
- 2 perc (120s - alapértelmezett screenshot kikapcsolva esetén)
- 5 perc (300s)
- 10 perc (600s)

**Automatikus beállítás**:
- Screenshot bekapcsolásakor automatikusan 15s-ra állítódik
- Screenshot kikapcsolásakor automatikusan 120s-ra állítódik
- Ezután manuálisan felülbírálható

## API Endpoints

### 1. Toggle Screenshot
`POST /api/toggle_screenshot.php`

Request body:
```json
{
  "kiosk_id": 123,
  "screenshot_enabled": 1
}
```

Response:
```json
{
  "success": true,
  "message": "Screenshot setting updated successfully",
  "screenshot_enabled": 1,
  "sync_interval": 15
}
```

### 2. Update Screenshot Settings
`POST /api/update_screenshot_settings.php`

Request body:
```json
{
  "kiosk_id": 123,
  "screenshot_enabled": 1,
  "screenshot_interval": 30
}
```

### 3. Get Kiosk Details
`GET /api/kiosk_details.php?id=123`

Response tartalmazza:
```json
{
  "screenshot_enabled": 1,
  "screenshot_url": "screenshots/screenshot_abc123.png",
  "sync_interval": 15
}
```

### 4. Hardware Data Sync (Kijelző felől)
`POST /api/hw_data_sync.php`

Response tartalmazza:
```json
{
  "screenshot_enabled": true,
  "sync_interval": 15
}
```

## Adatbázis Séma

```sql
-- kiosks tábla módosítása
ALTER TABLE kiosks ADD COLUMN screenshot_enabled TINYINT(1) DEFAULT 0;

-- Meglévő mezők:
-- screenshot_url VARCHAR(255) - A screenshot fájl relatív elérési útja
-- screenshot_requested TINYINT(1) - Igényelt-e új screenshot (opcionális)
-- sync_interval INT - Szinkronizálási időköz másodpercben
```

## Troubleshooting

### Screenshot nem jelenik meg
1. Ellenőrizd, hogy a `screenshots/` könyvtár létezik és írható
2. Nézd meg, hogy a kijelző tényleg küld-e screenshot-ot
3. Ellenőrizd a `screenshot_url` mezőt az adatbázisban

### Sync interval nem változik
1. Ellenőrizd a böngésző konzolt hibákért
2. Nézd meg a szerver PHP error log-ot
3. Teszteld az API endpoint-ot közvetlenül (Postman/curl)

### Adatbázis hiba
1. Futtasd le újra a migráció scriptet: `api/db_add_screenshot_enabled.php`
2. Ellenőrizd, hogy a `screenshot_enabled` mező létezik a `kiosks` táblában

## Jövőbeli Fejlesztések

- [ ] Screenshot képek automatikus törlése X nap után
- [ ] Screenshot előzmények (több kép tárolása időbélyeggel)
- [ ] Screenshot minőség beállítása
- [ ] Screenshot méret optimalizálás
- [ ] Screenshot letöltés gomb
- [ ] Screenshot összehasonlítás (előző vs. jelenlegi)
