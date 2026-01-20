#!/bin/bash
# edudisplej-init.sh - Egyszerusitett inicializalas -- Zjednodusena inicializacia
# =============================================================================
# Ez a szkript ellenorzi a rendszert es szukseg eseten telepiti a hianyzó komponenseket
# Tento skript kontroluje system a v pripade potreby nainstaluje chybajuce komponenty
# =============================================================================

set -euo pipefail

# Alapbeallitasok -- Zakladne nastavenia
EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
CONFIG_FILE="${EDUDISPLEJ_HOME}/edudisplej.conf"
MODE_FILE="${EDUDISPLEJ_HOME}/.mode"
SESSION_LOG="${EDUDISPLEJ_HOME}/session.log"
APT_LOG="${EDUDISPLEJ_HOME}/apt.log"

# Export kornyezeti valtozok -- Export premennych prostredia
export DISPLAY=:0
export HOME="${EDUDISPLEJ_HOME}"
export USER="edudisplej"

# Log fajl tisztitas -- Cistenie log suboru
if [[ -f "$SESSION_LOG" ]]; then
    mv "$SESSION_LOG" "${SESSION_LOG}.old" 2>/dev/null || true
fi

# Kimenet atiranyitas log fajlba -- Presmerovanie vystupu do log suboru
exec > >(tee -a "$SESSION_LOG") 2>&1

# =============================================================================
# Modulok betoltese -- Nacitanie modulov
# =============================================================================

echo "==========================================="
echo "      E D U D I S P L E J"
echo "==========================================="
echo ""
echo "Modulok betoltese... / Nacitavam moduly..."
echo ""

# Kozos fuggvenyek -- Spolocne funkcie
if [[ -f "${INIT_DIR}/common.sh" ]]; then
    # Try to source the file - the source command itself will detect syntax errors
    # We rely on file size verification during download to catch truncation
    if source "${INIT_DIR}/common.sh" 2>/dev/null; then
        print_success "✓ common.sh betoltve -- nacitany"
    else
        echo "==========================================="
        echo "[KRITICKA HIBA / CRITICAL ERROR]"
        echo "==========================================="
        echo ""
        echo "Nepodarilo sa nacitat common.sh!"
        echo "Failed to load common.sh!"
        echo ""
        echo "Subor moze obsahovat syntax chyby,"
        echo "moze byt poskodeny alebo neuplne stiahnuty."
        echo ""
        echo "File may contain syntax errors,"
        echo "may be corrupted or incompletely downloaded."
        echo ""
        echo "RIESENIE / SOLUTION:"
        echo "Znova spustite instalaciu:"
        echo "curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash"
        echo ""
        echo "Viac informacii: /opt/edudisplej/filestreamerror.md"
        echo "==========================================="
        sleep 10
        exit 1
    fi
else
    echo "[HIBA/CHYBA] common.sh nem talalhato!"
    exit 1
fi

# Halozati fuggvenyek -- Sietove funkcie
if [[ -f "${INIT_DIR}/network.sh" ]]; then
    source "${INIT_DIR}/network.sh"
    print_success "✓ network.sh betoltve -- nacitany"
fi

# Nyelvbeallitasok -- Jazykove nastavenia
if [[ -f "${INIT_DIR}/language.sh" ]]; then
    source "${INIT_DIR}/language.sh"
    print_success "✓ language.sh betoltve -- nacitany"
fi

# Ellenorzo szkript -- Kontrolny skript
if [[ -f "${INIT_DIR}/edudisplej-checker.sh" ]]; then
    source "${INIT_DIR}/edudisplej-checker.sh"
    print_success "✓ edudisplej-checker.sh betoltve -- nacitany"
else
    print_warning "! edudisplej-checker.sh nem talalhato -- nenajdeny"
fi

# Telepito szkript -- Instalacny skript
if [[ -f "${INIT_DIR}/edudisplej-installer.sh" ]]; then
    source "${INIT_DIR}/edudisplej-installer.sh"
    print_success "✓ edudisplej-installer.sh betoltve -- nacitany"
else
    print_warning "! edudisplej-installer.sh nem talalhato -- nenajdeny"
fi

echo ""

