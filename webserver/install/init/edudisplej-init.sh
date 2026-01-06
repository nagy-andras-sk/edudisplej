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

# Versioning and update source
CURRENT_VERSION="20260107-1"
INIT_BASE="https://install.edudisplej.sk/init"
VERSION_URL="${INIT_BASE}/version.txt"
FILES_LIST_URL="${INIT_BASE}/download.php?getfiles"
DOWNLOAD_URL="${INIT_BASE}/download.php?streamfile="
APT_LOG="${EDUDISPLEJ_HOME}/apt.log"
UPDATE_LOG="${EDUDISPLEJ_HOME}/update.log"

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

if [[ -f "${INIT_DIR}/services.sh" ]]; then
    source "${INIT_DIR}/services.sh"
    print_success "services.sh loaded"
else
    print_warning "services.sh not found (service management disabled)"
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

BROWSER_CANDIDATES=(chromium-browser chromium)
BROWSER_BIN=""
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

    echo ""
    print_info "$(t boot_summary)"
    echo "-------------------------------------------"
    echo "Mode: ${current_mode}"
    echo "Kiosk URL: ${KIOSK_URL:-$DEFAULT_KIOSK_URL}"
    echo "Language: ${CURRENT_LANG}"
    echo "IP: $(get_current_ip)"
    echo "Gateway: $(get_gateway)"
    echo "Wi-Fi SSID: $(get_current_ssid)"
    echo "Wi-Fi signal: $(get_current_signal)"
    if [[ -f "$LAST_ONLINE_FILE" ]]; then
        echo "Last online: $(cat "$LAST_ONLINE_FILE")"
    else
        echo "Last online: unknown"
    fi
    echo "Resolution: $(get_current_resolution)"
    echo "Hostname: $(hostname)"
    echo "-------------------------------------------"
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
fi

echo ""

# Try to install missing packages (when internet is up)
if ! ensure_required_packages; then
    # Stop boot early if dependencies are missing
    print_error "Required packages missing or failed to install. Fix issues and reboot."
    exit 1
fi

# Ensure browser exists (chromium-browser/chromium)
if ! ensure_browser; then
    print_error "No supported browser available. Fix installation and reboot."
    exit 1
fi

# Ensure kiosk service unit exists
if command -v systemctl >/dev/null 2>&1; then
    ensure_chromium_kiosk_service || print_warning "Could not ensure kiosk service"
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
            if command -v systemctl >/dev/null 2>&1; then
                start_or_restart_chromium_kiosk_service || start_kiosk_mode
            else
                start_kiosk_mode
            fi
            break
        fi
        
        case "$choice" in
            0)
                # EduServer mode
                print_info "$(t menu_eduserver)"
                set_mode "EDSERVER"
                KIOSK_URL="https://server.edudisplej.sk/demo/client/"
                save_config
                if command -v systemctl >/dev/null 2>&1; then
                    start_or_restart_chromium_kiosk_service || start_kiosk_mode
                else
                    start_kiosk_mode
                fi
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
                if command -v systemctl >/dev/null 2>&1; then
                    start_or_restart_chromium_kiosk_service || start_kiosk_mode
                else
                    start_kiosk_mode
                fi
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
    
    # Start kiosk service if available, else direct kiosk
    if command -v systemctl >/dev/null 2>&1; then
        start_or_restart_chromium_kiosk_service || start_kiosk_mode
    else
        start_kiosk_mode
    fi
fi

# =============================================================================
# End of Script
# =============================================================================

echo ""
print_info "EduDisplej init script completed."
exit 0
