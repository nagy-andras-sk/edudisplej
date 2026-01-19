# EduDisplej - Digitálny Informačný Systém

EduDisplej je riešenie digitálneho informačného systému pre Raspberry Pi a Debian-based zariadenia, ktoré beží v kiosk móde s automatickou inštaláciou.

## Rýchla Inštalácia

Nainštalujte EduDisplej jedným príkazom:

```bash
curl https://install.edudisplej.sk/install.sh | sed 's/\r$//' | sudo bash
```

Systém automaticky:
1. Deteguje architektúru zariadenia (ARMv6 / ARMv7+ / x86_64)
2. Stiahne potrebné súbory z install.edudisplej.sk
3. Nakonfiguruje kiosk mód (Epiphany pre ARMv6, Chromium pre ostatné)
4. Nastaví služby a automatický štart
5. Reštartuje systém

## Ako Funguje Inštalácia - Vrstva po Vrstve

### 1. Install.sh - Prvotná Inštalácia

**Čo robí install.sh:**
- Overí root oprávnenia
- Deteguje architektúru (armv6l → Epiphany, ostatné → Chromium)
- Nainštaluje curl (ak chýba)
- Vytvorí adresár `/opt/edudisplej/init/`
- Stiahne zoznam súborov z `install.edudisplej.sk/init/download.php?getfiles`
- Stiahne všetky súbory (.sh skripty, .service súbory, .html šablóny)
- Opraví CRLF → LF v súboroch
- Pridá shebang do .sh súborov (ak chýba)
- Uloží nastavenia: kiosk mód, užívateľ, domovský adresár
- Nainštaluje a aktivuje `edudisplej-init.service`
- Reštartuje systém

**Stiahnuté súbory:**
- `edudisplej-init.sh` - hlavný inicializačný skript
- `common.sh` - zdieľané funkcie, preklady (SK/EN)
- `kiosk.sh` - kiosk mód funkcie
- `network.sh` - nastavenia siete
- `display.sh` - nastavenia displeja
- `language.sh` - výber jazyka
- `registration.sh` - registrácia zariadenia
- `*.service` - systemd služby
- `clock.html` - lokálna fallback stránka

### 2. Edudisplej-Init.sh - Inicializácia pri Štarte

**Čo robí init skript pri každom štarte:**

1. **Načíta moduly** - common.sh, kiosk.sh, network.sh, display.sh, language.sh
2. **Zobrazí banner** - ASCII "EDUDISPLEJ" logo
3. **Overí internet** - 30 pokusov, 2s interval
4. **Registruje zariadenie** - odošle info na server (len prvýkrát)
5. **Prečíta nastavenia** - kiosk mód, užívateľ, jazyk
6. **Skontroluje balíčky** - zistí chýbajúce balíčky
7. **Nainštaluje chýbajúce** - apt-get update + install s progress barom
8. **Nainštaluje browser** - Chromium alebo Epiphany podľa módu
9. **Nakonfiguruje kiosk** - autologin, .profile, .xinitrc, kiosk-launcher.sh
10. **Skontroluje aktualizácie** - porovná verziu, stiahne novú ak existuje
11. **Zobrazí zhrnutie** - hostname, IP, MAC, kiosk URL, rozlíšenie
12. **Štart kiosk módu** - spustí X server + browser

**Čo sa inštaluje:**

Základné balíčky (vždy):
- `openbox` - window manager
- `xinit` - X server štart
- `unclutter` - skrytie kurzora
- `curl` - HTTP requesty
- `x11-utils` - X nástroje
- `xserver-xorg` - X server
- `xterm` - terminál s bannerom
- `xdotool` - automatizácia klávesnice
- `figlet` - ASCII banner
- `dbus-x11` - D-Bus pre X

Browser (podľa módu):
- **ARMv6:** `epiphany-browser`
- **Ostatné:** `chromium-browser`

### 3. Konfigurácia Kiosk Módu

**Vytvorené súbory:**

1. **Autologin na tty1:**
   - `/etc/systemd/system/getty@tty1.service.d/autologin.conf`
   - Automatické prihlásenie užívateľa na tty1

2. **Auto-štart X servera:**
   - `~/.profile` - spustí X server na tty1
   - `~/.xinitrc` - spustí Openbox session

3. **Openbox konfigurácia:**
   - `~/.config/openbox/autostart` - vypne DPMS, spustí xterm s launcher skriptom

4. **Kiosk Launcher:**
   - `~/kiosk-launcher.sh` - zobrazí EDUDISPLEJ banner, odpočítavanie, spustí browser

5. **Utility:**
   - `~/.bashrc` - xrestart() funkcia

**Ako to funguje po reštarte:**
```
Boot → Autologin na tty1 → .profile spustí X → .xinitrc spustí Openbox →
Openbox autostart spustí xterm → xterm spustí kiosk-launcher.sh →
Banner + Odpočítavanie 5s → Browser v fullscreen → Watchdog hlídá browser
```

## Konfigurácia URL

Zmeňte URL v kiosk-launcher.sh:

```bash
sudo -u pi nano ~/kiosk-launcher.sh
# Zmeňte: URL="${1:-https://www.time.is}"
# Na:     URL="${1:-https://vasa-url.com}"
```

## Podporované Zariadenia

**ARMv6 (Epiphany browser):**
- Raspberry Pi 1 (všetky modely)
- Raspberry Pi Zero 1 (NIE Zero 2)

**ARMv7+ / x86_64 (Chromium browser):**
- Raspberry Pi 2, 3, 4, 5
- Raspberry Pi Zero 2 W
- Akékoľvek Debian/Ubuntu zariadenie

## Manuálne Ovládanie

```bash
# Reštart X servera
xrestart

# Kontrola bežiacich procesov
ps aux | grep -E "Xorg|openbox|chromium|epiphany|xterm"

# Testovanie bez reštartu
sudo -u pi DISPLAY=:0 XDG_VTNR=1 startx -- :0 vt1
```

## Log Súbory

- `/opt/edudisplej/session.log` - hlavný log init skriptu
- `/opt/edudisplej/apt.log` - inštalácia balíčkov
- `/opt/edudisplej/kiosk.log` - kiosk mód log
- `/var/log/Xorg.0.log` - X server chyby

## Kľúčové Vlastnosti

- **Automatická detekcia architektúry** - správny browser pre každé zariadenie
- **Automatické zotavenie z chýb** - retry logika s exponenciálnym backoff
- **Jasné chybové hlášky** - [súbor:riadok] v každej chybe
- **Ochrana pred loop** - limit reštartov predchádza nekonečným cyklom
- **URL fallback** - automatický fallback na funkčné URL
- **Detailné logovanie** - všetky akce s časovými značkami
- **Bezobslužná prevádzka** - navrhnuté pre spoľahlivý beh bez manuálneho zásahu

Viac info: [ERROR_HANDLING.md](ERROR_HANDLING.md)

## Verzia

**EDUDISPLEJ INSTALLER v. 28 12 2025**

