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
3. **Module Sync** / **Synchronizácia modulov**: Content syncs automatically every 5 minutes / Obsah sa synchronizuje automaticky každých 5 minút
4. **Display Rotation** / **Rotácia zobrazení**: Modules rotate based on your configuration / Moduly sa striedajú podľa vašej konfigurácie

---

## 🎯 Features / Funkcie

- ⏰ Clock module (digital/analog) / Modul hodín (digitálne/analógové)
- 📅 Name days (Slovak/Hungarian) / Meniny (slovenské/maďarské)
- 🖥️ Split-screen layouts / Rozdelené obrazovky
- ⏱️ Scheduled content / Naplánovaný obsah
- 📊 Real-time monitoring / Monitorovanie v reálnom čase
- 🔄 Automatic updates / Automatické aktualizácie
- 🔑 Module license management / Správa licencií modulov
- 🏢 Multi-company support / Podpora viacerých spoločností
- ⚙️ Per-kiosk module configuration / Konfigurácia modulov pre každý kiosk

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

### For Companies / Pre spoločnosti
Visit / Navštívte: **https://control.edudisplej.sk/dashboard/**
- Configure your kiosks / Konfigurujte svoje kioski
- Customize module settings / Prispôsobte nastavenia modulov
- Monitor your displays / Monitorujte svoje displeje

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

- **Sync Interval**: Configurable (default: 5 minutes) / **Interval synchronizácie**: Konfigurovateľný (predvolené: 5 minút)
- **Loop Auto-Update**: Checks for configuration changes every 30 seconds / **Automatická aktualizácia slučky**: Kontroluje zmeny konfigurácie každých 30 sekúnd
- **Automatic Reload**: Browser reloads when new configuration detected / **Automatické načítanie**: Prehliadač sa znovu načíta pri detekcii novej konfigurácie

### Hostname Configuration / Konfigurácia názvu zariadenia

Devices are automatically named: `edudisplej-XXXXXX` (last 6 chars of MAC address)
Zariadenia sú automaticky pomenované: `edudisplej-XXXXXX` (posledných 6 znakov MAC adresy)

---

## 📄 License / Licencia

This project is proprietary software. All rights reserved.
Tento projekt je proprietárny softvér. Všetky práva vyhradené.

## 👥 Author / Autor

**Nagy András** - [nagy-andras-sk](https://github.com/nagy-andras-sk)

---

**Made with ❤️ for education**

