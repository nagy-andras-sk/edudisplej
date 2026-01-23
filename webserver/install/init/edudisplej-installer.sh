#!/bin/bash
# edudisplej-installer.sh - Csomagok telepitese -- Instalacia balickov
# =============================================================================

# Kezdeti beallitasok -- Pociatocne nastavenia
EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
DATA_DIR="${EDUDISPLEJ_HOME}/data"
PACKAGES_JSON="${DATA_DIR}/packages.json"
APT_LOG="${EDUDISPLEJ_HOME}/apt.log"

# Kozos fuggvenyek betoltese -- Nacitanie spolocnych funkcii
source "${INIT_DIR}/common.sh"

# Globalis valtozok -- Globalne premenne
APT_UPDATED=false

# =============================================================================
# Package tracking functions -- Funkcie sledovania balickov
# =============================================================================

# Ensure data directory exists
ensure_data_directory() {
    if [[ ! -d "$DATA_DIR" ]]; then
        mkdir -p "$DATA_DIR" || {
            print_error "Nepodarilo sa vytvorit data adresar -- Failed to create data directory"
            return 1
        }
    fi
    return 0
}

# Check if packages are already installed (tracked in packages.json)
check_packages_installed() {
    local package_group="$1"
    
    ensure_data_directory || return 1
    
    # Try jq-based check first
    if [[ -f "$PACKAGES_JSON" ]] && command -v jq >/dev/null 2>&1; then
        if jq -e ".packages.\"$package_group\".installed == true" "$PACKAGES_JSON" >/dev/null 2>&1; then
            return 0  # Already installed
        fi
    fi
    
    # Fallback: check marker file
    local marker_file="${DATA_DIR}/.installed_${package_group}"
    if [[ -f "$marker_file" ]]; then
        return 0  # Already installed
    fi
    
    # Fallback: simple grep check on tracking file
    local tracking_file="${DATA_DIR}/installed_packages.txt"
    if [[ -f "$tracking_file" ]] && grep -q "^${package_group}|" "$tracking_file" 2>/dev/null; then
        return 0  # Assume installed
    fi
    
    return 1  # Not installed
}

# Record successful package installation
record_package_installation() {
    local package_group="$1"
    shift
    local packages=("$@")
    
    ensure_data_directory || return 1
    
    local timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    local versions=""
    
    # Get package versions
    for pkg in "${packages[@]}"; do
        if dpkg -s "$pkg" >/dev/null 2>&1; then
            local version=$(dpkg -s "$pkg" 2>/dev/null | grep "^Version:" | cut -d' ' -f2)
            if [[ -n "$version" ]]; then
                versions="${versions}\"$pkg\": \"$version\", "
            fi
        fi
    done
    versions="${versions%, }"  # Remove trailing comma
    
    # Create or update packages.json
    if [[ ! -f "$PACKAGES_JSON" ]]; then
        cat > "$PACKAGES_JSON" << EOF
{
  "packages": {},
  "last_update": "$timestamp"
}
EOF
    fi
    
    # Use jq if available for proper JSON manipulation
    if command -v jq >/dev/null 2>&1; then
        local temp_file="${PACKAGES_JSON}.tmp"
        jq --arg group "$package_group" \
           --arg date "$timestamp" \
           --argjson versions "{$versions}" \
           '.packages[$group] = {installed: true, date: $date, versions: $versions} | .last_update = $date' \
           "$PACKAGES_JSON" > "$temp_file" && mv "$temp_file" "$PACKAGES_JSON"
    else
        # Fallback: Simple append-only approach (creates a marker file per package group)
        # This is simpler and more reliable than trying to parse/merge JSON without jq
        local marker_file="${DATA_DIR}/.installed_${package_group}"
        echo "$timestamp" > "$marker_file"
        
        # Also update a simple text-based tracking file
        local tracking_file="${DATA_DIR}/installed_packages.txt"
        echo "${package_group}|${timestamp}|${packages[*]}" >> "$tracking_file"
        
        print_warning "jq nie je k dispozicii, pouziva sa zjednodusene sledovanie -- jq not available, using simplified tracking"
    fi
    
    print_success "Zaznamena instalacia balickov: $package_group"
    return 0
}

# =============================================================================
# Csomagok ellenorzese es telepitese -- Kontrola a instalacia balickov
# =============================================================================

