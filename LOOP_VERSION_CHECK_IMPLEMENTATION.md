# Loop Version Check - Implementációs Útmutató

## Telepítés Lépései

### 1. Új API Végpont Telepítése

**Fájl:** `check_group_loop_update.php`  
**Helye:** `/webserver/control_edudisplej_sk/api/check_group_loop_update.php`

Másolni kell az alábbi helyre:
```bash
cp webserver/control_edudisplej_sk/api/check_group_loop_update.php \
   /path/to/production/api/check_group_loop_update.php
```

**Jogosultságok:**
```bash
chmod 644 /path/to/production/api/check_group_loop_update.php
```

### 2. Sync Service Frissítése

**Fájl:** `edudisplej_sync_service.sh`  
**Helye:** `/webserver/install/init/edudisplej_sync_service.sh`

Másolni kell az alábbi helyre:
```bash
cp webserver/install/init/edudisplej_sync_service.sh \
   /opt/edudisplej/init/edudisplej_sync_service.sh
chmod +x /opt/edudisplej/init/edudisplej_sync_service.sh
```

### 3. Szolgáltatás Újraindítása

```bash
# Sync szervíz újraindítása
systemctl restart edudisplej-sync.service

# Ellenőrzés
systemctl status edudisplej-sync.service

# Logok megtekintése
journalctl -u edudisplej-sync.service -f
```

## Konfigurációs Beállítások

### Environment Változók

```bash
# API URL beállítása (alapértelmezés: https://control.edudisplej.sk)
export EDUDISPLEJ_API_URL="https://control.edudisplej.sk"

# Debug mód engedélyezése
export EDUDISPLEJ_DEBUG=true
```

### Config File

Az `/opt/edudisplej/data/config.json` automatikusan létrejön, de szükség szerint módosítható:

```json
{
    "company_name": "Cég Neve",
    "company_id": 5,
    "device_id": "abc123def456",
    "token": "...",
    "sync_interval": 300,
    "last_update": "2026-02-02 17:41:44",
    "last_sync": "2026-02-03 10:15:22",
    "screenshot_enabled": false,
    "module_versions": {},
    "service_versions": {}
}
```

## Adatbázis Előfeltételek

Az `check_group_loop_update.php` API az alábbi táblákat használja:

### Táblák Szerkezete

1. **kiosks**
   - `id` - Elsődleges kulcs
   - `device_id` - Eszköz azonosító
   - `company_id` - Cég referencia (védelmi célra)
   - `mac` - MAC cím

2. **kiosk_group_assignments**
   - `kiosk_id` - Eszköz referencia
   - `group_id` - Csoport referencia

3. **kiosk_group_modules**
   - `group_id` - Csoport referencia
   - `module_id` - Modul referencia
   - `is_active` - Aktívitás jelzője
   - `updated_at` - Frissítési időpont (Fontos!)
   - `created_at` - Létrehozási időpont

4. **kiosk_groups**
   - `id` - Elsődleges kulcs
   - `company_id` - Cég referencia (Biztonsági ellenőrzéshez!)

5. **companies**
   - `id` - Elsődleges kulcs
   - `name` - Cég neve

### SQL Ellenőrzés

```sql
-- Ellenőrizze, hogy az eszköz benne van a cégben
SELECT k.id, k.device_id, k.company_id, c.name 
FROM kiosks k
LEFT JOIN companies c ON k.company_id = c.id
WHERE k.device_id = 'abc123def456';

-- Ellenőrizze a csoport és cég kapcsolatot
SELECT kg.id, kg.company_id, kg.name
FROM kiosk_groups kg
WHERE kg.id = 12 AND kg.company_id = 5;

-- Ellenőrizze a módulok frissítési időpontját
SELECT MAX(updated_at), MAX(created_at), COUNT(*)
FROM kiosk_group_modules
WHERE group_id = 12 AND is_active = 1;
```

## Tesztelési Forgatókönyvek

### Test 1: Sikeres Verzió Ellenőrzés

**Bemeneti adat:**
```bash
device_id = "valid_device_123"  # Létező eszköz
```

**Várt kimenet:**
```json
{
  "success": true,
  "loop_updated_at": "2026-02-02 17:41:44"
}
```

**Napló kimenet:**
```
📋 Loop version check: Company='Test Cég', Source='group', Group='1'
✓ Loop configuration is up-to-date
```

### Test 2: Jogosulatlan Kérés

**Bemeneti adat:**
```bash
device_id = "nonexistent_device"  # Nem létező eszköz
```

**Várt kimenet:**
```json
{
  "success": false,
  "message": "Unauthorized"
}
```

**HTTP Status:** 403

**Napló kimenet:**
```
⚠️ Loop check UNAUTHORIZED: Device does not belong to any company or group access denied
```

### Test 3: Frissítés Szükséges

**Feltételek:**
- Helyi `loop.json`: `2026-02-01 10:00:00`
- Szerver `updated_at`: `2026-02-02 17:41:44`