# =============================================================================
# Banner megjelenitese -- Zobrazenie bannera
# =============================================================================

show_banner
print_info "Verzio -- Verzia: $(date +%Y%m%d)"

# =============================================================================
# Alapkonyvtar biztositasa -- Zabezpecenie zakladneho adresara
# =============================================================================

if [[ ! -d "$EDUDISPLEJ_HOME" ]]; then
    mkdir -p "$EDUDISPLEJ_HOME" || {
        print_error "Nem sikerult letrehozni -- Nepodarilo sa vytvorit: $EDUDISPLEJ_HOME"
        exit 1
    }
fi

mkdir -p "$INIT_DIR" "${EDUDISPLEJ_HOME}/localweb" 2>/dev/null || true
touch "$APT_LOG" 2>/dev/null || true

# =============================================================================
# Konfiguracio betoltese -- Nacitanie konfiguracie
# =============================================================================

# Konfiguracio betoltese, ha letezik -- Nacitanie konfiguracie, ak existuje
if [[ -f "$CONFIG_FILE" ]]; then
    source "$CONFIG_FILE" || true
fi

# Alapertelmezett ertekek -- Predvolene hodnoty
KIOSK_URL="${KIOSK_URL:-https://www.time.is}"
DEFAULT_KIOSK_URL="https://www.time.is"

# =============================================================================
# Kiosk mod beallitasok beolvasasa -- Nacitanie nastaveni kiosk modu
# =============================================================================

# Kiosk mod olvasasa telepitesbol -- Nacitanie kiosk modu z instalacie
read_kiosk_preferences() {
    local kiosk_mode_file="${EDUDISPLEJ_HOME}/.kiosk_mode"
    local console_user_file="${EDUDISPLEJ_HOME}/.console_user"
    local user_home_file="${EDUDISPLEJ_HOME}/.user_home"
    
    # Kiosk mod -- Kiosk mod
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
    print_info "Kiosk mod -- Kiosk mod: $KIOSK_MODE"
    
    # Konzol felhasznalo -- Konzolovy pouzivatel
    if [[ -f "$console_user_file" ]]; then
        CONSOLE_USER=$(cat "$console_user_file" | tr -d '\r\n')
    else
        CONSOLE_USER="$(awk -F: '$3==1000{print $1}' /etc/passwd | head -n1 || true)"
        [[ -z "$CONSOLE_USER" ]] && CONSOLE_USER="pi"
    fi
    print_info "Felhasznalo -- Pouzivatel: $CONSOLE_USER"
    
    # Felhasznalo home konyvtar -- Domovsky adresar pouzivatela
    if [[ -f "$user_home_file" ]]; then
        USER_HOME=$(cat "$user_home_file" | tr -d '\r\n')
    else
        USER_HOME="$(getent passwd "$CONSOLE_USER" | cut -d: -f6)"
    fi
    
    if [[ -z "$USER_HOME" ]]; then
        USER_HOME="/home/$CONSOLE_USER"
    fi
    print_info "Home konyvtar -- Domovsky adresar: $USER_HOME"
    
    export KIOSK_MODE CONSOLE_USER USER_HOME
}

echo ""
read_kiosk_preferences
echo ""

# =============================================================================
# Internet kapcsolat ellenorzese -- Kontrola internetoveho pripojenia
# =============================================================================

print_info "Internet ellenorzese -- Kontrola internetu..."
wait_for_internet
INTERNET_AVAILABLE=$?

if [[ $INTERNET_AVAILABLE -eq 0 ]]; then
    print_success "✓ Internet elerheto -- Internet je dostupny"
else
    print_warning "✗ Nincs internet -- Ziadny internet"
fi
echo ""

# =============================================================================
# Rendszer ellenorzese -- Kontrola systemu
# =============================================================================

# Rendszer allapot ellenorzese -- Kontrola stavu systemu
if check_system_ready "$KIOSK_MODE" "$CONSOLE_USER" "$USER_HOME"; then
    print_success "=========================================="
    print_success "Rendszer kesz! -- System je pripraveny!"
    print_success "=========================================="
    echo ""
    print_info "X kornyezet inditasa tortenik... -- Spusta sa X prostredie..."
    exit 0
fi

