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

### Remote Full Update (self-update) / Vzdialená aktualizácia

From the control panel admin view (**Kiosk Details** page) you can trigger a **full remote self-update**:

1. Open **Admin → Kiosk Details** for any kiosk.
2. The **Verzió és frissítés** (Version & Update) section shows:
   - The kiosk's currently running version (reported at each sync).
   - The latest available version from the server (`versions.json`).
   - A status indicator: *Naprakész* (up-to-date) or *Frissítés elérhető* (update available).
3. If an update is available, click **⬆ Frissítés indítása** – this queues a `full_update` command via `api/kiosk/queue_full_update.php`.
4. On the kiosk's next command-executor cycle (≤30 s) the `update.sh` script runs:
   - Downloads all init scripts and service files from the server.
   - Overwrites all systemd unit files with the latest versions.
   - Removes obsolete units, runs `systemctl daemon-reload`, and restarts affected services.
   - The process is idempotent – safe to run multiple times.
5. Update progress is logged to `/opt/edudisplej/logs/full_update.log`.

**Version tracking**: the kiosk reads its version from `/opt/edudisplej/VERSION` and reports it to the server at every sync. The server's canonical "latest" version is stored in `webserver/install/init/versions.json` under the `system_version` key.

---

## ⚡ Fast Loop / Gyors szinkron mód

By default the sync cycle runs every **5 minutes** (`SYNC_INTERVAL=300`).

**Fast loop mode** reduces the interval to **30 seconds** – useful when a user is actively watching the control panel (e.g. viewing live screenshots).

### How to activate / Aktiváció

From **Admin → Kiosk Details**, click **⚡ Fast loop BE** to enable or **⏸ Fast loop KI** to disable.  
This queues an `enable_fast_loop` / `disable_fast_loop` command which creates or removes `/opt/edudisplej/.fast_loop_enabled` on the kiosk.

Once the flag file is present, the sync service checks it after every cycle and sleeps for 30 s instead of the configured interval. Removing the flag restores the normal 5-minute interval on the next cycle.

### Via API / API-n keresztül

```http
POST /api/kiosk/control_fast_loop.php
Authorization: Bearer <token>
Content-Type: application/json

{"kiosk_id": 42, "enable": true}
```

---

## 📺 How It Works / Ako to funguje

1. **Automatic Registration** / **Automatická registrácia**: Devices automatically register on first boot / Zariadenia sa automaticky registrujú pri prvom spustení
2. **Web Management** / **Webová správa**: Configure displays at https://control.edudisplej.sk/admin/
3. **Unified Sync** / **Unified Sync**: Hardware data, screenshots and logs are sent in a single API call (`/api/v1/device/sync.php`) / Hardvérové dáta, screenshoty a logy sa odosielajú v jedinom API volaní
4. **Display Rotation** / **Rotácia zobrazení**: Modules rotate based on your configuration / Moduly sa striedajú podľa vašej konfigurácie

---

## 🎯 Features / Funkcie

- ⏰ Clock module (digital/analog) / Modul hodín (digitálne/analógové)
- 📅 Name days (Slovak/Hungarian) / Meniny (slovenské/maďarské)
- 🖥️ Split-screen layouts / Rozdelené obrazovky
- ⏱️ Scheduled content / Naplánovaný obsah
- 📊 Real-time monitoring / Monitorovanie v reálnom čase
- 📸 On-demand screenshots (only when someone is watching) / Screenshoty na vyžiadanie (len keď niekto sleduje)
- 🔒 Bearer token + optional HMAC-SHA256 request signing / Bearer token + voliteľné HMAC-SHA256 podpisovanie požiadaviek
- 🔄 Automatic updates + remote self-update from control panel / Automatické aktualizácie + vzdialená aktualizácia z kontrolného panela
- ⚡ Fast loop mode (30s sync) controllable from control panel / Rýchly synchrónny mód (30s) ovládateľný z kontrolného panela
- 🔑 Module license management / Správa licencií modulov
- 📜 Company license management with device limits / Správa licencií spoločností s limitom zariadení
- 🏢 Multi-company support / Podpora viacerých spoločností
- ⚙️ Per-kiosk module configuration / Konfigurácia modulov pre každý kiosk
- 📧 Configurable SMTP + multilingual email templates / Konfigurovateľný SMTP + viacjazyčné e-mailové šablóny
- 🔐 TOTP MFA with backup codes / TOTP MFA so záložnými kódmi
- 🔄 Password reset via email / Obnovenie hesla e-mailom

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
- View system logs and security logs / Zobrazujte systémové a bezpečnostné logy

