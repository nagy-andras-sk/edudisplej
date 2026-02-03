# ✨ Loop Version Check - Gyors Referencia

## Mi az Új?

Az **edudisplej_sync_service** mostantól automatikusan ellenőrzi, hogy van-e újabb loop konfiguráció és modul verzió:

### Működés
```
┌─────────────────────────────────────────────────────────┐
│  5 percenként (beállítható)                              │
│  ↓                                                        │
│  API lekérdezés: Milyen új van a kiosk_group_modules-ban?│
│  ↓                                                        │
│  Ha újabb: ⬇️ Letöltés → 🔄 Újraindítás → ✅ Kész!        │
└─────────────────────────────────────────────────────────┘
```

## Új API Végpont

**Elérési út:** `api/check_group_loop_update.php`

Ezt az API-t a szinkronizációs szervíz automatikusan hívja meg. Kézi híváshoz:

```bash
curl -X POST "https://control.edudisplej.sk/api/check_group_loop_update.php" \
  -H "Content-Type: application/json" \
  -d '{"device_id":"device_mac_address"}'
```

## Jellegzetességek

| Jellegzetesség | Leírás |
|---|---|
| 🔒 **Biztonsági ellenőrzés** | Csak a cégnél regisztrált eszközök frissíthetnek |
| 🔄 **Automatikus frissítés** | Nem szükséges kézi beavatkozás |
| 📊 **Verzió-összevetés** | Az `updated_at` mező alapján |
| 📥 **Modul letöltés** | Automatikus modul szinkronizáció |
| 🔔 **Kijelző újraindítás** | Az új loop automatikusan érvényre lép |
| 📝 **Részletes naplózás** | Emojikkal ellátott, könnyen olvasható logok |

## Napló Üzenetek

### Normális működés
```
✅ Loop configuration is up-to-date
📋 Loop version check: Company='Cég', Source='group', Group='1'
```

### Frissítés szükséges
```
⬆️ Server loop is newer - update required
📥 Downloading latest loop configuration and modules...
✅ Loop and modules updated successfully
🔄 Restarting kiosk display...
✅ Kiosk display restarted successfully
```

### Hiba
```
⚠️ Loop check UNAUTHORIZED: Device does not belong to any company
❌ Module update failed
```

## Tesztelés

### API Tesztelése
```bash
# Kérvény
curl -X POST "https://control.edudisplej.sk/api/check_group_loop_update.php" \
  -H "Content-Type: application/json" \
  -d '{"device_id":"abc123"}'

# Válasz (siker)
{"success":true,"company_id":5,"loop_updated_at":"2026-02-02 17:41:44"}

# Válasz (hiba)
{"success":false,"message":"Unauthorized"}
```

### Szinkronizáció Tesztelése
```bash
# Debug mód
export EDUDISPLEJ_DEBUG=true

# Szervíz manuál indítása
bash /opt/edudisplej/init/edudisplej_sync_service.sh

# Logok megjelenítése
tail -f /opt/edudisplej/logs/sync.log
```

## Beállítások

### Szinkronizáció Intervalluma

Alapértelmezés: 300 másodperc (5 perc)

Módosítás a systemd service fájlban:
```bash
Environment="SYNC_INTERVAL=600"  # 10 perc
```

### API URL Módosítása
```bash
Environment="EDUDISPLEJ_API_URL=https://your-api.com"
```

### Debug Mód
```bash
Environment="EDUDISPLEJ_DEBUG=true"  # Részletes logok
```

## HTTP Státusz Kódok

| Kód | Jelentés |
|-----|----------|
| 200 | ✅ Sikeres (loop_updated_at érték visszaadva) |
| 400 | ❌ Hiányzó paraméter vagy nincs aktív modul |
| 403 | ❌ Jogosulatlan (nincs hozzárendelés vagy cég) |
| 500 | ❌ Szerver hiba |

## Hibakeresés

### "UNAUTHORIZED" Hiba

Az API 403-at küld. Okok:
- Az eszköz nincs regisztrálva
- Nincs hozzárendelt cég
- A csoport más céghez tartozik

**Megoldás:**
```bash
# Ellenőrizze az eszköz regisztrációját
mysql> SELECT device_id, company_id FROM kiosks 
        WHERE device_id='abc123';

# Ellenőrizze a csoport-cég kapcsolatot
mysql> SELECT id, company_id FROM kiosk_groups WHERE id=1;
```

### "Loop check failed" Hiba

Az API nem válaszol. Okok:
- Hálózati probléma
- API nem érhető el
- Szyntaxiszhiba

**Megoldás:**
```bash
# Tesztelje az API-t közvetlenül
curl -v -X POST "https://control.edudisplej.sk/api/check_group_loop_update.php" \
  -H "Content-Type: application/json" \
  -d '{"device_id":"test"}'
```

### Modul Frissítési Hiba

**Okok:**
- Download script nem elérhető
- Lemezterület nincs
- Hálózati timeout

**Megoldás:**
```bash
# Ellenőrizze a download scriptet
ls -la /opt/edudisplej/init/edudisplej-download-modules.sh

# Ellenőrizze a szabad lemezterületet
df -h /opt/edudisplej/

# Nézze meg a teljes naplót
tail -100 /opt/edudisplej/logs/sync.log
```

## Szolgáltatás Kezelés

```bash
# Status ellenőrzés
systemctl status edudisplej-sync.service

# Újraindítás
systemctl restart edudisplej-sync.service

# Logok megtekintése
journalctl -u edudisplej-sync.service -f

# Leállítás (ha szükséges)
systemctl stop edudisplej-sync.service

# Engedélyezés bootkor
systemctl enable edudisplej-sync.service
```

## Előnyök

✅ **Automatikus frissítések** - Nincs kézi szinkronizáció szükséges  
✅ **Biztonsági** - Csak az értékesített eszközök frissíthetnek  
✅ **Megbízható** - Verifikált eszköz-cég-csoport kapcsolatok  
✅ **Gyors** - Hatékony JSON alapú kommunikáció  
✅ **Átlátható** - Részletes naplózás és hibakeresés  

## Támogatás

Kapcsolatfelvételi információkért nézze meg a **README.md** fájlt.
