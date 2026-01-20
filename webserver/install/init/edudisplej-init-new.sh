#!/bin/bash
# edudisplej-init.sh - EgyszerÅ±sített inicializálás -- Zjednodušená inicializácia
# =============================================================================
# Ez a szkript ellenőrzi a rendszert és szükség esetén telepíti a hiányzó komponenseket
# Tento skript kontroluje systém a v prípade potreby nainštaluje chýbajúce komponenty
# =============================================================================

set -euo pipefail

# Alapbeállítások -- Základné nastavenia
EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
CONFIG_FILE="${EDUDISPLEJ_HOME}/edudisplej.conf"
MODE_FILE="${EDUDISPLEJ_HOME}/.mode"
SESSION_LOG="${EDUDISPLEJ_HOME}/session.log"
APT_LOG="${EDUDISPLEJ_HOME}/apt.log"

# Export környezeti változók -- Export premenných prostredia
export DISPLAY=:0
export HOME="${EDUDISPLEJ_HOME}"
export USER="edudisplej"

# Log fájl tisztítás -- Čistenie log súboru
if [[ -f "$SESSION_LOG" ]]; then
    mv "$SESSION_LOG" "${SESSION_LOG}.old" 2>/dev/null || true
fi

# Kimenet átirányítás log fájlba -- Presmerovanie výstupu do log súboru
exec > >(tee -a "$SESSION_LOG") 2>&1

# =============================================================================
# Modulok betöltése -- Načítanie modulov
# =============================================================================

echo "==========================================="
echo "      E D U D I S P L E J"
echo "==========================================="
echo ""
echo "Modulok betöltése... / Načítavam moduly..."
echo ""

# Közös függvények -- Spoločné funkcie
if [[ -f "${INIT_DIR}/common.sh" ]]; then
    source "${INIT_DIR}/common.sh"
    print_success "✓ common.sh betöltve -- načítaný"
else
    echo "[HIBA/CHYBA] common.sh nem található!"
    exit 1
fi

# Hálózati függvények -- Sieťové funkcie
if [[ -f "${INIT_DIR}/network.sh" ]]; then
    source "${INIT_DIR}/network.sh"
    print_success "✓ network.sh betöltve -- načítaný"
fi

# Nyelvbeállítások -- Jazykové nastavenia
if [[ -f "${INIT_DIR}/language.sh" ]]; then
    source "${INIT_DIR}/language.sh"
    print_success "✓ language.sh betöltve -- načítaný"
fi

# Ellenőrző szkript -- Kontrolný skript
if [[ -f "${INIT_DIR}/edudisplej-checker.sh" ]]; then
    source "${INIT_DIR}/edudisplej-checker.sh"
    print_success "✓ edudisplej-checker.sh betöltve -- načítaný"
else
    print_warning "! edudisplej-checker.sh nem található -- nenájdený"
fi

# Telepítő szkript -- Inštalačný skript
if [[ -f "${INIT_DIR}/edudisplej-installer.sh" ]]; then
    source "${INIT_DIR}/edudisplej-installer.sh"
    print_success "✓ edudisplej-installer.sh betöltve -- načítaný"
else
    print_warning "! edudisplej-installer.sh nem található -- nenájdený"
fi

echo ""

# =============================================================================
# Banner megjelenítése -- Zobrazenie bannera
# =============================================================================

show_banner
print_info "Verzió -- Verzia: $(date +%Y%m%d)"

# =============================================================================
# Alapkönyvtár biztosítása -- Zabezpečenie základného adresára
# =============================================================================

if [[ ! -d "$EDUDISPLEJ_HOME" ]]; then
    mkdir -p "$EDUDISPLEJ_HOME" || {
        print_error "Nem sikerült létrehozni -- Nepodarilo sa vytvoriť: $EDUDISPLEJ_HOME"
        exit 1
    }
fi

mkdir -p "$INIT_DIR" "${EDUDISPLEJ_HOME}/localweb" 2>/dev/null || true
touch "$APT_LOG" 2>/dev/null || true

# =============================================================================
# Konfiguráció betöltése -- Načítanie konfigurácie
# =============================================================================

# Konfiguráció betöltése, ha létezik -- Načítanie konfigurácie, ak existuje
if [[ -f "$CONFIG_FILE" ]]; then
    source "$CONFIG_FILE" || true
fi

# Alapértelmezett értékek -- Predvolené hodnoty
KIOSK_URL="${KIOSK_URL:-https://www.time.is}"
DEFAULT_KIOSK_URL="https://www.time.is"

