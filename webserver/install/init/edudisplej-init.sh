
#!/bin/bash
# edudisplej-init.sh - EduDisplej initialization script
# =============================================================================
# Initialization
# =============================================================================

# Set script directory
EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
CONFIG_FILE="${EDUDISPLEJ_HOME}/edudisplej.conf"
MODE_FILE="${EDUDISPLEJ_HOME}/.mode"
LAST_ONLINE_FILE="${EDUDISPLEJ_HOME}/.last_online"
LOCAL_WEB_DIR="${EDUDISPLEJ_HOME}/localweb"
SESSION_LOG="${EDUDISPLEJ_HOME}/session.log"

# Clean old session log on startup (keep only current session)
if [[ -f "$SESSION_LOG" ]]; then
    mv "$SESSION_LOG" "${SESSION_LOG}.old" 2>/dev/null || true
fi

# Redirect all output to session log
exec > >(tee -a "$SESSION_LOG") 2>&1

# Versioning and update source
CURRENT_VERSION="20260107-1"
INIT_BASE="https://install.edudisplej.sk/init"
VERSION_URL="${INIT_BASE}/version.txt"
FILES_LIST_URL="${INIT_BASE}/download.php?getfiles"
DOWNLOAD_URL="${INIT_BASE}/download.php?streamfile="
MAX_LOG_SIZE=2097152  # 2MB max log size
APT_LOG="${EDUDISPLEJ_HOME}/apt.log"
UPDATE_LOG="${EDUDISPLEJ_HOME}/update.log"

# Clean old apt log on startup (keep only current session)
if [[ -f "$APT_LOG" ]]; then
    log_size=$(stat -f%z "$APT_LOG" 2>/dev/null || stat -c%s "$APT_LOG" 2>/dev/null || echo 0)
    if [[ $log_size -gt $MAX_LOG_SIZE ]]; then
        mv "$APT_LOG" "${APT_LOG}.old" 2>/dev/null || true
    fi
fi

# Ensure home/init directories and permissions exist
ensure_edudisplej_home() {
    if [[ ! -d "$EDUDISPLEJ_HOME" ]]; then
        if ! mkdir -p "$EDUDISPLEJ_HOME"; then
            print_error "Unable to create $EDUDISPLEJ_HOME"
            exit 1
        fi
    fi

    mkdir -p "$INIT_DIR" "$LOCAL_WEB_DIR" || true
    touch "$APT_LOG" "$UPDATE_LOG" 2>/dev/null || true

    if check_root && id -u edudisplej >/dev/null 2>&1; then
        chown -R edudisplej:edudisplej "$EDUDISPLEJ_HOME" 2>/dev/null || print_warning "Could not change owner of $EDUDISPLEJ_HOME"
    fi
}

# Export display for X operations
export DISPLAY=:0
export HOME="${EDUDISPLEJ_HOME}"
export USER="edudisplej"

# Countdown seconds before auto-start
COUNTDOWN_SECONDS=10

# =============================================================================
# Kiosk Configuration Functions
# =============================================================================

# Read kiosk mode preference from install.sh
read_kiosk_preferences() {
    local kiosk_mode_file="${EDUDISPLEJ_HOME}/.kiosk_mode"
    local console_user_file="${EDUDISPLEJ_HOME}/.console_user"
    local user_home_file="${EDUDISPLEJ_HOME}/.user_home"
    
    if [[ -f "$kiosk_mode_file" ]]; then
        KIOSK_MODE=$(cat "$kiosk_mode_file" | tr -d '\r\n')
        print_info "Kiosk mode preference: $KIOSK_MODE"
    else
        # Detect architecture if not saved
        local arch
        arch="$(uname -m)"
        if [[ "$arch" = "armv6l" ]]; then
            KIOSK_MODE="epiphany"
        else
            KIOSK_MODE="chromium"
        fi
        print_info "Kiosk mode detected: $KIOSK_MODE (arch: $arch)"
    fi
    
    if [[ -f "$console_user_file" ]]; then
        CONSOLE_USER=$(cat "$console_user_file" | tr -d '\r\n')
    else
        CONSOLE_USER="$(awk -F: '$3==1000{print $1}' /etc/passwd | head -n1 || true)"
        [[ -z "$CONSOLE_USER" ]] && CONSOLE_USER="pi"
    fi
    print_info "Console user: $CONSOLE_USER"
    
    if [[ -f "$user_home_file" ]]; then
        USER_HOME=$(cat "$user_home_file" | tr -d '\r\n')
    else
        USER_HOME="$(getent passwd "$CONSOLE_USER" | cut -d: -f6)"
    fi
    
    if [[ -z "$USER_HOME" ]]; then
        print_warning "Could not determine home directory for user: $CONSOLE_USER"
        USER_HOME="/home/$CONSOLE_USER"
    fi
    print_info "User home: $USER_HOME"
    
    export KIOSK_MODE CONSOLE_USER USER_HOME
}

