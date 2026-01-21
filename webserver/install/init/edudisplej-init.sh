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
# Boot Screen Display
# =============================================================================

show_boot_screen
countdown_with_f2

# =============================================================================
# Banner megjelenitese -- Zobrazenie bannera
# =============================================================================

echo ""
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
REQUIRED_PACKAGES=(openbox xinit unclutter curl x11-utils xserver-xorg x11-xserver-utils python3-xdg)
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
cat > "$USER_HOME/.config/openbox/autostart" <<'AUTOSTART_EOF'
#!/bin/bash
# Openbox autostart with HDMI display activation
AUTOSTART_LOG="/tmp/openbox-autostart.log"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] === Openbox autostart BEGIN ===" >> "$AUTOSTART_LOG"

# === CRITICAL: Force HDMI output activation ===
if command -v xrandr >/dev/null 2>&1; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Activating HDMI output..." >> "$AUTOSTART_LOG"
    
    # Turn on HDMI-1 explicitly (no flicker - direct activation)
    xrandr --output HDMI-1 --auto --primary >> "$AUTOSTART_LOG" 2>&1
    
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] HDMI-1 activated" >> "$AUTOSTART_LOG"
fi

# Set white background (visible test)
if command -v xsetroot >/dev/null 2>&1; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Setting white background..." >> "$AUTOSTART_LOG"
    xsetroot -solid white >> "$AUTOSTART_LOG" 2>&1
fi

# Screen saver settings (only if xset is available)
if command -v xset >/dev/null 2>&1; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Setting xset options..." >> "$AUTOSTART_LOG"
    xset -dpms >> "$AUTOSTART_LOG" 2>&1
    xset s off >> "$AUTOSTART_LOG" 2>&1
    xset s noblank >> "$AUTOSTART_LOG" 2>&1
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] xset configured" >> "$AUTOSTART_LOG"
fi

# Hide cursor (only if unclutter is available)
if command -v unclutter >/dev/null 2>&1; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting unclutter..." >> "$AUTOSTART_LOG"
    unclutter -idle 1 >> "$AUTOSTART_LOG" 2>&1 &
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] unclutter started" >> "$AUTOSTART_LOG"
fi

# Wait for display to be ready
sleep 2

# Launch xterm with VISIBLE colors (white background, black text)
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Launching xterm..." >> "$AUTOSTART_LOG"
if command -v xterm >/dev/null 2>&1; then
    xterm -display :0 \
          -fa "Monospace" -fs 16 \
          -geometry 100x30+100+100 \
          -bg white -fg black \
          -title "EduDisplej Terminal - VISIBLE TEST" \
          +sb \
          -e bash -c '
              clear
              echo "========================================="
              echo "   EduDisplej Terminal - WORKING!"
              echo "========================================="
              echo ""
              echo "If you can read this, the display works!"
              echo ""
              echo "X Display: $DISPLAY"
              echo "User: $USER"
              echo "Date: $(date)"
              echo ""
              echo "Screen resolution:"
              xrandr | grep "*" || echo "xrandr not available"
              echo ""
              echo "This terminal should be VISIBLE on HDMI monitor!"
              echo ""
              echo "Press Ctrl+C to exit"
              echo ""
              # Keep terminal open with live clock (5sec interval for CPU efficiency)
              while true; do
                  echo "$(date +%T) - Terminal is running..."
                  sleep 5
              done
          ' >> "$AUTOSTART_LOG" 2>&1 &
    
    XTERM_PID=$!
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] xterm launched (PID: $XTERM_PID)" >> "$AUTOSTART_LOG"
    
    # Verify xterm started
    sleep 2
    if ps -p $XTERM_PID > /dev/null 2>&1; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] ✓ xterm process confirmed running" >> "$AUTOSTART_LOG"
        
        # Raise window to front
        if command -v xdotool >/dev/null 2>&1; then
            XTERM_WID=$(xdotool search --pid $XTERM_PID 2>/dev/null | head -1)
            if [ -n "$XTERM_WID" ]; then
                xdotool windowactivate $XTERM_WID 2>/dev/null
                xdotool windowraise $XTERM_WID 2>/dev/null
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] ✓ xterm window raised (WID: $XTERM_WID)" >> "$AUTOSTART_LOG"
            fi
        fi
    else
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] ✗ ERROR: xterm process died!" >> "$AUTOSTART_LOG"
    fi
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: xterm not found!" >> "$AUTOSTART_LOG"
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] === Openbox autostart END ===" >> "$AUTOSTART_LOG"
AUTOSTART_EOF

