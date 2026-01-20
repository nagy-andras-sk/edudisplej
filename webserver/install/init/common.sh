#!/bin/bash
# common.sh - Shared functions, translations, and configuration
# All text is in Slovak (without diacritics) or English

# Directory paths
EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
CONFIG_FILE="${EDUDISPLEJ_HOME}/edudisplej.conf"
MODE_FILE="${EDUDISPLEJ_HOME}/.mode"

# Default values
DEFAULT_LANG="sk"
CURRENT_LANG="${DEFAULT_LANG}"
DEFAULT_KIOSK_URL="https://www.time.is"
# Fallback URLs if primary fails
FALLBACK_URLS=(
    "https://www.time.is"
    "file:///opt/edudisplej/localweb/clock.html"
    "about:blank"
)

# =============================================================================
# Translation System
# =============================================================================

# Translations array - Slovak without diacritics
declare -Ag TRANS_SK
TRANS_SK=(
    # Boot messages
    ["boot_starting"]="Spustanie EduDisplej systemu..."
    ["boot_loading_modules"]="Nacitavam moduly..."
    ["boot_hostname_check"]="Kontrolujem hostname..."
    ["boot_hostname_set"]="Hostname nastaveny na:"
    ["boot_waiting_network"]="Cakam na pripojenie k internetu..."
    ["boot_network_connected"]="Pripojenie k internetu uspesne!"
    ["boot_network_failed"]="Pripojenie k internetu zlyhalo!"
    ["boot_f12_prompt"]="Stlacte F12 pre vstup do konfiguracie (5 sekund)..."
    ["boot_starting_kiosk"]="Spustam kiosk rezim..."
    ["boot_loading_mode"]="Nacitavam ulozeny rezim..."

    # Menu
    ["menu_title"]="EduDisplej - Konfiguracne Menu"
    ["menu_select"]="Vyberte moznost:"
    ["menu_eduserver"]="EduServer rezim"
    ["menu_standalone"]="Samostatny rezim"
    ["menu_language"]="Jazyk"
    ["menu_display"]="Nastavenia displeja"
    ["menu_network"]="Nastavenia siete"
    ["menu_exit"]="Ukoncit"
    ["menu_invalid"]="Neplatna volba. Skuste znova."

    # Network
    ["network_wifi_setup"]="Nastavenie Wi-Fi"
    ["network_enter_ssid"]="Zadajte SSID siete:"
    ["network_enter_password"]="Zadajte heslo:"
    ["network_connecting"]="Pripajam sa k sieti..."
    ["network_connected"]="Uspesne pripojene!"
    ["network_failed"]="Pripojenie zlyhalo!"
    ["network_current_ip"]="Aktualna IP adresa:"
    ["network_static_ip"]="Nastavenie statickej IP"
    ["network_enter_ip"]="Zadajte IP adresu:"
    ["network_enter_gateway"]="Zadajte gateway:"
    ["network_enter_dns"]="Zadajte DNS server:"

    # Display
    ["display_settings"]="Nastavenia displeja"
    ["display_current_res"]="Aktualne rozlisenie:"
    ["display_select_res"]="Vyberte rozlisenie:"
    ["display_applied"]="Rozlisenie nastavene!"

    # Language
    ["language_select"]="Vyberte jazyk:"
    ["language_english"]="Anglictina"
    ["language_slovak"]="Slovencina"
    ["language_changed"]="Jazyk zmeneny!"

    # Kiosk
    ["kiosk_starting_x"]="Spustam X server..."
    ["kiosk_starting_browser"]="Spustam prehliadac chromium-browser..."
    ["kiosk_retry"]="Pokus o znovuspustenie..."
    ["kiosk_failed"]="Spustenie zlyhal po 3 pokusoch!"
    ["boot_pkg_check"]="Kontrolujem potrebne balicky..."
    ["boot_pkg_missing"]="Chybajuce balicky:"
    ["boot_pkg_ok"]="Vsetky balicky nainstalovane"
    ["boot_pkg_installing"]="Instalujem balicky:"
    ["boot_pkg_install_failed"]="Instalacia balickov zlyhala"
    ["boot_summary"]="Zhrnutie systemu pred startom"
    ["boot_countdown"]="Stlacte M alebo F12 pre menu"
    ["boot_version"]="Verzia:"
    ["boot_update_check"]="Kontrolujem aktualizacie..."
    ["boot_update_available"]="Nova verzia dostupna:"
    ["boot_update_downloading"]="Stahujem aktualizovane skripty..."
    ["boot_update_done"]="Aktualizacia dokoncena, restartujem skript..."
    ["boot_update_failed"]="Aktualizacia zlyhala"

    # General
    ["press_enter"]="Stlacte ENTER pre pokracovanie..."
    ["yes"]="ano"
    ["no"]="nie"
    ["error"]="Chyba"
    ["success"]="Uspech"
    ["warning"]="Upozornenie"
)

