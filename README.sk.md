# EduDisplej - Systémová Architektúra a Dokumentácia

## Prehľad

EduDisplej je kiosk systém pre Raspberry Pi a iné Linux zariadenia, ktorý automaticky spúšťa webový prehliadač na celú obrazovku po štarte systému. Systém je navrhnutý tak, aby bol jednoduchý na inštaláciu, spoľahlivý a ľahko udržiavateľný.

## Rýchla Inštalácia

```bash
curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash
```

Po inštalácii reštartujte systém a EduDisplej sa automaticky spustí.

---

## Štruktúra Adresárov a Súborov

### Vytvorené Adresáre pri Inštalácii

```
/opt/edudisplej/                          # Hlavný adresár aplikácie
├── init/                                 # Inicializačné skripty
│   ├── edudisplej-init.sh               # Hlavný inicializačný skript
│   ├── edudisplej-checker.sh            # Kontrola systému
│   ├── edudisplej-installer.sh          # Inštalátor balíčkov
│   ├── common.sh                         # Spoločné funkcie
│   ├── kiosk-start.sh                    # Wrapper pre systemd službu
│   ├── kiosk.sh                          # Kiosk funkcie (zastavenie)
│   ├── display.sh                        # Funkcie pre nastavenie rozlíšenia
│   ├── edudisplej_terminal_script.sh    # ASCII banner skript
│   ├── edudisplej-kiosk.service         # Systemd servisná definícia
│   └── clock.html                        # Lokálna webová stránka (hodiny)
├── localweb/                             # Lokálne webové súbory
│   └── clock.html                        # Offline záložná stránka
├── data/                                 # Dátový adresár
│   ├── packages.json                     # Sledovanie nainštalovaných balíčkov
│   └── installed_packages.txt            # Záložné sledovanie balíčkov
├── edudisplej.conf                       # Konfiguračný súbor
├── session.log                           # Log aktuálnej relácie
├── session.log.old                       # Predchádzajúca relácia
├── apt.log                               # Log APT operácií
├── .kiosk_mode                           # Uložený kiosk mód (chromium)
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

---

## Kompletný Tok Systému - Diagram Sekvencie

```
┌──────────────────────────────────────────────────────────────────────┐
│                    INŠTALÁCIA (install.sh)                           │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  1. KONTROLA OPRAVNENÍ                                               │
│     └─ Skript musí bežať ako root (sudo)                            │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  2. DETEKCIA ARCHITEKTÚRY                                            │
│     └─ Všetky architektúry → kiosk_mode = "chromium"                │
│        (Chromium pre lepšiu stabilitu, žiadny D-Bus požadovaný)      │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  3. INŠTALÁCIA curl (ak chýba)                                      │
│     └─ apt-get install curl                                         │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  4. KONTROLA GUI                                                     │
│     └─ pgrep Xorg (len informačné)                                  │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  5. ZÁLOHA EXISTUJÚCEHO /opt/edudisplej                             │
│     └─ mv /opt/edudisplej /opt/edudisplej.bak.{timestamp}           │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  6. STIAHNUTIE SÚBOROV ZO SERVERA                                   │
│     ├─ curl https://install.edudisplej.sk/init/download.php         │
│     ├─ Stiahne zoznam súborov                                       │
│     ├─ Pre každý súbor:                                             │
│     │  ├─ Stiahne súbor                                             │
│     │  ├─ Overí veľkosť súboru                                      │
│     │  ├─ Opraví koniec riadkov (CRLF → LF)                         │
│     │  ├─ Pre .sh: nastaví shebang a chmod +x                       │
│     │  └─ Pre .html: skopíruje do /opt/edudisplej/localweb/        │
│     └─ Celkovo ~11 súborov (skripty, HTML, service)                │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  7. URČENIE POUŽÍVATEĽA A DOMOVSKÉHO ADRESÁRA                        │
│     ├─ CONSOLE_USER = používateľ s UID 1000 (zvyčajne "pi")         │
│     ├─ USER_HOME = domovský adresár používateľa                     │
│     └─ Uloženie do súborov .console_user, .user_home, .kiosk_mode   │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  8. INŠTALÁCIA SYSTEMD SLUŽBY                                       │
│     ├─ Úprava edudisplej-kiosk.service                              │
│     │  ├─ Nastavenie User a Group na CONSOLE_USER                   │
│     │  └─ Nastavenie WorkingDirectory na USER_HOME                  │
│     ├─ Kopírovanie do /etc/systemd/system/                          │
│     ├─ Vytvorenie /etc/sudoers.d/edudisplej                         │
│     ├─ systemctl disable getty@tty1.service                         │
│     └─ systemctl enable edudisplej-kiosk.service                    │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  9. PONUKA REŠTARTU                                                  │
│     ├─ Čaká 30 sekúnd na používateľskú voľbu                        │
│     ├─ Y/Enter → sudo reboot                                        │
│     └─ N → ukončenie (manuálny reštart neskôr)                      │
└──────────────────────────────────────────────────────────────────────┘
```

---

## Tok Po Reštarte - Boot Sekvencia

```
┌──────────────────────────────────────────────────────────────────────┐
│                    RASPBERRY PI BOOT                                 │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  Linux Kernel → systemd init                                         │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  multi-user.target (systémové služby)                                │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  SYSTEMD SLUŽBA: edudisplej-kiosk.service                           │
│  ├─ Type: simple                                                     │
│  ├─ After: network-online.target                                     │
│  ├─ Conflicts: getty@tty1.service                                    │
│  ├─ User: pi (alebo iný CONSOLE_USER)                               │
│  └─ ExecStart: /opt/edudisplej/init/kiosk-start.sh                  │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  WRAPPER SKRIPT: kiosk-start.sh                                     │
│  └─ Kontroluje flag: /opt/edudisplej/.kiosk_system_configured       │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                    ┌────────┴────────┐
                    ▼                 ▼
        ┌───────────────────┐  ┌──────────────────┐
        │ Flag EXISTUJE?    │  │ Flag NEEXISTUJE? │
        │ (nie prvý štart)  │  │ (prvý štart)     │
        └─────────┬─────────┘  └────────┬─────────┘
                  │                     │
                  │                     ▼
                  │         ┌────────────────────────┐
                  │         │ sudo edudisplej-init.sh│
                  │         │ (Prvá inicializácia)   │
                  │         └────────┬───────────────┘
                  │                  │
                  └──────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  Ukončenie existujúcich X serverov (ak bežia)                       │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  SPUSTENIE X SERVERA: startx -- :0 vt1                              │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  ~/.xinitrc                                                          │