chmod +x "$USER_HOME/.config/openbox/autostart"
chown -R "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.config" 2>/dev/null || true

# kiosk-launcher.sh letrehozasa kiosk mod alapjan -- Vytvorenie kiosk-launcher.sh podla kiosk modu
print_info "Letrehozas -- Vytvorenie: kiosk-launcher.sh"

# NOTE: Simplified kiosk launcher for terminal test mode
# This removes browser launch code to focus on getting xterm visible first
# KIOSK_MODE variable is still set but not used here - browser can be added back later
# Simplified kiosk launcher - TERMINAL ONLY (no browser)
cat > "$USER_HOME/kiosk-launcher.sh" <<'LAUNCHER_EOF'
#!/bin/bash
# Simplified kiosk launcher - TERMINAL ONLY (no browser)
LAUNCHER_LOG="/tmp/kiosk-launcher.log"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] === KIOSK LAUNCHER START (TERMINAL TEST MODE) ===" | tee -a "$LAUNCHER_LOG"

# Display environment
echo "[$(date '+%Y-%m-%d %H:%M:%S')] DISPLAY=$DISPLAY" | tee -a "$LAUNCHER_LOG"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] HOME=$HOME" | tee -a "$LAUNCHER_LOG"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] USER=$USER" | tee -a "$LAUNCHER_LOG"

# Test X connection
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Testing X connection..." | tee -a "$LAUNCHER_LOG"
if command -v xdpyinfo >/dev/null 2>&1; then
    if xdpyinfo >/dev/null 2>&1; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] ✓ X connection OK" | tee -a "$LAUNCHER_LOG"
        
        # Get screen resolution (with error handling)
        SCREEN_INFO=$(xdpyinfo 2>/dev/null | grep dimensions | awk '{print $2}')
        if [ -n "$SCREEN_INFO" ]; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Screen resolution: $SCREEN_INFO" | tee -a "$LAUNCHER_LOG"
        else
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] WARNING: Could not determine screen resolution" | tee -a "$LAUNCHER_LOG"
        fi
    else
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] ✗ ERROR: Cannot connect to X display!" | tee -a "$LAUNCHER_LOG"
        exit 1
    fi
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] WARNING: xdpyinfo not available" | tee -a "$LAUNCHER_LOG"
fi

# Show ASCII logo (if figlet available)
if command -v figlet >/dev/null 2>&1; then
    echo "" | tee -a "$LAUNCHER_LOG"
    figlet -f standard "EduDisplej" | tee -a "$LAUNCHER_LOG"
    echo "" | tee -a "$LAUNCHER_LOG"
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Terminal test mode - no browser launch" | tee -a "$LAUNCHER_LOG"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] This terminal should remain visible" | tee -a "$LAUNCHER_LOG"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] === KIOSK LAUNCHER END ===" | tee -a "$LAUNCHER_LOG"

# Keep this script running so xterm doesn't close
echo ""
echo "==================================="
echo "Terminal is working!"
echo "==================================="
echo ""
echo "You should see this message on screen."
echo ""
echo "To add browser later, edit:"
echo "  /home/$USER/kiosk-launcher.sh"
echo ""
echo "Logs available at:"
echo "  /tmp/openbox-autostart.log"
echo "  /tmp/kiosk-launcher.log"
echo "  /tmp/edudisplej-watchdog.log"
echo ""
echo "Press Ctrl+C to close this terminal"
echo ""

# Keep terminal open
exec bash
LAUNCHER_EOF

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
