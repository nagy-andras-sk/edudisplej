#!/bin/bash
# common.sh - Spolocne funkcie / Kozos funkciok

# Adresare / Konyvtarak
EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
CONFIG_FILE="${EDUDISPLEJ_HOME}/edudisplej.conf"
MODE_FILE="${EDUDISPLEJ_HOME}/.mode"

# Predvolene hodnoty / Alapertelmezett ertekek
DEFAULT_LANG="sk"
CURRENT_LANG="${DEFAULT_LANG}"
DEFAULT_KIOSK_URL="https://www.time.is"
FALLBACK_URLS=(
    "https://www.time.is"
    "file:///opt/edudisplej/localweb/clock.html"
    "about:blank"
)

# Preklady - slovencina bez diakritiky / Forditas - szlovak diakritika nelkul
declare -Ag TRANS_SK
TRANS_SK=(
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
    ["press_enter"]="Stlacte ENTER pre pokracovanie..."
    ["yes"]="ano"
    ["no"]="nie"
    ["error"]="Chyba"
    ["success"]="Uspech"
    ["warning"]="Upozornenie"
)

declare -Ag TRANS_EN
TRANS_EN=(
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
    ["press_enter"]="Press ENTER to continue..."
    ["yes"]="yes"
    ["no"]="no"
    ["error"]="Error"
    ["success"]="Success"
    ["warning"]="Warning"
)

# Preklad / Forditas
t() {
    local key="$1"
    if [[ "$CURRENT_LANG" == "sk" ]]; then
        echo "${TRANS_SK[$key]:-$key}"
    else
        echo "${TRANS_EN[$key]:-$key}"
    fi
}

# Konfiguracne funkcie / Konfiguracios funkciok
load_config() {
    if [[ -f "$CONFIG_FILE" ]]; then
        source "$CONFIG_FILE"
        CURRENT_LANG="${LANG:-$DEFAULT_LANG}"
        return 0
    fi
    CURRENT_LANG="$DEFAULT_LANG"
    return 0
}

# Zobrazenie funkcii / Megjelenites funkciok
log_timestamp() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')]"
}

print_info() {
    local caller_info=""
    if [[ "${SHOW_CALLER_INFO:-false}" == true && -n "${BASH_SOURCE[1]:-}" ]]; then
        local file=$(basename "${BASH_SOURCE[1]}")
        local line="${BASH_LINENO[0]}"
        caller_info=" [${file}:${line}]"
    fi
    echo -e "$(log_timestamp) \033[0;36m[INFO]\033[0m${caller_info} $1"
}

print_success() {
    local caller_info=""
    if [[ "${SHOW_CALLER_INFO:-false}" == true && -n "${BASH_SOURCE[1]:-}" ]]; then
        local file=$(basename "${BASH_SOURCE[1]}")
        local line="${BASH_LINENO[0]}"
        caller_info=" [${file}:${line}]"
    fi
    echo -e "$(log_timestamp) \033[0;32m[SUCCESS]\033[0m${caller_info} $1"
}

print_warning() {
    local file=$(basename "${BASH_SOURCE[1]:-unknown}")
    local line="${BASH_LINENO[0]:-?}"
    echo -e "$(log_timestamp) \033[0;33m[WARNING]\033[0m [${file}:${line}] $1"
}

print_error() {
    local file=$(basename "${BASH_SOURCE[1]:-unknown}")
    local line="${BASH_LINENO[0]:-?}"
    echo -e "$(log_timestamp) \033[0;31m[ERROR]\033[0m [${file}:${line}] $1"
}

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

show_progress_bar() {
    local current="$1"
    local total="$2"
    local description="$3"
    local start_time="${4:-$(date +%s)}"
    
    local percent=$((current * 100 / total))
    local bar_width=50
    local filled=$((percent * bar_width / 100))
    local empty=$((bar_width - filled))
    
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
    
    local bar="["
    for ((i=0; i<filled; i++)); do bar+="█"; done
    for ((i=0; i<empty; i++)); do bar+="░"; done
    bar+="]"
    
    printf "\r\033[K%s %3d%% %s  ETA: %s" "$bar" "$percent" "$description" "$eta"
    
    if [[ $current -eq $total ]]; then
        echo ""
    fi
}

clear_screen() {
    clear 2>/dev/null || printf '\033[2J\033[H'
}

wait_for_enter() {
    echo ""
    read -rp "$(t press_enter)" _
}

# Pomocne funkcie / Segito funkciok
check_internet() {
    ping -c 1 -W 5 google.com &> /dev/null
    return $?
}