│  └─ exec openbox-session                                            │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  OPENBOX WINDOW MANAGER                                              │
│  └─ Načíta: ~/.config/openbox/autostart                            │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  ~/.config/openbox/autostart                                        │
│  ├─ xset -dpms (vypnutie šetriča obrazovky)                         │
│  ├─ xset s off, xset s noblank                                      │
│  ├─ unclutter -idle 1 & (skrytie kurzora)                          │
│  └─ xterm → ~/kiosk-launcher.sh                                     │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  KIOSK LAUNCHER: ~/kiosk-launcher.sh                                │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  1. ASCII ART BANNER (v terminále)                                   │
│     ╔═══════════════════════════════════════╗                        │
│     ║   ███████╗██████╗ ██╗   ██╗          ║                        │
│     ║   ██╔════╝██╔══██╗██║   ██║          ║                        │
│     ║   █████╗  ██║  ██║██║   ██║          ║                        │
│     ║   ██╔══╝  ██║  ██║██║   ██║          ║                        │
│     ║   ███████╗██████╔╝╚██████╔╝          ║                        │
│     ║   ██████╗ ██╗███████╗██████╗ ██╗    ║                        │
│     ║   ██╔══██╗██║██╔════╝██╔══██║██║    ║                        │
│     ║   ██║  ██║██║███████╗██████╔╝██║    ║                        │
│     ║   ██║  ██║██║╚════██║██╔═══╝ ██║    ║                        │
│     ║   ██████╔╝██║███████║██║     ███████╗║                        │
│     ║   ╚═════╝ ╚═╝╚══════╝╚═╝     ╚══════╝║                        │
│     ╚═══════════════════════════════════════╝                        │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  2. SYSTÉMOVÝ STATUS                                                 │
│     ├─ Internet: Dostupný / Nedostupný                              │
│     ├─ WiFi SSID a signál (ak pripojené)                           │
│     └─ Rozlíšenie obrazovky                                         │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  3. ODPOČÍTAVANIE 5 SEKÚND                                          │
│     └─ Možnosť stlačiť F2 pre raspi-config                          │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  4. SPUSTENIE PREHLIADAČA (kiosk mód)                               │
│     ├─ Chromium: chromium-browser --kiosk --no-sandbox ...          │
│     │  (s optimalizovanými príznakmi pre nízke zdroje a bez D-Bus)  │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  5. WATCHDOG SLUČKA                                                  │
│     └─ Sleduje proces prehliadača                                    │
│     └─ Ak prehliadač zhavaruje → automatický reštart                │
└──────────────────────────────────────────────────────────────────────┘
                             │
                             ▼
                    ┌────────────────┐
                    │  WEBOVÁ STRÁNKA│
                    │  (celá obrazovka)│
                    └────────────────┘
