# Display Scheduling System - Dokumentáció

Az **Display Scheduling System** egy teljes körű megoldás a Raspberry Pi kijelzők időzítésére és energiaszabályozására.

## Rendszer Összetevői

### 1. **Backend Logic** (`api/display_scheduler.php`)
A PHP-ben implementált adatbázis logika.

#### Adatbázis Táblák:
- **display_schedules**: Ütemezések fő táblája
  - `schedule_id`: Egyedi azonosító
  - `kijelzo_id`: Kijelző azonosító
  - `group_id`: Csoport azonosító
  - `created_at`, `updated_at`: Időbélyegek

- **schedule_time_slots**: Időblokkokat kezeli
  - `slot_id`: Egyedi azonosító
  - `schedule_id`: Utális ütemezés ID
  - `day_of_week`: 0-6 (vasárnap-szombat)
  - `start_time`: HH:MM:SS formátumban
  - `end_time`: HH:MM:SS formátumban
  - `is_enabled`: 1=bekapcsolt, 0=kikapcsolt

- **schedule_special_days**: Speciális napok (ünnepek, etc.)
  - Lehetővé teszi kivételes ütemezéseket
  - Felülírja a heti ütemezést

- **display_status_log**: Státusz változási log
  - Auditálás és hibaelhárítás céljára

#### Főbb Metódusok:

```php
// Alapértelmezett ütemezés létrehozása (22:00-06:00 kikapcsolt)
$scheduler->createDefaultScheduleForGroup($group_id, $kijelzo_id);

// Aktuális státusz lekérése (ACTIVE vagy TURNED_OFF)
$status = $scheduler->getCurrentDisplayStatus($kijelzo_id);

// Teljes ütemezés lekérése
$schedule = $scheduler->getScheduleForDisplay($kijelzo_id);

// Timeblokk hozzáadása
$scheduler->addTimeSlot($schedule_id, $day, $start_time, $end_time, $is_enabled);
```

### 2. **API Endpoints** (`api/display_schedule_api.php`)

#### Admin-szint vezérlés:
```
POST /api/admin/display_schedule/create
- group_id: int
- kijelzo_id: int
- returns: schedule_id

GET /api/admin/display_schedule/{id}
- returns: ütemezés adatok + time_slots

POST /api/admin/display_schedule/time_slot
- schedule_id: int
- day_of_week: int (0-6)
- start_time: string (HH:MM:SS)
- end_time: string (HH:MM:SS)
- is_enabled: boolean
- returns: slot_id
```

#### Kijelző státusz lekérdezés:
```
GET /api/kijelzo/{id}/schedule_status
- returns: { status: "ACTIVE" | "TURNED_OFF" }

POST /api/kijelzo/{id}/schedule_force_status (Admin only)
- status: string
- reason: string
- returns: ok
```

### 3. **Raspberry Pi Daemon** (`scripts/edudisplej-scheduler.py`)

A Python démon a Raspberry Pi-n futó service, amely:

#### Fő Funkcionalitás:
```python
# API-ról lekérdi jelenlegi státuszt
status = get_schedule_status(kijelzo_id)

# Szolgáltatást vezérli
control_service('edudisplej', 'start')  # vagy 'stop'

# HDMI kimenetet vezérli
control_hdmi('on')  # vagy 'off'

# Percenként ellenőrzi és alkalmazza
run()  # Main polling loop
```

#### Konfiguráció (`/etc/edudisplej/display_scheduler.conf`):
```ini
[api]
url = http://localhost/api
verify_ssl = true

[device]
kijelzo_id = 1

[scheduler]
check_interval = 60
enable_hdmi_control = true

[services]
content_service = edudisplej-content

[logging]
log_file = /var/log/edudisplej-scheduler.log
log_level = INFO
```

#### Systemd Service (`/etc/systemd/system/edudisplej-scheduler.service`):
```
[Service]
ExecStart=/usr/local/bin/edudisplej-scheduler.py
Restart=always
RestartSec=30
User=edudisplej
StandardOutput=journal
StandardError=journal
```

### 4. **Frontend Modul** (`modules/display-scheduler.js`)

JavaScript IIFE modul az admin felülethez.

#### Funkciók:
```javascript
// Heti nézet renderelése
renderScheduleGrid(schedule);

// Státusz indikátor
renderStatusIndicator(status);  // ACTIVE vagy TURNED_OFF

// Teljes panel
createSchedulingPanel(kijelzo_id, schedule);

// Aktuális státusz lekérése API-ból
getDisplayStatus(kijelzo_id);
```

#### HTML Integráció:
```html
<script src="/modules/display-scheduler.js"></script>
<script>
    const panel = GroupLoopDisplayScheduler.createSchedulingPanel(kijelzo_id, schedule);
    document.getElementById('schedule-container').appendChild(panel);
</script>
```

### 5. **Admin Panel** (`admin/display_scheduling.php`)

A teljes adminisztrációs felület ütemezéskezeléshez.

#### Funkciók:
- Kijelzők kiválasztása
- Alapértelmezett ütemezés létrehozása
- Új időblokkokat hozzádása
- Heti nézet megjelenítése
- Státusz monitorozás

## Telepítés & Konfigurálás

