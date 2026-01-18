
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

# Browser candidates - chromium-browser only
BROWSER_CANDIDATES=(chromium-browser)
BROWSER_BIN=""
# Core packages needed for kiosk mode (browser installed separately via ensure_browser)
REQUIRED_PACKAGES=(openbox xinit unclutter curl x11-utils xserver-xorg)
APT_UPDATED=false

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

    print_info "$(t boot_pkg_installing) ${missing[*]}"

    # Update package lists
    if [[ "$APT_UPDATED" == false ]]; then
        print_info "Updating package lists..."
        if ! apt-get update -y 2>&1 | tee "$APT_LOG"; then
            print_error "apt-get update failed"
            return 1
        fi
        APT_UPDATED=true
    fi

    # Try to install missing packages
    print_info "Installing packages: ${missing[*]}"
    apt-get install -y "${missing[@]}" 2>&1 | tee -a "$APT_LOG"

    # Verify each package was actually installed
    for pkg in "${missing[@]}"; do
        if ! dpkg -s "$pkg" >/dev/null 2>&1; then
            still_missing+=("$pkg")
            print_error "Failed to install: ${pkg}"
        else
            print_success "Installed: ${pkg}"
        fi
    done

    if [[ ${#still_missing[@]} -eq 0 ]]; then
        print_success "$(t boot_pkg_ok)"
        return 0
    else
        print_error "$(t boot_pkg_install_failed)"
        print_error "Still missing: ${still_missing[*]}"
        local tail_msg
        tail_msg=$(tail -n 10 "$APT_LOG" 2>/dev/null)
        echo "Last 10 lines of apt.log:"
        echo "$tail_msg"
        return 1
    fi
}

# Ensure a supported browser is installed and pick one
ensure_browser() {
    for candidate in "${BROWSER_CANDIDATES[@]}"; do
        if command -v "$candidate" >/dev/null 2>&1; then
            BROWSER_BIN="$candidate"
            export BROWSER_BIN
            print_success "Using browser: ${candidate}"
            return 0
        fi
    done

    if [[ "${INTERNET_AVAILABLE:-1}" -ne 0 ]]; then
        print_error "No supported browser installed and internet unavailable to install."
        return 1
    fi

    if [[ "$APT_UPDATED" == false ]]; then
        print_info "Updating package lists..."
        if ! apt-get update -y 2>&1 | tee -a "$APT_LOG"; then
            print_error "apt-get update failed"
            return 1
        fi
        APT_UPDATED=true
    fi

    for candidate in "${BROWSER_CANDIDATES[@]}"; do
        print_info "Installing browser: ${candidate}"
        if apt-get install -y "$candidate" 2>&1 | tee -a "$APT_LOG"; then
            if command -v "$candidate" >/dev/null 2>&1; then
                BROWSER_BIN="$candidate"
                export BROWSER_BIN
                print_success "Installed browser: ${candidate}"
                return 0
            fi
        else
            print_warning "Installation failed for ${candidate}, trying next option."
        fi
    done

    print_error "Unable to install supported browser (tried: ${BROWSER_CANDIDATES[*]})"
    return 1
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

# Try to install missing packages (when internet is up)
if ! ensure_required_packages; then
    # Stop boot early if dependencies are missing
    print_error "Required packages missing or failed to install. Fix issues and reboot."
    exit 1
fi

# Ensure browser exists (chromium-browser/chromium) - non-blocking, will try to install if needed
if ! ensure_browser; then
    print_warning "No supported browser available yet. System may not start correctly."
    print_warning "Kiosk mode will attempt to install browser automatically."
    # Don't exit - allow the system to continue and let minimal-kiosk handle it
fi

# Check for newer init bundle and self-update
if [[ "$INTERNET_AVAILABLE" -eq 0 ]]; then
    self_update_if_needed "$@"
else
    print_warning "$(t boot_update_failed)"
fi

# =============================================================================
# Main Menu Function
# =============================================================================

main_menu() {
    local choice

    while true; do
        show_main_menu
        if ! read -rp "> " -t $COUNTDOWN_SECONDS choice; then
            print_info "No selection detected, starting saved mode..."
            local saved_mode
            saved_mode=$(get_mode)
            [[ -z "$saved_mode" ]] && saved_mode="EDSERVER"
            set_mode "$saved_mode"
            save_config

            start_kiosk_mode
            break
        fi

        case "$choice" in
            0)
                # EduServer mode
                print_info "$(t menu_eduserver)"
                set_mode "EDSERVER"
                KIOSK_URL="https://server.edudisplej.sk/demo/client/"
                save_config
                start_kiosk_mode
                break
                ;;
            1)
                # Standalone mode
                print_info "$(t menu_standalone)"
                set_mode "STANDALONE"
                echo ""
                read -rp "Enter URL / Zadajte URL: " KIOSK_URL
                if [[ -z "$KIOSK_URL" ]]; then
                    KIOSK_URL="${DEFAULT_KIOSK_URL}"
                fi
                save_config
                start_kiosk_mode
                break
                ;;
            2)
                # Language settings
                show_language_menu
                ;;
            3)
                # Display settings
                show_display_menu
                ;;
            4)
                # Network settings
                show_network_menu
                ;;
            5)
                # Exit (just start kiosk with defaults)
                print_info "$(t menu_exit)"
                start_kiosk_mode
                break
                ;;
            *)
                print_error "$(t menu_invalid)"
                sleep 1
                ;;
        esac
    done
}