# Szukseges csomagok telepitese -- Instalacia potrebnych balickov
install_required_packages() {
    local packages=("$@")
    local missing=()
    local still_missing=()

    # Csomagok ellenorzese -- Kontrola balickov
    # Optimized: get all installed packages in one call
    print_info "Ellenorizzuk a csomagokat -- Kontrolujeme balicky..."
    local installed_packages
    # Use dpkg-query with error handling
    if ! installed_packages=$(dpkg-query -W -f='${Package}\n' 2>/dev/null); then
        # Fallback to dpkg if dpkg-query fails
        print_warning "dpkg-query zlyhal, pouzivam dpkg fallback -- dpkg-query failed, using dpkg fallback"
        for pkg in "${packages[@]}"; do
            if dpkg -s "$pkg" >/dev/null 2>&1; then
                print_success "${pkg} ✓"
            else
                missing+=("$pkg")
                print_warning "${pkg} hianzik -- chyba"
            fi
        done
    else
        # dpkg-query succeeded, use optimized approach
        for pkg in "${packages[@]}"; do
            if echo "$installed_packages" | grep -q "^${pkg}$"; then
                print_success "${pkg} ✓"
            else
                missing+=("$pkg")
                print_warning "${pkg} hianzik -- chyba"
            fi
        done
    fi

    if [[ ${#missing[@]} -eq 0 ]]; then
        print_success "Minden csomag telepitve van -- Vsetky balicky su nainstalovane"
        return 0
    fi

    # Internet ellenorzese -- Kontrola internetu
    if [[ "${INTERNET_AVAILABLE:-1}" -ne 0 ]]; then
        print_warning "Nincs internet kapcsolat -- Ziadne internetove pripojenie"
        print_warning "Hianyzo csomagok -- Chybajuce balicky: ${missing[*]}"
        return 1
    fi

    # Telepito banner megjelenitese -- Zobrazenie instalacneho bannera
    show_installer_banner
    
    local total_steps=$((1 + ${#missing[@]}))  # 1 az apt-get update-hez + csomagok
    local current_step=0
    local start_time=$(date +%s)
    
    echo ""
    print_info "Telepites -- Instalacia: ${#missing[@]} csomag -- balicek"
    echo ""

    # Csomaglista frissitese -- Aktualizacia zoznamu balickov
    if [[ "$APT_UPDATED" == false ]]; then
        ((current_step++))
        show_progress_bar $current_step $total_steps "Csomaglista frissitese -- Aktualizacia zoznamu..." $start_time
        
        # APT frissites ujraprobalassal -- APT aktualizacia s opakovanim
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
            print_error "APT frissites sikertelen -- APT aktualizacia zlyhala"
            return 1
        fi
        APT_UPDATED=true
    fi

    # Csomagok telepitese egyenkent -- Instalacia balickov po jednom
    local installed_count=0
    for pkg in "${missing[@]}"; do
        ((current_step++))
        
        # Reszletes informacio az aktualis folyamatrol -- Podrobne informacie o aktualnom procese
        show_progress_bar $current_step $total_steps "Telepites -- Instalujem: $pkg" $start_time
        
        # Csomag telepitese -- Instalacia balicka
        # APT kimenet megjelenitese a felhasznalonak -- Zobrazenie vystupu APT pouzivatelovi
        echo ""
        echo "► Folyamat -- Proces: apt-get install $pkg"
        if DEBIAN_FRONTEND=noninteractive apt-get install -y "$pkg" 2>&1 | tee -a "$APT_LOG" | grep -E "(Reading|Building|Unpacking|Setting up|Processing)" || true; then
            ((installed_count++))
            echo "✓ Sikeres -- Uspesne: $pkg"
        else
            # Ujraprobalas -- Opakovanie
            echo "⟳ Ujraprobalas -- Opakovanie: $pkg"
            sleep 2
            if DEBIAN_FRONTEND=noninteractive apt-get install -y "$pkg" 2>&1 | tee -a "$APT_LOG" | grep -E "(Reading|Building|Unpacking|Setting up|Processing)" || true; then
                ((installed_count++))
                echo "✓ Sikeres -- Uspesne: $pkg"
            else
                echo "✗ Sikertelen -- Zlyhalo: $pkg"
            fi
        fi
        echo ""
    done
    
    echo ""

    # Telepites ellenorzese -- Kontrola instalacie
    for pkg in "${missing[@]}"; do
        if ! dpkg -s "$pkg" >/dev/null 2>&1; then
            still_missing+=("$pkg")
            print_error "Nem sikerult telepiteni -- Nepodarilo sa nainštalovat: ${pkg}"
        fi
    done

    if [[ ${#still_missing[@]} -eq 0 ]]; then
        print_success "Telepites sikeres -- Instalacia uspesna: ${installed_count}/${#missing[@]}"
        # Record successful installation
        record_package_installation "required_packages" "${packages[@]}"
        return 0
    else
        print_error "Nehany csomag telepitese sikertelen -- Niektore balicky sa nepodarilo nainštalovat"
        print_error "Meg hianzik -- Stale chyba: ${still_missing[*]}"
        return 1
    fi
}

# =============================================================================
# Bongeszo telepitese -- Instalacia prehliadaca
# =============================================================================

install_browser() {
    local browser_name="$1"
    
    # Check if already installed and recorded
    if check_packages_installed "browser_${browser_name}"; then
        print_success "Bongeszo mar telepitve -- Prehliadac uz nainstalovany: ${browser_name}"
        return 0
    fi
    
    # Bongeszo ellenorzese -- Kontrola prehliadaca
    if command -v "$browser_name" >/dev/null 2>&1; then
        print_success "Bongeszo telepitve -- Prehliadac nainstalovany: ${browser_name}"
        record_package_installation "browser_${browser_name}" "$browser_name"
        return 0
    fi

    # Internet ellenorzese -- Kontrola internetu
    if [[ "${INTERNET_AVAILABLE:-1}" -ne 0 ]]; then
        print_error "Nincs bongeszo es nincs internet -- Ziadny prehliadac a ziadny internet"
        return 1
    fi

    # APT frissites ha szukseges -- APT aktualizacia ak je potrebna
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
            print_error "APT frissites sikertelen -- APT aktualizacia zlyhala"
            return 1
        fi
        APT_UPDATED=true
    fi

    # Telepito banner -- Banner instalatora
    show_installer_banner
    
    local start_time=$(date +%s)
    echo ""
    print_info "Bongeszo telepitese -- Instalacia prehliadaca: ${browser_name}"
    echo ""
    
    show_progress_bar 0 1 "Letoltes es telepites -- Stahovanie a instalacia ${browser_name}..." $start_time
    
    # Telepites ujraprobalassal -- Instalacia s opakovanim
    echo ""
    echo "► Folyamat -- Proces: apt-get install $browser_name"
    local install_success=false
    for attempt in 1 2; do
        if DEBIAN_FRONTEND=noninteractive apt-get install -y "$browser_name" 2>&1 | tee -a "$APT_LOG" | grep -E "(Reading|Building|Unpacking|Setting up|Processing)" || true; then
            install_success=true
            break
        else
            if [[ $attempt -lt 2 ]]; then
                echo "⟳ Ujraprobalas -- Opakovanie..."
                sleep 10
            fi
        fi
    done
    
    show_progress_bar 1 1 "Kesz -- Hotovo: ${browser_name}" $start_time
    echo ""
    echo ""
    
    if [[ "$install_success" == true ]] && command -v "$browser_name" >/dev/null 2>&1; then
        print_success "Bongeszo telepitve -- Prehliadac nainstalovany: ${browser_name}"
        record_package_installation "browser_${browser_name}" "$browser_name"
        return 0
    else
        print_error "Bongeszo telepitese sikertelen -- Instalacia prehliadaca zlyhala: ${browser_name}"
        return 1
    fi
}

# =============================================================================
# Kiosk csomagok telepitese -- Instalacia kiosk balickov
# =============================================================================

install_kiosk_packages() {
    local kiosk_mode="${1:-chromium}"
    local packages=()
    local configured_file="${EDUDISPLEJ_HOME}/.kiosk_configured"
    
    # Altalanos csomagok -- Vseobecne balicky
    packages+=("xterm" "xdotool" "figlet" "dbus-x11")
    
    # Bongeszo mod alapjan -- Podla modu prehliadaca
    if [[ "$kiosk_mode" = "epiphany" ]]; then
        packages+=("epiphany-browser")
        print_info "Epiphany bongeszo ARMv6-hoz -- Prehliadac Epiphany pre ARMv6..."
    else
        print_info "Chromium bongeszo kulon telepitve lesz -- Prehliadac Chromium bude nainstalovany samostatne..."
    fi
    
    # Check if already installed and recorded
    if check_packages_installed "kiosk_${kiosk_mode}"; then
        print_info "Kiosk csomagok mar telepitve -- Kiosk balicky uz nainstalovane"
        return 0
    fi
    
    # Ha mar konfiguralva van, kihagyas -- Ak uz je nakonfigurovane, preskocenie
    if [[ -f "$configured_file" ]]; then
        print_info "Kiosk csomagok mar telepitve -- Kiosk balicky uz nainstalovane"
        record_package_installation "kiosk_${kiosk_mode}" "${packages[@]}"
        return 0
    fi
    
    print_info "Kiosk csomagok telepitese -- Instalacia kiosk balickov: $kiosk_mode"
    
    # Csomagok telepitese -- Instalacia balickov
    if install_required_packages "${packages[@]}"; then
        touch "$configured_file"
        print_success "Kiosk csomagok telepitve -- Kiosk balicky nainstalovane"
        record_package_installation "kiosk_${kiosk_mode}" "${packages[@]}"
        return 0
    else
        print_error "Kiosk csomagok telepitese reszben sikertelen -- Instalacia kiosk balickov ciastocne zlyhala"
        return 1
    fi
}

# Export fuggvenyek -- Export funkcii
export -f install_required_packages
export -f install_browser
export -f install_kiosk_packages