```

---

## Prvá Inicializácia - edudisplej-init.sh

Tento skript sa spustí iba pri prvom štarte systému po inštalácii (keď neexistuje flag `.kiosk_system_configured`).

```
┌──────────────────────────────────────────────────────────────────────┐
│  EDUDISPLEJ-INIT.SH - Hlavný orchestrátor                           │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  1. NAČÍTANIE MODULOV                                                │
│     ├─ common.sh (spoločné funkcie, preklady)                       │
│     ├─ edudisplej-checker.sh (kontrola systému)                     │
│     └─ edudisplej-installer.sh (inštalácia balíčkov)                │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  2. ZOBRAZENIE BOOT SCREEN                                           │
│     ├─ ASCII art "EDUDISPLEJ"                                        │
│     ├─ Status internetu                                              │
│     ├─ WiFi SSID a signál                                           │
│     ├─ Rozlíšenie obrazovky                                         │
│     └─ Odpočítavanie 5s s možnosťou F2 → raspi-config               │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  3. NAČÍTANIE KONFIGURÁCIE                                           │
│     ├─ Kiosk mód: čítanie z .kiosk_mode                             │
│     │  └─ "chromium" (pre všetky architektúry)                        │
│     ├─ Konzolový používateľ: čítanie z .console_user                │
│     └─ Domovský adresár: čítanie z .user_home                       │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  4. KONTROLA INTERNETOVÉHO PRIPOJENIA                                │
│     ├─ wait_for_internet() - max 10 pokusov (20 sekúnd)            │
│     ├─ ping -c 1 google.com                                         │
│     ├─ Ak úspešné → INTERNET_AVAILABLE=0                            │
│     └─ Ak zlyhá → INTERNET_AVAILABLE=1 (pokračuje offline)          │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  5. KONTROLA SYSTÉMU (edudisplej-checker.sh)                        │
│     └─ check_system_ready($KIOSK_MODE, $CONSOLE_USER, $USER_HOME)   │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                    ┌────────┴────────┐
                    ▼                 ▼
        ┌───────────────────┐  ┌──────────────────┐
        │ SYSTÉM PRIPRAVENÝ?│  │ CHÝBAJÚ          │
        │ (všetko OK)       │  │ KOMPONENTY?      │
        └─────────┬─────────┘  └────────┬─────────┘
                  │                     │
                  │                     ▼
                  │         ┌────────────────────────┐
                  │         │ INŠTALÁCIA KOMPONENTOV │
                  │         └────────┬───────────────┘
                  │                  │
                  └──────────────────┘
                             │
                             ▼
                   ┌─────────────────┐
                   │ EXIT 0 (hotovo) │
                   └─────────────────┘
```

### 5. Kontrola Systému - Detailný Prehľad

```
┌──────────────────────────────────────────────────────────────────────┐
│  check_system_ready() - Kompletná kontrola systému                  │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  [1/4] KONTROLA ZÁKLADNÝCH BALÍČKOV                                 │
│  ├─ openbox                                                          │
│  ├─ xinit                                                            │
│  ├─ unclutter                                                        │
│  ├─ curl                                                             │
│  ├─ x11-utils                                                        │
│  └─ xserver-xorg                                                     │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  [2/4] KONTROLA KIOSK BALÍČKOV                                      │
│  ├─ xterm                                                            │
│  ├─ xdotool                                                          │
│  └─ figlet                                                           │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  [3/4] KONTROLA PREHLIADAČA                                         │
│  └─ chromium-browser alebo chromium                                 │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  [4/4] KONTROLA KIOSK KONFIGURÁCIE                                  │
│  ├─ ~/.xinitrc                                                       │
│  ├─ ~/.config/openbox/autostart                                     │
│  ├─ ~/kiosk-launcher.sh                                             │
│  └─ /opt/edudisplej/.kiosk_system_configured                        │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                    ┌────────┴────────┐
                    ▼                 ▼
        ┌───────────────────┐  ┌──────────────────┐
        │ VŠETKO OK         │  │ NIEČO CHÝBA      │
        │ return 0          │  │ return 1         │
        └───────────────────┘  └──────────────────┘