echo ""
print_info "=========================================="
print_info "Telepites szukseges -- Je potrebna instalacia"
print_info "=========================================="
echo ""

# =============================================================================
# Hianyzo komponensek telepitese -- Instalacia chybajucich komponentov
# =============================================================================

# Alapcsomagok telepitese -- Instalacia zakladnych balickov
REQUIRED_PACKAGES=(openbox xinit unclutter curl x11-utils xserver-xorg)
print_info "1. Alapcsomagok telepitese -- Instalacia zakladnych balickov..."
if ! install_required_packages "${REQUIRED_PACKAGES[@]}"; then
    print_warning "Nehany alapcsomag telepitese sikertelen -- Niektore zakladne balicky sa nepodarilo nainštalovat"
fi
echo ""

# Kiosk csomagok telepitese -- Instalacia kiosk balickov
print_info "2. Kiosk csomagok telepitese -- Instalacia kiosk balickov..."
if ! install_kiosk_packages "$KIOSK_MODE"; then
    print_warning "Nehany kiosk csomag telepitese sikertelen -- Niektore kiosk balicky sa nepodarilo nainštalovat"
fi
echo ""

# Bongeszo telepitese -- Instalacia prehliadaca
print_info "3. Bongeszo telepitese -- Instalacia prehliadaca..."
if [[ "$KIOSK_MODE" = "epiphany" ]]; then
    BROWSER_NAME="epiphany-browser"
else
    BROWSER_NAME="chromium-browser"
fi

if ! install_browser "$BROWSER_NAME"; then
    print_warning "Bongeszo telepitese sikertelen -- Instalacia prehliadaca zlyhala"
fi
echo ""

# =============================================================================
# Kiosk rendszer konfiguralasa -- Konfiguracia kiosk systemu
# =============================================================================

print_info "4. Kiosk rendszer konfiguralasa -- Konfiguracia kiosk systemu..."

KIOSK_CONFIGURED_FILE="${EDUDISPLEJ_HOME}/.kiosk_system_configured"

# Ha mar konfiguralva, kilepes -- Ak uz je nakonfigurovane, ukoncenie
if [[ -f "$KIOSK_CONFIGURED_FILE" ]]; then
    print_info "Kiosk rendszer mar konfiguralva van -- Kiosk system je uz nakonfigurovany"
    exit 0
fi

# Display managerek letiltasa -- Vypnutie display managerov
print_info "Display managerek letiltasa -- Vypinanie display managerov..."
DISPLAY_MANAGERS=("lightdm" "lxdm" "sddm" "gdm3" "gdm" "xdm" "plymouth")
for dm in "${DISPLAY_MANAGERS[@]}"; do
    if systemctl list-unit-files | grep -q "^${dm}.service"; then
        systemctl disable --now "${dm}.service" 2>/dev/null || true
        systemctl mask "${dm}.service" 2>/dev/null || true
    fi
done

# .xinitrc letrehozasa -- Vytvorenie .xinitrc
print_info "Letrehozas -- Vytvorenie: .xinitrc"
cat > "$USER_HOME/.xinitrc" <<'XINITRC_EOF'
#!/bin/bash
# X inicializacio -- X inicializacia
exec openbox-session
XINITRC_EOF
chmod +x "$USER_HOME/.xinitrc"
chown "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.xinitrc" 2>/dev/null || true

# Openbox autostart konfiguralasa -- Konfiguracia Openbox autostart
print_info "Letrehozas -- Vytvorenie: Openbox autostart"
mkdir -p "$USER_HOME/.config/openbox"
cat > "$USER_HOME/.config/openbox/autostart" <<AUTOSTART_EOF
# Kepernyovedo kikapcsolasa -- Vypnutie setraca obrazovky
xset -dpms
xset s off
xset s noblank

# Egerkurzor elrejtese -- Skrytie kurzora mysi
unclutter -idle 1 &

# ASCII logo megjelenitese terminalban -- Zobrazenie ASCII loga v terminale
xterm -fa Monospace -fs 14 -geometry 120x36+20+20 -e "\$HOME/kiosk-launcher.sh" &
AUTOSTART_EOF
chown -R "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.config" 2>/dev/null || true