# Translations array - English
declare -Ag TRANS_EN
TRANS_EN=(
    # Boot messages
    ["boot_starting"]="Starting EduDisplej system..."
    ["boot_loading_modules"]="Loading modules..."
    ["boot_hostname_check"]="Checking hostname..."
    ["boot_hostname_set"]="Hostname set to:"
    ["boot_waiting_network"]="Waiting for internet connection..."
    ["boot_network_connected"]="Internet connection successful!"
    ["boot_network_failed"]="Internet connection failed!"
    ["boot_f12_prompt"]="Press F12 to enter configuration (5 seconds)..."
    ["boot_starting_kiosk"]="Starting kiosk mode..."
    ["boot_loading_mode"]="Loading saved mode..."

    # Menu
    ["menu_title"]="EduDisplej - Configuration Menu"
    ["menu_select"]="Select an option:"
    ["menu_eduserver"]="EduServer mode"
    ["menu_standalone"]="Standalone mode"
    ["menu_language"]="Language"
    ["menu_display"]="Display settings"
    ["menu_network"]="Network settings"
    ["menu_exit"]="Exit"
    ["menu_invalid"]="Invalid option. Try again."

    # Network
    ["network_wifi_setup"]="Wi-Fi Setup"
    ["network_enter_ssid"]="Enter network SSID:"
    ["network_enter_password"]="Enter password:"
    ["network_connecting"]="Connecting to network..."
    ["network_connected"]="Successfully connected!"
    ["network_failed"]="Connection failed!"
    ["network_current_ip"]="Current IP address:"
    ["network_static_ip"]="Static IP configuration"
    ["network_enter_ip"]="Enter IP address:"
    ["network_enter_gateway"]="Enter gateway:"
    ["network_enter_dns"]="Enter DNS server:"

    # Display
    ["display_settings"]="Display settings"
    ["display_current_res"]="Current resolution:"
    ["display_select_res"]="Select resolution:"
    ["display_applied"]="Resolution applied!"

    # Language
    ["language_select"]="Select language:"
    ["language_english"]="English"
    ["language_slovak"]="Slovak"
    ["language_changed"]="Language changed!"

    # Kiosk
    ["kiosk_starting_x"]="Starting X server..."
    ["kiosk_starting_browser"]="Starting chromium-browser..."
    ["kiosk_retry"]="Retrying..."
    ["kiosk_failed"]="Failed after 3 attempts!"
    ["boot_pkg_check"]="Checking required packages..."
    ["boot_pkg_missing"]="Missing packages:"
    ["boot_pkg_ok"]="All required packages installed"
    ["boot_pkg_installing"]="Installing packages:"
    ["boot_pkg_install_failed"]="Package installation failed"
    ["boot_summary"]="System summary before start"
    ["boot_countdown"]="Press M or F12 to enter menu"
    ["boot_version"]="Version:"
    ["boot_update_check"]="Checking for updates..."
    ["boot_update_available"]="New version available:"
    ["boot_update_downloading"]="Downloading updated scripts..."
    ["boot_update_done"]="Update finished, restarting script..."
    ["boot_update_failed"]="Update failed"

    # General
    ["press_enter"]="Press ENTER to continue..."
    ["yes"]="yes"
    ["no"]="no"
    ["error"]="Error"
    ["success"]="Success"
    ["warning"]="Warning"
)

# =============================================================================
# Translation Function
# =============================================================================