```

### 6. Inštalácia Komponentov (ak niečo chýba)

```
┌──────────────────────────────────────────────────────────────────────┐
│  INŠTALÁCIA CHÝBAJÚCICH KOMPONENTOV                                 │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  1. INŠTALÁCIA ZÁKLADNÝCH BALÍČKOV                                  │
│     └─ install_required_packages()                                   │
│        ├─ Kontrola každého balíčka (dpkg -s)                        │
│        ├─ Zoznam chýbajúcich balíčkov                               │
│        ├─ apt-get update (s 3 pokusmi)                              │
│        ├─ Pre každý chýbajúci balíček:                              │
│        │  ├─ apt-get install $pkg                                    │
│        │  ├─ Zobrazuje reálny APT výstup (Reading, Unpacking, ...)  │
│        │  └─ 2 pokusy pri zlyhání                                    │
│        ├─ Overenie inštalácie                                       │
│        └─ Uloženie do data/packages.json (sledovanie)               │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  2. INŠTALÁCIA KIOSK BALÍČKOV                                       │
│     └─ install_kiosk_packages($KIOSK_MODE)                           │
│        ├─ xterm, xdotool, figlet                                     │
│        ├─ Kontrola flagu .kiosk_configured                          │
│        ├─ Volá install_required_packages()                          │
│        ├─ Vytvorí flag .kiosk_configured                            │
│        └─ Uloženie do data/packages.json                            │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  3. INŠTALÁCIA PREHLIADAČA                                          │
│     └─ install_browser($BROWSER_NAME)                                │
│        └─ chromium-browser (všetky architektúry)                     │
│        ├─ Kontrola či už nainštalované (data/packages.json)         │
│        ├─ apt-get update (ak ešte nebolo)                           │
│        ├─ apt-get install s 2 pokusmi                               │
│        ├─ Zobrazuje reálny APT výstup                               │
│        └─ Uloženie do data/packages.json                            │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  4. KONFIGURÁCIA KIOSK SYSTÉMU                                      │
│     ├─ Kontrola flagu .kiosk_system_configured                      │
│     ├─ Ak už nakonfigurované → koniec                               │
│     └─ Ak nie nakonfigurované:                                      │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  4.1 VYPNUTIE DISPLAY MANAGEROV                                     │
│      ├─ systemctl disable --now lightdm.service                     │
│      ├─ systemctl disable --now lxdm.service                        │
│      ├─ systemctl disable --now sddm.service                        │
│      ├─ systemctl disable --now gdm3.service                        │
│      └─ systemctl disable --now plymouth.service                    │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  4.2 VYTVORENIE ~/.xinitrc                                          │
│      └─ Obsahuje: exec openbox-session                              │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  4.3 VYTVORENIE ~/.config/openbox/autostart                         │
│      ├─ xset -dpms (vypnutie power management)                      │
│      ├─ xset s off (vypnutie screensaver)                           │
│      ├─ xset s noblank (bez blankovania)                            │
│      ├─ unclutter -idle 1 & (skrytie kurzora)                      │
│      └─ xterm → $HOME/kiosk-launcher.sh                             │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  4.4 VYTVORENIE ~/kiosk-launcher.sh                                 │
│      ├─ Zobrazuje ASCII art EDUDISPLEJ                              │
│      ├─ Zobrazuje systémový status                                  │
│      ├─ Odpočítava 5 sekúnd s možnosťou F2                          │
│      ├─ Spúšťa prehliadač v kiosk móde                              │
│      │  └─ chromium-browser --kiosk --no-sandbox (+ optimalizované  │
│      │     príznaky pre nízke zdroje a bez D-Bus)                   │
│      └─ Watchdog slučka (reštart pri zlyhaní)                       │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  4.5 NASTAVENIE FLAGOV A OPRÁVNENÍ                                  │
│      ├─ chmod +x kiosk-launcher.sh                                  │
│      ├─ chown používateľ:používateľ všetky súbory                  │
│      ├─ touch .kiosk_system_configured                              │
│      └─ systemctl daemon-reload                                     │
└────────────────────────────┬─────────────────────────────────────────┘
                             │
                             ▼
                   ┌─────────────────┐
                   │ EXIT 0          │
                   │ (reštart potrebný)│
                   └─────────────────┘
