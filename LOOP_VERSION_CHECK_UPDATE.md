# EduDisplej Sync Service - Loop Version Check Enhancement

## Szimpozium

A `edudisplej_sync_service.sh` szolgáltatás **továbbfejlesztésére** került sor ahhoz, hogy biztosítson:

1. **Kiosk Group Modules verzió ellenőrzést** - Az eszköz csoportjában és cégében a `kiosk_group_modules` táblából az `updated_at` mező alapján
2. **Automatikus loop frissítést** - Ha újabb verzió van a szerveren, letöltődik az aktuális loop konfiguráció (modulokkal)
3. **Szervíz újraindítást** - A kijelző frissítésének után automatikusan újraindul
4. **Biztonsági ellenőrzéseket** - Az API csak akkor válaszol, ha az eszköz valóban benne van a cégben

## Megvalósított Komponensek

### 1. Új API Végpont: `check_group_loop_update.php`

**Elérési út:** `/api/check_group_loop_update.php`

**Funkció:** 
- Ellenőrzi a device_id alapján, hogy az eszköz melyik csoportban és cégben van
- Verifikálja, hogy az eszköz valóban benne van a cégnél (biztonsági ellenőrzés)
- Visszaadja a `kiosk_group_modules` táblából az `updated_at` mező értékét

**Kérés (POST):**
```json
{
  "device_id": "abc123def456"
}
```

**Válasz (sikeres):**
```json
{
  "success": true,
  "kiosk_id": 42,
  "device_id": "abc123def456",
  "company_id": 5,
  "company_name": "Cég Neve",
  "group_id": 12,
  "config_source": "group",
  "module_count": 3,
  "loop_updated_at": "2026-02-02 17:41:44"
}
```

**Válasz (hiba - jogosulatlan):**
```json
{
  "success": false,
  "message": "Unauthorized"
}
```

**Biztonsági Jellegzetességek:**
- ✅ Verifikálja, hogy az eszköz létezik az adatbázisban
- ✅ Ellenőrzi, hogy az eszköz hozzá van rendelve egy céghez
- ✅ Megbizonyosodik arról, hogy a csoport ugyanahhoz a céghez tartozik
- ✅ Csak a `is_active = 1` modulokat számolja
- ✅ HTTP 403 státuszokat küld jogosulatlan kérésekre

### 2. Frissített `edudisplej_sync_service.sh`

#### Új API URL Konfiguráció
```bash
CHECK_GROUP_LOOP_UPDATE_API="${API_BASE_URL}/api/check_group_loop_update.php"
```

#### Fejlesztett `check_loop_updates()` Funkció

**Javulások:**
1. **API váltás** - Mostantól a `CHECK_GROUP_LOOP_UPDATE_API`-t hívja meg az összes jogosultság ellenőrzéssel
2. **Biztonsági hibakezelés** - Felismeri és kezeli a jogosulatlan kéréseket
3. **Részletesebb naplózás** - Emojikkal és jól formázott üzenetekkel
4. **Csomag információ** - Megmutatja melyik cég, csoport és forrás (kiosk vagy group)

**Új Log Üzenetek:**
```
📋 Loop version check: Company='Cég Neve', Source='group', Group='12'
🔄 No local loop found - downloading initial configuration from server...
⬆️ Server loop is newer - update required
✓ Loop configuration is up-to-date
📥 Downloading latest loop configuration and modules from kiosk_group_modules...
✅ Loop and modules updated successfully
🔄 Restarting kiosk display to apply new configuration...
✅ Kiosk display restarted successfully
⚠️ Loop check UNAUTHORIZED: Device does not belong to any company...
❌ Failed to restart kiosk display service
```

## Munkafolyamat

```
Sync Ciklus Indítása
        ↓
check_loop_updates() hívása device_id-vel
        ↓
API kérés: CHECK_GROUP_LOOP_UPDATE_API
        ↓
┌─────────────────────────────────────┐
│  API - Biztonsági Ellenőrzések      │
├─────────────────────────────────────┤
│ 1. Device létezik? ← NO → 403 Error │
│ 2. Company hozzárendelve? ← NO → 403│
│ 3. Group ugyanaz a cég? ← NO → 403  │
│ 4. Van aktív modul? ← NO → 400      │
└─────────────────────────────────────┘
        ↓ (Sikeres válasz)
loop_updated_at értékek összehasonlítása
        ↓
┌─────────────────┬─────────────────┐
│ Helyi > Szerver │ Szerver > Helyi │
├─────────────────┼─────────────────┤
│ ✓ Készen van    │ 📥 Frissítés!   │
└─────────────────┴─────────────────┘
        ↓ (ha frissítés kell)
edudisplej-download-modules.sh futtatása
        ↓
🔄 Kiosk display szervíz újraindítása
        ↓
✅ Szinkronizáció Befejeződött
```

## Implementáció Érdekei

### Felhasználó Szempontjából
- 🎯 **Automatikus frissítések** - Az eszköz automatikusan letölti a legújabb loop-ot
- 🔒 **Biztonság** - Csak a cégnél regisztrált eszközök frissíthetnek
- 📊 **Nyomkövetés** - Részletes logok minden lépésről

### Rendszergazda Szempontjából
- 🔐 **Jogosultság Ellenőrzés** - Az API verifikálja az eszköz és cég kapcsolatát
- 📝 **Jó Logolás** - Emojikkal ellátott, könnyen olvasható naplók
- ⚡ **Teljesítmény** - Gyors API kérések JSON-nel
- 🛡️ **Hiba Tolerancia** - Megfelelő hibakezelés jogosulatlan kérésekre

## API Hibakódok

| HTTP Kód | Leírás |
|----------|--------|
| 200 | ✅ Sikeres kérés |
| 400 | ❌ Hiányzó/Érvénytelen paraméter vagy nincs aktív modul |
| 403 | ❌ Jogosulatlan (nincs hozzárendelés/cég) |
| 500 | ❌ Szerver hiba |

## Tesztelés

### API Tesztelése cURL-vel
```bash
# Sikeres kérés
curl -X POST "https://control.edudisplej.sk/api/check_group_loop_update.php" \
  -H "Content-Type: application/json" \
  -d '{"device_id":"abc123def456"}'

# Jogosulatlan kérés
curl -X POST "https://control.edudisplej.sk/api/check_group_loop_update.php" \
  -H "Content-Type: application/json" \
  -d '{"device_id":"nonexistent"}'
```

### Sync Service Tesztelése
```bash
# Debug mód engedélyezése
export EDUDISPLEJ_DEBUG=true

# Szinkronizáció manuális indítása
bash /opt/edudisplej/init/edudisplej_sync_service.sh

# Logok megtekintése
tail -f /opt/edudisplej/logs/sync.log
```

## Előnyök

✅ **Teljes verzió-ellenőrzés** - Az `updated_at` alapján szinkronizál  
✅ **Automatikus frissítések** - Nincs kézi beavatkozás szükséges  
✅ **Erős biztonsággal** - Csak az értékesített eszközök frissítik  
✅ **Jó felhasználói élmény** - Az eszközöket átláthatóan frissíti  
✅ **Részletes naplózás** - Könnyű hibakeresés  

## Jövőbeni Fejlesztések

- 🔄 Frissítési sürgősség szintjei (kritikus, normál, opcionális)
- 📊 Frissítési statisztikák és jelentések
- 🔔 Értesítések frissítési hibáról
- 🌍 Többnyelvű üzenetek
