# EduDisplej - Digital Signage System / Digitálny zobrazovacie systém

**EduDisplej** is a simple, powerful digital display system designed for educational institutions.
**EduDisplej** je jednoduchý, výkonný systém digitálnych zobrazení navrhnutý pre vzdelávacie inštitúcie.

---

## 🚀 Quick Install / Rýchla inštalácia

```bash
curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash
```

After installation, **reboot your device**. The system will:
Po inštalácii **reštartujte zariadenie**. Systém bude:
1. **Automatically register** with the control panel / **Automaticky sa zaregistrovať** v kontrolnom paneli
2. **Wait for admin assignment** (assign device to company) / **Čakať na priradenie správcom** (priradenie zariadenia k spoločnosti)
3. **Download modules** and start displaying / **Stiahnuť moduly** a začať zobrazovať

---

## 🔄 Complete Reinstall / Úplná preinštalácia

To completely remove and reinstall the system / Na úplné odstránenie a preinštalovanie systému:

```bash
sudo systemctl stop edudisplej-kiosk.service edudisplej-watchdog.service edudisplej-sync.service edudisplej-terminal.service 2>/dev/null; \
sudo systemctl disable edudisplej-kiosk.service edudisplej-watchdog.service edudisplej-sync.service edudisplej-terminal.service 2>/dev/null; \
sudo rm -f /etc/systemd/system/edudisplej-*.service; \
sudo rm -f /etc/sudoers.d/edudisplej; \
sudo rm -rf /opt/edudisplej; \
sudo systemctl daemon-reload; \
curl https://install.edudisplej.sk/install.sh | sed 's/\r$//' | sudo bash
```

---

## 🔄 System Updates / Aktualizácie systému

System updates are installed **automatically every 24 hours**. No manual intervention needed.
Aktualizácie systému sa inštalujú **automaticky každých 24 hodín**. Nie je potrebná manuálna intervencia.

To update manually / Pre manuálnu aktualizáciu:

```bash
sudo /opt/edudisplej/init/update.sh
```

---

## 📺 How It Works / Ako to funguje

1. **Automatic Registration** / **Automatická registrácia**: Devices automatically register on first boot / Zariadenia sa automaticky registrujú pri prvom spustení
2. **Web Management** / **Webová správa**: Configure displays at https://control.edudisplej.sk/admin/
3. **Module Sync** / **Synchronizácia modulov**: Content syncs automatically every 5 minutes (default) / Obsah sa synchronizuje automaticky každých 5 minút
4. **Display Rotation** / **Rotácia zobrazení**: Modules rotate based on your configuration / Moduly sa striedajú podľa vašej konfigurácie
5. **Real-time Monitoring** / **Monitorovanie v reálnom čase**: Dashboard shows kiosk status, screenshots, and technical info / Dashboard zobrazuje stav kioskoch, snímky obrazovky a technické info

---

## 🎯 Features / Funkcie

- ⏰ Clock module (digital/analog) / Modul hodín (digitálne/analógové)
- 📅 Name days (Slovak/Hungarian) / Meniny (slovenské/maďarské)
- 🖥️ Split-screen layouts / Rozdelené obrazovky
- ⏱️ Scheduled content / Naplánovaný obsah
- 📊 Real-time monitoring / Monitorovanie v reálnom čase
- 📸 Screenshot capture & upload / Zachytávanie a nahrávanie snímkov
- 🔄 Automatic updates / Automatické aktualizácie
- 🔑 Module license management / Správa licencií modulov
- 🏢 Multi-company support / Podpora viacerých spoločností
- ⚙️ Per-kiosk module configuration / Konfigurácia modulov pre každý kiosk
- 📱 Group-based device management / Správa zariadení na základe skupín

---

## 🛠️ System Requirements / Systémové požiadavky

- Raspberry Pi or x86 Linux / Raspberry Pi alebo x86 Linux
- Internet connection / Internetové pripojenie
- HDMI display / HDMI displej

---

## 📖 Management / Správa

Visit the control panel / Navštívte kontrolný panel: **https://control.edudisplej.sk/admin/**

### For Administrators / Pre správcov
- Manage companies and users / Spravujte spoločnosti a používateľov
- Assign module licenses / Priraďte licencie modulov
- Monitor all kiosks / Monitorujte všetky kioski
- View system logs / Zobrazujte systémové logy
- Manage kiosk groups / Spravujte skupiny kioskoch