```

---

## Moduly a Ich Funkcie

### common.sh
Spoločné funkcie a konfigurácia používaná všetkými skriptmi.

**Funkcie:**
- `show_banner()` - Zobrazenie ASCII bannera "EDUDISPLEJ"
- `show_installer_banner()` - Instalačný banner s ASCII artom
- `show_boot_screen()` - Boot obrazovka so systémovým statusom
- `countdown_with_f2()` - Odpočítavanie s detekciou F2 pre raspi-config
- `show_progress_bar(current, total, description, start_time)` - Progress bar s ETA
- `print_success(msg)`, `print_error(msg)`, `print_warning(msg)`, `print_info(msg)` - Farebný výstup
- `check_internet()` - Kontrola internetového pripojenia
- `wait_for_internet()` - Čaká na internet (max 10 pokusov = 20s)
- `get_current_ssid()` - Získa WiFi SSID
- `get_current_signal()` - Získa WiFi signál
- `get_screen_resolution()` - Získa rozlíšenie obrazovky
- `retry_command(attempts, command)` - Opakuje príkaz s exponenciálnym backoffom
- Prekladový systém (slovenčina/angličtina)
- Konfiguračné funkcie

### edudisplej-checker.sh
Kontrola systému a overenie nainštalovaných komponentov.

**Funkcie:**
- `check_required_packages(packages...)` - Kontrola balíčkov (dpkg -s)
- `check_browser(browser_name)` - Kontrola prehliadača
- `check_x_environment()` - Kontrola X prostredia
- `check_kiosk_configuration(user, home)` - Kontrola kiosk konfiguračných súborov
- `check_system_ready(kiosk_mode, user, home)` - **Hlavná funkcia**
  - Kontroluje všetky 4 oblasti (základné balíčky, kiosk balíčky, prehliadač, konfigurácia)
  - Vracia 0 ak všetko OK, 1 ak niečo chýba

### edudisplej-installer.sh
Inštalácia balíčkov a sledovanie nainštalovaných komponentov.

**Funkcie:**
- `ensure_data_directory()` - Zabezpečenie existencie /opt/edudisplej/data/
- `check_packages_installed(group)` - Kontrola či je skupina balíčkov už nainštalovaná
- `record_package_installation(group, packages...)` - Zaznamenanie inštalácie do packages.json
- `install_required_packages(packages...)` - **Hlavná inštalačná funkcia**
  - Kontroluje chýbajúce balíčky
  - apt-get update s 3 pokusmi
  - Inštaluje každý balíček zvlášť
  - Zobrazuje reálny APT výstup (nie len progress bar)
  - Overuje inštaláciu
  - Zaznamenáva do packages.json
- `install_browser(browser_name)` - Inštalácia prehliadača
  - Kontroluje či už nainštalované (packages.json)
  - Inštaluje s 2 pokusmi
  - Zaznamenáva do packages.json
- `install_kiosk_packages(kiosk_mode)` - Inštalácia kiosk balíčkov
  - xterm, xdotool, figlet
  - Používa flag .kiosk_configured

### kiosk-start.sh
Wrapper skript pre systemd službu.

**Úloha:**
1. Kontroluje existenciu flagu `.kiosk_system_configured`
2. Ak neexistuje → prvý štart → spustí `sudo edudisplej-init.sh`
3. Ukončí existujúce X servery
4. Spustí X server: `startx -- :0 vt1`

### kiosk.sh
Pomocné funkcie pre kiosk mód.

**Funkcie:**
- `start_kiosk_mode()` - Informačná funkcia (kiosk beží automaticky)
- `stop_kiosk_mode()` - Zastavenie kiosk módu
  - Ukončí chromium-browser, chromium, openbox, unclutter, Xorg, xinit
  - Vyčistí X lock súbory

### display.sh
Funkcie pre nastavenie rozlíšenia displeja.

**Funkcie:**
- `get_current_resolution()` - Získa aktuálne rozlíšenie
- `get_display_outputs()` - Získa display výstupy (HDMI, atď.)
- `set_resolution_xrandr(resolution, output)` - Nastaví rozlíšenie pomocou xrandr
- `set_resolution_config(resolution)` - Nastaví rozlíšenie v /boot/config.txt
- `set_resolution(resolution)` - Hlavná funkcia (automaticky zvolí metódu)
- `show_display_menu()` - Interaktívne menu pre nastavenie rozlíšenia

### edudisplej_terminal_script.sh
Jednoduchý skript pre zobrazenie ASCII bannera v terminále.

**Úloha:**
- Vyčistí obrazovku
- Zobrazí ASCII art "EDUDISPLEJ" (figlet ak dostupný)
- Spustí bash shell

---

## Konfiguračné Súbory a Flagy

### Flag Súbory

| Súbor | Význam |
|-------|--------|
| `.kiosk_mode` | Obsahuje "chromium" |
| `.console_user` | Meno používateľa (napr. "pi") |
| `.user_home` | Domovský adresár používateľa |
| `.kiosk_configured` | Flag: kiosk balíčky nainštalované |
| `.kiosk_system_configured` | **Hlavný flag**: kiosk systém plne nakonfigurovaný |

### edudisplej.conf
Konfiguračný súbor (vytvorený pri prvej inicializácii).

```bash
# EduDisplej Configuration File
MODE=EDSERVER
KIOSK_URL=https://www.time.is
LANG=sk
PACKAGES_INSTALLED=0
```

### data/packages.json
Sledovanie nainštalovaných balíčkov (JSON formát).

```json
{
  "packages": {
    "required_packages": {
      "installed": true,
      "date": "2024-01-20T10:30:00Z",
      "versions": {
        "openbox": "3.6.1-8",
        "xinit": "1.4.1-0",
        "curl": "7.74.0-1"
      }
    },
    "browser_chromium-browser": {
      "installed": true,
      "date": "2024-01-20T10:35:00Z",
      "versions": {
        "chromium-browser": "120.0.6099.224-1"
      }
    }
  },
  "last_update": "2024-01-20T10:35:00Z"
}
```

---

## Kľúčové Vylepšenia v Architektúre

### 1. Modulárnosť
- Rozdelenie na špecializované moduly
- Každý modul má jasnú zodpovednosť
- Ľahká údržba a testovanie

### 2. Riešenie Problému "Zaseknutia na 33%"
**Starý problém:** Progress bar zamrzol na 33%, užívateľ nevedel čo sa deje.

**Nové riešenie:**
```bash
echo "► Proces: apt-get install $pkg"
apt-get install -y "$pkg" | tee -a "$APT_LOG" | grep -E "(Reading|Building|Unpacking|Setting up)"
```
- Užívateľ vidí reálny APT výstup
- Transparentný feedback počas inštalácie

### 3. Sledovanie Nainštalovaných Balíčkov
- `data/packages.json` - JSON databáza nainštalovaných balíčkov
- Zabránenie opakovanej inštalácie
- Verzie balíčkov a časové značky

### 4. Dvojjazyčné Komentáre
- Každý komentár v kóde je v maďarčine a slovenčine
- Príklad: `# Csomagok ellenőrzése -- Kontrola balíčkov`

