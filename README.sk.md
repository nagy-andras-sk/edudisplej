# EduDisplej - Systémová Architektúra

## Prehľad

EduDisplej je kiosk systém pre Raspberry Pi a iné Linux zariadenia, ktorý automaticky spúšťa webový prehliadač na celú obrazovku po štarte systému.

## Inštalácia - Štruktúra Adresárov

### Vytvorené Adresáre a Súbory

Pri inštalácii systém vytvorí nasledujúce štruktúry:

```
/opt/edudisplej/                          # Hlavný adresár aplikácie
├── init/                                 # Inicializačné skripty
│   ├── edudisplej-init.sh               # Hlavný inicializačný skript
│   ├── edudisplej-checker.sh            # Kontrola systému
│   ├── edudisplej-installer.sh          # Inštalátor balíčkov
│   ├── common.sh                         # Spoločné funkcie
│   ├── network.sh                        # Sieťové funkcie
│   ├── language.sh                       # Jazykové nastavenia
│   ├── kiosk.sh                          # Kiosk funkcie
│   ├── display.sh                        # Nastavenia displeja
│   ├── registration.sh                   # Registrácia zariadenia
│   ├── kiosk-start.sh                    # Wrapper pre systemd službu
│   ├── edudisplej-kiosk.service         # Systemd servisná definícia
│   └── clock.html                        # Lokálna webová stránka (hodiny)
├── localweb/                             # Lokálne webové súbory
│   └── clock.html                        # Offline záložná stránka
├── edudisplej.conf                       # Konfiguračný súbor
├── session.log                           # Log aktuálnej relácie
├── apt.log                               # Log APT operácií
├── .kiosk_mode                           # Uložený kiosk mód (chromium/epiphany)
├── .console_user                         # Uložený používateľ
├── .user_home                            # Domovský adresár používateľa
├── .kiosk_configured                     # Flag: kiosk balíčky nainštalované
└── .kiosk_system_configured              # Flag: kiosk systém nakonfigurovaný

/home/[používateľ]/                       # Domovský adresár používateľa (napr. /home/pi)
├── .xinitrc                              # X inicializačný súbor
├── .config/openbox/autostart             # Openbox autostart konfigurácia
└── kiosk-launcher.sh                     # Spúšťač kiosk terminála a prehliadača

/etc/systemd/system/
└── edudisplej-kiosk.service              # Systemd služba pre automatický štart

/etc/sudoers.d/
└── edudisplej                            # Sudoers konfigurácia pre init skript
```

## Architektúra Systému - Vrstvy

### Vrstva 1: Systemd Služba

```
┌─────────────────────────────────────────────────────┐
│  SYSTEMD (multi-user.target)                        │
│  Po štarte systému                                  │
└───────────────────┬─────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────┐
│  edudisplej-kiosk.service                           │
│  • Type: simple                                     │
│  • Conflicts: getty@tty1.service                    │
│  • After: network-online.target                     │
│  • ExecStart: /opt/edudisplej/init/kiosk-start.sh  │
└───────────────────┬─────────────────────────────────┘
                    │
                    ▼
```

### Vrstva 2: Wrapper Skript (kiosk-start.sh)

```
┌─────────────────────────────────────────────────────┐
│  kiosk-start.sh                                     │
│  Kontroluje či je potrebná prvá inicializácia      │
└───────────────────┬─────────────────────────────────┘
                    │
          ┌─────────┴──────────┐
          │                    │
          ▼                    ▼
   ┌──────────────┐    ┌──────────────────┐
   │ Flag exist?  │    │ Flag neexistuje? │
   │ .kiosk_      │    │ -> Prvý štart    │
   │ _system_     │    └────────┬─────────┘
   │ _configured  │             │
   └──────┬───────┘             │
          │                     ▼
          │          ┌─────────────────────────┐
          │          │ sudo edudisplej-init.sh │
          │          │ (Prvá inicializácia)    │
          │          └────────┬────────────────┘
          │                   │
          └───────────────────┘
                    │
                    ▼
          ┌─────────────────────┐
          │  Ukončí X servery   │
          └─────────┬───────────┘
                    │
                    ▼
          ┌─────────────────────┐
          │  startx -- :0 vt1   │
          │  (Spustí X server)  │
          └─────────┬───────────┘
                    │
                    ▼
```

