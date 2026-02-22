# Display Scheduling System - Telepítési Útmutató

Ez a dokumentum a Display Scheduling System teljes telepítésének lépéseit írja le.

## Előkövetelmények

- PHP 7.4+
- MySQL 5.7+
- Python 3.6+ (Raspberry Pi démon)
- Raspberry Pi (Optional, de ajánlott)
- Webszerver (Apache/Nginx)
- Superuser/Root hozzáférés

## Telepítési Lépések

### 1. Adatbázis Séma Telepítése

#### 1.1 Fájlok másolása
```bash
# Az adatbázis séma az api/display_scheduler.php fájlban van
# Másolja a file-t a webserver könyvtárba
cp api/display_scheduler.php /path/to/webserver/api/
```

#### 1.2 SQL Séma Futtatása
```bash
# Csatlakozzon MySQL-hez
mysql -u root -p edudisplej

# Vagy másola az SQL-t külön fájlba:
cat > /tmp/display_scheduler.sql << 'EOF'
-- Create display_schedules table
CREATE TABLE IF NOT EXISTS display_schedules (
    schedule_id INT AUTO_INCREMENT PRIMARY KEY,
    kijelzo_id INT NOT NULL,
    group_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create schedule_time_slots table
CREATE TABLE IF NOT EXISTS schedule_time_slots (
    slot_id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    day_of_week INT NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_enabled TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (schedule_id) REFERENCES display_schedules(schedule_id) ON DELETE CASCADE
);

-- Create schedule_special_days table
CREATE TABLE IF NOT EXISTS schedule_special_days (
    special_day_id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    date DATE NOT NULL,
    start_time TIME,
    end_time TIME,
    is_enabled TINYINT DEFAULT 1,
    reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (schedule_id) REFERENCES display_schedules(schedule_id) ON DELETE CASCADE
);

-- Create display_status_log table
CREATE TABLE IF NOT EXISTS display_status_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    kijelzo_id INT NOT NULL,
    previous_status VARCHAR(50),
    new_status VARCHAR(50) NOT NULL,
    reason VARCHAR(255),
    triggered_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_kijelzo (kijelzo_id),
    INDEX idx_created (created_at)
);

-- Add indexes for better performance
CREATE INDEX idx_schedule_kijelzo ON display_schedules(kijelzo_id);
CREATE INDEX idx_slot_schedule ON schedule_time_slots(schedule_id);
CREATE INDEX idx_special_schedule ON schedule_special_days(schedule_id);
EOF

# Futtassa az SQL-t
mysql -u root -p edudisplej < /tmp/display_scheduler.sql
```

#### 1.3 Ellenőrzés
```bash
# Csatlakozzon MySQL-hez és ellenőrizze
mysql -u root -p edudisplej -e "SHOW TABLES LIKE 'display%';"
mysql -u root -p edudisplej -e "SHOW TABLES LIKE 'schedule%';"

# Várt kimenet:
# display_schedules
# display_status_log
# schedule_special_days
# schedule_time_slots
```

---

### 2. Backend API Telepítése

#### 2.1 PHP Fájlok Másolása
```bash
# Api fájlok
cp api/display_scheduler.php /path/to/webserver/api/
cp api/display_schedule_api.php /path/to/webserver/api/

# Admin panel
cp admin/display_scheduling.php /path/to/webserver/admin/

# Engedélyek beállítása
chmod 644 /path/to/webserver/api/display_scheduler.php
chmod 644 /path/to/webserver/api/display_schedule_api.php
chmod 644 /path/to/webserver/admin/display_scheduling.php
```

#### 2.2 Webszerver Konfigurálása

**Apache** - Hozzáadja `.htaccess` fájlhoz:
```apache
# Lehetővé teszi az API routing-ot
RewriteRule ^api/kijelzo/([0-9]+)/schedule_status$ api/display_schedule_api.php [L]
RewriteRule ^api/admin/display_schedule/(.*)$ api/display_schedule_api.php [L]
```

**Nginx** - nginx.conf-hez:
```nginx
location ~ ^/api/.*$ {
    try_files $uri $uri/ /api/api-router.php?$query_string;
}
```

#### 2.3 Tesztelés
```bash
# API endpoint tesztel
curl http://localhost/api/kijelzo/1/schedule_status

# Várt válasz:
# {"status": "ACTIVE", "timestamp": "2024-12-19T12:00:00Z"}
```

---

### 3. Frontend Modul Telepítése