# Get translated string
# Usage: t "key"
t() {
    local key="$1"
    if [[ "$CURRENT_LANG" == "sk" ]]; then
        echo "${TRANS_SK[$key]:-$key}"
    else
        echo "${TRANS_EN[$key]:-$key}"
    fi
}

# =============================================================================
# Configuration Functions
# =============================================================================

# Load configuration from file
load_config() {
    if [[ -f "$CONFIG_FILE" ]]; then
        source "$CONFIG_FILE"
        CURRENT_LANG="${LANG:-$DEFAULT_LANG}"
        return 0
    fi
    CURRENT_LANG="$DEFAULT_LANG"
    return 0
}

# Save configuration to file
save_config() {
    cat > "$CONFIG_FILE" << EOF
# EduDisplej Configuration File
MODE=${MODE:-EDSERVER}
KIOSK_URL=${KIOSK_URL:-$DEFAULT_KIOSK_URL}
LANG=${CURRENT_LANG}
PACKAGES_INSTALLED=${PACKAGES_INSTALLED:-0}
EOF
}

# Get current mode
get_mode() {
    if [[ -f "$MODE_FILE" ]]; then
        cat "$MODE_FILE"
    else
        echo ""
    fi
}

# Set current mode
set_mode() {
    local mode="$1"
    echo "$mode" > "$MODE_FILE"
}

# =============================================================================
# Display Functions
# =============================================================================

# Print colored text
print_color() {
    local color="$1"
    local text="$2"
    case "$color" in
        red)    echo -e "\033[0;31m${text}\033[0m" ;;
        green)  echo -e "\033[0;32m${text}\033[0m" ;;
        yellow) echo -e "\033[0;33m${text}\033[0m" ;;
        blue)   echo -e "\033[0;94m${text}\033[0m" ;;
        *)      echo "$text" ;;
    esac
}

# Print info message
print_info() {
    local caller_info=""
    if [[ "${SHOW_CALLER_INFO:-false}" == true && -n "${BASH_SOURCE[1]:-}" ]]; then
        local file=$(basename "${BASH_SOURCE[1]}")
        local line="${BASH_LINENO[0]}"
        caller_info=" [${file}:${line}]"
    fi
    print_color "blue" "[INFO]${caller_info} $1"
}

# Print success message
print_success() {
    local caller_info=""
    if [[ "${SHOW_CALLER_INFO:-false}" == true && -n "${BASH_SOURCE[1]:-}" ]]; then
        local file=$(basename "${BASH_SOURCE[1]}")
        local line="${BASH_LINENO[0]}"
        caller_info=" [${file}:${line}]"
    fi
    print_color "green" "[OK]${caller_info} $1"
}

# Print warning message
print_warning() {
    local file=$(basename "${BASH_SOURCE[1]:-unknown}")
    local line="${BASH_LINENO[0]:-?}"
    print_color "yellow" "[$(t warning)] [${file}:${line}] $1"
}

# Print error message
print_error() {
    local file=$(basename "${BASH_SOURCE[1]:-unknown}")
    local line="${BASH_LINENO[0]:-?}"
    print_color "red" "[$(t error)] [${file}:${line}] $1"
}

# Show banner using figlet if available
show_banner() {
    if command -v figlet &> /dev/null; then
        figlet -c "EduDisplej"
    else
        echo "================================"
        echo "       E D U D I S P L E J      "
        echo "================================"
    fi
    echo ""
}

# Telepito banner megjelenitese ASCII muveszettel -- Zobrazenie instalacneho bannera ASCII artom
show_installer_banner() {
    clear_screen
    echo ""
    echo ",------.,------.  ,--. ,--.,------.  ,--. ,---.  ,------. ,--.   ,------.     ,--. "
    echo "|  .---'|  .-.  \ |  | |  ||  .-.  \ |  |'   .-' |  .--. '|  |   |  .---'     |  | "
    echo "|  \`--, |  |  \  :|  | |  ||  |  \  :|  |'.  \`-. |  '--' ||  |   |  \`--, ,--. |  | "
    echo "|  \`---.|  '--'  /'  '-'  '|  '--'  /|  |.-'    ||  | --' |  '--.|  \`---.|  '-'  / "
    echo "\`------'\`-------'  \`-----' \`-------' \`--'\`-----' \`--'     \`-----'\`------' \`-----' "
    echo "║                T E L E P I T O   /   I N S T A L A T O R       ║"
    echo ""
}