### Vrstva 3: Hlavný Inicializačný Skript (edudisplej-init.sh)

```
┌──────────────────────────────────────────────────────────┐
│  edudisplej-init.sh - HLAVNÝ ORCHESTRÁTOR               │
│  Koordinuje celý proces inicializácie                    │
└────────────────────┬─────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────┐
│  1. Načítanie modulov                                    │
│     ├─ common.sh      (spoločné funkcie, preklady)      │
│     ├─ network.sh     (sieťové funkcie)                 │
│     ├─ language.sh    (jazykové nastavenia)             │
│     ├─ edudisplej-checker.sh    (kontrola systému)      │
│     └─ edudisplej-installer.sh  (inštalácia balíčkov)   │
└────────────────────┬─────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────┐
│  2. Načítanie konfigurácie                               │
│     ├─ Kiosk mód (chromium/epiphany)                    │
│     ├─ Konzolový používateľ (pi)                        │
│     └─ Domovský adresár (/home/pi)                      │
└────────────────────┬─────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────┐
│  3. Kontrola internetového pripojenia                    │
│     └─ wait_for_internet() z network.sh                 │
└────────────────────┬─────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────┐
│  4. KONTROLA SYSTÉMU (edudisplej-checker.sh)            │
│     └─ check_system_ready()                             │
│        ├─ [1/4] Základné balíčky                        │
│        ├─ [2/4] Kiosk balíčky                           │
│        ├─ [3/4] Prehliadač                              │
│        └─ [4/4] Kiosk konfigurácia                      │
└────────────────────┬─────────────────────────────────────┘
                     │
          ┌──────────┴──────────┐
          │                     │
          ▼                     ▼
   ┌─────────────┐      ┌──────────────────┐
   │ Systém OK?  │      │ Systém CHÝBA     │
   │ -> EXIT 0   │      │ komponenty?      │
   └─────────────┘      └────────┬─────────┘
                                 │
                                 ▼
┌──────────────────────────────────────────────────────────┐
│  5. INŠTALÁCIA (edudisplej-installer.sh)                │
│                                                          │
│  ┌────────────────────────────────────────────────────┐ │
│  │ 5.1 Inštalácia základných balíčkov                 │ │
│  │     install_required_packages()                    │ │
│  │     ├─ openbox, xinit, unclutter, curl             │ │
│  │     ├─ x11-utils, xserver-xorg                     │ │
│  │     └─ Zobrazuje aktuálny APT proces (nie iba %)   │ │
│  └────────────────────────────────────────────────────┘ │
│                          │                               │
│                          ▼                               │
│  ┌────────────────────────────────────────────────────┐ │
│  │ 5.2 Inštalácia kiosk balíčkov                      │ │
│  │     install_kiosk_packages()                       │ │
│  │     ├─ xterm, xdotool, figlet, dbus-x11            │ │
│  │     └─ epiphany-browser (ak ARMv6)                 │ │
│  └────────────────────────────────────────────────────┘ │
│                          │                               │
│                          ▼                               │
│  ┌────────────────────────────────────────────────────┐ │
│  │ 5.3 Inštalácia prehliadača                         │ │
│  │     install_browser()                              │ │
│  │     ├─ chromium-browser (štandardný)               │ │
│  │     └─ epiphany-browser (ARMv6)                    │ │
│  └────────────────────────────────────────────────────┘ │
└────────────────────┬─────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────┐
│  6. KONFIGURÁCIA KIOSK SYSTÉMU                          │
│     ├─ Vypnutie display managerov (lightdm, gdm, atď.) │
│     ├─ Vytvorenie ~/.xinitrc                            │
│     ├─ Vytvorenie ~/.config/openbox/autostart           │
│     ├─ Vytvorenie ~/kiosk-launcher.sh                   │
│     └─ Nastavenie flagu .kiosk_system_configured        │
└────────────────────┬─────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────┐
│  HOTOVO - EXIT 0                                         │
│  Systém je pripravený na reštart                         │
└──────────────────────────────────────────────────────────┘
```