### 1. Adatbázis séma:
```sql
-- display_scheduler.php fájl tartalmazza a CREATE TABLE utasításokat
mysql -u root < migration.sql
```

### 2. Raspberry Pi démon telepítése:
```bash
# Python script másolása
sudo cp scripts/edudisplej-scheduler.py /usr/local/bin/
sudo chmod +x /usr/local/bin/edudisplej-scheduler.py

# Systemd service másolása
sudo cp scripts/edudisplej-scheduler.service /etc/systemd/system/

# Konfiguráció
sudo mkdir -p /etc/edudisplej
sudo bash scripts/display_scheduler.conf > /etc/edudisplej/display_scheduler.conf
sudo chown edudisplej:edudisplej /etc/edudisplej/display_scheduler.conf

# Service engedélyezése
sudo systemctl daemon-reload
sudo systemctl enable edudisplej-scheduler
sudo systemctl start edudisplej-scheduler

# Státusz ellenőrzése
sudo systemctl status edudisplej-scheduler
sudo journalctl -u edudisplej-scheduler -f
```

### 3. PHP konfigurálás:
```bash
# Ensure database tables exist
sudo mysql edudisplej < /path/to/display_scheduler.php

# Check API endpoints work
curl http://localhost/api/kijelzo/1/schedule_status
```

## Ütemezési Logika

### Alapértelmezett Viselkedés:
```
Hétfő-Vasárnap:
  22:00 - 06:00: KIKAPCSOLT (kijelzo és HDMI OFF)
  06:00 - 22:00: AKTÍV (kijelzo és tartalom bekapcsolt)
```

### Státusz Értékek:
- **ACTIVE**: Kijelző bekapcsolt, tartalom megjelenik
- **TURNED_OFF**: Kijelző kikapcsolt, HDMI és szolgáltatás OFF
- **MAINTENANCE**: Karbantartási üzemmód

### Kijelző Vezérlés:
1. **Szoftveres**: `systemctl stop edudisplej-content`
2. **Hardveres**: `vcgencmd display_power 0` (HDMI OFF)

## Monitorozás & Hibaelhárítás

### Logok:
```bash
# Systemd journal
sudo journalctl -u edudisplej-scheduler -f

# Python log file
tail -f /var/log/edudisplej-scheduler.log

# Database log
SELECT * FROM display_status_log ORDER BY created_at DESC LIMIT 10;
```

### Tesztelés:
```python
# Démon kézi tesztelése
python3 /usr/local/bin/edudisplej-scheduler.py

# API endpoint tesztelése
curl -X GET http://localhost/api/kijelzo/1/schedule_status

# Státusz kényszerítése (admin)
curl -X POST http://localhost/api/kijelzo/1/schedule_force_status \
  -H "Content-Type: application/json" \
  -d '{"status": "ACTIVE", "reason": "Manual testing"}'
```

### Gyakori Problémák:

| Probléma | Megoldás |
|----------|----------|
| Démon nem indul | `sudo systemctl status edudisplej-scheduler` |
| HDMI nem vált | `vcgencmd display_power` jogosultságok ellenőrzése |
| API nem válaszol | PHP error log, MySQL connection check |
| Ütemezés nem alkalmazódik | Database schemas vs. Daemon logok fúziója |

## Fejlesztői Megjegyzések

### Modul Kiterjesztés:
```javascript
// Új funkció hozzáadása a display-scheduler.js-hez
GroupLoopDisplayScheduler.newFunction = function() {
    // implementation
};
```

### API Bővítés:
```php
// Új endpoint az api/display_schedule_api.php-ben
case 'GET':
    if (preg_match('/\/api\/kijelzo\/(\d+)\/new_feature/', $_SERVER['REQUEST_URI'], $matches)) {
        // Handle new feature
    }
```

### Daemon Konfigurálás:
- `check_interval`: Milyen gyakran ellenőrizzem a státuszt (alapértelmezés: 60s)
- `enable_hdmi_control`: HDMI vezérlés engedélyezése (alapértelmezés: true)
- `log_level`: Naplózási szint (DEBUG, INFO, WARNING, ERROR)

## Biztonsági Megfontolások

1. **HTTPS**: Ajánlott az API-hoz (SSL/TLS)
2. **Hitelesítés**: Token-alapú vagy session-alapú
3. **Jogosultságok**: Admin-szint védelme
4. **Input validáció**: Összes felhasználói bemenet szűrése
5. **SQL injection**: Prepared statements (már implementálva)

## API Hiérarchiak

```
Admin (teljes hozzáférés)
├─ Ütemezés létrehozása/módosítása
├─ Időblokkokat kezelése
└─ Státusz kényszerítése

Csoport Kezelő (csoportspecifikus hozzáférés)
├─ Csoportütemezés módosítása
└─ Státusz megtekintése

Felhasználó (csak megtekintés)
└─ Státusz megtekintése
```

## Verziókövetés

- **1.0.0** (Jelenlegi)
  - Alapvetö ütemezési logika
  - Python daemon
  - Admin panel
  - API endpoints

## Támogatás & Kontakt

Problémák vagy kérdések esetén:
- Admin panel súgót megtekinteni
- Log fájlokat ellenőrizni
- Adatbázis sémákat validálni

---

**Utolsó módosítás**: 2024-12-19
