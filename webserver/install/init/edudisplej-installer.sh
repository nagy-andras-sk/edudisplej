#!/bin/bash
# edudisplej-installer.sh - Csomagok telepítése -- Inštalácia balíčkov
# =============================================================================

# Kezdeti beállítások -- Počiatočné nastavenia
EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
APT_LOG="${EDUDISPLEJ_HOME}/apt.log"

# Közös függvények betöltése -- Načítanie spoločných funkcií
source "${INIT_DIR}/common.sh"

# Globális változók -- Globálne premenné
APT_UPDATED=false

# =============================================================================
# Csomagok ellenőrzése és telepítése -- Kontrola a inštalácia balíčkov
# =============================================================================

# Szükséges csomagok telepítése -- Inštalácia potrebných balíčkov
install_required_packages() {
    local packages=("$@")
    local missing=()
    local still_missing=()

    # Csomagok ellenőrzése -- Kontrola balíčkov
    print_info "Ellenőrizzük a csomagokat -- Kontrolujeme balíčky..."
    for pkg in "${packages[@]}"; do
        if dpkg -s "$pkg" >/dev/null 2>&1; then
            print_success "${pkg} ✓"
        else
            missing+=("$pkg")
            print_warning "${pkg} hiányzik -- chýba"
        fi
    done

    if [[ ${#missing[@]} -eq 0 ]]; then
        print_success "Minden csomag telepítve van -- Všetky balíčky sú nainštalované"
        return 0
    fi

    # Internet ellenőrzése -- Kontrola internetu
    if [[ "${INTERNET_AVAILABLE:-1}" -ne 0 ]]; then
        print_warning "Nincs internet kapcsolat -- Žiadne internetové pripojenie"
        print_warning "Hiányzó csomagok -- Chýbajúce balíčky: ${missing[*]}"
        return 1
    fi

    # Telepítő banner megjelenítése -- Zobrazenie inštalačného bannera
    show_installer_banner
    
    local total_steps=$((1 + ${#missing[@]}))  # 1 az apt-get update-hez + csomagok
    local current_step=0
    local start_time=$(date +%s)
    
    echo ""
    print_info "Telepítés -- Inštalácia: ${#missing[@]} csomag -- balíček"
    echo ""

    # Csomaglista frissítése -- Aktualizácia zoznamu balíčkov
    if [[ "$APT_UPDATED" == false ]]; then
        ((current_step++))
        show_progress_bar $current_step $total_steps "Csomaglista frissítése -- Aktualizácia zoznamu..." $start_time
        
        # APT frissítés újrapróbálással -- APT aktualizácia s opakovaním
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
            print_error "APT frissítés sikertelen -- APT aktualizácia zlyhala"
            return 1
        fi
        APT_UPDATED=true
    fi

    # Csomagok telepítése egyenként -- Inštalácia balíčkov po jednom
    local installed_count=0
    for pkg in "${missing[@]}"; do
        ((current_step++))
        
        # Részletes információ az aktuális folyamatról -- Podrobné informácie o aktuálnom procese
        show_progress_bar $current_step $total_steps "Telepítés -- Inštalujem: $pkg" $start_time
        
        # Csomag telepítése -- Inštalácia balíčka
        # APT kimenet megjelenítése a felhasználónak -- Zobrazenie výstupu APT používateľovi
        echo ""
        echo "► Folyamat -- Proces: apt-get install $pkg"
        if DEBIAN_FRONTEND=noninteractive apt-get install -y "$pkg" 2>&1 | tee -a "$APT_LOG" | grep -E "(Reading|Building|Unpacking|Setting up|Processing)" || true; then
            ((installed_count++))
            echo "✓ Sikeres -- Úspešné: $pkg"
        else
            # Újrapróbálás -- Opakovanie
            echo "⟳ Újrapróbálás -- Opakovanie: $pkg"
            sleep 2
            if DEBIAN_FRONTEND=noninteractive apt-get install -y "$pkg" 2>&1 | tee -a "$APT_LOG" | grep -E "(Reading|Building|Unpacking|Setting up|Processing)" || true; then
                ((installed_count++))
                echo "✓ Sikeres -- Úspešné: $pkg"
            else
                echo "✗ Sikertelen -- Zlyhalo: $pkg"
            fi
        fi
        echo ""
    done
    
    echo ""

    # Telepítés ellenőrzése -- Kontrola inštalácie
    for pkg in "${missing[@]}"; do
        if ! dpkg -s "$pkg" >/dev/null 2>&1; then
            still_missing+=("$pkg")
            print_error "Nem sikerült telepíteni -- Nepodarilo sa nainštalovať: ${pkg}"
        fi
    done

    if [[ ${#still_missing[@]} -eq 0 ]]; then
        print_success "Telepítés sikeres -- Inštalácia úspešná: ${installed_count}/${#missing[@]}"
        return 0
    else
        print_error "Néhány csomag telepítése sikertelen -- Niektoré balíčky sa nepodarilo nainštalovať"
        print_error "Még hiányzik -- Stále chýba: ${still_missing[*]}"
        return 1
    fi
}

# =============================================================================
# Böngésző telepítése -- Inštalácia prehliadača
# =============================================================================

install_browser() {
    local browser_name="$1"
    
    # Böngésző ellenőrzése -- Kontrola prehliadača
    if command -v "$browser_name" >/dev/null 2>&1; then
        print_success "Böngésző telepítve -- Prehliadač nainštalovaný: ${browser_name}"
        return 0
    fi

    # Internet ellenőrzése -- Kontrola internetu
    if [[ "${INTERNET_AVAILABLE:-1}" -ne 0 ]]; then
        print_error "Nincs böngésző és nincs internet -- Žiadny prehliadač a žiadny internet"
        return 1
    fi

    # APT frissítés ha szükséges -- APT aktualizácia ak je potrebná
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
            print_error "APT frissítés sikertelen -- APT aktualizácia zlyhala"
            return 1
        fi
        APT_UPDATED=true
    fi

    # Telepítő banner -- Banner inštalátora
    show_installer_banner
    
    local start_time=$(date +%s)
    echo ""
    print_info "Böngésző telepítése -- Inštalácia prehliadača: ${browser_name}"
    echo ""
    
    show_progress_bar 0 1 "Letöltés és telepítés -- Sťahovanie a inštalácia ${browser_name}..." $start_time
    
    # Telepítés újrapróbálással -- Inštalácia s opakovaním
    echo ""
    echo "► Folyamat -- Proces: apt-get install $browser_name"
    local install_success=false
    for attempt in 1 2; do
        if DEBIAN_FRONTEND=noninteractive apt-get install -y "$browser_name" 2>&1 | tee -a "$APT_LOG" | grep -E "(Reading|Building|Unpacking|Setting up|Processing)" || true; then
            install_success=true
            break
        else
            if [[ $attempt -lt 2 ]]; then
                echo "⟳ Újrapróbálás -- Opakovanie..."
                sleep 10
            fi
        fi
    done
    
    show_progress_bar 1 1 "Kész -- Hotovo: ${browser_name}" $start_time
    echo ""
    echo ""
    
    if [[ "$install_success" == true ]] && command -v "$browser_name" >/dev/null 2>&1; then
        print_success "Böngésző telepítve -- Prehliadač nainštalovaný: ${browser_name}"
        return 0
    else
        print_error "Böngésző telepítése sikertelen -- Inštalácia prehliadača zlyhala: ${browser_name}"
        return 1
    fi
}

# =============================================================================
# Kiosk csomagok telepítése -- Inštalácia kiosk balíčkov
# =============================================================================

install_kiosk_packages() {
    local kiosk_mode="${1:-chromium}"
    local packages=()
    local configured_file="${EDUDISPLEJ_HOME}/.kiosk_configured"
    
    # Ha már konfigurálva van, kihagyás -- Ak už je nakonfigurované, preskočenie
    if [[ -f "$configured_file" ]]; then
        print_info "Kiosk csomagok már telepítve -- Kiosk balíčky už nainštalované"
        return 0
    fi
    
    print_info "Kiosk csomagok telepítése -- Inštalácia kiosk balíčkov: $kiosk_mode"
    
    # Általános csomagok -- Všeobecné balíčky
    packages+=("xterm" "xdotool" "figlet" "dbus-x11")
    
    # Böngésző mód alapján -- Podľa módu prehliadača
    if [[ "$kiosk_mode" = "epiphany" ]]; then
        packages+=("epiphany-browser")
        print_info "Epiphany böngésző ARMv6-hoz -- Prehliadač Epiphany pre ARMv6..."
    else
        print_info "Chromium böngésző külön telepítve lesz -- Prehliadač Chromium bude nainštalovaný samostatne..."
    fi
    
    # Csomagok telepítése -- Inštalácia balíčkov
    if install_required_packages "${packages[@]}"; then
        touch "$configured_file"
        print_success "Kiosk csomagok telepítve -- Kiosk balíčky nainštalované"
        return 0
    else
        print_error "Kiosk csomagok telepítése részben sikertelen -- Inštalácia kiosk balíčkov čiastočne zlyhala"
        return 1
    fi
}

# Export függvények -- Export funkcií
export -f install_required_packages
export -f install_browser
export -f install_kiosk_packages