### Vrstva 4: X Server a Openbox

```
┌─────────────────────────────────────────────────────┐
│  X Server (startx)                                  │
│  Spustený na :0 vt1                                 │
└───────────────────┬─────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────┐
│  ~/.xinitrc                                         │
│  exec openbox-session                               │
└───────────────────┬─────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────┐
│  Openbox Window Manager                             │
│  Načíta: ~/.config/openbox/autostart               │
└───────────────────┬─────────────────────────────────┘
                    │
                    ▼
```

### Vrstva 5: Kiosk Launcher a Prehliadač

```
┌─────────────────────────────────────────────────────┐
│  ~/.config/openbox/autostart                        │
│  ├─ xset -dpms (vypnutie šetriča obrazovky)        │
│  ├─ xset s off                                      │
│  ├─ unclutter (skrytie kurzora)                    │
│  └─ xterm -> ~/kiosk-launcher.sh                   │
└───────────────────┬─────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────┐
│  ~/kiosk-launcher.sh                                │
│  (Spustený v xterm)                                 │
└───────────────────┬─────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────┐
│  1. Vyčistí obrazovku                               │
│  2. Zobrazí ASCII art "EDUDISPLEJ" (figlet)         │
│  3. Odpočítavanie 5 sekúnd                          │
└───────────────────┬─────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────┐
│  4. Spustenie prehliadača                           │
│     ├─ chromium-browser --kiosk (štandardný)       │
│     └─ epiphany-browser --fullscreen (ARMv6)       │
└───────────────────┬─────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────┐
│  5. Watchdog slučka                                 │
│     └─ Reštartuje prehliadač ak sa zrúti           │
└─────────────────────────────────────────────────────┘
```

## Celkový Tok Systému - Od Štartu po Displej

```
┌──────────────────┐
│  RASPBERRY PI    │
│  Boot            │
└────────┬─────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  Linux Kernel                       │
│  systemd init                       │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  multi-user.target                  │
│  (Systémové služby)                 │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  edudisplej-kiosk.service           │
│  /opt/edudisplej/init/              │
│  kiosk-start.sh                     │
└────────┬────────────────────────────┘
         │
         │ Prvý štart?
         ▼
┌─────────────────────────────────────┐
│  edudisplej-init.sh                 │
│  ├─ Kontrola (checker.sh)           │
│  ├─ Inštalácia (installer.sh)       │
│  └─ Konfigurácia (kiosk setup)      │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  startx                             │
│  X Server :0 vt1                    │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  Openbox                            │
│  Window Manager                     │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  xterm                              │
│  ~/kiosk-launcher.sh                │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  ┌───────────────────────────────┐  │
│  │                               │  │
│  │    ███████╗██████╗ ██╗   ██╗ │  │
│  │    ██╔════╝██╔══██╗██║   ██║ │  │
│  │    █████╗  ██║  ██║██║   ██║ │  │
│  │    ██╔══╝  ██║  ██║██║   ██║ │  │
│  │    ███████╗██████╔╝╚██████╔╝ │  │
│  │    ╚══════╝╚═════╝  ╚═════╝  │  │
│  │    ██████╗ ██╗███████╗██████╗│  │
│  │    ██╔══██╗██║██╔════╝██╔══██│  │
│  │    ██║  ██║██║███████╗██████╔│  │
│  │    ██║  ██║██║╚════██║██╔═══╝│  │
│  │    ██████╔╝██║███████║██║    │  │
│  │    ╚═════╝ ╚═╝╚══════╝╚═╝    │  │
│  │    ██╗     ███████╗     ██╗  │  │
│  │    ██║     ██╔════╝     ██║  │  │
│  │    ██║     █████╗       ██║  │  │
│  │    ██║     ██╔══╝  ██   ██║  │  │
│  │    ███████╗███████╗╚█████╔╝  │  │
│  │    ╚══════╝╚══════╝ ╚════╝   │  │
│  │                               │  │
│  │  Betöltés... / Načítava sa...│  │
│  │                               │  │
│  │  Spustenie o 5 sekúnd...     │  │
│  └───────────────────────────────┘  │
│  ASCII Art v terminále              │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  Chromium / Epiphany                │
│  Fullscreen Browser                 │
│  (kiosk mód)                        │
└─────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  ┌───────────────────────────────┐  │
│  │                               │  │
│  │   WEBOVÁ STRÁNKA              │  │
│  │   (napr. time.is)             │  │
│  │                               │  │
│  │   Celá obrazovka              │  │
│  │   Bez lišt a ovládacích prvkov│  │
│  │                               │  │
│  └───────────────────────────────┘  │
│  Displej pre používateľa            │
└─────────────────────────────────────┘
```