# Install additional packages based on kiosk mode
install_kiosk_packages() {
    local packages=()
    local configured_file="${EDUDISPLEJ_HOME}/.kiosk_configured"
    
    # Skip if already configured
    if [[ -f "$configured_file" ]]; then
        print_info "Kiosk packages already installed (skipping)"
        return 0
    fi
    
    print_info "Installing kiosk-specific packages for mode: $KIOSK_MODE"
    
    # Common packages for both modes
    packages+=("xterm" "xdotool" "figlet" "dbus-x11")
    
    # Mode-specific browser
    if [[ "$KIOSK_MODE" = "epiphany" ]]; then
        packages+=("epiphany-browser")
        print_info "Installing Epiphany browser for ARMv6..."
    else
        # Chromium will be installed by ensure_browser
        print_info "Chromium browser will be installed separately..."
    fi
    
    if [[ "${INTERNET_AVAILABLE:-1}" -ne 0 ]]; then
        print_warning "Internet not available, skipping kiosk package installation"
        return 1
    fi
    
    # Update package lists if not already done
    if [[ "$APT_UPDATED" == false ]]; then
        local apt_update_success=false
        for attempt in 1 2 3; do
            if apt-get update -y >>"$APT_LOG" 2>&1; then
                apt_update_success=true
                break
            else
                if [[ $attempt -lt 3 ]]; then
                    sleep 5
                fi
            fi
        done
        
        if [[ "$apt_update_success" == false ]]; then
            print_error "apt-get update failed after 3 attempts"
            return 1
        fi
        APT_UPDATED=true
    fi
    
    # Show progress banner
    show_installer_banner
    
    local total_steps=${#packages[@]}
    local current_step=0
    local start_time=$(date +%s)
    
    echo ""
    print_info "Inštalácia kiosk balíčkov: ${total_steps} balíčkov"
    echo ""
    
    # Install packages one by one with progress
    local installed_count=0
    for pkg in "${packages[@]}"; do
        ((current_step++))
        show_progress_bar $current_step $total_steps "Inštalujem: $pkg" $start_time
        
        if DEBIAN_FRONTEND=noninteractive apt-get install -y "$pkg" >>"$APT_LOG" 2>&1; then
            ((installed_count++))
        else
            # Retry once
            sleep 2
            if DEBIAN_FRONTEND=noninteractive apt-get install -y "$pkg" >>"$APT_LOG" 2>&1; then
                ((installed_count++))
            fi
        fi
    done
    
    echo ""
    echo ""
    
    if [[ $installed_count -eq ${#packages[@]} ]]; then
        print_success "Kiosk balíčky úspešne nainštalované (${installed_count}/${total_steps})"
        touch "$configured_file"
        return 0
    else
        print_error "Niektoré kiosk balíčky sa nepodarilo nainštalovať"
        return 1
    fi
}

# Configure kiosk system (autologin, .profile, .xinitrc, kiosk-launcher.sh)
configure_kiosk_system() {
    local configured_file="${EDUDISPLEJ_HOME}/.kiosk_system_configured"
    
    # Skip if already configured
    if [[ -f "$configured_file" ]]; then
        print_info "Kiosk system already configured (skipping)"
        return 0
    fi
    
    print_info "Configuring kiosk system for mode: $KIOSK_MODE"
    
    # Disable/remove display managers
    print_info "Disabling display managers..."
    local DISPLAY_MANAGERS=("lightdm" "lxdm" "sddm" "gdm3" "gdm" "xdm" "plymouth")
    for dm in "${DISPLAY_MANAGERS[@]}"; do
        if systemctl list-unit-files | grep -q "^${dm}.service"; then
            print_info "Disabling $dm..."
            systemctl disable --now "${dm}.service" 2>/dev/null || true
            systemctl mask "${dm}.service" 2>/dev/null || true
        fi
        if dpkg -l | grep -q "^ii  $dm "; then
            print_info "Removing package $dm..."
            DEBIAN_FRONTEND=noninteractive apt-get purge -y "$dm" 2>/dev/null || true
        fi
    done
    
    # Set up autologin on tty1
    print_info "Configuring autologin on tty1..."
    local GETTY_DIR="/etc/systemd/system/getty@tty1.service.d"
    mkdir -p "$GETTY_DIR"
    cat > "$GETTY_DIR/autologin.conf" <<EOF
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin $CONSOLE_USER --noclear %I 38400 linux
EOF
    
    # Configure auto-start X on tty1
    print_info "Configuring auto-start X on tty1..."
    local PROFILE_SNIPPET='
# Auto-start X/Openbox on tty1
if [ -z "$DISPLAY" ] && [ "$(tty)" = "/dev/tty1" ]; then
  # Safely terminate any existing X server
  if pgrep Xorg >/dev/null 2>&1; then
    XORG_PIDS=$(pgrep Xorg)
    for pid in $XORG_PIDS; do
      kill -TERM "$pid" 2>/dev/null || true
    done
    sleep 2
    # Force kill if still running
    for pid in $XORG_PIDS; do
      if kill -0 "$pid" 2>/dev/null; then
        kill -KILL "$pid" 2>/dev/null || true
      fi
    done
  fi
  sleep 1
  startx -- :0 vt1
fi'
    
    if [[ -f "$USER_HOME/.profile" ]]; then
        if ! grep -q "Auto-start X/Openbox on tty1" "$USER_HOME/.profile"; then
            echo "$PROFILE_SNIPPET" >> "$USER_HOME/.profile"
            print_info "Added X auto-start to .profile"
        else
            print_info ".profile already configured"
        fi
    else
        echo "$PROFILE_SNIPPET" > "$USER_HOME/.profile"
        print_info "Created .profile with X auto-start"
    fi
    chown "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.profile" 2>/dev/null || true
    
    # Create .xinitrc
    print_info "Creating .xinitrc..."
    cat > "$USER_HOME/.xinitrc" <<'XINITRC_EOF'
#!/bin/bash
# Start Openbox session
exec openbox-session
XINITRC_EOF
    chmod +x "$USER_HOME/.xinitrc"
    chown "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.xinitrc" 2>/dev/null || true
    
    # Create Openbox autostart
    print_info "Creating Openbox autostart configuration..."
    mkdir -p "$USER_HOME/.config/openbox"
    cat > "$USER_HOME/.config/openbox/autostart" <<AUTOSTART_EOF
# Disable DPMS/screensaver
xset -dpms
xset s off
xset s noblank

# Hide mouse after inactivity
unclutter -idle 1 &

# Start TERMINAL with launcher
xterm -fa Monospace -fs 14 -geometry 120x36+20+20 -e "\$HOME/kiosk-launcher.sh" &
AUTOSTART_EOF
    chown -R "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.config" 2>/dev/null || true
    
    # Create kiosk-launcher.sh based on kiosk mode
    print_info "Creating kiosk-launcher.sh for $KIOSK_MODE..."
    
    if [[ "$KIOSK_MODE" = "epiphany" ]]; then
        cat > "$USER_HOME/kiosk-launcher.sh" <<'KIOSK_LAUNCHER_EOF'
#!/bin/bash
# kiosk-launcher.sh - Terminal launcher for Epiphany browser kiosk mode
set -euo pipefail

# Configuration
URL="${1:-https://www.time.is}"
COUNT_FROM=5

# Function to ensure fullscreen with F11
ensure_fullscreen() {
  if command -v xdotool >/dev/null 2>&1; then
    xdotool key --window "$(xdotool getactivewindow 2>/dev/null || true)" F11 || true
  fi
}

# Terminal appearance: hide cursor, clear screen
tput civis || true
clear

# ASCII banner (figlet)
if command -v figlet >/dev/null 2>&1; then
  figlet -w 120 "EDUDISPLEJ"
else
  echo "==== EDUDISPLEJ ===="
fi
echo

# Brief description
echo "Starting... Browser will launch in ${COUNT_FROM} seconds."
echo "URL: ${URL}"
echo

# Countdown
for ((i=COUNT_FROM; i>=1; i--)); do
  printf "\rStarting in %2d..." "$i"
  sleep 1
done
echo -e "\rStarting now!     "
sleep 0.3

# Disable screensaver/power management (if running under X)
if command -v xset >/dev/null 2>&1; then
  xset -dpms
  xset s off
  xset s noblank
fi

# Hide mouse cursor (background)
if command -v unclutter >/dev/null 2>&1; then
  unclutter -idle 1 -root >/dev/null 2>&1 &
fi

# Restore cursor if interrupted (Ctrl+C)
trap 'tput cnorm || true' EXIT

# Launch browser in fullscreen (Epiphany is ARMv6-compatible)
epiphany-browser --fullscreen "${URL}" &

# Optional: ensure fullscreen is active with F11
sleep 3
ensure_fullscreen

# Optional watchdog: restart Epiphany if it closes
while true; do
  sleep 2
  if ! pgrep -x "epiphany-browser" >/dev/null; then
    epiphany-browser --fullscreen "${URL}" &
    sleep 3
    ensure_fullscreen
  fi
done
KIOSK_LAUNCHER_EOF
    else
        cat > "$USER_HOME/kiosk-launcher.sh" <<'KIOSK_LAUNCHER_EOF'
#!/bin/bash
# kiosk-launcher.sh - Terminal launcher for Chromium browser kiosk mode
set -euo pipefail

# Configuration
URL="${1:-https://www.time.is}"
COUNT_FROM=5

# Function to ensure fullscreen with F11
ensure_fullscreen() {
  if command -v xdotool >/dev/null 2>&1; then
    xdotool key --window "$(xdotool getactivewindow 2>/dev/null || true)" F11 || true
  fi
}

# Terminal appearance: hide cursor, clear screen
tput civis || true
clear

# ASCII banner (figlet)
if command -v figlet >/dev/null 2>&1; then
  figlet -w 120 "EDUDISPLEJ"
else
  echo "==== EDUDISPLEJ ===="
fi
echo

# Brief description
echo "Starting... Browser will launch in ${COUNT_FROM} seconds."
echo "URL: ${URL}"
echo

# Countdown
for ((i=COUNT_FROM; i>=1; i--)); do
  printf "\rStarting in %2d..." "$i"
  sleep 1
done
echo -e "\rStarting now!     "
sleep 0.3

# Disable screensaver/power management (if running under X)
if command -v xset >/dev/null 2>&1; then
  xset -dpms
  xset s off
  xset s noblank
fi

# Hide mouse cursor (background)
if command -v unclutter >/dev/null 2>&1; then
  unclutter -idle 1 -root >/dev/null 2>&1 &
fi

# Restore cursor if interrupted (Ctrl+C)
trap 'tput cnorm || true' EXIT

# Launch browser in fullscreen (Chromium for standard platforms)
chromium-browser --kiosk --no-sandbox --disable-gpu --disable-infobars \
  --no-first-run --incognito --noerrdialogs --disable-translate \
  --disable-features=TranslateUI --disable-session-crashed-bubble \
  --check-for-update-interval=31536000 "${URL}" &

# Optional: ensure fullscreen is active with F11
sleep 3
ensure_fullscreen

# Optional watchdog: restart Chromium if it closes
while true; do
  sleep 2
  if ! pgrep -x "chromium-browser" >/dev/null; then
    chromium-browser --kiosk --no-sandbox --disable-gpu --disable-infobars \
      --no-first-run --incognito --noerrdialogs --disable-translate \
      --disable-features=TranslateUI --disable-session-crashed-bubble \
      --check-for-update-interval=31536000 "${URL}" &
    sleep 3
    ensure_fullscreen
  fi
done
KIOSK_LAUNCHER_EOF
    fi
    
    chmod +x "$USER_HOME/kiosk-launcher.sh"
    chown "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/kiosk-launcher.sh" 2>/dev/null || true
    
    # Add xrestart function to .bashrc
    print_info "Adding xrestart function to .bashrc..."
    local BASHRC="$USER_HOME/.bashrc"
    local XRESTART_FUNC='# X restart function
xrestart() {
  # Terminate X server safely
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
}'
    
    if [[ -f "$BASHRC" ]]; then
        if ! grep -q "xrestart()" "$BASHRC"; then
            echo "$XRESTART_FUNC" >> "$BASHRC"
            print_info "Added xrestart function"
        else
            print_info "xrestart function already exists"
        fi
    else
        echo "$XRESTART_FUNC" > "$BASHRC"
        print_info "Created .bashrc with xrestart function"
    fi
    chown "$CONSOLE_USER:$CONSOLE_USER" "$BASHRC" 2>/dev/null || true
    
    # Reload systemd
    systemctl daemon-reload 2>/dev/null || true
    
    # Mark as configured
    touch "$configured_file"
    print_success "Kiosk system configuration complete"
    return 0
}

# =============================================================================
# Load Modules
# =============================================================================

echo "==========================================="
echo "      E D U D I S P L E J"
echo "==========================================="
echo ""
echo "Nacitavam moduly... / Loading modules..."

# Source all modules
if [[ -f "${INIT_DIR}/common.sh" ]]; then
    source "${INIT_DIR}/common.sh"
    print_success "common.sh loaded"
else
    echo "[ERROR] common.sh not found!"
    exit 1
fi

if [[ -f "${INIT_DIR}/kiosk.sh" ]]; then
    source "${INIT_DIR}/kiosk.sh"
    print_success "kiosk.sh loaded"
else
    print_error "kiosk.sh not found!"
fi

if [[ -f "${INIT_DIR}/network.sh" ]]; then
    source "${INIT_DIR}/network.sh"
    print_success "network.sh loaded"
else
    print_error "network.sh not found!"
fi

if [[ -f "${INIT_DIR}/display.sh" ]]; then
    source "${INIT_DIR}/display.sh"
    print_success "display.sh loaded"
else
    print_error "display.sh not found!"
fi

if [[ -f "${INIT_DIR}/language.sh" ]]; then
    source "${INIT_DIR}/language.sh"
    print_success "language.sh loaded"
else
    print_error "language.sh not found!"
fi

if [[ -f "${INIT_DIR}/registration.sh" ]]; then
    source "${INIT_DIR}/registration.sh"
    print_success "registration.sh loaded"
else
    print_warning "registration.sh not found! Device registration will be skipped."
fi

echo ""

# =============================================================================
# Show Banner
# =============================================================================

show_banner

print_info "$(t boot_version) ${CURRENT_VERSION}"

# Ensure base directory exists and is writable
ensure_edudisplej_home

# Load configuration early so defaults are available
if ! load_config; then
    print_warning "Configuration not found, using defaults"
    KIOSK_URL="${DEFAULT_KIOSK_URL}"
    if save_config; then
        print_success "Default configuration created at ${CONFIG_FILE}"
    else
        print_error "Failed to create default configuration at ${CONFIG_FILE}"
    fi
fi

# =============================================================================
# Helper Functions
# =============================================================================

# Browser candidates - determined by kiosk mode
get_browser_candidates() {
    if [[ "${KIOSK_MODE:-chromium}" = "epiphany" ]]; then
        echo "epiphany-browser"
    else
        echo "chromium-browser"
    fi
}

BROWSER_BIN=""
# Core packages needed for kiosk mode (browser installed separately via ensure_browser)
REQUIRED_PACKAGES=(openbox xinit unclutter curl x11-utils xserver-xorg)
APT_UPDATED=false
# INTERNET_AVAILABLE will be set by wait_for_internet() in main execution
# 0 = available, 1 (or non-zero) = not available (following shell exit code convention)
INTERNET_AVAILABLE=1

# Check and install required packages
ensure_required_packages() {
    local missing=()
    local still_missing=()

    print_info "$(t boot_pkg_check)"
    for pkg in "${REQUIRED_PACKAGES[@]}"; do
        if dpkg -s "$pkg" >/dev/null 2>&1; then
            print_success "${pkg}"
        else
            missing+=("$pkg")
        fi
    done

    if [[ ${#missing[@]} -eq 0 ]]; then
        print_success "$(t boot_pkg_ok)"
        return 0
    fi

    if [[ "${INTERNET_AVAILABLE:-1}" -ne 0 ]]; then
        print_warning "$(t boot_pkg_missing) ${missing[*]}"
        print_warning "Internet nie je dostupny, preskakuje sa instalacia."
        return 1
    fi

    # Show installer banner
    show_installer_banner
    
    local total_steps=$((1 + ${#missing[@]}))  # 1 for apt-get update + packages
    local current_step=0
    local start_time=$(date +%s)
    
    echo ""
    print_info "Instalacia balickov: ${#missing[@]} balickov na nainstalovanie"
    echo ""

    # Update package lists with retry
    if [[ "$APT_UPDATED" == false ]]; then
        ((current_step++))
        show_progress_bar $current_step $total_steps "Aktualizacia zoznamu balickov..." $start_time
        
        local apt_update_success=false
        for attempt in 1 2 3; do
            if apt-get update -y >>"$APT_LOG" 2>&1; then
                apt_update_success=true
                break
            else
                if [[ $attempt -lt 3 ]]; then
                    sleep 5
                fi
            fi
        done
        
        if [[ "$apt_update_success" == false ]]; then
            echo ""
            print_error "apt-get update zlyhalo po 3 pokusoch"
            print_warning "Pokracujem s cache zoznamom balickov..."
        fi
        APT_UPDATED=true
    fi

    # Install packages one by one with progress
    local installed_count=0
    for pkg in "${missing[@]}"; do
        ((current_step++))
        show_progress_bar $current_step $total_steps "Instalujem: $pkg" $start_time
        
        # Try to install the package
        if DEBIAN_FRONTEND=noninteractive apt-get install -y "$pkg" >>"$APT_LOG" 2>&1; then
            ((installed_count++))
        else
            # Retry once
            sleep 2
            if DEBIAN_FRONTEND=noninteractive apt-get install -y "$pkg" >>"$APT_LOG" 2>&1; then
                ((installed_count++))
            fi
        fi
    done
    
    echo ""
    echo ""

    # Verify each package was actually installed
    for pkg in "${missing[@]}"; do
        if ! dpkg -s "$pkg" >/dev/null 2>&1; then
            still_missing+=("$pkg")
            print_error "Nepodarilo sa nainštalovať: ${pkg}"
        fi
    done

    if [[ ${#still_missing[@]} -eq 0 ]]; then
        print_success "$(t boot_pkg_ok) - Nainstalovanych: ${installed_count}/${#missing[@]}"
        return 0
    else
        print_error "$(t boot_pkg_install_failed)"
        print_error "Stale chybaju: ${still_missing[*]}"
        local tail_msg
        tail_msg=$(tail -n 10 "$APT_LOG" 2>/dev/null)
        echo "Poslednych 10 riadkov z apt.log:"
        echo "$tail_msg"
        return 1
    fi
}

# Ensure a supported browser is installed and pick one
ensure_browser() {
    local BROWSER_CANDIDATES
    BROWSER_CANDIDATES=$(get_browser_candidates)
    
    if command -v "$BROWSER_CANDIDATES" >/dev/null 2>&1; then
        BROWSER_BIN="$BROWSER_CANDIDATES"
        export BROWSER_BIN
        print_success "Using browser: ${BROWSER_CANDIDATES}"
        return 0
    fi

    if [[ "${INTERNET_AVAILABLE:-1}" -ne 0 ]]; then
        print_error "No supported browser installed and internet unavailable to install."
        return 1
    fi

    if [[ "$APT_UPDATED" == false ]]; then
        local apt_update_success=false
        for attempt in 1 2 3; do
            if apt-get update -y >>"$APT_LOG" 2>&1; then
                apt_update_success=true
                break
            else
                if [[ $attempt -lt 3 ]]; then
                    sleep 5
                fi
            fi
        done
        
        if [[ "$apt_update_success" == false ]]; then
            print_error "apt-get update failed after 3 attempts"
            return 1
        fi
        APT_UPDATED=true
    fi

    # Show progress banner
    show_installer_banner
    
    local start_time=$(date +%s)
    echo ""
    print_info "Instalacia prehliadaca: ${BROWSER_CANDIDATES}"
    echo ""
    
    show_progress_bar 0 1 "Stahujem a instalujem ${BROWSER_CANDIDATES}..." $start_time
    
    # Try installation with retry
    local install_success=false
    for attempt in 1 2; do
        if DEBIAN_FRONTEND=noninteractive apt-get install -y "$BROWSER_CANDIDATES" >>"$APT_LOG" 2>&1; then
            install_success=true
            break
        else
            if [[ $attempt -lt 2 ]]; then
                sleep 10
            fi
        fi
    done
    
    show_progress_bar 1 1 "Dokoncene: ${BROWSER_CANDIDATES}" $start_time
    echo ""
    echo ""
    
    if [[ "$install_success" == true ]] && command -v "$BROWSER_CANDIDATES" >/dev/null 2>&1; then
        BROWSER_BIN="$BROWSER_CANDIDATES"
        export BROWSER_BIN
        print_success "Installed browser: ${BROWSER_CANDIDATES}"
        return 0
    else
        print_error "Unable to install supported browser: ${BROWSER_CANDIDATES}"
        return 1
    fi
}

# Fetch latest version string from server
fetch_remote_version() {
    local out
    if ! out=$(curl -fsSL "$VERSION_URL" 2>&1); then
        print_error "$(t boot_update_failed) ${out}"
        return 1
    fi
    echo "$out" | tr -d '\r' | head -n 1
}

# Download init files from server (same logic as install.sh)
download_init_files() {
    local tmpdir
    tmpdir=$(mktemp -d) || return 1

    local files_list
    if ! files_list=$(curl -fsSL "$FILES_LIST_URL" 2>>"$UPDATE_LOG" | tr -d '\r'); then
        print_error "$(t boot_update_failed) $(tail -n 5 "$UPDATE_LOG" 2>/dev/null)"
        return 1
    fi
    if [[ -z "$files_list" ]]; then
        return 1
    fi

    while IFS=";" read -r NAME SIZE MODIFIED; do
        [[ -z "${NAME:-}" ]] && continue
        if ! curl -fsSL "${DOWNLOAD_URL}${NAME}" -o "${tmpdir}/${NAME}" 2>>"$UPDATE_LOG"; then
            print_error "$(t boot_update_failed) $(tail -n 5 "$UPDATE_LOG" 2>/dev/null)"
            return 1
        fi
        sed -i 's/\r$//' "${tmpdir}/${NAME}"
        if [[ "${NAME}" == *.sh ]]; then
            chmod +x "${tmpdir}/${NAME}"
            local first_line
            first_line=$(head -n1 "${tmpdir}/${NAME}" || true)
            if [[ "${first_line}" != "#!"* ]]; then
                sed -i '1i #!/bin/bash' "${tmpdir}/${NAME}"
            fi
        fi
    done <<< "$files_list"

    cp -f "${tmpdir}"/* "$INIT_DIR"/
    if [[ -f "${tmpdir}/clock.html" ]]; then
        mkdir -p "$LOCAL_WEB_DIR"
        cp -f "${tmpdir}/clock.html" "$LOCAL_WEB_DIR/clock.html"
    fi
    chmod -R 755 "$INIT_DIR"
    rm -rf "$tmpdir"
    return 0
}

# Self-update when a newer version exists
self_update_if_needed() {
    print_info "$(t boot_update_check)"

    local remote_version
    remote_version=$(fetch_remote_version) || return 0

    if [[ -z "$remote_version" ]]; then
        print_warning "$(t boot_update_failed)"
        return 0
    fi

    if [[ "$remote_version" == "$CURRENT_VERSION" ]]; then
        print_success "$(t boot_version) $CURRENT_VERSION"
        return 0
    fi

    print_info "$(t boot_update_available) $remote_version"
    print_info "$(t boot_update_downloading)"

    if download_init_files; then
        print_success "$(t boot_update_done)"
        exec "$0" "$@"
    else
        print_error "$(t boot_update_failed)"
    fi
}

# Gather current system information for boot summary
show_system_summary() {
    local current_mode
    current_mode=$(get_mode)
    [[ -z "$current_mode" ]] && current_mode="${MODE:-EDSERVER}"

    # Get device info
    local device_model
    device_model=$(cat /proc/device-tree/model 2>/dev/null | tr -d '\0' || echo "Unknown")
    
    # Get MAC address (try eth0, wlan0, or first available)
    local mac_addr
    mac_addr=$(cat /sys/class/net/eth0/address 2>/dev/null || \
               cat /sys/class/net/wlan0/address 2>/dev/null || \
               ip link show | grep -A1 "state UP" | grep "link/ether" | awk '{print $2}' | head -n1 || \
               echo "N/A")
    
    # Get CPU info
    local cpu_temp
    cpu_temp=$(vcgencmd measure_temp 2>/dev/null | cut -d= -f2 || echo "N/A")
    
    # Get memory info
    local mem_info
    mem_info=$(free -h | awk '/^Mem:/ {print $2 " total, " $3 " used, " $4 " free"}')

    echo ""
    print_info "$(t boot_summary)"
    echo "==========================================="
    echo "Device: ${device_model}"
    echo "MAC Address: ${mac_addr}"
    echo "Hostname: $(hostname)"
    echo "IP Address: $(get_current_ip)"
    echo "Gateway: $(get_gateway)"
    echo "-------------------------------------------"
    echo "Mode: ${current_mode}"
    echo "Kiosk URL: ${KIOSK_URL:-$DEFAULT_KIOSK_URL}"
    echo "Language: ${CURRENT_LANG}"
    echo "-------------------------------------------"
    echo "Wi-Fi SSID: $(get_current_ssid)"
    echo "Wi-Fi Signal: $(get_current_signal)"
    if [[ -f "$LAST_ONLINE_FILE" ]]; then
        echo "Last Online: $(cat "$LAST_ONLINE_FILE")"
    else
        echo "Last Online: unknown"
    fi
    echo "-------------------------------------------"
    echo "Resolution: $(get_current_resolution)"
    echo "CPU Temp: ${cpu_temp}"
    echo "Memory: ${mem_info}"
    echo "==========================================="
    echo ""
}

# Countdown before auto-start, allow entering the menu
countdown_or_menu() {
    local seconds="${1:-$COUNTDOWN_SECONDS}"
    if [[ "${ENTER_MENU:-false}" == true ]]; then
        return
    fi
    ENTER_MENU=false

    for ((i=seconds; i>=1; i--)); do
        echo -ne "\r$(t boot_countdown) (${i}s)  "
        if read -r -t 1 -n 1 key 2>/dev/null; then
            ENTER_MENU=true
            break
        fi
    done
    echo ""
}

# =============================================================================
# Wait for Internet Connection
# =============================================================================

wait_for_internet
INTERNET_AVAILABLE=$?

if [[ $INTERNET_AVAILABLE -eq 0 ]]; then
    date -u +"%Y-%m-%dT%H:%M:%SZ" > "$LAST_ONLINE_FILE"
    
    # Try to register device to remote server (only if not already registered)
    if command -v register_device >/dev/null 2>&1; then
        register_device || print_warning "Device registration failed (will retry on next boot)"
    fi
fi

echo ""

# Read kiosk preferences from install.sh
read_kiosk_preferences

# Try to install missing packages (when internet is up)
if ! ensure_required_packages; then
    # Stop boot early if dependencies are missing
    print_error "Required packages missing or failed to install. Fix issues and reboot."
    exit 1
fi

# Install kiosk-specific packages based on mode
if ! install_kiosk_packages; then
    print_warning "Kiosk packages installation incomplete, will retry on next boot"
fi

# Ensure browser exists (chromium-browser/chromium) - non-blocking, will try to install if needed
if ! ensure_browser; then
    print_warning "No supported browser available yet. System may not start correctly."
    print_warning "Kiosk mode will attempt to install browser automatically."
    # Don't exit - allow the system to continue and let minimal-kiosk handle it
fi

# Configure kiosk system (autologin, .profile, etc.)
if ! configure_kiosk_system; then
    print_warning "Kiosk system configuration incomplete, will retry on next boot"
fi

# Check for newer init bundle and self-update
if [[ "$INTERNET_AVAILABLE" -eq 0 ]]; then
    self_update_if_needed "$@"
else
    print_warning "$(t boot_update_failed)"
fi

# =============================================================================
# Check if running as service (one-time setup mode)
# =============================================================================

# If kiosk system is configured, we're done with first-time setup
# The .profile approach will handle starting X and kiosk
KIOSK_CONFIGURED_FILE="${EDUDISPLEJ_HOME}/.kiosk_system_configured"
if [[ -f "$KIOSK_CONFIGURED_FILE" ]]; then
    print_success "System initialization complete"
    print_info "Kiosk will start automatically after user login"
    print_info "User $CONSOLE_USER will auto-login on tty1"
    print_info "X server will start automatically via .profile"
    exit 0
fi

# =============================================================================
# First-time Setup - Configuration without menu
# =============================================================================

show_system_summary

# Set default mode if not configured
if [[ ! -f "$MODE_FILE" ]]; then
    print_info "First-time setup - using default mode"
    set_mode "EDSERVER"
    KIOSK_URL="https://www.time.is"
    save_config
fi

print_success "System initialization complete - kiosk configured"
print_info "System will auto-start kiosk after reboot when user logs in on tty1"
exit 0