# kiosk-launcher.sh letrehozasa kiosk mod alapjan -- Vytvorenie kiosk-launcher.sh podla kiosk modu
print_info "Letrehozas -- Vytvorenie: kiosk-launcher.sh"

if [[ "$KIOSK_MODE" = "epiphany" ]]; then
    cat > "$USER_HOME/kiosk-launcher.sh" <<'KIOSK_LAUNCHER_EOF'
#!/bin/bash
# Terminal indito Epiphany bongeszohoz -- Terminalovy spustac pre Epiphany
set -euo pipefail

URL="${1:-https://www.time.is}"
COUNT_FROM=5

# Terminal kinezetl -- Vzhad terminalu
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
echo "Betoltes... / Nacitava sa..."
echo

# Visszaszamlalas -- Odpocitavanie
for ((i=COUNT_FROM; i>=1; i--)); do
  printf "\rInditas %2d masodperc mulva... / Spustenie o %2d sekund..." "$i" "$i"
  sleep 1
done
echo -e "\rInditas most! / Spusta sa teraz!     "
sleep 0.3

# Kepernyovedo kikapcsolasa -- Vypnutie setraca obrazovky
if command -v xset >/dev/null 2>&1; then
  xset -dpms
  xset s off
  xset s noblank
fi

# Egerkurzor elrejtese -- Skrytie kurzora mysi
if command -v unclutter >/dev/null 2>&1; then
  unclutter -idle 1 -root >/dev/null 2>&1 &
fi

trap 'tput cnorm || true' EXIT

# Bongeszo inditasa -- Spustenie prehliadaca
epiphany-browser --fullscreen "${URL}" &

sleep 3
if command -v xdotool >/dev/null 2>&1; then
  xdotool key --window "$(xdotool getactivewindow 2>/dev/null || true)" F11 || true
fi

# Watchdog: bongeszo ujrainditasa ha bezarodik -- Watchdog: restart prehliadaca ak sa zatvori
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
# Terminal indito Chromium bongeszohoz -- Terminalovy spustac pre Chromium
set -euo pipefail

URL="${1:-https://www.time.is}"
COUNT_FROM=5

# Terminal kinezet -- Vzhad terminalu
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
echo "Betoltes... / Nacitava sa..."
echo

# Visszaszamlalas -- Odpocitavanie
for ((i=COUNT_FROM; i>=1; i--)); do
  printf "\rInditas %2d masodperc mulva... / Spustenie o %2d sekund..." "$i" "$i"
  sleep 1
done
echo -e "\rInditas most! / Spusta sa teraz!     "
sleep 0.3

# Kepernyovedo kikapcsolasa -- Vypnutie setraca obrazovky
if command -v xset >/dev/null 2>&1; then
  xset -dpms
  xset s off
  xset s noblank
fi

# Egerkurzor elrejtese -- Skrytie kurzora mysi
if command -v unclutter >/dev/null 2>&1; then
  unclutter -idle 1 -root >/dev/null 2>&1 &
fi

trap 'tput cnorm || true' EXIT

# Bongeszo inditasa -- Spustenie prehliadaca
chromium-browser --kiosk --no-sandbox --disable-gpu --disable-infobars \
  --no-first-run --incognito --noerrdialogs --disable-translate \
  --disable-features=TranslateUI --disable-session-crashed-bubble \
  --check-for-update-interval=31536000 "${URL}" &

sleep 3
if command -v xdotool >/dev/null 2>&1; then
  xdotool key --window "$(xdotool getactivewindow 2>/dev/null || true)" F11 || true
fi

# Watchdog: bongeszo ujrainditasa ha bezarodik -- Watchdog: restart prehliadaca ak sa zatvori
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

# Konfigurait flag letrehozasa -- Vytvorenie flagu nakonfigurovany
touch "$KIOSK_CONFIGURED_FILE"

# Systemd ujratoltese -- Reload systemd
systemctl daemon-reload 2>/dev/null || true

print_success "=========================================="
print_success "Kiosk konfiguracio kesz! -- Konfiguracia kiosk hotova!"
print_success "=========================================="
echo ""
print_info "Rendszer ujrainditasa szukseges -- Je potrebny restart systemu"
exit 0