## Moduly a Ich Úlohy

### common.sh
- **Úloha**: Poskytuje spoločné funkcie pre všetky skripty
- **Funkcie**:
  - `show_banner()` - Zobrazenie ASCII bannera "EDUDISPLEJ"
  - `show_installer_banner()` - Instalačný banner s ASCII artom
  - `show_progress_bar()` - Progress bar s ETA
  - `print_success()`, `print_error()`, `print_warning()`, `print_info()` - Farebný výstup
  - Prekladový systém (slovenčina/angličtina)
  - Konfiguračné funkcie

### network.sh
- **Úloha**: Sieťové operácie a kontroly
- **Funkcie**:
  - `wait_for_internet()` - Čaká na internetové pripojenie
  - `get_current_ip()` - Získa aktuálnu IP adresu
  - `get_gateway()` - Získa gateway
  - `get_current_ssid()` - Získa WiFi SSID
  - WiFi konfigurácia

### language.sh
- **Úloha**: Správa jazykových nastavení
- **Funkcie**:
  - Nastavenie jazyka systému
  - Prepínanie medzi jazykmi

### edudisplej-checker.sh
- **Úloha**: Kontrola systému a jeho komponentov
- **Funkcie**:
  - `check_required_packages()` - Kontrola balíčkov
  - `check_browser()` - Kontrola prehliadača
  - `check_x_environment()` - Kontrola X prostredia
  - `check_kiosk_configuration()` - Kontrola kiosk konfigurácie
  - `check_system_ready()` - Komplexná kontrola systému

### edudisplej-installer.sh
- **Úloha**: Inštalácia balíčkov a komponentov
- **Funkcie**:
  - `install_required_packages()` - Inštalácia základných balíčkov
    - **Vylepšenie**: Zobrazuje aktuálny APT proces (Reading, Unpacking, Setting up)
    - **Riešenie problému "33%"**: Užívateľ vidí reálny postup, nie len zamrznutý progress bar
  - `install_browser()` - Inštalácia prehliadača
  - `install_kiosk_packages()` - Inštalácia kiosk balíčkov

## Konfiguračné Súbory

### .kiosk_mode
- Obsahuje: `chromium` alebo `epiphany`
- Určuje ktorý prehliadač sa použije

### .console_user
- Obsahuje: meno používateľa (napr. `pi`)
- Používa sa pre konfiguráciu domovského adresára

### .kiosk_system_configured
- Flag súbor - existencia znamená že systém je plne nakonfigurovaný
- Ak neexistuje, `kiosk-start.sh` spustí `edudisplej-init.sh`

## Kľúčové Vylepšenia v Novej Architektúre

### 1. Modulárnosť
- **Starý systém**: Jeden veľký monolitický `edudisplej-init.sh` (992 riadkov)
- **Nový systém**: Rozdelený na špecializované moduly
  - `edudisplej-checker.sh` - kontrola (177 riadkov)
  - `edudisplej-installer.sh` - inštalácia (257 riadkov)
  - `edudisplej-init.sh` - orchestrácia (345 riadkov)

