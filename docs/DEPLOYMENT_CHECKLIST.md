# Display Scheduling System - Telepítési Ellenőrzési Lista

Használja ezt az ellenőrzési listát a sikeres telepítés biztosításához.

## ✅ Pre-Telepítés Ellenőrzések

- [ ] **Rendszer ürtékek ellenőrzése**
  - [ ] PHP 7.4+ telepítve
  - [ ] MySQL 5.7+ telepítve
  - [ ] Python 3.6+ (Raspberry Pi-hez szükséges)
  - [ ] pip3 telepítve

- [ ] **Hozzáférési jogosultságok**
  - [ ] Root/Sudo hozzáférés (adatbázis, sistem fájlok)
  - [ ] Webszerver root (PHP fájlok)
  - [ ] Git hozzáférés (ha verziókezeléshez szükséges)

- [ ] **Háttérszolgáltatások**
  - [ ] MySQL szerver futó
  - [ ] Webszerver (Apache/Nginx) futó
  - [ ] SSH hozzáférés Raspberry Pi-hez

---

## 🗄️ Adatbázis Telepítés

- [ ] **Séma Futtatása**
  - [ ] SQL fájl szerkesztve (display_scheduler.php)
  - [ ] MySQL-be csatlakozva
  - [ ] CREATE TABLE utasítások futtatva
  - [ ] Indexek létrehozva

- [ ] **Táblaellenőrzés**
  ```sql
  mysql -u root -p edudisplej
  SHOW TABLES LIKE 'display%';
  SHOW TABLES LIKE 'schedule%';
  ```
  - [ ] `display_schedules` létezik
  - [ ] `schedule_time_slots` létezik
  - [ ] `schedule_special_days` létezik
  - [ ] `display_status_log` létezik

- [ ] **Oszlopok Ellenőrzése**
  - [ ] display_schedules: schedule_id, kijelzo_id, group_id, created_at, updated_at
  - [ ] schedule_time_slots: slot_id, schedule_id, day_of_week, start_time, end_time, is_enabled
  - [ ] display_status_log: log_id, kijelzo_id, previous_status, new_status, reason, created_at

- [ ] **Indexek Ellenőrzése**
  - [ ] schedule_kijelzo index: display_schedules(kijelzo_id)
  - [ ] slot_schedule index: schedule_time_slots(schedule_id)
  - [ ] status_log indexes: kijelzo és created_at

---

## 🔌 Backend API Telepítés

- [ ] **Fájlok Másolása**
  - [ ] `api/display_scheduler.php` másolva
  - [ ] `api/display_schedule_api.php` másolva
  - [ ] `admin/display_scheduling.php` másolva

- [ ] **Fájl Permissziók**
  - [ ] PHP fájlok 644-es engedély
  - [ ] config fájlok 600-as engedély
  - [ ] log directory 755-ös engedély

- [ ] **Webszerver Konfigurálása**
  - [ ] **Apache**: .htaccess módosítva (RewriteRules)
  - [ ] **Nginx**: nginx.conf módosítva (location blocks)
  - [ ] Webszerver újraindítva: `sudo systemctl restart apache2` vagy `sudo systemctl restart nginx`

- [ ] **PHP Értékek**
  ```php
  // php.ini ellenőrzésé
  - [ ] error_reporting = E_ALL
  - [ ] display_errors = On (dev) / Off (prod)
  - [ ] log_errors = On
  - [ ] error_log = /var/log/php_errors.log
  ```

- [ ] **API Végpontok Tesztelése**
  ```bash
  # HTTP GET test
  curl http://localhost/api/kijelzo/1/schedule_status
  ```
  - [ ] Válasz érkezett
  - [ ] JSON válasz formátum
  - [ ] HTTP 200 vagy 404 (schedule még nem létezhet)

---

## 🎨 Frontend Telepítés

- [ ] **JavaScript Modulok Másolása**
  - [ ] `modules/display-scheduler.js` másolva
  - [ ] Helyesen elhelyezve: `/assets/js/modules/`

- [ ] **HTML Integráció**
  - [ ] Script tag hozzáadva az admin panelhez
  - [ ] `GroupLoopDisplayScheduler` objektum elérhető a konzolon
  - [ ] Modul függvények tesztelve: `GroupLoopDisplayScheduler.renderScheduleGrid()`

- [ ] **Admin Panel Tesztelése**
  - [ ] `admin/display_scheduling.php` megnyitva böngészőben
  - [ ] Kijelzo dropdown elérhető
  - [ ] "Alapértelmezett Ütemezés Létrehozása" gomb látható

---

## 🐍 Raspberry Pi Démon Telepítés