### For Companies / Pre spoločnosti
Visit / Navštívte: **https://control.edudisplej.sk/dashboard/**
- Configure your kiosks / Konfigurujte svoje kioski
- Customize module settings / Prispôsobte nastavenia modulov
- Monitor your displays / Monitorujte svoje displeje
- View real-time screenshots / Zobraziť snímky obrazovky v reálnom čase
- Filter by groups / Filtrovanie podľa skupín
- Monitor technical information (version, screen resolution, status) / Monitorovať technické informácie

---

## 🆘 Support / Podpora

For issues, check system status / Pri problémoch skontrolujte stav systému:
```bash
sudo systemctl status edudisplej-sync.service
sudo systemctl status edudisplej-kiosk.service
sudo systemctl status edudisplej-screenshot-service.service
```

View logs / Zobraziť logy:
```bash
tail -f /opt/edudisplej/logs/sync.log
tail -f /opt/edudisplej/logs/screenshot-service.log
```

---

## 🔧 Technical Architecture / Technická architektúra

### System Components / Systémové komponenty

**Main Services:**
- `edudisplej-sync.service` - Synchronizes with server, manages configuration
- `edudisplej-kiosk.service` - Display browser and module player
- `edudisplej-screenshot-service.service` - Independent screenshot capture service
- `edudisplej-watchdog.service` - Monitors system health

### Synchronization / Synchronizácia

- **Sync Interval**: Configurable (default: 5 minutes) / **Interval synchronizácie**: Konfigurovateľný (predvolené: 5 minút)
- **Loop Update Detection**: Timestamp-based comparison / **Detekcia aktualizácie slučky**: Porovnanie na základe času
- **Hardware Sync**: Reports device info (version, screen resolution, screen status) every 15 seconds / **Synchronizácia hardvéru**: Hlási informácie o zariadení každých 15 sekúnd
- **Automatic Reload**: Browser reloads when new configuration detected / **Automatické načítanie**: Prehliadač sa znovu načíta pri detekcii novej konfigurácie

### Centralized Data Structure / Centralizovaná dátová štruktúra

All configuration is centralized in `/opt/edudisplej/data/config.json`:

```json
{
    "company_name": "Company Name",
    "company_id": 123,
    "device_id": "abc123def456",
    "token": "api-token",
    "sync_interval": 300,
    "last_update": "2026-02-02 12:00:00",
    "last_sync": "2026-02-02 12:05:00",
    "screenshot_enabled": true,
    "last_screenshot": "2026-02-02 12:04:30",
    "module_versions": {},
    "service_versions": {}
}
```

### Screenshot Service / Služba snímkov

Independent service that captures and uploads screenshots:
- **Script**: `/opt/edudisplej/init/edudisplej-screenshot-service.sh`
- **Service**: `edudisplej-screenshot-service.service`
- **Interval**: 15 seconds (when enabled) / **Interval**: 15 sekúnd (keď je povolené)
- **Filename Format**: `scrn_edudisplejmac_YYYYMMDDHHMMSS.png`

The service:
1. Reads screenshot setting from `config.json`
2. Captures screenshot using `DISPLAY=:0 scrot` if enabled
3. Uploads with proper filename
4. Updates last_screenshot timestamp
5. Can be dynamically enabled/disabled via dashboard

### Loop Configuration Synchronization / Synchronizácia konfigurácií slučiek

Timestamp-based change detection:
- **Local**: `last_update` field in loop.json
- **Server**: Latest `updated_at` from kiosk_group_modules
- **Comparison**: If server timestamp > local timestamp → sync needed
- **Action**: Downloads updated modules and reloads browser

Example logs:
```
[2026-02-02 11:57:31] [INFO] Checking loop configuration changes...
[2026-02-02 11:57:31] [INFO]   Local loop last_update: 2026-02-02 09:17:34
[2026-02-02 11:57:31] [INFO]   Server loop updated_at: 2026-02-02 11:43:11
[2026-02-02 11:57:31] [INFO] ⚠ Loop configuration changed!
[2026-02-02 11:57:31] [INFO] Downloading latest modules...
[2026-02-02 11:57:31] [INFO] Last sync: 2026-02-02 11:57:31
[2026-02-02 11:57:31] [INFO] Loop version: 2026-02-02 11:43:11
```

### Dashboard Real-time Monitoring / Monitorovanie cez dashboard v reálnom čase

