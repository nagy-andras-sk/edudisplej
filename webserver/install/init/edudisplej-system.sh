#!/bin/bash
# edudisplej-system.sh - Unified system initialization and management
# Combines functionality from edudisplej-checker.sh and edudisplej-installer.sh
# =============================================================================

set -euo pipefail

# Base settings
EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
DATA_DIR="${EDUDISPLEJ_HOME}/data"
PACKAGES_JSON="${DATA_DIR}/packages.json"
APT_LOG="${EDUDISPLEJ_HOME}/apt.log"

# Source common functions
source "${INIT_DIR}/common.sh"

APT_UPDATED=false
INSTALL_STATUS_FILE="${DATA_DIR}/install_status.json"
API_BASE_URL="${EDUDISPLEJ_API_URL:-https://control.edudisplej.sk}"
INSTALL_STATUS_API_URL="${INSTALL_STATUS_API_URL:-${API_BASE_URL}/api/install/progress.php}"
API_TOKEN_FILE="${EDUDISPLEJ_HOME}/lic/token"
KIOSK_CONF_FILE="${EDUDISPLEJ_HOME}/kiosk.conf"
KIOSK_DEVICE_ID=""
KIOSK_ID=""

# Global APT hardening options (non-interactive + config conflict safety)
APT_COMMON_OPTS=(
    "-y"
    "-o" "Dpkg::Use-Pty=0"
    "-o" "Dpkg::Options::=--force-confdef"
    "-o" "Dpkg::Options::=--force-confold"
    "-o" "Acquire::Retries=3"
    "-o" "Acquire::http::Timeout=30"
    "-o" "Acquire::https::Timeout=30"
)

# =============================================================================
# Install Progress / Status Reporting
# =============================================================================