- [ ] **Python Telepítés**
  - [ ] Python 3.6+ telepítve: `python3 --version`
  - [ ] pip3 telepítve: `pip3 --version`
  - [ ] `requests` csomag telepítve: `python3 -c "import requests"`

- [ ] **Fájlok Másolása**
  - [ ] `edudisplej-scheduler.py` másolva `/usr/local/bin/`
  - [ ] Futtatható: `sudo chmod +x /usr/local/bin/edudisplej-scheduler.py`
  - [ ] `edudisplej-scheduler.service` másolva `/etc/systemd/system/`

- [ ] **Felhasználó & Jogosultságok**
  - [ ] `edudisplej` felhasználó létrehozva: `sudo useradd -r -s /bin/false edudisplej`
  - [ ] Log könyvtár létrehozva: `sudo mkdir -p /var/log/edudisplej`
  - [ ] Jogosultságok beállítva: `sudo chown edudisplej:edudisplej /var/log/edudisplej`

- [ ] **Konfigurálás**
  - [ ] `/etc/edudisplej/display_scheduler.conf` létrehozva
  - [ ] API URL beállítva: `url = http://webserver-ip/api`
  - [ ] Kijelzo ID beállítva: `kijelzo_id = 1`
  - [ ] Check interval beállítva: `check_interval = 60`

- [ ] **Systemd Service**
  - [ ] Service fájl ellenőrizve: `sudo systemctl cat edudisplej-scheduler`
  - [ ] Daemon újratöltve: `sudo systemctl daemon-reload`
  - [ ] Service engedélyezve: `sudo systemctl enable edudisplej-scheduler`
  - [ ] Service indítva: `sudo systemctl start edudisplej-scheduler`

- [ ] **Démon Tesztelése**
  ```bash
  sudo systemctl status edudisplej-scheduler
  journalctl -u edudisplej-scheduler -f
  ```
  - [ ] Status: `active (running)`
  - [ ] Logok láthatók
  - [ ] Nincs hibák

---

## 🔄 Integrációs Tesztek

- [ ] **Database-API Integráció**
  ```bash
  # Új ütemezés létrehozása
  curl -X POST http://localhost/api/admin/display_schedule/create \
    -d '{"group_id": 1, "kijelzo_id": 1}'
  ```
  - [ ] 201 Created válasz
  - [ ] schedule_id visszaadva

- [ ] **API-Frontend Integráció**
  - [ ] Admin panel megnyitva
  - [ ] Kijelzo kiválasztva
  - [ ] "Alapértelmezett Ütemezés Létrehozása" gomb kattintva
  - [ ] Ütemezés létrehozva az adatbázisban

- [ ] **Frontend-Daemon Integráció**
  - [ ] Démon logja mutat API kéréseket: `sudo journalctl -u edudisplej-scheduler`
  - [ ] Status lekérdezés sikeres: `curl http://localhost/api/kijelzo/1/schedule_status`
  - [ ] Válasz: `{"status": "ACTIVE"}` vagy `{"status": "TURNED_OFF"}`

- [ ] **Full End-to-End Teszt**
  1. [ ] Admin panelben ütemezés módosítása
  2. [ ] API-vel status lekérdezése
  3. [ ] Raspberry Pi demon futása ellenőrzése
  4. [ ] HDMI/Service vezérlés ellenőrzése (ha rendelkezésre áll)

---

## 🧪 Tesztelési Szenáriók

### 1. Alapértelmezett Ütemezés
- [ ] Ütemezés létrehozva alapértelmezetten
- [ ] 22:00-06:00: KIKAPCSOLT (is_enabled = 0)
- [ ] 06:00-22:00: AKTÍV (is_enabled = 1)

### 2. Státusz Lekérdezés
- [ ] 05:59 - Status: TURNED_OFF
- [ ] 06:00 - Status: ACTIVE
- [ ] 21:59 - Status: ACTIVE
- [ ] 22:00 - Status: TURNED_OFF

### 3. Timeblokk Hozzáadás
- [ ] Új timeblokk hozzáadva
- [ ] Státusz frissült az adatbázisban
- [ ] Daemon frissített státuszt lekérdezett

### 4. Daemon Működés
- [ ] Daemon percenként ellenőrzi (60s interval)
- [ ] Logok mutatják az ellenőrzéseket
- [ ] Státusz közvetlenül HDMI-hez/service-hez vezet (ha konfigurálva)

---

## 📊 Teljesítményi Tesztek

- [ ] **Database Performance**
  ```sql
  -- Lekérdezés időzítése
  SELECT * FROM display_schedules WHERE kijelzo_id = 1;
  ```
  - [ ] Válasz < 10ms