### 5. Robustná Kontrola Systému
- 4-stupňová kontrola systému
- Jasná identifikácia chýbajúcich komponentov
- Automatická inštalácia len potrebných častí

### 6. Watchdog pre Prehliadač
- Automatický reštart prehliadača pri zlyhaní
- Zabezpečenie nepretržitého chodu

---

## Ladenie a Logy

### Log Súbory

| Súbor | Obsah |
|-------|-------|
| `/opt/edudisplej/session.log` | Kompletný log aktuálnej relácie |
| `/opt/edudisplej/session.log.old` | Log predchádzajúcej relácie |
| `/opt/edudisplej/apt.log` | APT operácie (inštalácie, aktualizácie) |

### Zobrazenie Logov

```bash
# Aktuálna relácia
cat /opt/edudisplej/session.log

# Predchádzajúca relácia
cat /opt/edudisplej/session.log.old

# APT operácie
cat /opt/edudisplej/apt.log

# Systemd služba (živý výstup)
sudo journalctl -u edudisplej-kiosk.service -f

# Systemd služba (celý log)
sudo journalctl -u edudisplej-kiosk.service --no-pager
```

### Manuálne Testovanie

```bash
# Spustenie init skriptu manuálne
sudo /opt/edudisplej/init/edudisplej-init.sh

# Kontrola systému bez inštalácie
source /opt/edudisplej/init/common.sh
source /opt/edudisplej/init/edudisplej-checker.sh
check_system_ready chromium pi /home/pi

# Reštart služby
sudo systemctl restart edudisplej-kiosk.service

# Status služby
sudo systemctl status edudisplej-kiosk.service

# Zastavenie služby
sudo systemctl stop edudisplej-kiosk.service

# Manuálne spustenie X servera
startx -- :0 vt1
```

---

## Údržba

### Pridanie Nového Balíčka

1. Otvorte `/opt/edudisplej/init/edudisplej-init.sh`
2. Pridajte balíček do príslušného poľa:
   ```bash
   # Pre základné balíčky (riadok 228)
   REQUIRED_PACKAGES=(openbox xinit unclutter curl x11-utils xserver-xorg novybalik)
   
   # Pre kiosk balíčky - upravte edudisplej-installer.sh (riadok 336)
   packages+=("xterm" "xdotool" "figlet" "dbus-x11" "novybalik")
   ```