#### 3.1 JavaScript Módulok Másolása
```bash
# Display scheduler modul
cp modules/display-scheduler.js /path/to/webserver/assets/js/modules/

# Engedélyek
chmod 644 /path/to/webserver/assets/js/modules/display-scheduler.js
```

#### 3.2 HTML Integráció
Az admin panelben az alábbi sorokat adja hozzá:
```html
<!-- Betölteni a display scheduler modult -->
<script src="/assets/js/modules/display-scheduler.js"></script>

<!-- Létrehozni a scheduling panelt -->
<script>
    const panel = GroupLoopDisplayScheduler.createSchedulingPanel(
        kijelzo_id, 
        schedule_data
    );
    document.getElementById('schedule-container').appendChild(panel);
</script>
```

---

### 4. Raspberry Pi Démon Telepítése

#### 4.1 Python Fájlok Másolása és Telepítése
```bash
# Python script másolása
sudo cp scripts/edudisplej-scheduler.py /usr/local/bin/
sudo chmod +x /usr/local/bin/edudisplej-scheduler.py

# Python szükséges csomagok
sudo pip3 install requests

# Ellenőrzés
python3 --version  # v3.6+
python3 -c "import requests" # Nem hibázhat
```

#### 4.2 Systemd Service Beállítása
```bash
# Service fájl másolása
sudo cp scripts/edudisplej-scheduler.service /etc/systemd/system/

# Daemon konfigurálása
sudo mkdir -p /etc/edudisplej
sudo cp scripts/display_scheduler.conf /etc/edudisplej/

# Display scheduler felhasználó létrehozása (ha nem létezik)
sudo useradd -r -s /bin/false edudisplej 2>/dev/null || true

# Jogosultságok beállítása
sudo chown -R edudisplej:edudisplej /etc/edudisplej
sudo chmod 644 /etc/edudisplej/display_scheduler.conf

# Index file a log-hoz
sudo touch /var/log/edudisplej-scheduler.log
sudo chown edudisplej:edudisplej /var/log/edudisplej-scheduler.log
sudo chmod 644 /var/log/edudisplej-scheduler.log
```

#### 4.3 Konfigurálás
```bash
# Szerkessze az /etc/edudisplej/display_scheduler.conf fájlt
sudo nano /etc/edudisplej/display_scheduler.conf
```

Módosítsa az alábbi értékeket:
```ini
[api]
url = http://localhost/api  # A webszerver IP-je a Raspberry Pi-n

[device]
kijelzo_id = 1  # A kijelző egyedi ID-je

[scheduler]
check_interval = 60  # Percenként ellenőrizve a státuszt

[services]
content_service = edudisplej-content  # A tartalom service neve

[logging]
log_file = /var/log/edudisplej-scheduler.log
log_level = INFO
```

#### 4.4 Service Indítása
```bash
# Daemon bezönyítése
sudo systemctl daemon-reload

# Service engedélyezése (automatikus indítás boot-on)
sudo systemctl enable edudisplej-scheduler

# Service indítása
sudo systemctl start edudisplej-scheduler

# Állapot ellenőrzése
sudo systemctl status edudisplej-scheduler

# Logok megtekintése
sudo journalctl -u edudisplej-scheduler -f
```

#### 4.5 Teszt
```bash
# Démon futó-e?
sudo systemctl status edudisplej-scheduler

# Logok ellenőrzése
sudo tail -f /var/log/edudisplej-scheduler.log

# Várható üzenet:
# 2024-12-19 12:00:00 - INFO - Checking schedule status
# 2024-12-19 12:00:00 - INFO - Current status: ACTIVE
```

---

### 5. Tesztelés & Inicializálás

#### 5.1 Adatbázis Tesztelése
```bash
# Alapértelmezett ütemezés létrehozása az első kijelzőhöz
mysql -u root -p edudisplej << 'EOF'
-- Ellenőrizze a display_schedules táblát
SELECT * FROM display_schedules;

-- Ellenőrizze az időblokkokat
SELECT * FROM schedule_time_slots;
EOF
```

#### 5.2 API Végpont Tesztelése
```bash
# Hozzon létre egy alapértelmezett ütemezést
curl -X POST http://localhost/api/admin/display_schedule/create \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"group_id": 1, "kijelzo_id": 1}'

# Lekérja az ütemezést
curl http://localhost/api/admin/display_schedule/1

# Lekérja az aktuális státuszt
curl http://localhost/api/kijelzo/1/schedule_status
```

#### 5.3 Frontend Tesztelése
```bash
# Nyissa meg a böngészőt
http://localhost/admin/display_scheduling.php

# Kijelzo kiválasztása
# Alapértelmezett ütemezés létrehozása kattintással
# UI ellenőrzése
```