- [ ] **API Response Time**
  ```bash
  time curl http://localhost/api/kijelzo/1/schedule_status
  ```
  - [ ] Válasz < 100ms

- [ ] **Daemon Resource Usage**
  ```bash
  ps aux | grep edudisplej-scheduler
  ```
  - [ ] CPU: < 1%
  - [ ] Memory: < 50MB

---

## 🔒 Biztonsági Tesztek

- [ ] **API Hitelesítés**
  - [ ] Admin endpoint auth check működik
  - [ ] Jogosulatlan kérés 403 Forbidden-t ad vissza

- [ ] **Input Validáció**
  - [ ] Invalid kijelzo_id: 403 vagy 404
  - [ ] Invalid day_of_week (>6): 400 Bad Request
  - [ ] Invalid time format: 400 Bad Request

- [ ] **SQL Injection Védelem**
  - [ ] SQL injection kísérlet nem működik
  - [ ] Prepared statements használva

- [ ] **Rate Limiting** (ha implementálva)
  - [ ] 100+ kérés/perc blokkolva
  - [ ] 429 Too Many Requests válasz

---

## 📋 Dokumentáció

- [ ] **Dokumentáció Megírt**
  - [ ] `docs/DISPLAY_SCHEDULING.md` létezik
  - [ ] `docs/INSTALLATION_GUIDE.md` létezik
  - [ ] `docs/DISPLAY_SCHEDULING_README.md` létezik

- [ ] **Dokumentáció Teljessége**
  - [ ] API referencia teljes
  - [ ] Telepítési lépések világosak
  - [ ] Hibaelhárítási útmutató van

- [ ] **Kódkommentek**
  - [ ] PHP metódusok dokumentálva
  - [ ] Python függvények dokumentálva
  - [ ] JS modulok dokumentálva

---

## 🚀 Production Deployement

- [ ] **SSL/TLS Beállítása**
  - [ ] HTTPS aktiválva
  - [ ] SSL cert telepítve
  - [ ] Mixed content ellenőrizve

- [ ] **Logging Konfigurálása**
  - [ ] Log rotation beállítva
  - [ ] Log level INFO (nem DEBUG)
  - [ ] Archiving beállítva >30 napig

- [ ] **Monitoring Beállítása**
  - [ ] Health check endpoint: `/api/health`
  - [ ] Daemon monitoring: systemd watch
  - [ ] Database backup: napi

- [ ] **Disaster Recovery**
  - [ ] Backup terv dokumentálva
  - [ ] Recovery eljárás tesztelve
  - [ ] RTO/RPO definiálva

---

## 📝 Post-Telepítés

- [ ] **Dokumentáció Frissítése**
  - [ ] Telepítési dátum rögzítve
  - [ ] Verzió szám frissítve
  - [ ] Repo-t megjelölve "ready-for-production"

- [ ] **Csapat Tájékoztatása**
  - [ ] Oktatás teljesítve
  - [ ] Dokumentáció megosztva
  - [ ] Support terv tisztázva

- [ ] **Monitoring Aktiválása**
  - [ ] Alerting bekapcsolva
  - [ ] Notifikációk konfigurálva
  - [ ] Dashboards létrehozva

---

## ✅ Végleges Ellenőrzés

- [ ] **Telepítés Sikeres**
  - [ ] Adatbázis: 4 tábla, 100%
  - [ ] Backend: API válaszol, 100%
  - [ ] Frontend: Admin panel működik, 100%
  - [ ] Daemon: Fut, logol, 100%

- [ ] **Tesztelés Teljes**
  - [ ] Integrációs tesztek: 6/6 pass
  - [ ] Manual tesztelés: OK
  - [ ] Performance tesztek: OK
  - [ ] Security tesztek: OK

- [ ] **Dokumentáció Teljes**
  - [ ] Technikai dokumentáció: OK
  - [ ] Telepítési útmutató: OK
  - [ ] API dokumentáció: OK
  - [ ] Hibaelhárítás: OK

- [ ] **Production Ready**
  - [ ] Code review: Teljes
  - [ ] Security audit: Teljes
  - [ ] Performance tuning: Teljes
  - [ ] Go-live approval: ✅

---

## 📞 Támogatási Kontaktok

- **Technikai támogatás**: [Contact]
- **Adatbázis admin**: [Contact]
- **Raspberry Pi szakértő**: [Contact]
- **Szoftver mérnök**: [Contact]

---

**Ellenőrzési dátum**: _______________
**Ellenőrző neve**: _______________
**Aláírás**: _______________

---

**Utolsó módosítás**: 2024-12-19