3. Vymažte flag súbory pre opätovnú inštaláciu:
   ```bash
   sudo rm /opt/edudisplej/.kiosk_configured
   sudo rm /opt/edudisplej/.kiosk_system_configured
   ```
4. Reštartujte službu:
   ```bash
   sudo systemctl restart edudisplej-kiosk.service
   ```

### Zmena URL Kiosk Módu

**Metóda 1:** Upravte konfiguračný súbor
```bash
sudo nano /opt/edudisplej/edudisplej.conf
# Zmeňte riadok:
KIOSK_URL=https://vasaurl.sk
```

**Metóda 2:** Upravte kiosk-launcher.sh
```bash
nano ~/kiosk-launcher.sh
# Zmeňte riadok podľa potreby (Chromium s optimalizovanými príznakmi):
URL="${1:-https://vasaurl.sk}"
```

**Metóda 3:** Upravte autostart pre trvalú zmenu
```bash
nano ~/.config/openbox/autostart
# Upravte riadok s xterm:
xterm -fa Monospace -fs 14 -geometry 120x36+20+20 -e "$HOME/kiosk-launcher.sh https://vasaurl.sk" &
```

Po zmene reštartujte službu:
```bash
sudo systemctl restart edudisplej-kiosk.service
```

### Overenie Nastavenia Prehliadača

**Poznámka**: Systém teraz používa výhradne Chromium pre lepšiu stabilitu a bez potreby D-Bus session.

```bash
# Overenie nastavenia (mali by ste vidieť "chromium")
cat /opt/edudisplej/.kiosk_mode

# Ak súbor neexistuje alebo obsahuje "epiphany", opravte ho:
echo "chromium" | sudo tee /opt/edudisplej/.kiosk_mode
sudo rm -f /opt/edudisplej/.kiosk_system_configured

# Reštart služby pre aplikovanie zmeny
sudo systemctl restart edudisplej-kiosk.service
```
sudo rm /opt/edudisplej/.kiosk_system_configured

# Zmena na Chromium
echo "chromium" | sudo tee /opt/edudisplej/.kiosk_mode
sudo rm /opt/edudisplej/.kiosk_system_configured

# Reštart služby pre aplikovanie zmeny
sudo systemctl restart edudisplej-kiosk.service
```

### Resetovanie Systému do Východzieho Stavu

```bash
# Odstránenie všetkých konfiguračných flag súborov
sudo rm /opt/edudisplej/.kiosk_configured
sudo rm /opt/edudisplej/.kiosk_system_configured

# Odstránenie konfiguračných súborov používateľa
rm ~/.xinitrc
rm ~/.config/openbox/autostart
rm ~/kiosk-launcher.sh

# Reštart služby - spustí sa prvá inicializácia
sudo systemctl restart edudisplej-kiosk.service
```

### Odinštalovanie EduDisplej

```bash
# Zastavenie a vypnutie služby
sudo systemctl stop edudisplej-kiosk.service
sudo systemctl disable edudisplej-kiosk.service

# Odstránenie systemd služby
sudo rm /etc/systemd/system/edudisplej-kiosk.service
sudo systemctl daemon-reload

# Odstránenie sudoers konfigurácie
sudo rm /etc/sudoers.d/edudisplej

# Opätovné povolenie getty@tty1
sudo systemctl enable getty@tty1.service
sudo systemctl unmask getty@tty1.service

# Odstránenie súborov EduDisplej
sudo rm -rf /opt/edudisplej

# Odstránenie konfiguračných súborov používateľa
rm ~/.xinitrc
rm ~/.config/openbox/autostart
rm ~/kiosk-launcher.sh