**Várt kimenet:**
```
⬆️ Server loop is newer - update required
📥 Downloading latest loop configuration and modules...
✅ Loop and modules updated successfully
🔄 Restarting kiosk display...
✅ Kiosk display restarted successfully
```

## Hibaelhárítás

### Problem: "Loop check failed"

**Megoldás:**
1. Ellenőrizze az API URL-t (`EDUDISPLEJ_API_URL`)
2. Verifikálja a hálózati kapcsolatot
3. Nézze meg a szerver naplókat

```bash
# API válasz tesztelése
curl -X POST "https://control.edudisplej.sk/api/check_group_loop_update.php" \
  -H "Content-Type: application/json" \
  -d '{"device_id":"test"}'
```

### Problem: "UNAUTHORIZED"

**Megoldás:**
1. Ellenőrizze, hogy az eszköz regisztrálva van-e
2. Verifikálja, hogy van hozzárendelt cég
3. Nézze meg a `kiosks` táblát:

```bash
mysql -u user -p database -e \
  "SELECT device_id, company_id FROM kiosks WHERE device_id='abc123';"
```

### Problem: "No local loop found"

**Megoldás:**
1. Ez normális az első futtatásnál
2. Verifikálja, hogy a `LOOP_FILE` írható:

```bash
ls -la /opt/edudisplej/localweb/modules/loop.json
```

### Problem: Szervíz Újraindítási Hiba

**Megoldás:**
```bash
# Ellenőrizze a szervíz státuszát
systemctl status edudisplej-kiosk.service

# Nézze meg a rendszer naplókat
journalctl -u edudisplej-kiosk.service -n 50

# Kézi újraindítás
systemctl restart edudisplej-kiosk.service
```

## Monitoring és Naplózás

### Log Fájlok

```bash
# Szinkronizáció logok
tail -f /opt/edudisplej/logs/sync.log

# Szervíz logok (systemd)
journalctl -u edudisplej-sync.service -f

# Frissítés logok
tail -f /opt/edudisplej/logs/update.log
```

### Debug Mód Engedélyezése

```bash
# Szerkesztse a service fájlt
vim /etc/systemd/system/edudisplej-sync.service

# Adja hozzá a követezőt az [Service] szakaszhoz
Environment="EDUDISPLEJ_DEBUG=true"

# Frissítse a systemd
systemctl daemon-reload
systemctl restart edudisplej-sync.service
```

### Log Szintek

| Szint | Jel | Leírás |
|-------|-----|--------|
| INFO | ℹ️ | Általános információk |
| DEBUG | 🔍 | Részletes debug információk (csak ha enabled) |
| SUCCESS | ✅ | Sikeres műveletek |
| ERROR | ❌ | Hibák |
| WARNING | ⚠️ | Figyelmeztetések |

## Performance Tuning

### Szinkronizáció Intervalluma

Alapértelmezés: **300 másodperc** (5 perc)

Módosítás:
```bash
# Sync szervíz konfig fájlban
vim /etc/systemd/system/edudisplej-sync.service

# SYNC_INTERVAL módosítása (másodperc)
Environment="SYNC_INTERVAL=600"  # 10 perc

# Alkalmazás
systemctl daemon-reload
systemctl restart edudisplej-sync.service
```

### API Timeout Beállítások

`edudisplej_sync_service.sh`-ben:

```bash
# Alapértelmezés: 30 másodperc
--max-time 30

# Csökkentse lassú hálózatnál 10-re
--max-time 10
```

## Biztonsági Ajánlások

✅ **Mindig HTTPS-t használjon**
- API URL-ben HTTPS végpontot adjon meg

✅ **Eszköz Azonosítás**
- Az API csak device_id alapján működik (nincs jelszó szükséges)
- Az API elveszi az eszköz cég-hozzárendeléshez

✅ **Jogosultság Ellenőrzés**
- Az API ellenőrzi, hogy az eszköz valóban a cégnél van-e
- Jogosulatlan kérésekre 403-as választ küld

✅ **Naplózás**
- Minden API kérés naplózva van
- Biztonsági események külön naplózva

## Támogatás

### Gyakran Ismételt Kérdések

**K: Mennyi idő alatt frissül az eszköz?**
A: Az alapértelmezett szinkronizációs intervallum 5 perc. Ez módosítható a config-ban.

**K: Mi történik, ha az API elérhetetelen?**
A: Az eszköz 60 másodpercig vár, majd újrapróbálkozik. Az szinkronizáció folytatódik.

**K: Lehet-e a szinkronizációs intervallumot dinamikusan módosítani?**
A: Igen, az API válaszban küldött `sync_interval` érték felülírja az alapértelmezést.

**K: A frissítés alatt megjelenik-e valami a kijelzőn?**
A: A frissítés után a kijelző automatikusan újraindul, az új loop-ot mutatva.

### Kapcsolat

Támogatásért nézze meg a README.md fájlt vagy vegye fel a kapcsolatot a fejlesztőcsapattal.
