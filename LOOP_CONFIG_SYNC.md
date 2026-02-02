# Loop Configuration Synchronization

## Áttekintés

A sync service automatikusan lekéri és frissíti a Group-hoz tartozó loop.json konfigurációt **timestamp alapú összehasonlítással**.

## Működés

### 1. Sync Folyamat
Amikor az `edudisplej-sync.service` fut:
1. Lekéri az aktuális loop konfigurációt az API-ból (`/api/kiosk_loop.php`)
2. Összehasonlítja a lokális loop.json `last_update` értékét a szerver `loop_last_update` értékével
3. Ha a szerver időpontja újabb, akkor:
   - Frissíti a lokális fájlt
   - Letölti a szükséges modulokat
   - Újraindítja az `edudisplej-kiosk.service`-t
4. Ha egyforma, akkor nem csinál semmit

### 2. Timestamp-alapú Változás Detektálás
Az API lekéri az adott group-hoz tartozó modulok legutolsó módosítási dátumát (MAX created_at):
- Lokális: `last_update` mező a loop.json-ban
- Szerver: `loop_last_update` a kiosk_group_modules táblában
- Összehasonlítás: egyszerű string összehasonlítás (ISO 8601 formátum)
- Ha szerver > lokális → sync szükséges

### 3. Loop.json Struktúra
```json
{
    "last_update": "2026-02-02 11:43:11",
    "loop": [
        {
            "module_id": 1,
            "module_name": "Clock & Time",
            "module_key": "clock",
            "duration_seconds": 5,
            "display_order": 0,
            "settings": {...},
            "source": "group"
        }
    ]
}
```

## API Endpoint

### `/api/kiosk_loop.php`
**Metódus:** POST  
**Paraméter:** `device_id`

**Válasz:**
```json
{
    "success": true,
    "kiosk_id": 123,
    "device_id": "abc123",
    "loop_config": [...],
    "module_count": 2,
    "loop_last_update": "2026-02-02 11:43:11"
}
```

## Logózás

Például a sync szolgáltatás logjaiban:
```
[2026-02-02 11:57:28] [INFO] Checking loop configuration changes...
[2026-02-02 11:57:31] [INFO]   Local loop update:  2026-02-02 09:17:34
[2026-02-02 11:57:31] [INFO]   Server loop update: 2026-02-02 11:43:11
[2026-02-02 11:57:31] [INFO] ⚠ Loop configuration changed!
[2026-02-02 11:57:31] [INFO]   Local:  2026-02-02 09:17:34
[2026-02-02 11:57:31] [INFO]   Server: 2026-02-02 11:43:11
[2026-02-02 11:57:31] [INFO] Updating local loop.json...
```

Vagy ha nincs változás:
```
[2026-02-02 11:58:03] [INFO] Checking loop configuration changes...
[2026-02-02 11:58:03] [INFO]   Local loop update:  2026-02-02 11:43:11
[2026-02-02 11:58:03] [INFO]   Server loop update: 2026-02-02 11:43:11
[2026-02-02 11:58:03] [INFO] ✓ Loop configuration is up-to-date
[2026-02-02 11:58:03] [INFO]   Timestamp: 2026-02-02 11:43:11
```

## Módosított Fájlok

1. **kiosk_loop.php** - API csak az `loop_last_update` és `loop_config` mezőket adja vissza
2. **edudisplej_sync_service.sh** - Timestamp alapú összehasonlítás
3. **edudisplej-download-modules.sh** - Egyszerűsített loop.json formátum

## Hibaelhárítás

### Loop nem frissül
```bash
# Ellenőrizd a lokális loop.json-t
cat /opt/edudisplej/localweb/modules/loop.json

# Kényszerített frissítés (töröld a local fájlt)
sudo rm /opt/edudisplej/localweb/modules/loop.json
sudo systemctl restart edudisplej-sync.service
```

### Service újraindítás
```bash
sudo systemctl restart edudisplej-sync.service
sudo journalctl -u edudisplej-sync.service -f
```