check_url() {
    local url="$1"
    if [[ "$url" == file://* ]] || [[ "$url" == about:* ]]; then
        return 0
    fi
    if command -v curl >/dev/null 2>&1; then
        if curl -fsSL --max-time 10 --connect-timeout 5 --head "$url" >/dev/null 2>&1; then
            return 0
        fi
    fi
    return 1
}

get_working_url() {
    local primary_url="$1"
    if check_url "$primary_url"; then
        echo "$primary_url"
        return 0
    fi
    print_warning "Primary URL not accessible: $primary_url"
    for fallback in "${FALLBACK_URLS[@]}"; do
        if [[ "$fallback" == "$primary_url" ]]; then
            continue
        fi
        print_info "Trying fallback URL: $fallback"
        if check_url "$fallback"; then
            echo "$fallback"
            return 0
        fi
    done
    print_warning "No URL verified, using first fallback: ${FALLBACK_URLS[0]}"
    echo "${FALLBACK_URLS[0]}"
    return 0
}

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

# Sietove informacie / Halozati informaciok
get_current_ssid() {
    if command -v nmcli &> /dev/null; then
        nmcli -t -f ACTIVE,SSID device wifi list 2>/dev/null | awk -F: '$1=="yes" {print $2; exit}'
    elif command -v iwgetid &> /dev/null; then
        iwgetid -r 2>/dev/null
    else
        echo "unknown"
    fi
}

get_current_signal() {
    if command -v nmcli &> /dev/null; then
        nmcli -t -f IN-USE,SIGNAL device wifi list 2>/dev/null | awk -F: '$1=="*" {print $2"%"; exit}'
    else
        local quality total
        read quality total <<<$(iwconfig 2>/dev/null | awk -F'[ =/]+' '/Link Quality/ {print $4" " $5; exit}')
        if [[ -n "$quality" && -n "$total" ]]; then
            echo "$quality/$total"
        else
            echo "unknown"
        fi
    fi
}

get_screen_resolution() {
    if command -v xrandr &> /dev/null && [[ -n "${DISPLAY:-}" ]]; then
        xrandr 2>/dev/null | grep -o '[0-9][0-9]*x[0-9][0-9]*' | head -1
    elif [[ -f /sys/class/graphics/fb0/virtual_size ]]; then
        cat /sys/class/graphics/fb0/virtual_size 2>/dev/null | tr ',' 'x'
    else
        echo "unknown"
    fi
}

# Zobrazenie loga / Logo megjelenites
show_edudisplej_logo() {
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
}

show_system_status() {
    echo "╔═══════════════════════════════════════════════════════════════════════════╗"
    echo "║                         SYSTEM STATUS / STAV SYSTEMU                      ║"
    echo "╠═══════════════════════════════════════════════════════════════════════════╣"
    
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
    
    local resolution=$(get_screen_resolution)
    printf "║ %-73s ║\n" "Rozlisenie / Resolution: $resolution"
    
    echo "╚═══════════════════════════════════════════════════════════════════════════╝"
    echo ""
}

show_boot_screen() {
    clear_screen
    show_edudisplej_logo
    show_system_status
}

countdown_with_f2() {
    local countdown_seconds=5
    
    echo "╔═══════════════════════════════════════════════════════════════════════════╗"
    echo "║  Stlacte F2 pre nastavenia (raspi-config) / Press F2 for settings        ║"
    echo "╚═══════════════════════════════════════════════════════════════════════════╝"
    echo ""
    
    if [[ -t 0 ]]; then
        local old_tty_settings=$(stty -g 2>/dev/null || true)
        stty -echo -icanon time 0 min 0 2>/dev/null || true
        
        local key=""
        local escape_sequence=""
        for ((i=countdown_seconds; i>=1; i--)); do
            printf "\rSpustenie o / Starting in: %d sekund / seconds...  " "$i"
            
            key=""
            for ((j=0; j<10; j++)); do
                if read -t 0.1 -n 1 char 2>/dev/null; then
                    key+="$char"
                    if [[ "$char" == $'\x1b' ]]; then
                        escape_sequence="$char"
                        for ((k=0; k<10; k++)); do
                            if read -t 0.05 -n 1 char 2>/dev/null; then
                                escape_sequence+="$char"
                            else
                                break
                            fi
                        done
                        if [[ "$escape_sequence" == $'\x1b'* ]]; then
                            [[ -n "$old_tty_settings" ]] && stty "$old_tty_settings" 2>/dev/null || true
                            echo ""
                            echo ""
                            print_info "F2 stlacene! Spustam raspi-config..."
                            sleep 1
                            sudo raspi-config
                            echo ""
                            print_info "Navrat z nastaveni..."
                            sleep 2
                            return 0
                        fi
                    fi
                fi
            done
        done
        
        [[ -n "$old_tty_settings" ]] && stty "$old_tty_settings" 2>/dev/null || true
    else
        for ((i=countdown_seconds; i>=1; i--)); do
            printf "\rSpustenie o / Starting in: %d sekund / seconds...  " "$i"
            sleep 1
        done
    fi
    
    echo ""
    echo ""
    return 0
}

load_config
