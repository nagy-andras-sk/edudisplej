# EduDisplej - Architektúra a Technická Dokumentácia / Architecture and Technical Documentation

## Obsah / Table of Contents
1. [Prehľad Systému / System Overview](#prehľad-systému--system-overview)
2. [Inštalačný Proces / Installation Process](#inštalačný-proces--installation-process)
3. [Architektúra Vrstiev / Layer Architecture](#architektúra-vrstiev--layer-architecture)
4. [Inicializačný Proces / Initialization Process](#inicializačný-proces--initialization-process)
5. [Kiosk Mód / Kiosk Mode](#kiosk-mód--kiosk-mode)
6. [Správa Balíčkov / Package Management](#správa-balíčkov--package-management)
7. [Sieťová Konfigurácia / Network Configuration](#sieťová-konfigurácia--network-configuration)
8. [Aktualizačný Mechanizmus / Update Mechanism](#aktualizačný-mechanizmus--update-mechanism)
9. [Riešenie Problémov / Troubleshooting](#riešenie-problémov--troubleshooting)

---

## Prehľad Systému / System Overview

### Čo je EduDisplej?
EduDisplej je komplexný systém pre Raspberry Pi a Debian-based zariadenia, ktorý transformuje zariadenie na kiosk displej s automatickou konfiguráciou a správou.

**Hlavné komponenty:**
- **Install Script** (`install.sh`) - Prvotná inštalácia systému
- **Init Script** (`edudisplej-init.sh`) - Inicializácia pri každom štarte
- **Moduly** (`common.sh`, `kiosk.sh`, `network.sh`, atď.) - Funkčné moduly
- **Systemd Services** - Automatický štart a správa služieb
- **Kiosk Launcher** - Spúšťanie prehliadača v kiosk móde

### Podporované Platformy
| Architektúra | Zariadenia | Browser |
|-------------|-----------|---------|
| ARMv6 | Raspberry Pi 1, Zero (v1) | Epiphany |
| ARMv7+ | Raspberry Pi 2, 3, 4, 5, Zero 2 W | Chromium |
| x86_64 | PC, Debian/Ubuntu systémy | Chromium |

---

## Inštalačný Proces / Installation Process

### Fáza 1: Stiahnutie a Spustenie Inštalátora

```bash
curl https://install.edudisplej.sk/install.sh | sed 's/\r$//' | sudo bash
```

**Čo sa deje:**
1. Stiahnutie `install.sh` z centrálneho servera
2. Odstránenie Windows line endings (CRLF → LF)
3. Spustenie skriptu s root oprávneniami

### Fáza 2: Install.sh - Prvotná Konfigurácia

**Kroky:**
```
┌─────────────────────────────────────────────────────────────┐
│ 1. Kontrola Root Oprávnení                                  │
│    - Overí, či je skript spustený ako root (sudo)          │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 2. Detekcia Architektúry                                    │
│    - ARMv6 → Epiphany browser                               │
│    - Ostatné → Chromium browser                             │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 3. Inštalácia Curl                                          │
│    - Skontroluje prítomnosť curl                            │
│    - Nainštaluje ak chýba: apt-get install -y curl         │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 4. Kontrola GUI                                             │
│    - Zistí, či beží X server (Xorg)                        │
│    - Poznamenanie pre informáciu (neprerušuje inštaláciu)  │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 5. Zálohovanie Existujúcej Inštalácie                      │
│    - Ak /opt/edudisplej existuje → backup s timestampom    │
│    - Vytvorenie nových adresárov: init/, localweb/         │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 6. Stiahnutie Zoznamu Súborov                              │
│    - GET request na download.php?getfiles                   │
│    - Formát: NAME;SIZE;MODIFIED (CSV)                       │
│    - Validácia: kontrola prázdnej odpovede                  │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 7. Sťahovanie Súborov (Loop)                               │
│    Pre každý súbor zo zoznamu:                              │
│    - curl -sL download.php?streamfile={NAME}                │
│    - Oprava CRLF → LF (sed -i 's/\r$//')                   │
│    - .sh súbory: chmod +x, kontrola shebang                 │
│    - .html súbory: kopírovanie do localweb/                 │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 8. Validácia Stiahnutých Súborov                           │
│    - Kontrola prítomnosti edudisplej-init.sh                │
│    - Exit s chybou ak chýba                                 │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 9. Nastavenie Oprávnení                                     │
│    - chmod -R 755 /opt/edudisplej                           │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 10. Identifikácia Používateľa                               │
│     - Hľadá používateľa s UID 1000 (zvyčajne "pi")         │
│     - Zistí home directory (/home/pi alebo iný)            │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 11. Uloženie Konfigurácie                                   │
│     - Zapísanie do /opt/edudisplej/.kiosk_mode              │
│     - Zapísanie do /opt/edudisplej/.console_user            │
│     - Zapísanie do /opt/edudisplej/.user_home               │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 12. Inštalácia Systemd Service                             │
│     - Kopírovanie edudisplej-kiosk.service                  │
│     - Prispôsobenie: User, Group, WorkingDirectory          │
│     - chmod 644 /etc/systemd/system/edudisplej-kiosk.service│
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 13. Sudoers Konfigurácia                                    │
│     - Vytvorenie /etc/sudoers.d/edudisplej                  │
│     - Povolenie bezheslového sudo pre init script           │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 14. Systemd Konfigurácia                                    │
│     - Zakázanie getty@tty1 (nahradí ho kiosk service)      │
│     - systemctl daemon-reload                               │
│     - systemctl enable edudisplej-kiosk.service             │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 15. Informačné Hlásenie a Reboot                           │
│     - Zobrazenie zhrnutia (kiosk mode, user)                │
│     - Upozornenie na automatický reboot                     │
│     - sleep 10 (čakanie 10 sekúnd)                          │
│     - reboot                                                │
└─────────────────────────────────────────────────────────────┘
```

**Stiahnuté Súbory:**
- `edudisplej-init.sh` - Hlavný inicializačný skript
- `common.sh` - Zdieľané funkcie, preklady
- `kiosk.sh` - Kiosk mód funkcie
- `network.sh` - Sieťové funkcie
- `display.sh` - Displej funkcie
- `language.sh` - Jazykové funkcie
- `registration.sh` - Registrácia zariadenia
- `edudisplej-kiosk.service` - Systemd service súbor
- `kiosk-start.sh` - Wrapper pre štart kiosk módu
- `clock.html` - Lokálna fallback stránka

---

## Architektúra Vrstiev / Layer Architecture

### Vrstva 1: Operačný Systém
```
┌─────────────────────────────────────────────────────────────┐
│ Raspberry Pi OS / Debian / Ubuntu                           │
│ - Kernel: Linux                                             │
│ - Init System: systemd                                      │
│ - Shell: bash                                               │
└─────────────────────────────────────────────────────────────┘
```

### Vrstva 2: Systémové Balíčky
```
┌─────────────────────────────────────────────────────────────┐
│ X11 & Window Manager                                        │
│ - xserver-xorg: X Window Server                            │
│ - openbox: Lightweight window manager                       │
│ - xinit: X server initialization                            │
└─────────────────────────────────────────────────────────────┘
        ↓
┌─────────────────────────────────────────────────────────────┐
│ Browser                                                      │
│ - chromium-browser (ARMv7+, x86_64)                        │
│ - epiphany-browser (ARMv6)                                  │
└─────────────────────────────────────────────────────────────┘
        ↓
┌─────────────────────────────────────────────────────────────┐
│ Utility Tools                                               │
│ - unclutter: Hide mouse cursor                             │
│ - xterm: Terminal emulator                                  │
│ - xdotool: X automation                                     │
│ - figlet: ASCII art                                         │
│ - curl: HTTP client                                         │
│ - x11-utils: X utilities (xset, xrandr)                    │
└─────────────────────────────────────────────────────────────┘
```

### Vrstva 3: EduDisplej Core
```
┌─────────────────────────────────────────────────────────────┐
│ /opt/edudisplej/                                            │
│ ├── init/                    Inicializačné skripty          │
│ │   ├── edudisplej-init.sh  Hlavný init skript            │
│ │   ├── common.sh           Zdieľané funkcie               │
│ │   ├── kiosk.sh            Kiosk funkcie                  │
│ │   ├── network.sh          Sieťové funkcie                │
│ │   ├── display.sh          Displej funkcie                │
│ │   ├── language.sh         Jazykové funkcie               │
│ │   └── registration.sh     Registrácia zariadenia         │
│ ├── localweb/               Lokálne web súbory             │
│ │   └── clock.html          Fallback hodiny                │
│ ├── .kiosk_mode             Uložený kiosk mód              │
│ ├── .console_user           Uložený používateľ             │
│ ├── .user_home              Uložený home adresár           │
│ ├── session.log             Hlavný log súbor               │
│ └── apt.log                 APT operácie log               │
└─────────────────────────────────────────────────────────────┘
```

### Vrstva 4: Systemd Services
```
┌─────────────────────────────────────────────────────────────┐
│ /etc/systemd/system/edudisplej-kiosk.service               │
│ - Type=oneshot                                              │
│ - ExecStart=/opt/edudisplej/init/edudisplej-init.sh        │
│ - Runs as configured user (e.g., pi)                        │
│ - Wants=network-online.target                               │
└─────────────────────────────────────────────────────────────┘
        ↓
┌─────────────────────────────────────────────────────────────┐
│ getty@tty1.service                                          │
│ - Disabled (zakázaný)                                       │
│ - Nahradený autologin mechanizmom                           │
└─────────────────────────────────────────────────────────────┘
```

### Vrstva 5: User Space Configuration
```
┌─────────────────────────────────────────────────────────────┐
│ /home/{user}/ (napríklad /home/pi/)                        │
│ ├── .profile                 Auto-start X servera          │
│ ├── .xinitrc                 Openbox session štart          │
│ ├── .bashrc                  xrestart() funkcia            │
│ ├── .config/openbox/        Openbox konfigurácia           │
│ │   └── autostart           Autostart skripty              │
│ └── kiosk-launcher.sh        Browser launcher               │
└─────────────────────────────────────────────────────────────┘
```

### Vrstva 6: Autologin Configuration
```
┌─────────────────────────────────────────────────────────────┐
│ /etc/systemd/system/getty@tty1.service.d/autologin.conf   │
│ - Override pre getty@tty1                                   │
│ - ExecStart=-/sbin/agetty --autologin {user} --noclear ... │
└─────────────────────────────────────────────────────────────┘
```

---

## Inicializačný Proces / Initialization Process

### Štart Sekvencie po Reboot

```
┌─────────────────────────────────────────────────────────────┐
│ Boot → Systemd → Autologin → .profile → X Server           │
└─────────────────────────────────────────────────────────────┘
```

**Detailný Proces:**

1. **Systemd Boot**
   - Kernel štart
   - Systemd inicializácia
   - Network services štart

2. **Autologin na tty1**
   - `getty@tty1` zakázaný
   - Autologin konfigurácia prihlási používateľa automaticky
   - Shell (.bash_profile alebo .profile) sa spustí

3. **Auto-start X servera** (`.profile`)
   ```bash
   # Check if running on tty1
   if [ "$(tty)" = "/dev/tty1" ]; then
       startx -- :0 vt1
   fi
   ```

4. **X Server Štart**
   - X server sa spustí na :0 display
   - Načíta `.xinitrc`

5. **Openbox Session** (`.xinitrc`)
   ```bash
   exec openbox-session
   ```

6. **Openbox Autostart** (`~/.config/openbox/autostart`)
   - Vypnutie screensaver: `xset -dpms; xset s off; xset s noblank`
   - Skrytie kurzora: `unclutter -idle 1 &`
   - Spustenie xterm s launcher skriptom

7. **Kiosk Launcher** (`~/kiosk-launcher.sh`)
   - Zobrazenie ASCII banneru (figlet "EDUDISPLEJ")
   - Odpočítavanie 5 sekúnd
   - Spustenie browsera v fullscreen móde
   - Watchdog loop (reštart browser ak spadne)

---

## Inicializačný Skript Detaily

### edudisplej-init.sh Flow

```
START
  ↓
[1] Load Modules (common.sh, kiosk.sh, network.sh, display.sh, language.sh)
  ↓
[2] Show Banner (ASCII Art "EDUDISPLEJ")
  ↓
[3] Print Version Info
  ↓
[4] Ensure /opt/edudisplej/ Exists and Writable
  ↓
[5] Load Configuration (edudisplej.conf)
  ↓
[6] Wait for Internet (10 attempts × 2s = 20s max)
  ↓
[7] Register Device (prvýkrát, potom skip)
  ↓
[8] Read Kiosk Preferences (.kiosk_mode, .console_user, .user_home)
  ↓
[9] Check Required Packages
  ├─ Missing packages?
  │   ├─ YES → [10] Install Missing Packages
  │   │         ├─ apt-get update
  │   │         ├─ apt-get install (s progress bar)
  │   │         └─ Verify installation
  │   └─ NO → Continue
  ↓
[11] Install Kiosk-Specific Packages
  ├─ Already configured? (.kiosk_configured)
  │   ├─ YES → Skip
  │   └─ NO → Install (xterm, xdotool, figlet, dbus-x11, browser)
  ↓
[12] Ensure Browser Installed
  ├─ Browser exists?
  │   ├─ YES → Use it
  │   └─ NO → Install (chromium-browser or epiphany-browser)
  ↓
[13] Configure Kiosk System
  ├─ Already configured? (.kiosk_system_configured)
  │   ├─ YES → Skip
  │   └─ NO → Configure
  │         ├─ Disable display managers (lightdm, lxdm, etc.)
  │         ├─ Create .xinitrc
  │         ├─ Create openbox autostart
  │         ├─ Create kiosk-launcher.sh
  │         └─ Add xrestart() to .bashrc
  ↓
[14] Self-Update Check
  ├─ Internet available?
  │   ├─ YES → Check version.txt
  │   │         ├─ Newer version?
  │   │         │   ├─ YES → Download files, restart script
  │   │         │   └─ NO → Continue
  │   └─ NO → Skip update check
  ↓
[15] Check if First-Time Setup
  ├─ Kiosk system configured? (.kiosk_system_configured)
  │   ├─ YES → [16] Exit (X server will start via .profile)
  │   └─ NO → [17] Show System Summary
  │                 ↓
  │               [18] Set Default Mode (STANDALONE)
  │                 ↓
  │               [19] Save Config
  │                 ↓
  │               [20] Exit
  ↓
END (X server starts automatically)
```

### Konfigurační Súbory Generované Počas Init

#### 1. `/home/{user}/.xinitrc`
```bash
#!/bin/bash
# Start Openbox session
exec openbox-session
```

#### 2. `/home/{user}/.config/openbox/autostart`
```bash
# Disable DPMS/screensaver
xset -dpms
xset s off
xset s noblank

# Hide mouse after inactivity
unclutter -idle 1 &

# Show ASCII logo in xterm
xterm -fa Monospace -fs 14 -geometry 120x36+20+20 -e "$HOME/kiosk-launcher.sh" &
```

#### 3. `/home/{user}/kiosk-launcher.sh` (Chromium version)
```bash
#!/bin/bash
set -euo pipefail

URL="${1:-https://www.time.is}"
COUNT_FROM=5

# Terminal appearance
tput civis
clear
figlet -w 120 "EDUDISPLEJ"

# Countdown
for ((i=COUNT_FROM; i>=1; i--)); do
  printf "\rStarting in %2d..." "$i"
  sleep 1
done

# Launch browser
chromium-browser --kiosk --no-sandbox --disable-gpu \
  --disable-infobars --no-first-run --incognito \
  --noerrdialogs --disable-translate "${URL}" &

# Watchdog loop
while true; do
  sleep 2
  if ! pgrep -x "chromium-browser" >/dev/null; then
    chromium-browser --kiosk ... "${URL}" &
  fi
done
```

#### 4. `/home/{user}/.bashrc` (xrestart funkcia)
```bash
xrestart() {
  # Terminate X server
  for pid in $(pgrep Xorg 2>/dev/null || true); do
    kill -TERM "$pid" 2>/dev/null || true
  done
  sleep 2
  # Force kill if still running
  for pid in $(pgrep Xorg 2>/dev/null || true); do
    if kill -0 "$pid" 2>/dev/null; then
      kill -KILL "$pid" 2>/dev/null || true
    fi
  done
  sleep 1
  # Start X
  startx -- :0 vt1
}
```

---

## Kiosk Mód / Kiosk Mode

### Kiosk Módy

| Mód | Browser | Použitie | Architektúry |
|-----|---------|----------|--------------|
| chromium | Chromium Browser | Moderné zariadenia | ARMv7+, x86_64 |
| epiphany | Epiphany Browser | Staré Raspberry Pi | ARMv6 |

### Chromium Kiosk Flags

```bash
chromium-browser \
  --kiosk                              # Fullscreen bez UI
  --no-sandbox                         # Bezpečnostný sandbox (vypnutý)
  --disable-gpu                        # GPU akcelerácia (vypnutá)
  --disable-infobars                   # Info lišty (vypnuté)
  --no-first-run                       # Prvé spustenie wizard (skip)
  --incognito                          # Inkognito mód
  --noerrdialogs                       # Chybové dialógy (vypnuté)
  --disable-translate                  # Preklad stránok (vypnutý)
  --disable-features=TranslateUI       # Prekladové UI (vypnuté)
  --disable-session-crashed-bubble     # Crash bubble (vypnuté)
  --check-for-update-interval=31536000 # Aktualizácie raz za rok
  "${URL}"
```

### Epiphany Kiosk Flags

```bash
epiphany-browser --fullscreen "${URL}"
```

### Watchdog Mechanizmus

Oba browsery majú watchdog loop v `kiosk-launcher.sh`:

```bash
while true; do
  sleep 2
  if ! pgrep -x "{browser_name}" >/dev/null; then
    # Browser crashed, restart it
    {browser_command} &
  fi
done
```

**Výhody:**
- Automatické zotavenie po páde browsera
- Nepretržitá prevádzka
- Bez manuálneho zásahu

---

## Správa Balíčkov / Package Management

### Základné Balíčky (Required Packages)

Inštalované v `ensure_required_packages()`:

```bash
REQUIRED_PACKAGES=(
  openbox          # Window manager
  xinit            # X server init
  unclutter        # Mouse cursor hider
  curl             # HTTP client
  x11-utils        # X utilities
  xserver-xorg     # X Window Server
)
```

### Kiosk Balíčky (Kiosk Packages)

Inštalované v `install_kiosk_packages()`:

```bash
packages=(
  xterm            # Terminal emulator
  xdotool          # X automation
  figlet           # ASCII art
  dbus-x11         # D-Bus for X
  epiphany-browser # Browser (ARMv6 only)
)
```

### Browser Inštalácia

Inštalovaný v `ensure_browser()`:

```bash
# ARMv6
apt-get install -y epiphany-browser

# ARMv7+, x86_64
apt-get install -y chromium-browser
```

### Inštalačný Proces s Progress Bar

```
╔════════════════════════════════════════════════════════════╗
║                                                            ║
║   ███████╗██████╗ ██╗   ██╗██████╗ ██╗███████╗██████╗    ║
║   ...                                                      ║
║              I N S T A L L E R   v. 19.01.2026            ║
║                                                            ║
╚════════════════════════════════════════════════════════════╝

[████████████████████████████░░░░░░░░░░░░░░░░░░░░░░]  60% Instalujem: xterm  ETA: 02:15
```

**Progress Bar Komponenty:**
- Current step / Total steps percentage
- Visual bar (█ for filled, ░ for empty)
- Current package name
- ETA (Estimated Time to Arrival)

---

## Sieťová Konfigurácia / Network Configuration

### Internet Check

```bash
wait_for_internet() {
  local max_attempts=10  # 10 pokusov
  local attempt=1
  
  while [[ $attempt -le $max_attempts ]]; do
    if ping -c 1 -W 5 google.com &> /dev/null; then
      return 0  # Success
    fi
    echo -n "."
    sleep 2
    ((attempt++))
  done
  
  return 1  # Failure
}
```

**Timeout:** 10 attempts × 2s = 20 sekúnd maximum

### Získanie IP Adresy

```bash
get_current_ip() {
  ip route get 1 2>/dev/null | awk '{print $7}' | head -n1
}
```

### Získanie Gateway

```bash
get_gateway() {
  ip route | grep default | awk '{print $3}' | head -n1
}
```

### Získanie Wi-Fi SSID

```bash
get_current_ssid() {
  iwgetid -r 2>/dev/null || echo "N/A"
}
```

### Získanie Wi-Fi Signál

```bash
get_current_signal() {
  local signal
  signal=$(iwconfig 2>/dev/null | grep "Signal level" | sed 's/.*Signal level=\(.*\) dBm.*/\1/')
  echo "${signal:-N/A} dBm"
}
```

---

## Aktualizačný Mechanizmus / Update Mechanism

### Auto-Update Flow

```
START
  ↓
[1] Check Internet Connection
  ├─ Not Available → SKIP UPDATE
  └─ Available → Continue
  ↓
[2] Fetch Remote Version
  ├─ curl -fsSL https://install.edudisplej.sk/init/version.txt
  └─ Store in $remote_version
  ↓
[3] Compare Versions
  ├─ $remote_version == $CURRENT_VERSION → SKIP UPDATE
  └─ $remote_version != $CURRENT_VERSION → Continue
  ↓
[4] Download Updated Files
  ├─ Fetch file list: download.php?getfiles
  ├─ Create temporary directory
  ├─ Download each file: download.php?streamfile={NAME}
  ├─ Fix CRLF → LF
  ├─ Add shebang to .sh files if missing
  └─ Copy files to /opt/edudisplej/init/
  ↓
[5] Restart Init Script
  ├─ exec "$0" "$@"
  └─ Script restarts with new version
  ↓
END
```

### Verziování

```bash
CURRENT_VERSION="20260119"  # Format: YYYYMMDD
```

### Update Server Endpoints

| Endpoint | Účel |
|----------|------|
| `/init/version.txt` | Aktuálna verzia (plain text) |
| `/init/download.php?getfiles` | Zoznam súborov (CSV) |
| `/init/download.php?streamfile={NAME}` | Stiahnutie súboru |

---

## Riešenie Problémov / Troubleshooting

### Log Súbory

| Súbor | Obsah |
|-------|-------|
| `/opt/edudisplej/session.log` | Hlavný log init skriptu |
| `/opt/edudisplej/apt.log` | APT operácie (install, update) |
| `/opt/edudisplej/update.log` | Auto-update log |
| `/opt/edudisplej/kiosk.log` | Kiosk mód log (ak existuje) |
| `/var/log/Xorg.0.log` | X server log |

### Kontrola Stavu

```bash
# Skontrolovať bežiace procesy
ps aux | grep -E "Xorg|openbox|chromium|epiphany|xterm"

# Skontrolovať systemd service
systemctl status edudisplej-kiosk.service

# Skontrolovať X server log
tail -f /var/log/Xorg.0.log

# Skontrolovať session log
tail -f /opt/edudisplej/session.log
```

### Reštart X Servera

```bash
# Z terminálu (SSH alebo Ctrl+Alt+F2)
xrestart

# Alebo manuálne
sudo systemctl restart getty@tty1.service
```

### Reštart Init Procesu

```bash
# Manuálne spustenie init skriptu
sudo /opt/edudisplej/init/edudisplej-init.sh
```

### Resetovanie Konfigurácie

```bash
# Vymazanie konfiguračných značiek (flags)
sudo rm /opt/edudisplej/.kiosk_configured
sudo rm /opt/edudisplej/.kiosk_system_configured

# Reštart - init skript znovu nakonfiguruje systém
sudo reboot
```

### Problém: Browser sa nespustí

**Diagnostika:**
```bash
# Skontrolovať, či je browser nainštalovaný
which chromium-browser
which epiphany-browser

# Skontrolovať X server
echo $DISPLAY  # Should be :0
xrandr         # Should show display info

# Skontrolovať kiosk-launcher.sh
cat ~/kiosk-launcher.sh

# Manuálne spustenie
bash ~/kiosk-launcher.sh
```

**Riešenie:**
```bash
# Reinstalovať browser
sudo apt-get install --reinstall chromium-browser

# Alebo epiphany pre ARMv6
sudo apt-get install --reinstall epiphany-browser
```

### Problém: X Server sa nespustí

**Diagnostika:**
```bash
# Skontrolovať .xinitrc
cat ~/.xinitrc

# Skontrolovať X server log
tail -50 /var/log/Xorg.0.log

# Manuálne spustenie X servera
startx -- :0 vt1
```

**Riešenie:**
```bash
# Reinstalovať X server
sudo apt-get install --reinstall xserver-xorg

# Resetovať konfiguráciu
sudo rm /opt/edudisplej/.kiosk_system_configured
sudo reboot
```

### Problém: Autologin nefunguje

**Diagnostika:**
```bash
# Skontrolovať autologin konfiguráciu
cat /etc/systemd/system/getty@tty1.service.d/autologin.conf

# Skontrolovať getty service
systemctl status getty@tty1.service
```

**Riešenie:**
```bash
# Resetovať getty configuration
sudo systemctl daemon-reload
sudo systemctl restart getty@tty1.service
```

### Problém: Systém zamrzne po inštalácii

**Možné príčiny:**
1. Display manager konflikt (lightdm/lxdm stále beží)
2. X server chyba
3. Nesprávne permissions
4. Chýbajúce balíčky

**Riešenie:**
```bash
# Boot do recovery mode alebo SSH

# 1. Zakázať všetky display managery
sudo systemctl disable lightdm lxdm gdm3 sddm --now
sudo systemctl mask lightdm lxdm gdm3 sddm

# 2. Skontrolovať permissions
sudo chmod -R 755 /opt/edudisplej
sudo chown -R pi:pi /home/pi

# 3. Skontrolovať a reinstalovať balíčky
sudo apt-get update
sudo apt-get install --reinstall openbox xinit xserver-xorg

# 4. Reštart
sudo reboot
```

---

## Záver / Conclusion

EduDisplej je robustný systém navrhnutý pre bezobslužnú prevádzku digitálnych displejov na Raspberry Pi a Debian-based zariadeniach. Systém je:

- **Automatizovaný:** Minimálny manuálny zásah potrebný
- **Odolný:** Watchdog mechanizmy, auto-recovery
- **Jednoduchý:** Jednoduché príkazy na inštaláciu a správu
- **Flexibilný:** Podporuje viacero architektúr a browserov
- **Udržovateľný:** Auto-update, centralizovaná konfigurácia

Pre viac informácií a podporu, pozrite:
- [README.md](README.md) - Základné info a quick start
- [ERROR_HANDLING.md](ERROR_HANDLING.md) - Error handling stratégie
- [TESTING_GUIDE.md](TESTING_GUIDE.md) - Testovanie a validácia