# Reštart systému
sudo reboot
```

---

## Bezpečnosť

### Sudoers Konfigurácia

Súbor `/etc/sudoers.d/edudisplej` povoľuje spustenie init skriptu bez hesla:

```
# Allow console user to run init script without password
pi ALL=(ALL) NOPASSWD: /opt/edudisplej/init/edudisplej-init.sh
```

**Prečo je to potrebné:**
- `kiosk-start.sh` beží pod užívateľským účtom (nie root)
- Init skript potrebuje root oprávnenia na inštaláciu balíčkov
- Zabezpečuje automatický beh bez manuálneho zadania hesla

### Automatické Prihlásenie

- Systém nepoužíva display manager (lightdm, gdm, atď.)
- `getty@tty1.service` je vypnutý
- `edudisplej-kiosk.service` prevezme TTY1
- X server je spustený priamo cez `startx`

### Sieťová Bezpečnosť

- Chromium beží s `--no-sandbox` (potrebné pre Raspberry Pi)
- Doporučujeme použiť firewall (ufw) pre obmedzenie sieťového prístupu
- Pre produkčné nasadenie zvážte:
  - VPN pripojenie
  - Whitelist URL adries
  - Pravidelné bezpečnostné aktualizácie

---

## Riešenie Problémov

### Problém: Systém sa nezapne do kiosk módu

**Riešenie:**
1. Skontrolujte status služby:
   ```bash
   sudo systemctl status edudisplej-kiosk.service
   ```
2. Skontrolujte logy:
   ```bash
   sudo journalctl -u edudisplej-kiosk.service -n 50
   cat /opt/edudisplej/session.log
   ```
3. Manuálne spustite init skript:
   ```bash
   sudo /opt/edudisplej/init/edudisplej-init.sh
   ```

### Problém: Prehliadač sa nezobrazuje

**Riešenie:**
1. Skontrolujte či beží X server:
   ```bash
   ps aux | grep Xorg
   ```
2. Skontrolujte kiosk-launcher.sh:
   ```bash
   cat ~/kiosk-launcher.sh
   ```
3. Manuálne spustite X server:
   ```bash
   startx -- :0 vt1
   ```

### Problém: Inštalácia zlyháva pri sťahovaní súborov

**Riešenie:**
1. Skontrolujte internetové pripojenie:
   ```bash
   ping google.com
   ```
2. Skontrolujte dostupnosť servera:
   ```bash
   curl -I https://install.edudisplej.sk
   ```
3. Opakujte inštaláciu s podrobným výstupom:
   ```bash
   curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash -x
   ```

### Problém: "Zaseknutie na 33%" pri inštalácii balíčkov

**Poznámka:** Tento problém bol vyriešený v novej architektúre.

**Ak sa stále vyskytne:**
1. Počkajte dlhšie - veľké balíčky (chromium) môžu trvať aj 10+ minút
2. Skontrolujte APT log v reálnom čase:
   ```bash
   tail -f /opt/edudisplej/apt.log
   ```
3. Skontrolujte sieťovú prevádzku:
   ```bash
   sudo iftop
   ```

### Problém: Chýbajú fonty alebo ikony

**Riešenie:**
```bash
sudo apt-get install fonts-dejavu fonts-liberation ttf-mscorefonts-installer
sudo fc-cache -f -v
```

---

## Často Kladené Otázky (FAQ)

### Aké sú minimálne požiadavky?

- **Hardvér:** Raspberry Pi 2 alebo novší (podporovaný aj ARMv6)
- **OS:** Raspberry Pi OS Lite alebo Desktop (Debian-based)
- **Sieť:** Internetové pripojenie pri inštalácii
- **Pamäť:** Min. 512 MB RAM
- **Úložisko:** Min. 2 GB voľného miesta

### Funguje to na iných zariadeniach okrem Raspberry Pi?

Áno, EduDisplej funguje na akejkoľvek Debian-based distribúcii (Ubuntu, Debian, atď.). Automaticky deteguje architektúru a zvolí vhodný prehliadač.

### Ako zmením jazyk systému?

Momentálne je systém primárne v slovenčine a angličtine. Jazykové funkcie sú pripravené v `common.sh`, ale nie sú plne implementované.

### Môžem použiť vlastný HTML súbor namiesto URL?

Áno. Umiestnite HTML súbor do `/opt/edudisplej/localweb/` a zmeňte URL:
```bash
# V kiosk-launcher.sh
URL="${1:-file:///opt/edudisplej/localweb/mojastranka.html}"
```

### Ako zobrazím viac terminálových informácií pri štarte?

Upravte `edudisplej_terminal_script.sh` a pridajte požadované informácie do boot screen sekcie.

---

## Záver

EduDisplej poskytuje:
- ✅ **Jednoduchú inštaláciu:** Jeden príkaz curl
- ✅ **Automatický štart:** Systemd služba
- ✅ **Modulárnosť:** Jasne oddelené moduly
- ✅ **Transparentnosť:** Reálny feedback pri inštalácii
- ✅ **Spoľahlivosť:** Watchdog, automatické reštarty
- ✅ **Flexibilitu:** Podpora Chromium s optimalizovanými príznakmi pre nízke zdroje
- ✅ **Udržiavateľnosť:** Sledovanie balíčkov, jasná štruktúra

Pre viac informácií, otázok alebo hlásenie problémov navštívte GitHub repozitár projektu.