The dashboard displays:
- **Hostname & Group** / Názov zariadenia a skupina
- **Status** (Online/Offline) / Stav (Online/Offline)
- **Technical Info** / Technické informácie:
  - 📦 Version / Verzia
  - 🖥️ Screen Resolution / Rozlíšenie obrazovky
  - 💡 Screen Status / Stav obrazovky
- **Sync Timestamps** / Časové pečiatky synchronizácie:
  - ⏱️ Last Sync Time / Čas poslednej synchronizácie
  - 🔄 Loop Version Time / Čas verzie slučky
- **Real-time Screenshots** / Snímky obrazovky v reálnom čase (15-second auto-refresh)
- **Group Filtering** / Filtrovanie podľa skupín

---

### Hostname Configuration / Konfigurácia názvu zariadenia

Devices are automatically named: `edudisplej-XXXXXX` (last 6 chars of MAC address)
Zariadenia sú automaticky pomenované: `edudisplej-XXXXXX` (posledných 6 znakov MAC adresy)

---

## 📊 Database Schema Enhancements / Vylepšenia schémy databázy

### New Columns in `kiosks` Table / Nové stĺpce v tabuľke `kiosks`

```sql
ALTER TABLE kiosks ADD COLUMN version VARCHAR(50) DEFAULT NULL;
ALTER TABLE kiosks ADD COLUMN screen_resolution VARCHAR(50) DEFAULT NULL;
ALTER TABLE kiosks ADD COLUMN screen_status VARCHAR(20) DEFAULT NULL;
ALTER TABLE kiosks ADD COLUMN loop_last_update DATETIME DEFAULT NULL;
ALTER TABLE kiosks ADD COLUMN last_sync DATETIME DEFAULT NULL;
```

These columns store:
- `version`: Kiosk software version / Verzia softvéru kiošku
- `screen_resolution`: Display resolution (e.g., "1920x1080") / Rozlíšenie displeja
- `screen_status`: Screen power state ("on", "off", "unknown") / Stav napájania obrazovky
- `loop_last_update`: Timestamp of last loop configuration update / Čas poslednej aktualizácie konfigurácie slučky
- `last_sync`: Timestamp of last successful synchronization / Čas poslednej úspešnej synchronizácie

---

## 🔄 API Endpoints / API Koncové body

### `/api/hw_data_sync.php`
Syncs hardware data and returns configuration:
- **POST data**: MAC, hostname, hardware info, version, screen resolution, screen status
- **Returns**: kiosk_id, sync_interval, screenshot_enabled, company info
- **Updates**: last_seen, hw_info, version, screen_resolution, screen_status

### `/api/kiosk_details.php`
Returns kiosk details including:
- Group names and IDs / Názvy a ID skupín
- Technical info (version, resolution, screen status) / Technické informácie
- Last sync and loop update times / Časy poslednej synchronizácie a aktualizácie slučky
- Screenshot URL / Adresa URL snímku
- **Supports bulk refresh**: `?refresh_list=1,2,3` for dashboard data updates

### `/api/get_kiosk_loop.php`
Returns loop configuration with:
- Module list with durations / Zoznam modulov s trvaním
- Loop update timestamp for change detection / Časová pečiatka pre detekciu zmien
- Source (group or kiosk-specific) / Zdroj

### `/api/update_sync_timestamp.php`
Updates sync timestamps in database:
- **POST data**: mac, last_sync, loop_last_update
- **Updates**: last_sync, loop_last_update columns
- Called after each successful sync cycle

### `/api/update_screenshot_settings.php`
Controls screenshot capture globally:
- **POST data**: screenshot_enabled (1/0)
- Syncs to device config.json

### `/api/update_sync_interval.php`
Sets synchronization interval:
- **POST data**: sync_interval (seconds)
- Syncs to device config.json

---

## 🛡️ Configuration Management / Správa konfigurácie

### Config Manager Tool / Nástroj na správu konfigurácie

```bash
# Initialize config.json
/opt/edudisplej/init/edudisplej-config-manager.sh init

# View entire config
/opt/edudisplej/init/edudisplej-config-manager.sh show

# Get specific value
/opt/edudisplej/init/edudisplej-config-manager.sh get screenshot_enabled

# Update value
/opt/edudisplej/init/edudisplej-config-manager.sh update screenshot_enabled true
```

---

## 📄 License / Licencia

This project is proprietary software. All rights reserved.
Tento projekt je proprietárny softvér. Všetky práva vyhradené.

## 👥 Author / Autor

**Nagy András** - [nagy-andras-sk](https://github.com/nagy-andras-sk)

---

**Made with ❤️ for education**