# =============================================================================
# Kiosk mód beállítások beolvasása -- Načítanie nastavení kiosk módu
# =============================================================================

# Kiosk mód olvasása telepítésből -- Načítanie kiosk módu z inštalácie
read_kiosk_preferences() {
    local kiosk_mode_file="${EDUDISPLEJ_HOME}/.kiosk_mode"
    local console_user_file="${EDUDISPLEJ_HOME}/.console_user"
    local user_home_file="${EDUDISPLEJ_HOME}/.user_home"
    
    # Kiosk mód -- Kiosk mód
    if [[ -f "$kiosk_mode_file" ]]; then
        KIOSK_MODE=$(cat "$kiosk_mode_file" | tr -d '\r\n')
    else
        local arch
        arch="$(uname -m)"
        if [[ "$arch" = "armv6l" ]]; then
            KIOSK_MODE="epiphany"
        else
            KIOSK_MODE="chromium"
        fi
    fi
    print_info "Kiosk mód -- Kiosk mód: $KIOSK_MODE"
    
    # Konzol felhasználó -- Konzolový používateľ
    if [[ -f "$console_user_file" ]]; then
        CONSOLE_USER=$(cat "$console_user_file" | tr -d '\r\n')
    else
        CONSOLE_USER="$(awk -F: '$3==1000{print $1}' /etc/passwd | head -n1 || true)"
        [[ -z "$CONSOLE_USER" ]] && CONSOLE_USER="pi"
    fi
    print_info "Felhasználó -- Používateľ: $CONSOLE_USER"
    
    # Felhasználó home könyvtár -- Domovský adresár používateľa
    if [[ -f "$user_home_file" ]]; then
        USER_HOME=$(cat "$user_home_file" | tr -d '\r\n')
    else
        USER_HOME="$(getent passwd "$CONSOLE_USER" | cut -d: -f6)"
    fi
    
    if [[ -z "$USER_HOME" ]]; then
        USER_HOME="/home/$CONSOLE_USER"
    fi
    print_info "Home könyvtár -- Domovský adresár: $USER_HOME"
    
    export KIOSK_MODE CONSOLE_USER USER_HOME
}

echo ""
read_kiosk_preferences
echo ""

# =============================================================================
# Internet kapcsolat ellenőrzése -- Kontrola internetového pripojenia
# =============================================================================

print_info "Internet ellenőrzése -- Kontrola internetu..."
wait_for_internet
INTERNET_AVAILABLE=$?

if [[ $INTERNET_AVAILABLE -eq 0 ]]; then
    print_success "✓ Internet elérhető -- Internet je dostupný"
else
    print_warning "✗ Nincs internet -- Žiadny internet"
fi
echo ""

# =============================================================================
# Rendszer ellenőrzése -- Kontrola systému
# =============================================================================

# Rendszer állapot ellenőrzése -- Kontrola stavu systému
if check_system_ready "$KIOSK_MODE" "$CONSOLE_USER" "$USER_HOME"; then
    print_success "=========================================="
    print_success "Rendszer kész! -- Systém je pripravený!"
    print_success "=========================================="
    echo ""
    print_info "X környezet indítása történik... -- Spúšťa sa X prostredie..."
    exit 0
fi

echo ""
print_info "=========================================="
print_info "Telepítés szükséges -- Je potrebná inštalácia"
print_info "=========================================="
echo ""

# =============================================================================
# Hiányzó komponensek telepítése -- Inštalácia chýbajúcich komponentov
# =============================================================================

# Alapcsomagok telepítése -- Inštalácia základných balíčkov
REQUIRED_PACKAGES=(openbox xinit unclutter curl x11-utils xserver-xorg)
print_info "1. Alapcsomagok telepítése -- Inštalácia základných balíčkov..."
if ! install_required_packages "${REQUIRED_PACKAGES[@]}"; then
    print_warning "Néhány alapcsomag telepítése sikertelen -- Niektoré základné balíčky sa nepodarilo nainštalovať"
fi
echo ""

# Kiosk csomagok telepítése -- Inštalácia kiosk balíčkov
print_info "2. Kiosk csomagok telepítése -- Inštalácia kiosk balíčkov..."
if ! install_kiosk_packages "$KIOSK_MODE"; then
    print_warning "Néhány kiosk csomag telepítése sikertelen -- Niektoré kiosk balíčky sa nepodarilo nainštalovať"
fi
echo ""