# =============================================================================
# Boot Summary + Countdown
# =============================================================================

show_system_summary

if [[ ! -f "$MODE_FILE" ]]; then
    print_warning "No mode configured - opening menu by default"
    ENTER_MENU=true
fi

countdown_or_menu "$COUNTDOWN_SECONDS"

echo ""

# =============================================================================
# Main Logic - Menu or Kiosk
# =============================================================================

if [[ "$ENTER_MENU" == true ]]; then
    # Enter interactive menu
    main_menu
else
    # Load existing mode and start kiosk
    print_info "$(t boot_loading_mode)"

    SAVED_MODE=$(get_mode)

    if [[ -n "$SAVED_MODE" ]]; then
        print_info "Mode: ${SAVED_MODE}"
    else
        SAVED_MODE="EDSERVER"
        set_mode "$SAVED_MODE"
    fi

    start_kiosk_mode
fi

# =============================================================================
# Keep running - monitor kiosk processes
# =============================================================================

echo ""
print_info "EduDisplej kiosk is running. Press Ctrl+C to stop."
print_info "Logs: session.log, kiosk.log"
echo ""

# Keep the script alive - monitor xinit/X processes with safety limit
MAX_RESTART_LOOPS=5
restart_loop_count=0
last_restart_time=0
MIN_UPTIME_FOR_RESET=300  # 5 minutes uptime resets the counter

while true; do
    sleep 10
    
    if ! pgrep -x xinit >/dev/null 2>&1 && ! pgrep -x Xorg >/dev/null 2>&1; then
        current_time=$(date +%s)
        uptime=$((current_time - last_restart_time))
        
        # Reset counter if system ran for long enough
        if [[ $last_restart_time -gt 0 && $uptime -ge $MIN_UPTIME_FOR_RESET ]]; then
            print_info "System ran for ${uptime}s, resetting restart counter"
            restart_loop_count=0
        fi
        
        ((restart_loop_count++))
        
        if [[ $restart_loop_count -ge $MAX_RESTART_LOOPS ]]; then
            print_error "X server restart limit reached ($MAX_RESTART_LOOPS attempts)"
            print_error "FATAL: Too many restart failures, stopping to prevent infinite loop"
            print_error "Check logs: session.log, kiosk.log, /var/log/Xorg.0.log"
            exit 1
        fi
        
        print_warning "X server stopped. Restarting (attempt $restart_loop_count/$MAX_RESTART_LOOPS)..."
        last_restart_time=$(date +%s)
        start_kiosk_mode
    fi
done
