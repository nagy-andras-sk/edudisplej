#!/bin/bash
# edudisplej-installer.sh - Instalacia balickov / Csomagok telepitese

# Zakladne nastavenia / Alapbeallitasok
EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
DATA_DIR="${EDUDISPLEJ_HOME}/data"
PACKAGES_JSON="${DATA_DIR}/packages.json"
APT_LOG="${EDUDISPLEJ_HOME}/apt.log"

source "${INIT_DIR}/common.sh"

APT_UPDATED=false

# Sledovanie balickov / Csomagok nyomkovetese
ensure_data_directory() {
    if [[ ! -d "$DATA_DIR" ]]; then
        mkdir -p "$DATA_DIR" || {
            print_error "Nepodarilo sa vytvorit data adresar"
            return 1
        }
    fi
    return 0
}

check_packages_installed() {
    local package_group="$1"
    ensure_data_directory || return 1
    
    if [[ -f "$PACKAGES_JSON" ]] && command -v jq >/dev/null 2>&1; then
        if jq -e ".packages.\"$package_group\".installed == true" "$PACKAGES_JSON" >/dev/null 2>&1; then
            return 0
        fi
    fi
    
    local marker_file="${DATA_DIR}/.installed_${package_group}"
    if [[ -f "$marker_file" ]]; then
        return 0
    fi
    
    local tracking_file="${DATA_DIR}/installed_packages.txt"
    if [[ -f "$tracking_file" ]] && grep -q "^${package_group}|" "$tracking_file" 2>/dev/null; then
        return 0
    fi
    
    return 1
}

record_package_installation() {
    local package_group="$1"
    shift
    local packages=("$@")
    
    ensure_data_directory || return 1
    
    local timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    local versions=""
    
    for pkg in "${packages[@]}"; do
        if dpkg -s "$pkg" >/dev/null 2>&1; then
            local version=$(dpkg -s "$pkg" 2>/dev/null | grep "^Version:" | cut -d' ' -f2)
            if [[ -n "$version" ]]; then
                versions="${versions}\"$pkg\": \"$version\", "
            fi
        fi
    done
    versions="${versions%, }"
    
    if [[ ! -f "$PACKAGES_JSON" ]]; then
        cat > "$PACKAGES_JSON" << EOF
{
  "packages": {},
  "last_update": "$timestamp"
}
EOF
    fi
    
    if command -v jq >/dev/null 2>&1; then
        local temp_file="${PACKAGES_JSON}.tmp"
        jq --arg group "$package_group" \
           --arg date "$timestamp" \
           --argjson versions "{$versions}" \
           '.packages[$group] = {installed: true, date: $date, versions: $versions} | .last_update = $date' \
           "$PACKAGES_JSON" > "$temp_file" && mv "$temp_file" "$PACKAGES_JSON"
    else
        local marker_file="${DATA_DIR}/.installed_${package_group}"
        echo "$timestamp" > "$marker_file"
        local tracking_file="${DATA_DIR}/installed_packages.txt"
        echo "${package_group}|${timestamp}|${packages[*]}" >> "$tracking_file"
        print_warning "jq nie je k dispozicii, pouziva sa zjednodusene sledovanie"
    fi
    
    print_success "Zaznamena instalacia balickov: $package_group"
    return 0
}