# Böngésző telepítése -- Inštalácia prehliadača
print_info "3. Böngésző telepítése -- Inštalácia prehliadača..."
if [[ "$KIOSK_MODE" = "epiphany" ]]; then
    BROWSER_NAME="epiphany-browser"
else
    BROWSER_NAME="chromium-browser"
fi

if ! install_browser "$BROWSER_NAME"; then
    print_warning "Böngésző telepítése sikertelen -- Inštalácia prehliadača zlyhala"
fi
echo ""

# =============================================================================
# Kiosk rendszer konfigurálása -- Konfigurácia kiosk systému
# =============================================================================

print_info "4. Kiosk rendszer konfigurálása -- Konfigurácia kiosk systému..."

KIOSK_CONFIGURED_FILE="${EDUDISPLEJ_HOME}/.kiosk_system_configured"

# Ha már konfigurálva, kilépés -- Ak už je nakonfigurované, ukončenie
if [[ -f "$KIOSK_CONFIGURED_FILE" ]]; then
    print_info "Kiosk rendszer már konfigurálva van -- Kiosk systém je už nakonfigurovaný"
    exit 0
fi

# Display managerek letiltása -- Vypnutie display managerov
print_info "Display managerek letiltása -- Vypínanie display managerov..."
DISPLAY_MANAGERS=("lightdm" "lxdm" "sddm" "gdm3" "gdm" "xdm" "plymouth")
for dm in "${DISPLAY_MANAGERS[@]}"; do
    if systemctl list-unit-files | grep -q "^${dm}.service"; then
        systemctl disable --now "${dm}.service" 2>/dev/null || true
        systemctl mask "${dm}.service" 2>/dev/null || true
    fi
done

# .xinitrc létrehozása -- Vytvorenie .xinitrc
print_info "Létrehozás -- Vytvorenie: .xinitrc"
cat > "$USER_HOME/.xinitrc" <<'XINITRC_EOF'
#!/bin/bash
# X inicializáció -- X inicializácia
exec openbox-session
XINITRC_EOF
chmod +x "$USER_HOME/.xinitrc"
chown "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.xinitrc" 2>/dev/null || true

# Openbox autostart konfigurálása -- Konfigurácia Openbox autostart
print_info "Létrehozás -- Vytvorenie: Openbox autostart"
mkdir -p "$USER_HOME/.config/openbox"
cat > "$USER_HOME/.config/openbox/autostart" <<AUTOSTART_EOF
# Képernyővédő kikapcsolása -- Vypnutie šetriča obrazovky
xset -dpms
xset s off
xset s noblank

# Egérkurzor elrejtése -- Skrytie kurzora myši
unclutter -idle 1 &

# ASCII logo megjelenítése terminálban -- Zobrazenie ASCII loga v terminále
xterm -fa Monospace -fs 14 -geometry 120x36+20+20 -e "\$HOME/kiosk-launcher.sh" &
AUTOSTART_EOF
chown -R "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.config" 2>/dev/null || true

# kiosk-launcher.sh létrehozása kiosk mód alapján -- Vytvorenie kiosk-launcher.sh podľa kiosk módu
print_info "Létrehozás -- Vytvorenie: kiosk-launcher.sh"

if [[ "$KIOSK_MODE" = "epiphany" ]]; then
    cat > "$USER_HOME/kiosk-launcher.sh" <<'KIOSK_LAUNCHER_EOF'
#!/bin/bash
# Terminal indító Epiphany böngészőhöz -- Terminálový spúšťač pre Epiphany
set -euo pipefail

URL="${1:-https://www.time.is}"
COUNT_FROM=5

# Terminál kinézet -- Vzhľad terminálu
tput civis || true
clear

# ASCII banner -- ASCII banner
if command -v figlet >/dev/null 2>&1; then
  figlet -w 120 "EDUDISPLEJ"
else
  echo "===================================="
  echo "      E D U D I S P L E J"
  echo "===================================="
fi
echo
echo "Betöltés... / Načítava sa..."
echo

# Visszaszámlálás -- Odpočítavanie
for ((i=COUNT_FROM; i>=1; i--)); do
  printf "\rIndítás %2d másodperc múlva... / Spustenie o %2d sekúnd..." "$i" "$i"
  sleep 1
done
echo -e "\rIndítás most! / Spúšťa sa teraz!     "
sleep 0.3

# Képernyővédő kikapcsolása -- Vypnutie šetriča obrazovky
if command -v xset >/dev/null 2>&1; then
  xset -dpms
  xset s off
  xset s noblank