### For Companies / Pre spoločnosti
Visit / Navštívte: **https://control.edudisplej.sk/dashboard/**
- Configure your kiosks / Konfigurujte svoje kioski
- Customize module settings / Prispôsobte nastavenia modulov
- Monitor your displays with real-time screenshots / Monitorujte displeje so screenshotmi v reálnom čase

---

## 🔒 Security / Bezpečnosť

- All device API endpoints require a **Bearer token** (`Authorization: Bearer <token>`)
- Dashboard and admin pages use **session-based auth**
- Optional **HMAC-SHA256 request signing** with replay protection (nonce + timestamp drift check)
- `?token=` query parameter is **deprecated** (works but emits warning; will be removed)
- Screenshots are sent **only on demand** (TTL-based flag set by the control panel)

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the full security model and API reference.

---

## 🆘 Support / Podpora

For issues, check system status / Pri problémoch skontrolujte stav systému:
```bash
sudo systemctl status edudisplej-sync.service
sudo systemctl status edudisplej-kiosk.service
```

View logs / Zobraziť logy:
```bash
tail -f /opt/edudisplej/logs/sync.log
```

---

## 🔧 Technical Architecture / Technická architektúra

### System Components / Systémové komponenty

```
┌─────────────────────────────────────────────────────────────┐
│                    KIOSK STARTUP FLOW                        │
└─────────────────────────────────────────────────────────────┘

1. System Boot
   └─> Auto-login (edudisplej user)
       └─> startx (X server)
           └─> Openbox
               └─> Terminal Script
                   ├─> Wait for device registration
                   ├─> Download modules & loop config
                   └─> Launch browser with loop player
```

### Synchronization / Synchronizácia

| Service | Purpose | Interval |
|---|---|---|
| `edudisplej-sync.service` | Unified sync: HW data, screenshot (on-demand), logs | Configurable (default 5 min) |
| `edudisplej-screenshot-service.service` | Captures & uploads screenshots when TTL active | Server-defined (default 3 s) |
| `edudisplej-health.service` | Health / heartbeat reporting | Fixed |
| `edudisplej-kiosk.service` | Chromium kiosk browser | – |
| `edudisplej-command-executor.service` | Executes remote commands from control panel | – |

### API Endpoints (v1) / API Endpointy

| Endpoint | Method | Description |
|---|---|---|
| `/api/v1/device/sync.php` | POST | ★ Unified device sync (hw, screenshot, logs) |
| `/api/registration.php` | POST | First-time device registration |
| `/api/modules_sync.php` | POST | Fetch current module loop |
| `/api/screenshot_request.php` | POST | Dashboard sets/clears screenshot TTL |
| `/api/health/report.php` | POST | Kiosk health reporting |
| `/api/health/status.php` | GET | Health status for one kiosk |
| `/api/health/list.php` | GET | Health status for all company kiosks |
| `/api/kiosk/queue_full_update.php` | POST | Queue a full self-update for a kiosk |
| `/api/kiosk/control_fast_loop.php` | POST | Enable/disable fast loop (30s sync) |
| `/api/check_versions.php` | GET | Returns service versions + latest_system_version |

### Hostname Configuration / Konfigurácia názvu zariadenia

Devices are automatically named: `edudisplej-XXXXXX` (last 6 chars of MAC address)  
Zariadenia sú automaticky pomenované: `edudisplej-XXXXXX` (posledných 6 znakov MAC adresy)

---

## 📚 Documentation / Dokumentácia

- **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** – Full architecture reference: repo structure, auth model, API spec, HMAC-SHA256 signing, screenshot TTL policy, kiosk service architecture, DB schema, migration plan, and manual test steps.
- **[docs/email.md](docs/email.md)** – SMTP configuration, multilingual email templates, password reset flow.
- **[docs/mfa.md](docs/mfa.md)** – TOTP MFA setup, backup codes, login flow.
- **[docs/licensing.md](docs/licensing.md)** – Company license model, device slot management, expiry policy.
- **[CHANGELOG.md](CHANGELOG.md)** – All notable changes.

---

## 📄 License / Licencia

This project is proprietary software. All rights reserved.  
Tento projekt je proprietárny softvér. Všetky práva vyhradené.

## 👥 Author / Autor

**Nagy András** - [nagy-andras-sk](https://github.com/nagy-andras-sk)

---

**Made with ❤️ for education**