# Progress bar display function
# Usage: show_progress_bar <current> <total> <description> [start_time]
show_progress_bar() {
    local current="$1"
    local total="$2"
    local description="$3"
    local start_time="${4:-$(date +%s)}"
    
    local percent=$((current * 100 / total))
    local bar_width=50
    local filled=$((percent * bar_width / 100))
    local empty=$((bar_width - filled))
    
    # Calculate ETA
    local elapsed=$(($(date +%s) - start_time))
    local eta="--:--"
    if [[ $current -gt 0 ]] && [[ $elapsed -gt 0 ]]; then
        local avg_time=$((elapsed / current))
        local remaining=$((total - current))
        local eta_seconds=$((avg_time * remaining))
        local eta_min=$((eta_seconds / 60))
        local eta_sec=$((eta_seconds % 60))
        eta=$(printf "%02d:%02d" $eta_min $eta_sec)
    fi
    
    # Build progress bar
    local bar="["
    for ((i=0; i<filled; i++)); do bar+="█"; done
    for ((i=0; i<empty; i++)); do bar+="░"; done
    bar+="]"
    
    # Print progress bar with description
    printf "\r\033[K%s %3d%% %s  ETA: %s" "$bar" "$percent" "$description" "$eta"
    
    # New line when complete
    if [[ $current -eq $total ]]; then
        echo ""
    fi
}

# Clear screen
clear_screen() {
    clear 2>/dev/null || printf '\033[2J\033[H'
}

# =============================================================================
# Menu Functions
# =============================================================================

# Show main menu
show_main_menu() {
    clear_screen
    show_banner
    echo "$(t menu_title)"
    echo "================================"
    echo ""
    echo "  0. $(t menu_eduserver)"
    echo "  1. $(t menu_standalone)"
    echo "  2. $(t menu_language)"
    echo "  3. $(t menu_display)"
    echo "  4. $(t menu_network)"
    echo "  5. $(t menu_exit)"
    echo ""
    echo "$(t menu_select)"
}

# Wait for user to press enter
wait_for_enter() {
    echo ""
    read -rp "$(t press_enter)" _
}

# =============================================================================
# Utility Functions
# =============================================================================

# Retry a command with exponential backoff
# Usage: retry_command <max_attempts> <command> [args...]
retry_command() {
    local max_attempts="$1"
    shift
    local attempt=1
    local delay=1
    
    while [[ $attempt -le $max_attempts ]]; do
        if "$@"; then
            return 0
        fi
        
        if [[ $attempt -lt $max_attempts ]]; then
            print_warning "Command failed (attempt $attempt/$max_attempts), retrying in ${delay}s..."
            sleep "$delay"
            delay=$((delay * 2))
        fi
        ((attempt++))
    done
    
    print_error "Command failed after $max_attempts attempts"
    return 1
}

# Check if running as root
check_root() {
    if [[ $EUID -ne 0 ]]; then
        return 1
    fi
    return 0
}

