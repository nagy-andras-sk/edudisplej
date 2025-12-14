#!/bin/bash
# edudisplej-init.sh - Main initialization script for EduDisplej
# This script is executed by systemd on boot
# All text is in Slovak (without diacritics) or English

# =============================================================================
# Initialization
# =============================================================================

# Set script directory
EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
CONFIG_FILE="${EDUDISPLEJ_HOME}/edudisplej.conf"
MODE_FILE="${EDUDISPLEJ_HOME}/.mode"

# Export display for X operations
export DISPLAY=:0
export HOME="${EDUDISPLEJ_HOME}"
export USER="edudisplej"

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

echo ""

# =============================================================================
# Show Banner
# =============================================================================

show_banner

# =============================================================================
# Hostname Check and Generation
# =============================================================================

print_info "$(t boot_hostname_check)"

CURRENT_HOSTNAME=$(hostname)

if [[ "$CURRENT_HOSTNAME" == "raspberrypi" || "$CURRENT_HOSTNAME" == "raspberry" ]]; then
    # Generate new hostname based on MAC address
    MAC_SUFFIX=$(get_mac_suffix)
    NEW_HOSTNAME="edudisplej-${MAC_SUFFIX}"
    
    print_info "Generating new hostname..."
    
    # Set hostname
    sudo hostnamectl set-hostname "$NEW_HOSTNAME" 2>/dev/null || sudo hostname "$NEW_HOSTNAME"
    
    # Update /etc/hostname
    echo "$NEW_HOSTNAME" | sudo tee /etc/hostname > /dev/null
    
    # Update /etc/hosts
    sudo sed -i "s/127.0.1.1.*/127.0.1.1\t${NEW_HOSTNAME}/" /etc/hosts 2>/dev/null
    
    print_success "$(t boot_hostname_set) ${NEW_HOSTNAME}"
else
    print_info "Hostname: ${CURRENT_HOSTNAME}"
fi

echo ""

# =============================================================================
# Wait for Internet Connection
# =============================================================================

wait_for_internet
INTERNET_AVAILABLE=$?

echo ""

# =============================================================================
# Main Menu Function
# =============================================================================

main_menu() {
    local choice
    
    while true; do
        show_main_menu
        read -rp "> " choice
        
        case "$choice" in
            0)
                # EduServer mode
                print_info "$(t menu_eduserver)"
                set_mode "EDSERVER"
                KIOSK_URL="https://www.edudisplej.sk/edserver/demo/client"
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
                    KIOSK_URL="https://www.edudisplej.sk/edserver/demo/client"
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
# F12 Menu Window (5 seconds)
# =============================================================================

print_info "$(t boot_f12_prompt)"

# Flag for menu entry
ENTER_MENU=false

# Function to restore terminal settings
restore_terminal() {
    if [[ -n "${OLD_STTY:-}" ]]; then
        stty "$OLD_STTY" 2>/dev/null
    fi
}

# Check if mode file exists
if [[ ! -f "$MODE_FILE" ]]; then
    # No mode file - automatically enter menu
    print_warning "No mode configured - entering menu..."
    ENTER_MENU=true
else
    # Wait for F12 key press (5 seconds)
    # Using read with timeout to detect key press
    echo ""
    
    # Set terminal to raw mode to capture key presses
    if [[ -t 0 ]]; then
        # Save terminal settings
        OLD_STTY=$(stty -g 2>/dev/null) || OLD_STTY=""
        
        # Set trap to restore terminal on exit
        trap restore_terminal EXIT
        
        # Set raw mode
        if [[ -n "$OLD_STTY" ]]; then
            stty raw -echo 2>/dev/null
        fi
        
        # Read with timeout
        for i in {5..1}; do
            echo -ne "\r$(t boot_f12_prompt) [${i}s]  "
            
            # Try to read a character with short timeout
            if read -r -t 1 -n 1 key 2>/dev/null; then
                # Check for F12 or any key for demo purposes
                # In real scenario, F12 sends escape sequence
                ENTER_MENU=true
                break
            fi
        done
        
        # Restore terminal settings
        restore_terminal
        trap - EXIT
        echo ""
    else
        # Not a TTY - just wait 5 seconds
        sleep 5
    fi
fi

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
    
    # Start kiosk mode
    start_kiosk_mode
fi

# =============================================================================
# End of Script
# =============================================================================

echo ""
print_info "EduDisplej init script completed."
exit 0
