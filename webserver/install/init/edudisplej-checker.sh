#!/bin/bash
# edudisplej-checker.sh - Rendszer ellenőrzése -- Kontrola systému
# =============================================================================

# Kezdeti beállítások -- Počiatočné nastavenia
EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"

# Közös függvények betöltése -- Načítanie spoločných funkcií
source "${INIT_DIR}/common.sh"

# =============================================================================
# Csomagok ellenőrzése -- Kontrola balíčkov
# =============================================================================

# Szükséges csomagok ellenőrzése -- Kontrola potrebných balíčkov
check_required_packages() {
    local packages=("$@")
    local missing=()

    # Get all installed packages in one call for efficiency
    # This reduces the number of dpkg calls from N to 1
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
        return 0  # Minden csomag megvan -- Všetky balíčky sú nainštalované
    else
        # Hiányzó csomagok kiírása -- Výpis chýbajúcich balíčkov
        for pkg in "${missing[@]}"; do
            echo "$pkg"
        done
        return 1
    fi
}

# =============================================================================
# Böngésző ellenőrzése -- Kontrola prehliadača
# =============================================================================

check_browser() {
    local browser_name="$1"
    
    if command -v "$browser_name" >/dev/null 2>&1; then
        return 0  # Böngésző megvan -- Prehliadač je nainštalovaný
    else
        return 1  # Böngésző hiányzik -- Prehliadač chýba
    fi
}

# =============================================================================
# X környezet ellenőrzése -- Kontrola X prostredia
# =============================================================================

check_x_environment() {
    local required_x_packages=("xinit" "xserver-xorg" "openbox")
    
    # X csomagok ellenőrzése -- Kontrola X balíčkov
    local missing_x=()
    for pkg in "${required_x_packages[@]}"; do
        if ! dpkg -s "$pkg" >/dev/null 2>&1; then
            missing_x+=("$pkg")
        fi
    done

    if [[ ${#missing_x[@]} -eq 0 ]]; then
        return 0  # X környezet rendben -- X prostredie je v poriadku
    else
        return 1  # X környezet hiányos -- X prostredie nie je kompletné
    fi
}

# =============================================================================
# Kiosk konfiguráció ellenőrzése -- Kontrola konfigurácie kiosk
# =============================================================================

check_kiosk_configuration() {
    local console_user="${1:-pi}"
    local user_home="${2:-/home/pi}"
    local kiosk_configured_file="${EDUDISPLEJ_HOME}/.kiosk_system_configured"
    
    # Ellenőrizzük a konfigurációs fájlokat -- Kontrolujeme konfiguračné súbory
    local missing_configs=()
    
    # .xinitrc ellenőrzése -- Kontrola .xinitrc
    if [[ ! -f "${user_home}/.xinitrc" ]]; then
        missing_configs+=(".xinitrc")
    fi
    
    # Openbox autostart ellenőrzése -- Kontrola Openbox autostart
    if [[ ! -f "${user_home}/.config/openbox/autostart" ]]; then
        missing_configs+=("openbox/autostart")
    fi
    
    # kiosk-launcher.sh ellenőrzése -- Kontrola kiosk-launcher.sh
    if [[ ! -f "${user_home}/kiosk-launcher.sh" ]]; then
        missing_configs+=("kiosk-launcher.sh")
    fi
    
    # Konfigurációs flag ellenőrzése -- Kontrola konfiguračného flagu
    if [[ ! -f "$kiosk_configured_file" ]]; then
        missing_configs+=("system_configured_flag")
    fi

    if [[ ${#missing_configs[@]} -eq 0 ]]; then
        return 0  # Kiosk konfiguráció rendben -- Konfigurácia kiosk je v poriadku
    else
        # Hiányzó konfigurációk kiírása -- Výpis chýbajúcich konfigurácií
        for cfg in "${missing_configs[@]}"; do
            echo "$cfg"
        done
        return 1  # Kiosk konfiguráció hiányos -- Konfigurácia kiosk nie je kompletná
    fi
}

# =============================================================================
# Teljes rendszer ellenőrzése -- Kompletná kontrola systému
# =============================================================================

check_system_ready() {
    local kiosk_mode="${1:-chromium}"
    local console_user="${2:-pi}"
    local user_home="${3:-/home/pi}"
    
    print_info "Rendszer ellenőrzése -- Kontrola systému..."
    echo ""
    
    local all_ready=true
    
    # 1. Alapcsomagok ellenőrzése -- Kontrola základných balíčkov
    local required_packages=(openbox xinit unclutter curl x11-utils xserver-xorg)
    print_info "[1/4] Alapcsomagok -- Základné balíčky..."
    if check_required_packages "${required_packages[@]}" >/dev/null 2>&1; then
        print_success "  ✓ Alapcsomagok rendben -- Základné balíčky sú v poriadku"
    else
        print_warning "  ✗ Hiányzó alapcsomagok -- Chýbajúce základné balíčky"
        all_ready=false
    fi
    
    # 2. Kiosk csomagok ellenőrzése -- Kontrola kiosk balíčkov
    # Removed dbus-x11 (not needed for terminal-only mode)
    local kiosk_packages=(xterm xdotool figlet)
    print_info "[2/4] Kiosk csomagok -- Kiosk balíčky..."
    if check_required_packages "${kiosk_packages[@]}" >/dev/null 2>&1; then
        print_success "  ✓ Kiosk csomagok rendben -- Kiosk balíčky sú v poriadku"
    else
        print_warning "  ✗ Hiányzó kiosk csomagok -- Chýbajúce kiosk balíčky"
        all_ready=false
    fi
    
    # 3. Böngésző ellenőrzése -- Kontrola prehliadača
    # Browser check skipped - terminal-only mode doesn't need browser
    print_info "[3/4] Böngésző -- Prehliadač..."
    print_success "  ✓ Browser not needed for terminal-only mode"
    
    # 4. Kiosk konfiguráció ellenőrzése -- Kontrola konfigurácie kiosk
    print_info "[4/4] Kiosk konfiguráció -- Konfigurácia kiosk..."
    if check_kiosk_configuration "$console_user" "$user_home" >/dev/null 2>&1; then
        print_success "  ✓ Kiosk konfiguráció rendben -- Konfigurácia kiosk je v poriadku"
    else
        print_warning "  ✗ Kiosk konfiguráció hiányos -- Konfigurácia kiosk nie je kompletná"
        all_ready=false
    fi
    
    echo ""
    
    if $all_ready; then
        print_success "Rendszer kész az indításra -- Systém je pripravený na spustenie"
        return 0
    else
        print_warning "Rendszer nem teljesen kész -- Systém nie je úplne pripravený"
        print_info "Telepítés/konfiguráció szükséges -- Je potrebná inštalácia/konfigurácia"
        return 1
    fi
}

# Export függvények -- Export funkcií
export -f check_required_packages
export -f check_browser
export -f check_x_environment
export -f check_kiosk_configuration
export -f check_system_ready