# Instalacia balickov / Csomagok telepitese
install_required_packages() {
    local packages=("$@")
    local missing=()
    local still_missing=()

    print_info "Kontrolujeme balicky..."
    local installed_packages
    if ! installed_packages=$(dpkg-query -W -f='${Package}\n' 2>/dev/null); then
        print_warning "dpkg-query zlyhal, pouzivam dpkg fallback"
        for pkg in "${packages[@]}"; do
            if dpkg -s "$pkg" >/dev/null 2>&1; then
                print_success "${pkg} ✓"
            else
                missing+=("$pkg")
                print_warning "${pkg} chyba"
            fi
        done
    else
        for pkg in "${packages[@]}"; do
            if echo "$installed_packages" | grep -q "^${pkg}$"; then
                print_success "${pkg} ✓"
            else
                missing+=("$pkg")
                print_warning "${pkg} chyba"
            fi
        done
    fi

    if [[ ${#missing[@]} -eq 0 ]]; then
        print_success "Vsetky balicky su nainstalovane"
        return 0
    fi

    if [[ "${INTERNET_AVAILABLE:-1}" -ne 0 ]]; then
        print_warning "Ziadne internetove pripojenie"
        print_warning "Chybajuce balicky: ${missing[*]}"
        return 1
    fi

    show_installer_banner
    
    local total_steps=$((1 + ${#missing[@]}))
    local current_step=0
    local start_time=$(date +%s)
    
    echo ""
    print_info "Instalacia: ${#missing[@]} balicek"
    echo ""

    if [[ "$APT_UPDATED" == false ]]; then
        ((current_step++))
        show_progress_bar $current_step $total_steps "Aktualizacia zoznamu..." $start_time
        
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
            print_error "APT aktualizacia zlyhala"
            return 1
        fi
        APT_UPDATED=true
    fi

    local installed_count=0
    for pkg in "${missing[@]}"; do
        ((current_step++))
        show_progress_bar $current_step $total_steps "Instalujem: $pkg" $start_time
        
        echo ""
        echo "► Proces: apt-get install $pkg"
        if DEBIAN_FRONTEND=noninteractive apt-get install -y "$pkg" 2>&1 | tee -a "$APT_LOG" | grep -E "(Reading|Building|Unpacking|Setting up|Processing)" || true; then
            ((installed_count++))
            echo "✓ Uspesne: $pkg"
        else
            echo "⟳ Opakovanie: $pkg"
            sleep 2
            if DEBIAN_FRONTEND=noninteractive apt-get install -y "$pkg" 2>&1 | tee -a "$APT_LOG" | grep -E "(Reading|Building|Unpacking|Setting up|Processing)" || true; then
                ((installed_count++))
                echo "✓ Uspesne: $pkg"
            else
                echo "✗ Zlyhalo: $pkg"
            fi
        fi
        echo ""
    done
    
    echo ""

    for pkg in "${missing[@]}"; do
        if ! dpkg -s "$pkg" >/dev/null 2>&1; then
            still_missing+=("$pkg")
            print_error "Nepodarilo sa nainstalovat: ${pkg}"
        fi
    done

    if [[ ${#still_missing[@]} -eq 0 ]]; then
        print_success "Instalacia uspesna: ${installed_count}/${#missing[@]}"
        record_package_installation "required_packages" "${packages[@]}"
        return 0
    else
        print_error "Niektore balicky sa nepodarilo nainstalovat"
        print_error "Stale chyba: ${still_missing[*]}"
        return 1
    fi
}

# Instalacia prehliadaca / Bongeszo telepitese
install_browser() {
    local browser_name="$1"
    
    if check_packages_installed "browser_${browser_name}"; then
        print_success "Prehliadac uz nainstalovany: ${browser_name}"
        return 0
    fi
    
    if command -v "$browser_name" >/dev/null 2>&1; then
        print_success "Prehliadac nainstalovany: ${browser_name}"
        record_package_installation "browser_${browser_name}" "$browser_name"
        return 0
    fi

    if [[ "${INTERNET_AVAILABLE:-1}" -ne 0 ]]; then
        print_error "Ziadny prehliadac a ziadny internet"
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
            print_error "APT aktualizacia zlyhala"
            return 1
        fi
        APT_UPDATED=true
    fi

    show_installer_banner
    
    local start_time=$(date +%s)
    echo ""
    print_info "Instalacia prehliadaca: ${browser_name}"
    echo ""
    
    show_progress_bar 0 1 "Stahovanie a instalacia ${browser_name}..." $start_time
    
    echo ""
    echo "► Proces: apt-get install $browser_name"
    local install_success=false
    for attempt in 1 2; do
        if DEBIAN_FRONTEND=noninteractive apt-get install -y "$browser_name" 2>&1 | tee -a "$APT_LOG" | grep -E "(Reading|Building|Unpacking|Setting up|Processing)" || true; then
            install_success=true
            break
        else
            if [[ $attempt -lt 2 ]]; then
                echo "⟳ Opakovanie..."
                sleep 10
            fi
        fi
    done
    
    show_progress_bar 1 1 "Hotovo: ${browser_name}" $start_time
    echo ""
    echo ""
    
    if [[ "$install_success" == true ]] && command -v "$browser_name" >/dev/null 2>&1; then
        print_success "Prehliadac nainstalovany: ${browser_name}"
        record_package_installation "browser_${browser_name}" "$browser_name"
        return 0
    else
        print_error "Instalacia prehliadaca zlyhala: ${browser_name}"
        return 1
    fi
}

# Instalacia kiosk balickov / Kiosk csomagok telepitese
install_kiosk_packages() {
    local kiosk_mode="${1:-chromium}"
    local packages=()
    local configured_file="${EDUDISPLEJ_HOME}/.kiosk_configured"
    
    packages+=("xterm" "xdotool" "figlet" "dbus-x11")
    
    if [[ "$kiosk_mode" = "epiphany" ]]; then
        packages+=("epiphany-browser")
        print_info "Epiphany prehliadac pre ARMv6..."
    else
        print_info "Chromium prehliadac bude nainstalovany samostatne..."
    fi
    
    if check_packages_installed "kiosk_${kiosk_mode}"; then
        print_info "Kiosk balicky uz nainstalovane"
        return 0
    fi
    
    if [[ -f "$configured_file" ]]; then
        print_info "Kiosk balicky uz nainstalovane"
        record_package_installation "kiosk_${kiosk_mode}" "${packages[@]}"
        return 0
    fi
    
    print_info "Instalacia kiosk balickov: $kiosk_mode"
    
    if install_required_packages "${packages[@]}"; then
        touch "$configured_file"
        print_success "Kiosk balicky nainstalovane"
        record_package_installation "kiosk_${kiosk_mode}" "${packages[@]}"
        return 0
    else
        print_error "Instalacia kiosk balickov ciastocne zlyhala"
        return 1
    fi
}

export -f install_required_packages
export -f install_browser
export -f install_kiosk_packages
