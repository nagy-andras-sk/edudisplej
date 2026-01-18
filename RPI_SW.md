# EduDisplej - Raspberry Pi Softvérová Architektúra

## Obsah

1. [Prehľad](#1-prehľad)
2. [Systémová Architektúra - Vrstvy](#2-systémová-architektúra---vrstvy)
3. [Štruktúra Súborov](#3-štruktúra-súborov)
4. [Proces Bootovanía](#4-proces-bootovanía)
5. [Konfigurácia](#5-konfigurácia)
6. [Riešenie Problémov](#6-riešenie-problémov)
7. [Informácie pre Vývojárov](#7-informácie-pre-vývojárov)

---

## 1. Prehľad

### Čo je EduDisplej?

EduDisplej je riešenie pre digitálne zobrazovanie (digital signage) založené na platforme Raspberry Pi, ktoré beží v kiosk móde. Systém poskytuje robustnú, bezobslužnú inštaláciu pre Debian/Ubuntu/Raspberry Pi OS.

**Hlavné vlastnosti:**
- 🖥️ Fullscreen kiosk mód s webovým prehliadačom
- 🔄 Automatický reštart pri zlyhaní
- 🌐 Podpora online aj offline režimu
- ⚙️ Jednoduché nastavenie cez F12 menu
- 🔐 Vzdialená registrácia a správa zariadení
- 📱 Podpora pre Chromium aj Epiphany prehliadače

### Prípady Použitia

- Školské informačné tabule
- Digitálne nástěnky v knižniciach
- Informačné displeje v kanceláriách
- Automatizované prezentačné systémy
- Digitálne hodiny s doplnkovým obsahom

### Podporované Platformy

- **Raspberry Pi** (1, 2, 3, 4, 5, Zero)
- **Raspberry Pi OS** (Debian-based)
- Iné **Debian/Ubuntu** distribúcie s ARM alebo x86 architektúrou
- Systémy s aj bez **NEON** podpory (ARM)

---

## 2. Systémová Architektúra - Vrstvy

EduDisplej je postavený ako viacvrstvový systém, kde každá vrstva má svoju špecifickú úlohu:

```
┌─────────────────────────────────────────────────────────┐
│  Vrstva 8: Webová Aplikácia (clock.html)               │
├─────────────────────────────────────────────────────────┤
│  Vrstva 7: Watchdog (watchdog.sh)                      │
├─────────────────────────────────────────────────────────┤
│  Vrstva 6: Kiosk Aplikácia (xclient.sh)                │
│            ├── Chromium/Chromium-browser                │
│            └── Epiphany-browser (fallback)              │
├─────────────────────────────────────────────────────────┤
│  Vrstva 5: X Prostredie                                 │
│            ├── Xinit                                     │
│            ├── Openbox (window manager)                 │
│            └── Unclutter (kurzor)                       │
├─────────────────────────────────────────────────────────┤
│  Vrstva 4: Init Systém (edudisplej-init.sh + moduly)   │
├─────────────────────────────────────────────────────────┤
│  Vrstva 3: Systemd Služba (chromiumkiosk.service)      │
├─────────────────────────────────────────────────────────┤
│  Vrstva 2: Inštalačný Systém (install.sh)              │
├─────────────────────────────────────────────────────────┤
│  Vrstva 1: Operačný Systém (Raspberry Pi OS)           │
└─────────────────────────────────────────────────────────┘
```

### Vrstva 1: Operačný Systém

**Základ systému:**
- **OS:** Raspberry Pi OS (Debian-based)
- **Používatelia:**
  - `root` - systémová správa
  - `edudisplej` - bežiaci kiosk proces
  - `pi` (voliteľné) - konzolový prístup
- **Základné Služby:**
  - `systemd` - správca služieb
  - `network-manager` / `dhcpcd` - sieťová správa
  - TTY1 - virtuálny terminál pre X server

**Zodpovednosti:**
- Boot proces a inicializácia hardvéru
- Správa používateľov a oprávnení
- Sieťová konektivita
- Správa súborového systému

---

### Vrstva 2: Inštalačný Systém

**Súbor:** [`webserver/install/install.sh`](webserver/install/install.sh)

**Účel:** Prvotná inštalácia a nastavenie EduDisplej systému.

**Proces inštalácie:**

1. **Kontrola oprávnení**
   - Vyžaduje root prístup
   - Overenie dostupnosti `curl`

2. **Stiahnutie init súborov**
   ```bash
   curl https://install.edudisplej.sk/init/download.php?getfiles
   ```
   - Získa zoznam všetkých potrebných súborov
   - Stiahne každý súbor individuálne
   - Opraví CRLF → LF (Windows → Unix)
   - Pridá shebang do shell skriptov ak chýba

3. **Vytvorenie štruktúry adresárov**
   ```
   /opt/edudisplej/
   ├── init/          # Init skripty
   └── localweb/      # Lokálne HTML súbory
   ```

4. **Registrácia systemd služby**
   - Vytvorí `chromiumkiosk.service`
   - Povolí automatické spustenie pri boote
   - Nastaví reštart politiku

5. **Nastavenie oprávnení**
   - Všetky súbory: `755`
   - Vlastníctvo: `edudisplej:edudisplej`

**Rýchla inštalácia:**
```bash
curl https://install.edudisplej.sk/install.sh | sed 's/\r$//' | sudo bash
```

---

### Vrstva 3: Systemd Služba

**Súbor:** `/etc/systemd/system/chromiumkiosk.service`

**Konfigurácia služby:**
```ini
[Unit]
Description=Chromium Kiosk Service
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=/opt/edudisplej/init
ExecStart=/usr/bin/xinit /opt/edudisplej/init/xclient.sh -- :0 vt1 -nolisten tcp
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
```

**Vlastnosti:**
- **Automatický štart:** Spustí sa pri každom boote
- **Reštart politika:** Automaticky reštartuje pri zlyhaní (2s delay)
- **Závislosti:** Čaká na sieťovú konektivitu
- **X Server:** Spúšťa Xinit s `xclient.sh` na display `:0`

**Správa služby:**
```bash
# Zapnutie služby
sudo systemctl enable chromiumkiosk.service

# Spustenie služby
sudo systemctl start chromiumkiosk.service

# Reštart služby
sudo systemctl restart chromiumkiosk.service

# Stav služby
sudo systemctl status chromiumkiosk.service

# Logy služby
sudo journalctl -u chromiumkiosk.service -f
```

**Modul:** [`webserver/install/init/services.sh`](webserver/install/init/services.sh)

---

### Vrstva 4: Init Systém

Init systém pozostáva z hlavného skriptu a modulov:

#### **Hlavný Skript: edudisplej-init.sh**

**Súbor:** [`webserver/install/init/edudisplej-init.sh`](webserver/install/init/edudisplej-init.sh)

**Zodpovednosti:**
1. **Načítanie modulov**
   - `common.sh` - zdieľané funkcie, preklady
   - `kiosk.sh` - X server a kiosk setup
   - `network.sh` - sieťové funkcie
   - `services.sh` - systemd správa
   - `registration.sh` - registrácia zariadení
   - `display.sh` - nastavenia displeja
   - `language.sh` - jazykové nastavenia

2. **Kontrola verzií a auto-update**
   ```
   Aktuálna verzia: 20260107-1
   Server: https://install.edudisplej.sk/init/version.txt
   ```
   - Porovná lokálnu verziu so serverom
   - Ak je dostupná novšia verzia → stiahne a reštartuje

3. **Konfiguračné menu (F12)**
   - 10-sekundový countdown
   - Stlačenie F12 alebo M otvorí menu:
     - EduServer režim
     - Samostatný režim
     - Jazyk (SK/EN)
     - Nastavenia displeja
     - Nastavenia siete
     - Ukončiť

4. **Spustenie kiosk módu**
   - Načíta uložený režim z `.mode`
   - Spustí X server a prehliadač

#### **Moduly:**

**1. common.sh** - Základné funkcie
- Prekladový systém (SK/EN)
- Pomocné funkcie: `print_info()`, `print_error()`, `print_success()`
- Konfiguračné premenné

**2. kiosk.sh** - X a kiosk setup
- `cleanup_x_sessions()` - vyčistenie starých X procesov
- `start_x_server()` - spustenie Xinit
- Konfigurácia Openbox

**3. services.sh** - Systemd služby
- `ensure_chromium_kiosk_service()` - vytvorenie služby
- `start_chromium_kiosk_service()` - spustenie
- `restart_chromium_kiosk_service()` - reštart
- `enable_chromium_kiosk_service()` - povolenie

**4. registration.sh** - Registrácia zariadení
- `get_primary_mac()` - získanie MAC adresy
- `register_device()` - registrácia na serveri
- Uloženie do `.registration.json`

**5. network.sh** - Sieťové funkcie
- WiFi konfigurácia
- Statická IP
- Test konektivity

**6. display.sh** - Nastavenia displeja
- Rozlíšenie obrazovky
- Orientácia displeja

**7. language.sh** - Jazykové nastavenia
- Prepínanie SK ↔ EN
- Aplikovanie prekladu na celý systém

---

### Vrstva 5: X Prostredie

Grafické prostredie pre kiosk prehliadač.

#### **Xinit**

**Účel:** Spustenie X servera a klienta.

**Príkaz:**
```bash
xinit /opt/edudisplej/init/xclient.sh -- :0 vt1 -nolisten tcp
```

**Parametre:**
- `:0` - Display číslo
- `vt1` - Virtuálny terminál 1
- `-nolisten tcp` - Vypnutie vzdialených X pripojení (bezpečnosť)

#### **Openbox**

**Účel:** Minimalistický window manager.

**Konfigurácia:** `~/.config/openbox/rc.xml`

```xml
<openbox_config>
    <desktops>
        <number>1</number>
    </desktops>
    <margins>
        <top>0</top><bottom>0</bottom>
        <left>0</left><right>0</right>
    </margins>
    <applications>
        <application name="chromium">
            <decor>no</decor>
            <maximized>yes</maximized>
        </application>
        <application name="chromium-browser">
            <decor>no</decor>
            <maximized>yes</maximized>
        </application>
    </applications>
</openbox_config>
```

**Vlastnosti:**
- **Borderless okná** - žiadne dekorácie
- **Maximalizácia** - automaticky celá obrazovka
- **1 Desktop** - jednoduchosť

#### **Unclutter**

**Účel:** Skryje kurzor myši po 0.5s nečinnosti.

```bash
unclutter -idle 0.5 -root &
```

#### **Xset nastavenia**

```bash
xset s off           # Vypnutie screensaveru
xset s noblank       # Bez blikania
xset -dpms           # Vypnutie energy saving
xset dpms 0 0 0      # Žiadny timeout
```

**Modul:** [`webserver/install/init/kiosk.sh`](webserver/install/init/kiosk.sh)

---

### Vrstva 6: Kiosk Aplikácia

**Súbor:** [`webserver/install/init/xclient.sh`](webserver/install/init/xclient.sh)

Toto je hlavný wrapper skript pre spustenie webového prehliadača v kiosk móde.

**UPOZORNENIE:** Tento skript bol zjednodušený 18.1.2026 z 417 riadkov na 209 riadkov (50% redukcia) pre zvýšenie stability a zníženie chybovosti. Pozri [SIMPLIFIED_ARCHITECTURE.md](SIMPLIFIED_ARCHITECTURE.md) pre detaily.

#### **Detekcia prehliadača**

**Funkcia:** `detect_browser()`

**Zjednodušená stratégia detekcie:**

Priorita prehliadačov (pre všetky systémy):
```
1. epiphany-browser      (ľahký, funguje na všetkých ARM)
2. chromium-browser       (štandardný Chromium)
3. chromium               (alternatívny Chromium)
4. firefox-esr            (fallback)
```

**Poznámka:** Odstránená zložitá kontrola NEON podpory - Epiphany funguje na všetkých zariadeniach.

#### **Príprava prostredia**

**Funkcia:** `setup_x_env()`

```bash
export LIBGL_ALWAYS_SOFTWARE=1      # Softvérové GL rendering
export XDG_RUNTIME_DIR="/tmp/edudisplej-runtime"
```

**X prostredie:**
- Vypnutie screensavera (`xset s off`, `xset -dpms`)
- Skrytie kurzora myši (`unclutter -idle 1`)
- Spustenie Openbox window managera

#### **Browser Flagy (Zjednodušené)**

**Epiphany:**
```bash
epiphany-browser --application-mode URL
```

**Chromium (iba 8 základných flagov):**
```bash
--kiosk                    # Fullscreen režim
--no-sandbox               # Potrebné pre root
--disable-gpu              # Software rendering
--disable-infobars         # Bez info lišty
--noerrdialogs             # Bez error dialógov
--incognito                # Privátny režim
--no-first-run             # Preskočiť wizard
--disable-translate        # Bez prekladu
```

**Firefox ESR:**
```bash
firefox-esr --kiosk --private-window URL
```

**Poznámka:** Odstránených 22+ zložitých flagov, ktoré spôsobovali crashe.

#### **Spustenie prehliadača**

**Funkcia:** `start_browser()`

**Zjednodušená stratégia:**
1. Nastavenie prostredia
2. Vyčistenie starých procesov (pomocou kill s PID, nie pkill)
3. Spustenie prehliadača
4. Čakanie na ukončenie
5. Reštart po 10s

**Príklad príkazu:**
```bash
# Epiphany
epiphany-browser --application-mode file:///opt/edudisplej/localweb/clock.html

# Chromium (zjednodušené)
chromium-browser --kiosk --no-sandbox --disable-gpu ... file:///opt/edudisplej/localweb/clock.html
```

#### **Zber hardvérových informácií**

Pri štarte X session sa automaticky volá:
```bash
/opt/edudisplej/init/hwinfo.sh generate
```

Zbiera informácie o:
- CPU (model, teплота, NEON podpora)
- Pamäť (celkom, voľná, dostupná)
- Disk (využitie)
- Sieť (MAC, IP, gateway, WiFi SSID)
- Displej (rozlíšenie z xrandr)
- Raspberry Pi (model, serial, firmware, napätie)
- Browser (nainštalované prehliadače)

Ukladá sa do: `/opt/edudisplej/hwinfo.conf`

**Log:** `/opt/edudisplej/xclient.log`

**Vlastnosti:**
- Automatická rotácia logov (max 2MB)
- Jednoduchšie logovanie (priame, bez tee)
- Timestamp pre každý záznam

---

### Vrstva 7: Watchdog

**Súbor:** [`webserver/install/init/watchdog.sh`](webserver/install/init/watchdog.sh)

**Účel:** Monitorovanie prehliadača a automatický reštart pri zlyhaní.

**Funkcie:**

1. **`is_chromium_running()`**
   ```bash
   pgrep -x "chromium" || pgrep -x "chromium-browser"
   ```

2. **`start_watchdog()`**
   - Kontroluje či watchdog už beží
   - Uloží PID do `.watchdog.pid`
   - Spustí monitor cyklus

3. **Monitor cyklus:**
   ```bash
   while true; do
       if ! is_chromium_running; then
           log_msg "Browser crashed, restarting..."
           restart_browser
       fi
       sleep 60  # Kontrola každých 60s
   done
   ```

**Ochrana:**
- **Rate limiting:** Max 3 reštarty za 60 sekúnd
- **Backoff stratégia:** Zvyšujúce sa čakacie doby
- **Log rotácia:** Max 2MB log súbor

**Log:** `/opt/edudisplej/watchdog.log`

---

### Vrstva 8: Webová Aplikácia

**Súbor:** [`webserver/install/init/clock.html`](webserver/install/init/clock.html)

**Účel:** Predvolený obsah pre kiosk displej.

**Vlastnosti:**
- **Fullscreen hodiny** s animáciami
- **Responzívny dizajn** - funguje na všetkých rozlíšeniach
- **Čierny pozadie** - úspora energie
- **Biele čísla** - dobrá viditeľnosť
- **JavaScript hodiny** - presný čas
- **Animované oddeľovače** - vizuálny efekt

**Technológie:**
- HTML5
- CSS3 (flexbox, animations)
- Vanilla JavaScript (bez závislostí)

**Fallback režim:**
- Zobrazí sa keď nie je internetové pripojenie
- Alebo keď vzdialený server nie je dostupný
- Lokálna kópia: `/opt/edudisplej/localweb/clock.html`

---

## 3. Štruktúra Súborov

### Kompletný prehľad

```
/opt/edudisplej/
├── init/                           # Init skripty a moduly
│   ├── edudisplej-init.sh          # 🔧 Hlavný init skript
│   ├── xclient.sh                  # 🌐 X kliens wrapper (browser launcher)
│   ├── common.sh                   # 📚 Zdieľané funkcie, preklady
│   ├── services.sh                 # ⚙️  Systemd služby správa
│   ├── kiosk.sh                    # 🖥️  X server a kiosk setup
│   ├── registration.sh             # 📝 Registrácia zariadení
│   ├── watchdog.sh                 # 👁️  Browser watchdog monitor
│   ├── network.sh                  # 🌐 Sieťové funkcie (WiFi, IP)
│   ├── display.sh                  # 📺 Displej nastavenia
│   ├── language.sh                 # 🌍 Jazykové nastavenia
│   ├── download.php                # 📥 Init súborov downloader
│   ├── openbox-rc.xml              # 🪟  Openbox konfigurácia
│   └── clock.html                  # 🕐 Predvolená HTML stránka
│
├── localweb/                       # Lokálne web súbory
│   └── clock.html                  # 🕐 Lokálna kópia hodín (fallback)
│
├── chromium-profile/               # Chromium profil a cache
│   ├── Default/                    # Predvolený profil
│   ├── SingletonLock               # Lock súbor
│   └── ...                         # Ďalšie cache súbory
│
├── edudisplej.conf                 # ⚙️  Hlavný konfiguračný súbor
├── .mode                           # 💾 Uložený prevádzkový režim
├── .registration.json              # 📋 Registračné údaje zariadenia
│
├── xclient.log                     # 📄 X kliens logy
├── session.log                     # 📄 Session logy (init)
├── watchdog.log                    # 📄 Watchdog logy
├── apt.log                         # 📄 APT inštalačné logy
└── update.log                      # 📄 Update logy

/etc/systemd/system/
└── chromiumkiosk.service           # 🔧 Systemd služba definícia

/tmp/
└── edudisplej-runtime/             # 🗂️  Runtime súbory (XDG_RUNTIME_DIR)
    ├── edudisplej-kiosk.desktop    # (deprecated, už sa nepoužíva)
    └── ...                         # Dočasné súbory
```

### Dôležité konfiguračné súbory

#### `/opt/edudisplej/edudisplej.conf`

```bash
# Hlavná konfigurácia EduDisplej

# Kiosk URL - adresa stránky na zobrazenie
KIOSK_URL="file:///opt/edudisplej/localweb/clock.html"

# Jazyk rozhrania (sk/en)
LANG="sk"

# Rozlíšenie displeja (voliteľné)
# RESOLUTION="1920x1080"

# Orientácia displeja (voliteľné)
# ROTATION="normal"  # normal, left, right, inverted

# Hostname zariadenia (voliteľné)
# HOSTNAME="edudisplej-001"

# Časová zóna (voliteľné)
# TIMEZONE="Europe/Bratislava"
```

#### `/opt/edudisplej/.mode`

```
standalone
```
alebo
```
eduserver
```

Určuje prevádzkový režim:
- **standalone** - samostatný režim, zobrazuje lokálny obsah
- **eduserver** - pripojené k EduServer, zobrazuje vzdialený obsah

#### `/opt/edudisplej/.registration.json`

```json
{
  "device_id": "abc123def456",
  "mac_address": "b8:27:eb:xx:xx:xx",
  "hostname": "edudisplej-rpi4",
  "registered_at": "2026-01-15T10:30:00Z",
  "server_url": "https://server.edudisplej.sk"
}
```

### Log súbory

| Súbor | Účel | Max veľkosť | Rotácia |
|-------|------|-------------|---------|
| `xclient.log` | X kliens a browser logy | 2MB | Automatická |
| `session.log` | Init skript logy | - | Pri reštarte |
| `watchdog.log` | Watchdog monitor logy | 2MB | Automatická |
| `apt.log` | Inštalácia balíkov | 2MB | Pri boote |
| `update.log` | Auto-update logy | 2MB | Pri update |

---

## 4. Proces Bootovanía

### Detailný boot flow

```
┌─────────────────────────────────────────────────────────┐
│ 1. Raspberry Pi Boot                                    │
│    - Bootloader (GPU firmware)                          │
│    - Kernel load (Linux)                                │
│    - Initramfs                                          │
└────────────────┬────────────────────────────────────────┘
                 ▼
┌─────────────────────────────────────────────────────────┐
│ 2. Systemd Init                                         │
│    - Mount filesystems                                  │
│    - Start essential services                           │
│    - network-online.target                              │
└────────────────┬────────────────────────────────────────┘
                 ▼
┌─────────────────────────────────────────────────────────┐
│ 3. chromiumkiosk.service Aktivácia                      │
│    After: network-online.target                         │
│    Wants: network-online.target                         │
└────────────────┬────────────────────────────────────────┘
                 ▼
┌─────────────────────────────────────────────────────────┐
│ 4. Xinit Spustenie                                      │
│    /usr/bin/xinit /opt/edudisplej/init/xclient.sh      │
│                   -- :0 vt1 -nolisten tcp               │
│    - Spustí X server na :0                              │
│    - Spustí xclient.sh ako X klienta                    │
└────────────────┬────────────────────────────────────────┘
                 ▼
┌─────────────────────────────────────────────────────────┐
│ 5. xclient.sh Inicializácia                             │
│    a) Načítanie konfigurácie                            │
│       - /opt/edudisplej/edudisplej.conf                 │
│       - KIOSK_URL, LANG, atď.                           │
│    b) Príprava runtime prostredia                       │
│       - XDG_RUNTIME_DIR=/tmp/edudisplej-runtime         │
│       - Chromium profile dir                            │
│    c) Detekcia prehliadača                              │
│       - Kontrola NEON podpory (ARM)                     │
│       - Výber Chromium alebo Epiphany                   │
│    d) X prostredie setup                                │
│       - Spustenie Openbox                               │
│       - Spustenie Unclutter                             │
│       - Xset nastavenia (screensaver off)               │
└────────────────┬────────────────────────────────────────┘
                 ▼
┌─────────────────────────────────────────────────────────┐
│ 6. Openbox Spustenie                                    │
│    - Načíta ~/.config/openbox/rc.xml                    │
│    - Nastaví borderless okná                            │
│    - Maximalizuje aplikácie                             │
└────────────────┬────────────────────────────────────────┘
                 ▼
┌─────────────────────────────────────────────────────────┐
│ 7. Browser Spustenie                                    │
│    ┌─────────────────────┐  ┌───────────────────────┐  │
│    │ Chromium            │  │ Epiphany              │  │
│    │ + kiosk flags       │  │ bez flags             │  │
│    │ + optimalizácie     │  │ lightweight           │  │
│    └─────────────────────┘  └───────────────────────┘  │
│    - Načíta KIOSK_URL                                   │
│    - Fullscreen mód                                     │
└────────────────┬────────────────────────────────────────┘
                 ▼
┌─────────────────────────────────────────────────────────┐
│ 8. Keep-Alive Loop                                      │
│    while true; do                                       │
│      start_browser                                      │
│      wait_for_exit                                      │
│      sleep 15                                           │
│    done                                                 │
└────────────────┬────────────────────────────────────────┘
                 ▼
┌─────────────────────────────────────────────────────────┐
│ 9. Watchdog Monitor (paralelne)                        │
│    - Každých 60s kontrola                               │
│    - Ak browser crashed → restart                       │
│    - Rate limiting (3 reštarty/60s)                     │
└─────────────────────────────────────────────────────────┘
```

### Krok za krokom popis

#### **Krok 1: Raspberry Pi Boot**
- GPU firmware načíta `config.txt` a `cmdline.txt`
- Spustí Linux kernel
- Inicializuje hardvér (CPU, RAM, periferie)

#### **Krok 2: Systemd Init**
- Pripojí filesystémy (`/`, `/boot`, atď.)
- Spustí základné služby (udev, dbus, network)
- Čaká na `network-online.target`

#### **Krok 3: chromiumkiosk.service Aktivácia**
```bash
systemctl start chromiumkiosk.service
```
- Systemd načíta `/etc/systemd/system/chromiumkiosk.service`
- Overí závislosti (`After=network-online.target`)
- Spustí `ExecStart` príkaz

#### **Krok 4: Xinit Spustenie**
```bash
xinit /opt/edudisplej/init/xclient.sh -- :0 vt1 -nolisten tcp
```
- Spustí X.Org server na display `:0`
- Použije virtuálny terminál `vt1`
- Vypne TCP listening (bezpečnosť)
- Spustí `xclient.sh` ako X klienta

#### **Krok 5: xclient.sh Inicializácia**

**5a. Načítanie konfigurácie:**
```bash
source /opt/edudisplej/edudisplej.conf
KIOSK_URL="${KIOSK_URL:-file:///opt/edudisplej/localweb/clock.html}"
```

**5b. Príprava runtime prostredia:**
```bash
export XDG_RUNTIME_DIR="/tmp/edudisplej-runtime"
mkdir -p "$XDG_RUNTIME_DIR"
mkdir -p "/opt/edudisplej/chromium-profile"
```

**5c. Detekcia prehliadača:**
```bash
detect_browser()
  ├─ Kontrola NEON (ARM): grep -qi 'neon' /proc/cpuinfo
  ├─ Priorita: epiphany (bez NEON) alebo chromium (s NEON)
  └─ Export: BROWSER_BIN="/usr/bin/chromium-browser"
```

**5d. X prostredie setup:**
```bash
setup_x_env()
  ├─ xset s off              # Vypnutie screensaveru
  ├─ xset -dpms              # Vypnutie energy saving
  ├─ xsetroot -solid white   # Biele pozadie
  ├─ unclutter -idle 0.5 &   # Skrytie kurzora
  └─ openbox &               # Spustenie window managera
```

#### **Krok 6: Openbox Spustenie**
```bash
openbox &
```
- Načíta `~/.config/openbox/rc.xml`
- Aplikuje nastavenia:
  - Borderless okná pre chromium/epiphany
  - Maximalizácia na fullscreen
  - 1 desktop bez animácií

#### **Krok 7: Browser Spustenie**

**Chromium:**
```bash
chromium-browser \
  --kiosk \
  --no-sandbox \
  --disable-gpu \
  ... \
  file:///opt/edudisplej/localweb/clock.html &
```

**Epiphany:**
```bash
epiphany-browser file:///opt/edudisplej/localweb/clock.html &
```

#### **Krok 8: Keep-Alive Loop**
```bash
while true; do
    start_chromium()
    wait $BROWSER_PID
    echo "Browser exited, restarting in 15s..."
    sleep 15
done
```

#### **Krok 9: Watchdog Monitor**
```bash
# Paralelný proces
while true; do
    if ! is_chromium_running; then
        restart_browser
    fi
    sleep 60
done
```

### Časová os typického bootu

```
00:00  Raspberry Pi zapnutie
00:05  Bootloader + Kernel load
00:10  Systemd init
00:15  Network services
00:20  chromiumkiosk.service start
00:22  Xinit spustenie
00:24  xclient.sh init
00:25  Openbox start
00:27  Browser detekcia
00:30  Browser spustenie
00:35  Zobrazenie KIOSK_URL ✅
```

**Celkový čas:** ~30-40 sekúnd (závisí od rýchlosti SD karty a siete)

---

## 5. Konfigurácia

### Prístup ku konfiguračnému menu

**Pri boote:**
1. Pozoruj konzolu alebo displej
2. Keď sa zobrazí **"Stlačte F12 pre vstup do konfigurácie (10 sekúnd)"**
3. Stlač **F12** alebo **M** kláves
4. Zobrazí sa konfiguračné menu

**Alternatívne:**
```bash
# SSH prístup
ssh pi@<ip-adresa>
sudo su - edudisplej
cd /opt/edudisplej/init
./edudisplej-init.sh
```

### Menu možnosti

#### **1. EduServer Režim**
- Pripojí zariadenie k centrálnemu EduServer
- Požiada o registračný kód
- Zobrazuje vzdialený obsah zo servera

#### **2. Samostatný Režim (Standalone)**
- Zobrazuje lokálny obsah
- Predvolene: `file:///opt/edudisplej/localweb/clock.html`
- Možnosť zmeniť URL v `edudisplej.conf`

#### **3. Jazyk**
```
[1] Slovenčina (SK)
[2] Angličtina (EN)
```
- Zmení jazyk rozhrania
- Uloží do `LANG` premennej

#### **4. Nastavenia Displeja**
```
- Rozlíšenie obrazovky
  [1] 1920x1080 (Full HD)
  [2] 1280x720 (HD)
  [3] 1024x768 (XGA)
  [4] Vlastné

- Orientácia
  [1] Normal
  [2] Left (90°)
  [3] Right (270°)
  [4] Inverted (180°)
```

#### **5. Nastavenia Siete**
```
[1] WiFi Konfigurácia
    - SSID
    - Heslo
    - WPA2 šifrovanie

[2] Statická IP
    - IP adresa
    - Gateway
    - DNS server

[3] Zobraz aktuálne nastavenia
    - IP adresa
    - MAC adresa
    - Gateway
    - SSID (ak WiFi)
```

#### **6. Ukončiť**
- Uloží zmeny
- Reštartuje službu
- Zobrazí kiosk mód

### Editácia konfigurácie manuálne

```bash
sudo nano /opt/edudisplej/edudisplej.conf
```

**Dostupné možnosti:**

```bash
# === ZÁKLADNÉ NASTAVENIA ===

# URL stránky na zobrazenie
KIOSK_URL="https://example.com/dashboard"

# Jazyk rozhrania (sk/en)
LANG="sk"

# === DISPLEJ ===

# Rozlíšenie (voliteľné, deteguje automaticky)
RESOLUTION="1920x1080"

# Orientácia (normal/left/right/inverted)
ROTATION="normal"

# === SIEŤ ===

# Hostname zariadenia
HOSTNAME="edudisplej-sala-01"

# === SYSTÉM ===

# Časová zóna
TIMEZONE="Europe/Bratislava"

# Auto-update zapnuté (true/false)
AUTO_UPDATE="true"

# === PREHLIADAČ ===

# Vynútený prehliadač (chromium-browser/epiphany-browser)
# BROWSER_BIN="/usr/bin/chromium-browser"

# === POKROČILÉ ===

# Debug režim (zobrazí viac logov)
DEBUG="false"

# Maximálna veľkosť logu (v bajtoch)
MAX_LOG_SIZE=2097152
```

**Po zmene reštartuj službu:**
```bash
sudo systemctl restart chromiumkiosk.service
```

### Zmena KIOSK_URL

**Metóda 1: Cez konfiguračný súbor**
```bash
sudo nano /opt/edudisplej/edudisplej.conf
```
Zmeň riadok:
```bash
KIOSK_URL="https://tvoja-stranka.sk/displej"
```

**Metóda 2: Priamo z príkazového riadku**
```bash
sudo sed -i 's|KIOSK_URL=.*|KIOSK_URL="https://nova-url.sk"|' /opt/edudisplej/edudisplej.conf
sudo systemctl restart chromiumkiosk.service
```

---

## 6. Riešenie Problémov

### Časté problémy a riešenia

#### **Problém 1: X server sa nespustí**

**Symptómy:**
- Čierna obrazovka
- Log: `X connection test failed`

**Riešenie:**
```bash
# 1. Kontrola X server procesov
ps aux | grep Xorg

# 2. Vyčistenie X lock súborov
sudo rm -f /tmp/.X0-lock
sudo rm -rf /tmp/.X11-unix/X0

# 3. Reštart služby
sudo systemctl restart chromiumkiosk.service

# 4. Kontrola xinit inštalácie
sudo apt-get install --reinstall xinit xserver-xorg x11-utils
```

#### **Problém 2: Browser crash loop**

**Symptómy:**
- Browser sa spustí a hneď spadne
- Opakované reštarty
- Log: `Browser exited with code 1`

**Riešenie:**
```bash
# 1. Kontrola logov
tail -f /opt/edudisplej/xclient.log

# 2. Vyčistenie browser profilu
sudo rm -rf /opt/edudisplej/chromium-profile/*

# 3. Kontrola KIOSK_URL
cat /opt/edudisplej/edudisplej.conf | grep KIOSK_URL

# 4. Test browser manuálne
export DISPLAY=:0
chromium-browser --version
chromium-browser --kiosk file:///opt/edudisplej/localweb/clock.html

# 5. Skúsiť alternatívny browser
sudo apt-get install epiphany-browser
```

#### **Problém 3: Žiadne internetové pripojenie**

**Symptómy:**
- Nemôže načítať vzdialené URL
- Zobrazuje iba lokálny clock.html

**Riešenie:**
```bash
# 1. Kontrola sieťového pripojenia
ping -c 4 8.8.8.8
ping -c 4 google.com

# 2. Kontrola sieťových rozhraní
ip addr show
ip route show

# 3. Reštart sieťovej služby
sudo systemctl restart NetworkManager
# alebo
sudo systemctl restart dhcpcd

# 4. WiFi konfigurácia
sudo nmtui  # Network Manager Text UI

# 5. Kontrola DNS
cat /etc/resolv.conf
```

#### **Problém 4: Nesprávne rozlíšenie displeja**

**Symptómy:**
- Rozmazaný obraz
- Čierne okraje
- Nesprávny pomer strán

**Riešenie:**
```bash
# 1. Zobraz aktuálne rozlíšenie
DISPLAY=:0 xrandr

# 2. Nastav rozlíšenie
DISPLAY=:0 xrandr --output HDMI-1 --mode 1920x1080

# 3. Raspberry Pi config.txt (natrvalo)
sudo nano /boot/config.txt

# Pridaj:
hdmi_force_hotplug=1
hdmi_group=2
hdmi_mode=82  # 1920x1080 @ 60Hz

# 4. Reštart
sudo reboot
```

#### **Problém 5: Browser zobrazuje chybovú stránku**

**Symptómy:**
- "Unable to connect"
- "Page not found"

**Riešenie:**
```bash
# 1. Kontrola KIOSK_URL syntaxe
cat /opt/edudisplej/edudisplej.conf

# Správne formáty:
# file:///opt/edudisplej/localweb/clock.html
# http://example.com
# https://example.com/path

# 2. Kontrola existencie lokálneho súboru
ls -la /opt/edudisplej/localweb/clock.html

# 3. Test URL z príkazového riadku
curl -I https://tvoja-url.sk

# 4. Dočasný test s funkčnou URL
sudo nano /opt/edudisplej/edudisplej.conf
# Zmeň na: KIOSK_URL="https://www.google.com"
sudo systemctl restart chromiumkiosk.service
```

### Diagnostické príkazy

#### **Kontrola stavu služby**
```bash
# Stav chromiumkiosk.service
sudo systemctl status chromiumkiosk.service

# Celý log
sudo journalctl -u chromiumkiosk.service

# Real-time log
sudo journalctl -u chromiumkiosk.service -f
```

#### **Kontrola procesov**
```bash
# X server
ps aux | grep Xorg

# Openbox
ps aux | grep openbox

# Browser
ps aux | grep chromium
ps aux | grep epiphany

# Watchdog
ps aux | grep watchdog
```

#### **Kontrola logov**
```bash
# Všetky EduDisplej logy
ls -lh /opt/edudisplej/*.log

# Posledných 50 riadkov
tail -50 /opt/edudisplej/xclient.log
tail -50 /opt/edudisplej/session.log
tail -50 /opt/edudisplej/watchdog.log

# Real-time sledovanie
tail -f /opt/edudisplej/xclient.log
```

#### **Kontrola X displeja**
```bash
# Nastavenie DISPLAY premennej
export DISPLAY=:0

# Test X pripojenia
xset q

# Zoznam okien
xwininfo -root -tree

# Screenshot (debugging)
xwd -root | convert xwd:- /tmp/screenshot.png
```

#### **Kontrola systémových zdrojov**
```bash
# CPU a RAM
top
htop

# Disk space
df -h

# Teplota (Raspberry Pi)
vcgencmd measure_temp

# Napätie (Raspberry Pi)
vcgencmd measure_volts
```

### Obnova systému

#### **Mäkký reset (soft reset)**
```bash
# Reštart služby
sudo systemctl restart chromiumkiosk.service
```

#### **Stredne tvrdý reset**
```bash
# Vyčistenie cache a profilu
sudo rm -rf /opt/edudisplej/chromium-profile/*
sudo rm -rf /tmp/edudisplej-runtime/*

# Reštart služby
sudo systemctl restart chromiumkiosk.service
```

#### **Tvrdý reset**
```bash
# Zálohuj konfiguráciu
sudo cp /opt/edudisplej/edudisplej.conf /tmp/edudisplej.conf.backup

# Reinštalácia
curl https://install.edudisplej.sk/install.sh | sed 's/\r$//' | sudo bash

# Obnov konfiguráciu
sudo cp /tmp/edudisplej.conf.backup /opt/edudisplej/edudisplej.conf
sudo systemctl restart chromiumkiosk.service
```

#### **Factory reset (úplný reset)**
```bash
# POZOR: Vymaže všetky dáta!

# Zálohuj dôležité súbory
sudo cp /opt/edudisplej/edudisplej.conf /home/pi/

# Odstránenie
sudo systemctl stop chromiumkiosk.service
sudo systemctl disable chromiumkiosk.service
sudo rm /etc/systemd/system/chromiumkiosk.service
sudo rm -rf /opt/edudisplej

# Reinštalácia
curl https://install.edudisplej.sk/install.sh | sed 's/\r$//' | sudo bash
```

### Získanie podpory

Ak problémy pretrvávajú, zhromaždite diagnostické informácie:

```bash
# Vytvor diagnostický report
cat > /tmp/edudisplej-report.txt <<EOF
=== EduDisplej Diagnostický Report ===
Dátum: $(date)

=== Systémové Informácie ===
$(uname -a)
$(cat /etc/os-release)

=== Verzia EduDisplej ===
$(grep CURRENT_VERSION /opt/edudisplej/init/edudisplej-init.sh)

=== Stav Služby ===
$(systemctl status chromiumkiosk.service)

=== Procesy ===
$(ps aux | grep -E "Xorg|openbox|chromium|epiphany")

=== Logy (posledných 50 riadkov) ===
--- xclient.log ---
$(tail -50 /opt/edudisplej/xclient.log 2>/dev/null)

--- session.log ---
$(tail -50 /opt/edudisplej/session.log 2>/dev/null)

=== Konfigurácia ===
$(cat /opt/edudisplej/edudisplej.conf 2>/dev/null)

=== Sieť ===
$(ip addr show)
$(ip route show)

EOF

echo "Report vytvorený: /tmp/edudisplej-report.txt"
```

Pošlite tento report na support email alebo GitHub Issues.

---

## 7. Informácie pre Vývojárov

### Auto-Update Mechanizmus

EduDisplej má vstavaný systém automatických aktualizácií.

#### **Ako funguje:**

1. **Kontrola verzií pri boote**
   ```bash
   CURRENT_VERSION="20260107-1"
   REMOTE_VERSION=$(curl -s https://install.edudisplej.sk/init/version.txt)
   ```

2. **Porovnanie verzií**
   ```bash
   if [[ "$REMOTE_VERSION" > "$CURRENT_VERSION" ]]; then
       echo "Nová verzia dostupná: $REMOTE_VERSION"
       perform_update
   fi
   ```

3. **Stiahnutie aktualizovaných súborov**
   ```bash
   # Zoznam súborov na update
   curl -s "${INIT_BASE}/download.php?getfiles"
   
   # Stiahnutie každého súboru
   curl -sL "${INIT_BASE}/download.php?streamfile=${NAME}"
   ```

4. **Aplikovanie aktualizácie**
   - Záloha starých súborov → `.bak`
   - Prepísanie novými súbormi
   - Oprava line endings (CRLF → LF)
   - Reštart skriptu

5. **Log aktualizácie**
   ```bash
   /opt/edudisplej/update.log
   ```

#### **Vypnutie auto-update:**
```bash
# V edudisplej-init.sh zakomentuj:
# perform_version_check_and_update
```

### Verzovanie

**Formát verzie:** `RRRRMMDD-P`

- `RRRR` - Rok (2026)
- `MM` - Mesiac (01)
- `DD` - Deň (07)
- `P` - Patch číslo (1, 2, 3...)

**Príklad:** `20260107-1` = 7. január 2026, patch 1

**Porovnanie verzií:**
```bash
# Bash lexikografické porovnanie
if [[ "20260107-2" > "20260107-1" ]]; then
    echo "Novšia verzia"
fi
```

### Log Rotácia

Všetky log súbory majú implementovanú rotáciu pre predchádzanie zaplneniu disku.

**Implementácia:**

```bash
MAX_LOG_SIZE=2097152  # 2MB

rotate_log_if_needed() {
    local log_file="$1"
    
    if [[ -f "$log_file" ]]; then
        local size=$(stat -c%s "$log_file" 2>/dev/null || echo 0)
        
        if [[ $size -gt $MAX_LOG_SIZE ]]; then
            # Posun starých logov
            mv "$log_file" "${log_file}.old"
            
            # Vytvor nový log
            touch "$log_file"
            
            echo "[$(date)] Log rotated" >> "$log_file"
        fi
    fi
}
```

**Stratégie rotácie:**

| Log | Stratégia |
|-----|-----------|
| `xclient.log` | Posun do `.old` pri prekročení 2MB |
| `session.log` | Posun do `.old` pri každom boote |
| `watchdog.log` | Orezanie na posledných 500 riadkov |

### Debugging Tipy

#### **Zapnutie debug módu**

```bash
# V xclient.sh
set -x  # Zobrazí každý príkaz pred vykonaním

# V edudisplej.conf
DEBUG="true"
```

#### **Verbose logging**

```bash
# Redirect všetkého do logu
exec > >(tee -a /opt/edudisplej/debug.log) 2>&1
```

#### **Test browser bez služby**

```bash
# Zastav službu
sudo systemctl stop chromiumkiosk.service

# Spusti manuálne
export DISPLAY=:0
export XAUTHORITY=/home/edudisplej/.Xauthority
cd /opt/edudisplej/init
./xclient.sh
```

#### **Sledovanie systémových volání**

```bash
# Strace na X server
strace -f -o /tmp/Xorg.trace Xorg :0 vt1

# Strace na browser
strace -f -o /tmp/chromium.trace chromium-browser --kiosk ...
```

#### **Profiling výkonu**

```bash
# CPU usage
top -p $(pgrep chromium)

# Memory usage
pmap $(pgrep chromium)

# IO usage
iotop -p $(pgrep chromium)
```

### Pridanie Vlastných Modulov

Systém je modulárny - môžeš pridať vlastné moduly.

**Príklad: custom.sh**

```bash
#!/bin/bash
# custom.sh - Vlastný modul

# Source common functions
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/common.sh"

# Tvoje funkcie
my_custom_function() {
    print_info "Vlastná funkcia beží!"
    # Tvoj kód
}

# Export funkcií
export -f my_custom_function
```

**Načítanie v edudisplej-init.sh:**

```bash
if [[ -f "${INIT_DIR}/custom.sh" ]]; then
    source "${INIT_DIR}/custom.sh"
    print_success "custom.sh loaded"
fi
```

### Testovanie

#### **Manuálny test celého systému**

```bash
# 1. Reinštalácia
curl https://install.edudisplej.sk/install.sh | sed 's/\r$//' | sudo bash

# 2. Kontrola služby
sudo systemctl status chromiumkiosk.service

# 3. Kontrola logov
tail -f /opt/edudisplej/xclient.log

# 4. Test browser
# Počkaj 30s na boot a skontroluj displej
```

#### **Unit testy (budúce)**

```bash
# tests/test_common.sh
source ../webserver/install/init/common.sh

test_translation() {
    local result=$(t "boot_starting")
    [[ "$result" == "Spustanie EduDisplej systemu..." ]] && echo "PASS" || echo "FAIL"
}

test_translation
```

### Kontribúcia

Chceš prispieť do projektu?

1. **Fork repository**
2. **Vytvor feature branch**
   ```bash
   git checkout -b feature/nova-funkcionalita
   ```
3. **Commit zmeny**
   ```bash
   git commit -am 'Pridaná nová funkcionalita'
   ```
4. **Push do branch**
   ```bash
   git push origin feature/nova-funkcionalita
   ```
5. **Vytvor Pull Request**

### Známe Limitácie

- **Raspberry Pi Zero/1:** Pomalý výkon, odporúčaný Epiphany browser
- **4K rozlíšenie:** Možné problémy s výkonom na starších Pi modeloch
- **HTTPS certifikáty:** Staršie Pi môžu mať problémy s niektorými SSL certifikátmi
- **Video playback:** Obmedzený hardvérový dekoding na starších modeloch

### Roadmap

- [ ] Web-based konfiguračné rozhranie
- [ ] Podpora pre viacero displejov
- [ ] Scheduling (časové zobrazovanie rôzneho obsahu)
- [ ] Screenshot API pre vzdialenú diagnostiku
- [ ] Vylepšený monitoring a alerting
- [ ] Containerizácia (Docker)

---

## Záver

EduDisplej je komplexný systém s 8 vrstvami, každá so špecifickou úlohou. Tento dokument poskytuje detailný pohľad na architektúru, konfiguráciu a riešenie problémov.

**Kľúčové body:**
- ✅ Viacvrstvová architektúra pre izaláciu zodpovedností
- ✅ Robustné error handling a automatické reštarty
- ✅ Podpora pre viacero prehliadačov (Chromium, Epiphany)
- ✅ Automatické aktualizácie
- ✅ Jednoduché F12 konfiguračné menu
- ✅ Kompletné logovanie a diagnostika

**Pre ďalšie informácie:**
- 📧 Email: support@edudisplej.sk
- 🌐 Web: https://edudisplej.sk
- 📦 GitHub: https://github.com/nagy-andras-sk/edudisplej
- 📚 Dokumentácia: https://install.edudisplej.sk

---

**Dokument vytvorený:** 2026-01-18  
**Verzia dokumentu:** 1.0  
**Verzia EduDisplej:** 20260107-1

*Tento dokument je súčasťou projektu EduDisplej a je udržiavaný komunitou vývojárov.*
