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

# Log fajl tisztitas -- Cistenie log suboru
if [[ -f "$SESSION_LOG" ]]; then
    mv "$SESSION_LOG" "${SESSION_LOG}.old" 2>/dev/null || true
fi

# Kimenet atiranyitas log fajlba -- Presmerovanie vystupu do log suboru
# Log to file and, when available, mirror to tty1 for live on-screen progress
LOG_FILE="${SESSION_LOG}"
TTY_OUTPUT="/dev/null"
if [[ -w /dev/tty1 ]]; then
    TTY_OUTPUT="/dev/tty1"
fi

{
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

# Unified system management script -- Zjednoduseny systemovy skript
if [[ -f "${INIT_DIR}/edudisplej-system.sh" ]]; then
    source "${INIT_DIR}/edudisplej-system.sh"
    print_success "✓ edudisplej-system.sh betoltve -- nacitany"
else
    print_error "! edudisplej-system.sh nem talalhato -- nenajdeny"
    exit 1
fi

echo ""

# =============================================================================
# Hostname Configuration -- Konfiguracia hostname
# =============================================================================

# Configure hostname based on MAC address before first boot
if [[ -f "${INIT_DIR}/edudisplej-hostname.sh" ]]; then
    if bash "${INIT_DIR}/edudisplej-hostname.sh"; then
        print_success "✓ Hostname configured"
    else
        print_warning "! Hostname configuration failed (non-critical)"
    fi
fi

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

# =============================================================================
# Kiosk mod beallitasok beolvasasa -- Nacitanie nastaveni kiosk modu
# =============================================================================

# Kiosk mod olvasasa telepitesbol -- Nacitanie kiosk modu z instalacie
read_kiosk_preferences() {
    local kiosk_mode_file="${EDUDISPLEJ_HOME}/.kiosk_mode"
    local console_user_file="${EDUDISPLEJ_HOME}/.console_user"
    local user_home_file="${EDUDISPLEJ_HOME}/.user_home"
    
    # Kiosk mod -- Kiosk mod (surf browser)
    # Read from file if exists, otherwise use default
    if [[ -f "$kiosk_mode_file" ]]; then
        KIOSK_MODE=$(tr -d '\r\n' < "$kiosk_mode_file")
    else
        KIOSK_MODE="surf"
    fi
    print_info "Kiosk mod -- Kiosk mod: $KIOSK_MODE"
    
    # Konzol felhasznalo -- Konzolovy pouzivatel
    if [[ -f "$console_user_file" ]]; then
        CONSOLE_USER=$(tr -d '\r\n' < "$console_user_file")
    else
        CONSOLE_USER="$(awk -F: '$3==1000{print $1}' /etc/passwd | head -n1 || true)"
        [[ -z "$CONSOLE_USER" ]] && CONSOLE_USER="pi"
    fi
    print_info "Felhasznalo -- Pouzivatel: $CONSOLE_USER"
    
    # Felhasznalo home konyvtar -- Domovsky adresar pouzivatela
    if [[ -f "$user_home_file" ]]; then
        USER_HOME=$(tr -d '\r\n' < "$user_home_file")
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
# Align runtime env with detected console user
export HOME="$USER_HOME"
export USER="$CONSOLE_USER"
export XAUTHORITY="$USER_HOME/.Xauthority"
echo ""

# =============================================================================
# Internet kapcsolat ellenorzese -- Kontrola internetoveho pripojenia
# =============================================================================

print_info "Internet ellenorzese -- Kontrola internetu..."
if wait_for_internet; then
    INTERNET_AVAILABLE=0
else
    INTERNET_AVAILABLE=1
fi

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
# Core packages needed for X11 and terminal display
REQUIRED_PACKAGES=(
    openbox
    xinit
    unclutter
    curl
    x11-utils
    xserver-xorg
    x11-xserver-utils
    python3-xdg
)

print_info "1. Alapcsomagok telepitese -- Instalacia zakladnych balickov..."
if ! install_packages "${REQUIRED_PACKAGES[@]}"; then
    print_warning "Nehany alapcsomag telepitese sikertelen -- Niektore zakladne balicky sa nepodarilo nainštalovat"
fi
echo ""

# Kiosk csomagok telepitese -- Instalacia kiosk balickov
# Including surf browser for lightweight browsing
print_info "2. Kiosk csomagok telepitese -- Instalacia kiosk balickov..."
KIOSK_PACKAGES=(xterm xdotool figlet dbus-x11 surf jq)

if ! install_packages "${KIOSK_PACKAGES[@]}"; then
    print_warning "Nehany kiosk csomag telepitese sikertelen -- Niektore kiosk balicky sa nepodarilo nainštalovat"
fi
echo ""

print_info "Skipping browser installation (terminal-only mode)"
echo ""

# =============================================================================
# Kiosk rendszer konfiguralasa -- Konfiguracia kiosk systemu
# Terminal-only mode: Display terminal on main screen, no browser
# =============================================================================

print_info "3. Kiosk rendszer konfiguralasa -- Konfiguracia kiosk systemu..."

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

# X server jogosultsagok konfigurálása -- Konfiguracia X server opravneni
print_info "X server jogosultsagok beallitasa -- Nastavenie X server opravneni..."
mkdir -p /etc/X11 2>/dev/null || true
cat > /etc/X11/Xwrapper.config <<'XWRAPPER_EOF'
# X server wrapper configuration for EduDisplej
# Allow non-console users to start X server
allowed_users=anybody
needs_root_rights=yes
XWRAPPER_EOF
print_success "✓ Xwrapper.config letrehozva -- vytvoreny"

# Felhasznalo csoportok hozzaadasa -- Pridanie pouzivatelskych skupin
print_info "Felhasznalo csoportok beallitasa -- Nastavenie pouzivatelskych skupin..."
usermod -a -G tty,video,input "$CONSOLE_USER" 2>/dev/null || true
print_success "✓ Felhasznalo hozzaadva: tty,video,input csoportokhoz -- Pouzivatel pridany do skupin"

# Sudoers beallitasa - passwordless sudo az edudisplej scriptekhez
# Nastavenie sudoers - bez hesla pre edudisplej scripty
print_info "Sudoers beallitasa -- Nastavenie sudoers..."
SUDOERS_FILE="/etc/sudoers.d/edudisplej"

# Biztonsan letrehozzuk a sudoers fajlt
touch "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"

# Az edudisplej felhasznalonak passwordless sudo jogokat adunk a szukseges scriptekhez
cat > "$SUDOERS_FILE" <<SUDOERS_EOF
# EduDisplej - Passwordless sudo for system services and terminal script
# Allow console user to run update.sh and terminal script without password
Defaults:$CONSOLE_USER !requiretty

# Script permissions
$CONSOLE_USER ALL=(ALL) NOPASSWD: /opt/edudisplej/init/update.sh
$CONSOLE_USER ALL=(ALL) NOPASSWD: /opt/edudisplej/init/edudisplej_terminal_script.sh
$CONSOLE_USER ALL=(ALL) NOPASSWD: /opt/edudisplej/init/edudisplej-download-modules.sh
$CONSOLE_USER ALL=(ALL) NOPASSWD: /opt/edudisplej/init/edudisplej_sync_service.sh
SUDOERS_EOF

# Verify sudoers syntax
if ! visudo -c -f "$SUDOERS_FILE" 2>/dev/null; then
    print_error "Sudoers szintaxis hiba! -- Chyba v syntaxi sudoers!"
    rm -f "$SUDOERS_FILE"
else
    print_success "✓ Sudoers beallitva -- Sudoers nastaveny"
fi
print_info "Letrehozas -- Vytvorenie: .xinitrc"
cat > "$USER_HOME/.xinitrc" <<'XINITRC_EOF'
#!/bin/bash
# Start Openbox window manager
exec openbox-session
XINITRC_EOF
chmod +x "$USER_HOME/.xinitrc"
chown "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.xinitrc" 2>/dev/null || true

# Openbox autostart konfiguralasa -- Konfiguracia Openbox autostart
print_info "Letrehozas -- Vytvorenie: Openbox autostart"
mkdir -p "$USER_HOME/.config/openbox"
cat > "$USER_HOME/.config/openbox/autostart" <<'AUTOSTART_EOF'
#!/bin/bash
# Openbox autostart - Launch EduDisplej terminal script
# Terminal script handles update, download, and browser launch

LOG="/tmp/openbox-autostart.log"
exec >> "$LOG" 2>&1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Openbox autostart starting"
echo "DISPLAY=${DISPLAY:-not set}"
echo "USER=$(whoami)"
echo "HOME=$HOME"

# Wait for X to be ready
MAX_WAIT=10
WAITED=0
while [ $WAITED -lt $MAX_WAIT ]; do
    if xset q >/dev/null 2>&1; then
        echo "X server ready after ${WAITED} seconds"
        break
    fi
    sleep 1
    WAITED=$((WAITED + 1))
done

if [ $WAITED -ge $MAX_WAIT ]; then
    echo "WARNING: X server may not be fully ready after ${MAX_WAIT} seconds"
fi

# Configure display - find first connected output and set it properly
if command -v xrandr >/dev/null 2>&1; then
    echo "=== xrandr output ==="
    xrandr 2>&1
    echo "===================="
    
    # Get the first connected output
    OUTPUT=$(xrandr 2>/dev/null | grep " connected" | head -1 | awk '{print $1}')
    if [ -n "$OUTPUT" ]; then
        echo "Found output: $OUTPUT"
        # Set output as primary and auto-configure
        xrandr --output "$OUTPUT" --auto --primary 2>&1 || echo "xrandr failed"
        echo "Display output configured: $OUTPUT"
        
        # Also try to explicitly set a resolution if auto fails
        RESOLUTION=$(xrandr 2>/dev/null | grep -A1 "^$OUTPUT" | tail -1 | awk '{print $1}')
        # Validate resolution format (should contain 'x' like 1920x1080)
        case "$RESOLUTION" in
            [0-9]*x[0-9]*)
                echo "Detected resolution: $RESOLUTION"
                xrandr --output "$OUTPUT" --mode "$RESOLUTION" 2>&1 || true
                ;;
            *)
                echo "No valid resolution detected, relying on auto configuration"
                ;;
        esac
    else
        echo "WARNING: No connected output found!"
        echo "Trying fallback display configuration..."
        # Try common output names as fallback
        FALLBACK_OUTPUTS="HDMI-1 HDMI-2 HDMI-3 HDMI1 HDMI2 HDMI3 VGA-1 VGA1 LVDS-1 LVDS1 DSI-1 DSI1 eDP-1 eDP1 DP-1 DP1"
        for OUT in $FALLBACK_OUTPUTS; do
            if xrandr 2>/dev/null | grep -q "^$OUT connected"; then
                echo "Found fallback output: $OUT"
                xrandr --output "$OUT" --auto --primary 2>&1 || true
                break
            fi
        done
    fi