### 2. Riešenie Problému "Zaseknutia na 33%"
- **Starý problém**: Progress bar zamrzol na 33%, užívateľ nevedel čo sa deje
- **Nové riešenie**: 
  ```bash
  echo "► Proces: apt-get install $pkg"
  apt-get install -y "$pkg" | tee -a "$APT_LOG" | grep -E "(Reading|Building|Unpacking|Setting up|Processing)"
  ```
  - Užívateľ vidí: "Reading package lists...", "Unpacking chromium-browser...", atď.
  - Reálny feedback z APT, nie len simulovaný progress bar

### 3. Dvojjazyčné Komentáre
- Každý komentár v kóde je v maďarčine a slovenčine
- Príklad: `# Csomagok ellenőrzése -- Kontrola balíčkov`
- Uľahčuje údržbu pre oboch developerov

### 4. ASCII Art "EDUDISPLEJ"
- **Starý systém**: Zobrazoval "EDUDISP"
- **Nový systém**: Zobrazuje "EDUDISPLEJ" v ASCII arte
- Viditeľné pri:
  - Inštalácii (banner)
  - Spustení kiosk launchera (figlet v terminále)

### 5. Jednoduchá Logika Init Skriptu
```
Kontrola systému (checker.sh)
    ↓
Je všetko OK?
    ↓ ÁNO        ↓ NIE
    EXIT      Inštalácia (installer.sh)
                  ↓
              Konfigurácia
                  ↓
                EXIT
```

## Ladenie a Logy

### Log Súbory
- `/opt/edudisplej/session.log` - Kompletný log aktuálnej relácie
- `/opt/edudisplej/apt.log` - APT operácie (inštalácie, aktualizácie)

### Zobrazenie Logov
```bash
# Aktuálna relácia
cat /opt/edudisplej/session.log

# APT operácie
cat /opt/edudisplej/apt.log

# Systemd služba
sudo journalctl -u edudisplej-kiosk.service -f
```

### Manuálne Testovanie
```bash
# Spustenie init skriptu manuálne
sudo /opt/edudisplej/init/edudisplej-init.sh

# Kontrola systému bez inštalácie
sudo /opt/edudisplej/init/edudisplej-checker.sh
source /opt/edudisplej/init/edudisplej-checker.sh
check_system_ready chromium pi /home/pi

# Reštart služby
sudo systemctl restart edudisplej-kiosk.service
```

## Údržba

### Pridanie Nového Balíčka
1. Otvorte `/opt/edudisplej/init/edudisplej-installer.sh`
2. Pridajte balíček do príslušného poľa:
   ```bash
   # Pre základné balíčky
   REQUIRED_PACKAGES=(openbox xinit unclutter curl x11-utils xserver-xorg novýbalíček)
   
   # Pre kiosk balíčky
   packages+=("xterm" "xdotool" "figlet" "dbus-x11" "novýbalíček")
   ```
3. Reštartujte službu

### Zmena URL Kiosk Módu
1. Upravte `/opt/edudisplej/edudisplej.conf`:
   ```
   KIOSK_URL=https://vasaurl.sk
   ```
2. Alebo upravte `~/kiosk-launcher.sh`:
   ```bash
   URL="${1:-https://vasaurl.sk}"
   ```

## Bezpečnosť

### Sudoers Konfigurácia
- Súbor: `/etc/sudoers.d/edudisplej`
- Povoľuje používateľovi spustiť init skript bez hesla
- Potrebné pre `kiosk-start.sh` wrapper

### Automatické Prihlásenie
- Systém nepoužíva display manager
- `getty@tty1.service` je vypnutý
- `edudisplej-kiosk.service` prevezme TTY1

## Záver

Nová architektúra EduDisplej je:
- **Modulárna**: Každý modul má jasnú úlohu
- **Transparentná**: Dvojjazyčné komentáre, jasný tok
- **Užívateľsky prívetivá**: Reálny feedback pri inštalácii
- **Spoľahlivá**: Watchdog pre prehliadač, automatické reštarty
- **Udržiavateľná**: Ľahko sa pridávajú nové funkcie a balíčky