#### 5.4 Raspberry Pi Tesztelése
```bash
# SSH csatlakozás RPi-hez
ssh pi@raspberrypi.local

# Démon futó-e?
sudo systemctl status edudisplej-scheduler

# Manuális test run
python3 /usr/local/bin/edudisplej-scheduler.py

# HDMI vezérlés teszt (RPi-ben)
vcgencmd display_power 1  # HDMI ON
vcgencmd display_power 0  # HDMI OFF
vcgencmd display_power    # Status lekérés
```

---

### 6. Hozzáférési Jogosultságok Beállítása

#### 6.1 Admin Funkcionalitás
Módosítsa az `api/display_schedule_api.php` fájlt az aktuális autentikáció rendszerhez:

```php
// Helyettesítse ezt:
if (!check_admin()) {
    http_response_code(403);
    die('Admin access required');
}

// Az ön jelenlegi engedély-rendszerével
```

#### 6.2 Csoport-szintű Hozzáférés
```php
// Módosítsa a csoport-hozzáférés szabályokat:
if (!check_group_access($group_id)) {
    http_response_code(403);
    die('Group access required');
}
```

---

### 7. Biztonsági Beállítások

#### 7.1 HTTPS/SSL
```bash
# PHP-ben SSL verifikálás engedélyezése
# display_scheduler.conf-ben
verify_ssl = true
```

#### 7.2 Token-alapú Hitelesítés
```php
// Az API-ban JWT token ellenőrzése
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!is_valid_token($token)) {
    http_response_code(401);
    die('Unauthorized');
}
```

#### 7.3 Rate Limiting
```php
// API végpontok védelmé
if (rate_limit_exceeded($ip, 100, 60)) { // 100 kérés/perc
    http_response_code(429);
    die('Too many requests');
}
```

---

### 8. Hibaelhárítás

#### Gyakori Hibák

| Hiba | Megoldás |
|------|----------|
| "Connection refused" | MySQL szerver futó-e? |
| "Table doesn't exist" | Futtassa a SQL schema-t |
| "Permission denied" | Ellenőrizze a fájl jogosultságokat |
| "HDMI not switching" | vcgencmd jogosultságok (sudo) |
| "API not responding" | PHP hibák - error.log ellenőrzése |
| "Daemon not starting" | systemctl status + journalctl ellenőrzése |

#### Logok Megtekintése
```bash
# Webszerver hibák
tail -f /var/log/apache2/error.log  # Apache
tail -f /var/log/nginx/error.log    # Nginx

# PHP hibák
tail -f /var/log/edudisplej-app.log

# MySQL hibák
tail -f /var/log/mysql/error.log

# Raspberry Pi démon
sudo journalctl -u edudisplej-scheduler -f
sudo tail -f /var/log/edudisplej-scheduler.log
```

---

### 9. Telepítés Megerősítése

```bash
# Végzzen el egy teljes teszt-ciklust:

# 1. Adatbázis
mysql -u root -p edudisplej -e "SELECT COUNT(*) FROM display_schedules;"

# 2. API
curl http://localhost/api/kijelzo/1/schedule_status

# 3. Admin panel
curl -s http://localhost/admin/display_scheduling.php | grep -q "Kijelzo Ütemezés"

# 4. Raspberry Pi démon (ha van)
sudo systemctl is-active edudisplej-scheduler

# Ha minden "active" vagy 200 OK → sikeres telepítés!
```

---

## Szállítás Terv

### 1. Lépés: Adatbázis Előkészítés
- SQL séma futtatása
- Tábláka ellenőrzése
- Kezdeti adat(ok) beszúrása

### 2. Lépés: Backend API
- PHP fájlok másolása
- Webszerver konfigurálása
- API végpontok tesztelése

### 3. Lépés: Frontend
- JS modulok másolása
- HTML integráció
- UI tesztelése

### 4. Lépés: Raspberry Pi
- Python démon telepítése
- Systemd service konfigurálása
- Service indítása

### 5. Lépés: Tesztelés
- Integrációs tesztek futtatása
- End-to-end tesztelés
- Teljesítmény mérés

---

## Támogatás

Problémák esetén:
1. A dokumentáció ellenőrzése: `docs/DISPLAY_SCHEDULING.md`
2. Logok megtekintése (lásd 8. Hibaelhárítás)
3. GitHub issues / Support csatorna
4. Közösségi fórumok

---

**Utolsó frissítés**: 2024-12-19
