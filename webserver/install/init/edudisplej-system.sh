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
            print_error "APT update failed"
            return 1
        fi
        APT_UPDATED=true
    fi

    local installed_count=0
    for pkg in "${missing[@]}"; do
        ((current_step++))
        show_progress_bar $current_step $total_steps "Installing: $pkg" $start_time
        
        echo ""
        echo "► Process: apt-get install $pkg"
        if DEBIAN_FRONTEND=noninteractive apt-get install -y "$pkg" 2>&1 | tee -a "$APT_LOG" | grep -E "(Reading|Building|Unpacking|Setting up|Processing)" || true; then
            ((installed_count++))
            echo "✓ Success: $pkg"
        else
            echo "⟳ Retrying: $pkg"
            sleep 2
            if DEBIAN_FRONTEND=noninteractive apt-get install -y "$pkg" 2>&1 | tee -a "$APT_LOG" | grep -E "(Reading|Building|Unpacking|Setting up|Processing)" || true; then
                ((installed_count++))
                echo "✓ Success: $pkg"
            else
                echo "✗ Failed: $pkg"
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
        return 0
    else
        print_error "Some packages failed to install"
        print_error "Still missing: ${still_missing[*]}"
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
    local kiosk_mode="${1:-chromium}"
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
    
    # 2. Check kiosk packages
    local kiosk_packages=(xterm xdotool figlet dbus-x11 surf)
    if [[ "$kiosk_mode" = "epiphany" ]]; then
        kiosk_packages+=("epiphany-browser")
    fi
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