fi

# Egérkurzor elrejtése -- Skrytie kurzora myši
if command -v unclutter >/dev/null 2>&1; then
  unclutter -idle 1 -root >/dev/null 2>&1 &
fi

trap 'tput cnorm || true' EXIT

# Böngésző indítása -- Spustenie prehliadača
epiphany-browser --fullscreen "${URL}" &

sleep 3
if command -v xdotool >/dev/null 2>&1; then
  xdotool key --window "$(xdotool getactivewindow 2>/dev/null || true)" F11 || true
fi

# Watchdog: böngésző újraindítása ha bezáródik -- Watchdog: reštart prehliadača ak sa zatvorí
while true; do
  sleep 2
  if ! pgrep -x "epiphany-browser" >/dev/null; then
    epiphany-browser --fullscreen "${URL}" &
    sleep 3
    if command -v xdotool >/dev/null 2>&1; then
      xdotool key --window "$(xdotool getactivewindow 2>/dev/null || true)" F11 || true
    fi
  fi
done
KIOSK_LAUNCHER_EOF
else
    cat > "$USER_HOME/kiosk-launcher.sh" <<'KIOSK_LAUNCHER_EOF'
#!/bin/bash
# Terminal indító Chromium böngészőhöz -- Terminálový spúšťač pre Chromium
set -euo pipefail

URL="${1:-https://www.time.is}"
COUNT_FROM=5

# Terminál kinézet -- Vzhľad terminálu
tput civis || true
clear

# ASCII banner -- ASCII banner
if command -v figlet >/dev/null 2>&1; then
  figlet -w 120 "EDUDISPLEJ"
else
  echo "===================================="
  echo "      E D U D I S P L E J"
  echo "===================================="
fi
echo
echo "Betöltés... / Načítava sa..."
echo

# Visszaszámlálás -- Odpočítavanie
for ((i=COUNT_FROM; i>=1; i--)); do
  printf "\rIndítás %2d másodperc múlva... / Spustenie o %2d sekúnd..." "$i" "$i"
  sleep 1
done
echo -e "\rIndítás most! / Spúšťa sa teraz!     "
sleep 0.3

# Képernyővédő kikapcsolása -- Vypnutie šetriča obrazovky
if command -v xset >/dev/null 2>&1; then
  xset -dpms
  xset s off
  xset s noblank
fi

# Egérkurzor elrejtése -- Skrytie kurzora myši
if command -v unclutter >/dev/null 2>&1; then
  unclutter -idle 1 -root >/dev/null 2>&1 &
fi

trap 'tput cnorm || true' EXIT

# Böngésző indítása -- Spustenie prehliadača
chromium-browser --kiosk --no-sandbox --disable-gpu --disable-infobars \
  --no-first-run --incognito --noerrdialogs --disable-translate \
  --disable-features=TranslateUI --disable-session-crashed-bubble \
  --check-for-update-interval=31536000 "${URL}" &

sleep 3
if command -v xdotool >/dev/null 2>&1; then
  xdotool key --window "$(xdotool getactivewindow 2>/dev/null || true)" F11 || true
fi

# Watchdog: böngésző újraindítása ha bezáródik -- Watchdog: reštart prehliadača ak sa zatvorí
while true; do
  sleep 2
  if ! pgrep -x "chromium-browser" >/dev/null; then
    chromium-browser --kiosk --no-sandbox --disable-gpu --disable-infobars \
      --no-first-run --incognito --noerrdialogs --disable-translate \
      --disable-features=TranslateUI --disable-session-crashed-bubble \
      --check-for-update-interval=31536000 "${URL}" &
    sleep 3
    if command -v xdotool >/dev/null 2>&1; then
      xdotool key --window "$(xdotool getactivewindow 2>/dev/null || true)" F11 || true
    fi
  fi
done
KIOSK_LAUNCHER_EOF
fi

chmod +x "$USER_HOME/kiosk-launcher.sh"
chown "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/kiosk-launcher.sh" 2>/dev/null || true

# Konfigurált flag létrehozása -- Vytvorenie flagu nakonfigurovaný
touch "$KIOSK_CONFIGURED_FILE"

# Systemd újratöltése -- Reload systemd
systemctl daemon-reload 2>/dev/null || true

print_success "=========================================="
print_success "Kiosk konfiguráció kész! -- Konfigurácia kiosk hotová!"
print_success "=========================================="
echo ""
print_info "Rendszer újraindítása szükséges -- Je potrebný reštart systému"
exit 0