fi

# Disable screen blanking
command -v xset >/dev/null 2>&1 && {
    xset -dpms 2>/dev/null || true
    xset s off 2>/dev/null || true
    xset s noblank 2>/dev/null || true
    echo "Screen blanking disabled"
}

# Hide cursor
command -v unclutter >/dev/null 2>&1 && {
    unclutter -idle 1 &
    echo "Cursor hiding enabled"
}

# Black background
command -v xsetroot >/dev/null 2>&1 && {
    xsetroot -solid black 2>/dev/null || true
    echo "Background set to black"
}

# Launch EduDisplej Terminal Script
# This script handles: update, module download, and browser launch
TERMINAL_SCRIPT="/opt/edudisplej/init/edudisplej_terminal_script.sh"

if [ -x "$TERMINAL_SCRIPT" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Launching EduDisplej terminal script"
    # Run xterm in background so openbox keeps running
    # xterm will stay open until user closes it or error occurs
    # Pass DISPLAY and other environment variables to sudo
            xterm -display :0 -fullscreen -fa Monospace -fs 14 \
                -bg black -fg green -title "EduDisplej Kiosk" +sb \
                -e "sudo -n DISPLAY=:0 XAUTHORITY=__USER_HOME__/.Xauthority $TERMINAL_SCRIPT || { echo 'ERROR: sudo failed for terminal script'; sleep 30; }" &
    
    XTERM_PID=$!
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Terminal script launched (PID: $XTERM_PID)"
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: Terminal script not found: $TERMINAL_SCRIPT"
    # Show error xterm in background too
    xterm -display :0 -fullscreen -fa Monospace -fs 14 \
          -bg black -fg red -title "EduDisplej Error" +sb \
          -e "echo 'FATAL ERROR: Terminal script not found'; echo '$TERMINAL_SCRIPT'; sleep 30" &
fi

# Keep openbox alive - don't exit this script
# This allows the window manager to stay responsive
while true; do
    sleep 60
done

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Openbox autostart completed"
AUTOSTART_EOF

sed -i "s|__USER_HOME__|$USER_HOME|g" "$USER_HOME/.config/openbox/autostart"

chmod +x "$USER_HOME/.config/openbox/autostart"
chown -R "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.config" 2>/dev/null || true

# Mark system as configured
touch "$KIOSK_CONFIGURED_FILE"

# Reload systemd
systemctl daemon-reload 2>/dev/null || true

print_success "=========================================="
print_success "Setup complete! Reboot to start terminal"
print_success "=========================================="
exit 0
} 2>&1 | tee -a "$LOG_FILE" > "$TTY_OUTPUT"