# Get MAC address suffix for hostname generation
get_mac_suffix() {
    local mac
    mac=$(ip link show | grep -A1 "eth0\|wlan0" | grep ether | head -1 | awk '{print $2}')
    if [[ -n "$mac" ]]; then
        local clean_mac
        clean_mac=${mac//:/}
        echo "${clean_mac: -6}"
    else
        echo "unknown"
    fi
}

# Check internet connectivity
check_internet() {
    ping -c 1 -W 5 google.com &> /dev/null
    return $?
}

# Check if URL is accessible
# Usage: check_url "https://example.com"
check_url() {
    local url="$1"
    
    # Skip check for local files and about:blank
    if [[ "$url" == file://* ]] || [[ "$url" == about:* ]]; then
        return 0
    fi
    
    # Try to fetch headers with timeout
    if command -v curl >/dev/null 2>&1; then
        if curl -fsSL --max-time 10 --connect-timeout 5 --head "$url" >/dev/null 2>&1; then
            return 0
        fi
    fi
    
    return 1
}

# Get working URL from list (prefers configured, falls back to alternatives)
# Usage: get_working_url "$KIOSK_URL"
get_working_url() {
    local primary_url="$1"
    
    # Try primary URL first
    if check_url "$primary_url"; then
        echo "$primary_url"
        return 0
    fi
    
    print_warning "Primary URL not accessible: $primary_url"
    
    # Try fallback URLs
    for fallback in "${FALLBACK_URLS[@]}"; do
        if [[ "$fallback" == "$primary_url" ]]; then
            continue  # Skip if same as primary
        fi
        
        print_info "Trying fallback URL: $fallback"
        if check_url "$fallback"; then
            echo "$fallback"
            return 0
        fi
    done
    
    # Last resort: return first fallback even if not verified
    print_warning "No URL verified as accessible, using first fallback: ${FALLBACK_URLS[0]}"
    echo "${FALLBACK_URLS[0]}"
    return 0
}

# Wait for internet with retries
# Reduced from 30 attempts (60s) to 10 attempts (20s) to speed up boot
# when internet is not available. Package installation will be retried on next boot.
wait_for_internet() {
    local max_attempts=10
    local attempt=1
    print_info "$(t boot_waiting_network)"
    while [[ $attempt -le $max_attempts ]]; do
        if check_internet; then
            print_success "$(t boot_network_connected)"
            return 0
        fi
        echo -n "."
        sleep 2
        ((attempt++))
    done
    echo ""
    print_error "$(t boot_network_failed)"
    return 1
}

# =============================================================================
# Network Information Functions (extracted from network.sh)
# =============================================================================

# Get current Wi-Fi SSID
get_current_ssid() {
    if command -v nmcli &> /dev/null; then
        nmcli -t -f ACTIVE,SSID device wifi list 2>/dev/null | awk -F: '$1=="yes" {print $2; exit}'
    elif command -v iwgetid &> /dev/null; then
        iwgetid -r 2>/dev/null
    else
        echo "unknown"
    fi
}

# Get current Wi-Fi signal (0-100 if available)
get_current_signal() {
    if command -v nmcli &> /dev/null; then
        nmcli -t -f IN-USE,SIGNAL device wifi list 2>/dev/null | awk -F: '$1=="*" {print $2"%"; exit}'
    else
        # Fallback via iwconfig
        local quality total
        read quality total <<<$(iwconfig 2>/dev/null | awk -F'[ =/]+' '/Link Quality/ {print $4" " $5; exit}')
        if [[ -n "$quality" && -n "$total" ]]; then
            echo "$quality/$total"
        else
            echo "unknown"
        fi
    fi
}

# Get screen resolution
get_screen_resolution() {
    if command -v xrandr &> /dev/null && [[ -n "${DISPLAY:-}" ]]; then
        xrandr 2>/dev/null | grep -o '[0-9][0-9]*x[0-9][0-9]*' | head -1
    elif [[ -f /sys/class/graphics/fb0/virtual_size ]]; then
        cat /sys/class/graphics/fb0/virtual_size 2>/dev/null | tr ',' 'x'
    else
        echo "unknown"
    fi
}

# =============================================================================
# Boot Screen Functions
# =============================================================================

# Display boot screen with system status
show_boot_screen() {
    clear_screen
    
    # ASCII Art for EDUDISPLEJ
    echo ""
    echo "╔═══════════════════════════════════════════════════════════════════════════╗"
    echo "║                                                                           ║"
    echo "║   ███████╗██████╗ ██╗   ██╗██████╗ ██╗███████╗██████╗ ██╗     ███████╗   ║"
    echo "║   ██╔════╝██╔══██╗██║   ██║██╔══██╗██║██╔════╝██╔══██╗██║     ██╔════╝   ║"
    echo "║   █████╗  ██║  ██║██║   ██║██║  ██║██║███████╗██████╔╝██║     █████╗     ║"
    echo "║   ██╔══╝  ██║  ██║██║   ██║██║  ██║██║╚════██║██╔═══╝ ██║     ██╔══╝     ║"
    echo "║   ███████╗██████╔╝╚██████╔╝██████╔╝██║███████║██║     ███████╗███████╗   ║"
    echo "║   ╚══════╝╚═════╝  ╚═════╝ ╚═════╝ ╚═╝╚══════╝╚═╝     ╚══════╝╚══════╝   ║"
    echo "║                                                                           ║"
    echo "╚═══════════════════════════════════════════════════════════════════════════╝"
    echo ""
    echo "╔═══════════════════════════════════════════════════════════════════════════╗"
    echo "║                         SYSTEM STATUS / STAV SYSTEMU                      ║"
    echo "╠═══════════════════════════════════════════════════════════════════════════╣"
    
    # Internet status
    local internet_status="✗ Nedostupny / Not available"
    local wifi_info=""
    if check_internet; then
        internet_status="✓ Dostupny / Available"
        local ssid=$(get_current_ssid)
        local signal=$(get_current_signal)
        if [[ -n "$ssid" && "$ssid" != "unknown" ]]; then
            wifi_info="WiFi SSID: $ssid | Signal: $signal"
        fi
    fi
    
    printf "║ %-73s ║\n" "Internet: $internet_status"
    if [[ -n "$wifi_info" ]]; then
        printf "║ %-73s ║\n" "  $wifi_info"
    fi
    
    # Screen resolution
    local resolution=$(get_screen_resolution)
    printf "║ %-73s ║\n" "Rozlisenie / Resolution: $resolution"
    
    echo "╚═══════════════════════════════════════════════════════════════════════════╝"
    echo ""
}

# Countdown with F2 detection
countdown_with_f2() {
    local countdown_seconds=5
    
    echo "╔═══════════════════════════════════════════════════════════════════════════╗"
    echo "║  Stlacte F2 pre nastavenia (raspi-config) / Press F2 for settings        ║"
    echo "╚═══════════════════════════════════════════════════════════════════════════╝"
    echo ""
    
    # Enable non-blocking read
    if [[ -t 0 ]]; then
        # Save terminal settings
        local old_tty_settings=$(stty -g 2>/dev/null || true)
        stty -echo -icanon time 0 min 0 2>/dev/null || true
        
        local key=""
        local escape_sequence=""
        for ((i=countdown_seconds; i>=1; i--)); do
            printf "\rSpustenie o / Starting in: %d sekund / seconds...  " "$i"
            
            # Read input with timeout, accumulate escape sequences
            # F2 can be ESC[OQ, ESC[[B, or ESC[12~ depending on terminal
            key=""
            for ((j=0; j<10; j++)); do
                if read -t 0.1 -n 1 char 2>/dev/null; then
                    key+="$char"
                    # Check if we got an escape character
                    if [[ "$char" == $'\x1b' ]]; then
                        escape_sequence="$char"
                        # Read more characters to complete escape sequence
                        for ((k=0; k<10; k++)); do
                            if read -t 0.05 -n 1 char 2>/dev/null; then
                                escape_sequence+="$char"
                            else
                                break
                            fi
                        done
                        # Check if this is F2 (various possibilities)
                        if [[ "$escape_sequence" == $'\x1b'* ]]; then
                            # Restore terminal settings
                            [[ -n "$old_tty_settings" ]] && stty "$old_tty_settings" 2>/dev/null || true
                            echo ""
                            echo ""
                            print_info "F2 stlacene! Spustam raspi-config... / F2 pressed! Launching raspi-config..."
                            sleep 1
                            sudo raspi-config
                            # After raspi-config exits, continue with normal boot
                            echo ""
                            print_info "Navrat z nastaveni... / Returning from settings..."
                            sleep 2
                            return 0
                        fi
                    fi
                fi
            done
        done
        
        # Restore terminal settings
        [[ -n "$old_tty_settings" ]] && stty "$old_tty_settings" 2>/dev/null || true
    else
        # If not in interactive terminal, just do simple countdown
        for ((i=countdown_seconds; i>=1; i--)); do
            printf "\rSpustenie o / Starting in: %d sekund / seconds...  " "$i"
            sleep 1
        done
    fi
    
    echo ""
    echo ""
    return 0
}

# Load configuration on source
load_config