json_escape() {
    echo "${1:-}" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

load_kiosk_identity() {
    if [[ -n "$KIOSK_DEVICE_ID" || -n "$KIOSK_ID" ]]; then
        return 0
    fi

    if [[ -f "$KIOSK_CONF_FILE" ]]; then
        KIOSK_DEVICE_ID="$(grep -E '^DEVICE_ID=' "$KIOSK_CONF_FILE" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '\r\n' || true)"
    fi

    local cfg_file="${DATA_DIR}/config.json"
    if [[ -f "$cfg_file" ]]; then
        if command -v jq >/dev/null 2>&1; then
            KIOSK_ID="$(jq -r '.kiosk_id // empty' "$cfg_file" 2>/dev/null || true)"
        else
            KIOSK_ID="$(grep -o '"kiosk_id"[[:space:]]*:[[:space:]]*[0-9]*' "$cfg_file" 2>/dev/null | head -1 | cut -d: -f2 | tr -d '[:space:]' || true)"
        fi
    fi
}

report_install_status() {
    local phase="${1:-unknown}"
    local step="${2:-0}"
    local total="${3:-0}"
    local state="${4:-running}"
    local message="${5:-}"
    local eta_seconds="${6:-null}"

    ensure_data_directory || true

    local percent=0
    if [[ "$total" -gt 0 ]]; then
        percent=$((step * 100 / total))
    fi

    local now_iso
    now_iso="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    local hostname
    hostname="$(hostname 2>/dev/null || echo unknown)"

    load_kiosk_identity

    local kiosk_id_json="null"
    if [[ -n "${KIOSK_ID:-}" ]] && [[ "${KIOSK_ID}" =~ ^[0-9]+$ ]]; then
        kiosk_id_json="${KIOSK_ID}"
    fi

    local msg_escaped
    msg_escaped="$(json_escape "$message")"

    cat > "$INSTALL_STATUS_FILE" <<EOF
{
  "type": "install_progress",
    "kiosk": {
        "device_id": "${KIOSK_DEVICE_ID}",
        "kiosk_id": ${kiosk_id_json},
        "hostname": "${hostname}"
    },
  "hostname": "${hostname}",
  "phase": "${phase}",
  "step": ${step},
  "total": ${total},
  "percent": ${percent},
  "state": "${state}",
  "message": "${msg_escaped}",
  "eta_seconds": ${eta_seconds},
  "updated_at": "${now_iso}"
}
EOF

    if [[ -n "$INSTALL_STATUS_API_URL" ]] && command -v curl >/dev/null 2>&1; then
        local auth_header=()
        if [[ -f "$API_TOKEN_FILE" ]]; then
            local token
            token="$(tr -d '\r\n' < "$API_TOKEN_FILE" 2>/dev/null || true)"
            if [[ -n "$token" ]]; then
                auth_header=( -H "Authorization: Bearer ${token}" )
            fi
        fi

        curl -sS --max-time 8 --connect-timeout 3 \
            -H "Content-Type: application/json" \
            "${auth_header[@]}" \
            -X POST "$INSTALL_STATUS_API_URL" \
            --data-binary @"$INSTALL_STATUS_FILE" >/dev/null 2>&1 || true
    fi
}

apt_exec_with_stream() {
    local timeout_seconds="$1"
    shift

    local status
    set +e
    timeout "${timeout_seconds}s" \
        env DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none NEEDRESTART_MODE=a UCF_FORCE_CONFFOLD=1 \
        apt-get "${APT_COMMON_OPTS[@]}" "$@" < /dev/null 2>&1 \
        | sed -u 's/^/[APT] /' \
        | tee -a "$APT_LOG"
    status=${PIPESTATUS[0]}
    set -e

    return "$status"
}

# =============================================================================
# Package Management Functions
# =============================================================================

ensure_data_directory() {
    if [[ ! -d "$DATA_DIR" ]]; then
        mkdir -p "$DATA_DIR" || {
            print_error "Failed to create data directory"
            return 1
        }
    fi
    return 0
}

check_required_packages() {
    local packages=("$@")
    local missing=()

    # Get all installed packages in one call for efficiency
    local installed_packages
    if ! installed_packages=$(dpkg-query -W -f='${Package}\n' 2>/dev/null); then
        # Fallback to individual dpkg calls if dpkg-query fails
        for pkg in "${packages[@]}"; do
            if ! dpkg -s "$pkg" >/dev/null 2>&1; then
                missing+=("$pkg")
            fi
        done
    else
        # Use optimized approach
        for pkg in "${packages[@]}"; do
            if ! echo "$installed_packages" | grep -q "^${pkg}$"; then
                missing+=("$pkg")
            fi
        done
    fi

    if [[ ${#missing[@]} -eq 0 ]]; then
        return 0
    else
        for pkg in "${missing[@]}"; do
            echo "$pkg"
        done
        return 1
    fi
}

check_browser() {
    local browser_name="$1"
    
    if command -v "$browser_name" >/dev/null 2>&1; then
        return 0
    else
        return 1
    fi
}

install_packages() {
    local packages=("$@")
    local missing=()
    local still_missing=()

    print_info "Checking packages..."
    local installed_packages
    if ! installed_packages=$(dpkg-query -W -f='${Package}\n' 2>/dev/null); then
        print_warning "dpkg-query failed, using dpkg fallback"
        for pkg in "${packages[@]}"; do
            if dpkg -s "$pkg" >/dev/null 2>&1; then
                print_success "${pkg} ✓"
            else
                missing+=("$pkg")
                print_warning "${pkg} missing"
            fi
        done
    else
        for pkg in "${packages[@]}"; do
            if echo "$installed_packages" | grep -q "^${pkg}$"; then
                print_success "${pkg} ✓"
            else
                missing+=("$pkg")
                print_warning "${pkg} missing"
            fi
        done
    fi

    if [[ ${#missing[@]} -eq 0 ]]; then
        print_success "All packages installed"
        return 0
    fi

    if [[ "${INTERNET_AVAILABLE:-1}" -ne 0 ]]; then
        print_warning "No internet connection"
        print_warning "Missing packages: ${missing[*]}"
        return 1
    fi

    show_installer_banner
    
    local total_steps=$((1 + ${#missing[@]}))
    local current_step=0
    local start_time=$(date +%s)
    
    echo ""
    print_info "Installing: ${#missing[@]} packages"
    echo ""

    if [[ "$APT_UPDATED" == false ]]; then
        ((current_step++))
        show_progress_bar $current_step $total_steps "Updating package list..." $start_time
        report_install_status "apt_update" "$current_step" "$total_steps" "running" "Updating package list"
        
        local apt_update_success=false
        for attempt in 1 2 3; do
            print_info "APT update attempt ${attempt}/3"
            if apt_exec_with_stream 900 update; then
                apt_update_success=true
                break
            else
                print_warning "APT update attempt ${attempt}/3 failed"
                if [[ $attempt -lt 3 ]]; then
                    sleep 5
                fi
            fi
        done
        
        if [[ "$apt_update_success" == false ]]; then
            echo ""
            print_error "APT update failed"
            report_install_status "apt_update" "$current_step" "$total_steps" "failed" "APT update failed"
            return 1
        fi
        report_install_status "apt_update" "$current_step" "$total_steps" "running" "APT update completed"
        APT_UPDATED=true
    fi

    local installed_count=0
    for pkg in "${missing[@]}"; do
        ((current_step++))
        local elapsed=$(( $(date +%s) - start_time ))
        local avg_per_step=0
        local remaining_steps=$((total_steps - current_step))
        local eta_seconds="null"
        if [[ $current_step -gt 0 ]] && [[ $elapsed -gt 0 ]]; then
            avg_per_step=$((elapsed / current_step))
            eta_seconds=$((avg_per_step * remaining_steps))
        fi

        show_progress_bar $current_step $total_steps "Installing: $pkg" $start_time
        report_install_status "install_package" "$current_step" "$total_steps" "running" "Installing package: $pkg" "$eta_seconds"
        
        echo ""
        echo "► Process: apt-get install $pkg"
        
        # Install package and capture result
        if apt_exec_with_stream 1200 install "$pkg"; then
            ((installed_count++))
            echo "✓ Success: $pkg"
            report_install_status "install_package" "$current_step" "$total_steps" "running" "Installed package: $pkg" "$eta_seconds"
        else
            echo "⟳ Retrying: $pkg"
            sleep 2
            if apt_exec_with_stream 1200 install "$pkg"; then
                ((installed_count++))
                echo "✓ Success: $pkg"
                report_install_status "install_package" "$current_step" "$total_steps" "running" "Installed package on retry: $pkg" "$eta_seconds"
            else
                echo "✗ Failed: $pkg"
                print_error "Installation failed for $pkg - check $APT_LOG for details"
                report_install_status "install_package" "$current_step" "$total_steps" "failed" "Failed package install: $pkg" "$eta_seconds"
            fi
        fi
        echo ""
    done
    
    echo ""

    for pkg in "${missing[@]}"; do
        if ! dpkg -s "$pkg" >/dev/null 2>&1; then
            still_missing+=("$pkg")
            print_error "Failed to install: ${pkg}"
        fi
    done

    if [[ ${#still_missing[@]} -eq 0 ]]; then
        print_success "Installation successful: ${installed_count}/${#missing[@]}"
        report_install_status "install_complete" "$total_steps" "$total_steps" "completed" "Installation successful: ${installed_count}/${#missing[@]}"
        return 0
    else
        print_error "Some packages failed to install"
        print_error "Still missing: ${still_missing[*]}"
        report_install_status "install_complete" "$current_step" "$total_steps" "failed" "Some packages failed: ${still_missing[*]}"
        return 1
    fi
}

# =============================================================================
# System Check Functions
# =============================================================================

check_kiosk_configuration() {
    local console_user="${1:-pi}"
    local user_home="${2:-/home/pi}"
    local kiosk_configured_file="${EDUDISPLEJ_HOME}/.kiosk_system_configured"
    
    local missing_configs=()
    
    if [[ ! -f "${user_home}/.xinitrc" ]]; then
        missing_configs+=(".xinitrc")
    fi
    
    if [[ ! -f "${user_home}/.config/openbox/autostart" ]]; then
        missing_configs+=("openbox/autostart")
    fi
    
    if [[ ! -f "$kiosk_configured_file" ]]; then
        missing_configs+=("system_configured_flag")
    fi

    if [[ ${#missing_configs[@]} -eq 0 ]]; then
        return 0
    else
        for cfg in "${missing_configs[@]}"; do
            echo "$cfg"
        done
        return 1
    fi
}

check_system_ready() {
    local kiosk_mode="${1:-surf}"
    local console_user="${2:-pi}"
    local user_home="${3:-/home/pi}"
    
    print_info "System check..."
    echo ""
    
    local all_ready=true
    
    # 1. Check base packages
    local required_packages=(openbox xinit unclutter curl x11-utils xserver-xorg)
    print_info "[1/3] Base packages..."
    if check_required_packages "${required_packages[@]}" >/dev/null 2>&1; then
        print_success "  ✓ Base packages OK"
    else
        print_warning "  ✗ Missing base packages"
        all_ready=false
    fi
    
    # 2. Check kiosk packages (surf browser only)
    local kiosk_packages=(xterm xdotool figlet dbus-x11 surf)
    print_info "[2/3] Kiosk packages..."
    if check_required_packages "${kiosk_packages[@]}" >/dev/null 2>&1; then
        print_success "  ✓ Kiosk packages OK"
    else
        print_warning "  ✗ Missing kiosk packages"
        all_ready=false
    fi
    
    # 3. Check kiosk configuration
    print_info "[3/3] Kiosk configuration..."
    if check_kiosk_configuration "$console_user" "$user_home" >/dev/null 2>&1; then
        print_success "  ✓ Kiosk configuration OK"
    else
        print_warning "  ✗ Kiosk configuration incomplete"
        all_ready=false
    fi
    
    echo ""
    
    if $all_ready; then
        print_success "System ready!"
        return 0
    else
        print_warning "System not fully ready"
        print_info "Installation/configuration needed"
        return 1
    fi
}

# Export functions
export -f check_required_packages
export -f check_browser
export -f check_kiosk_configuration
export -f check_system_ready
export -f install_packages
