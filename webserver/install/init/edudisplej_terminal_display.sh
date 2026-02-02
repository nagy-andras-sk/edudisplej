#!/bin/bash
# EduDisplej Terminal Status Display
# Real-time sync status viewer for kiosk terminal
# =============================================================================

# Service version
SERVICE_VERSION="1.0.0"

set -euo pipefail

CONFIG_DIR="/opt/edudisplej"
STATUS_FILE="${CONFIG_DIR}/sync_status.json"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
GRAY='\033[0;90m'
NC='\033[0m' # No Color
BOLD='\033[1m'

# Clear screen
clear_screen() {
    clear
    echo -e "${BOLD}=========================================${NC}"
    echo -e "${BOLD}         E D U D I S P L E J${NC}"
    echo -e "${BOLD}     Terminal Status Display${NC}"
    echo -e "${BOLD}=========================================${NC}"
    echo ""
}

# Read status file
read_status() {
    if [ -f "$STATUS_FILE" ]; then
        cat "$STATUS_FILE"
    else
        echo '{"status":"not_started","message":"Waiting for first sync..."}'
    fi
}

# Get WiFi information
get_wifi_info() {
    local wifi_name=$(iwgetid -r 2>/dev/null || echo "Not connected")
    local wifi_signal=$(grep "^\s*wlan0:" /proc/net/wireless | awk '{print int($3 * 100 / 70)}' 2>/dev/null || echo "0")
    echo "$wifi_name|$wifi_signal"
}

# Get network status
get_network_status() {
    if ping -c 1 -W 2 8.8.8.8 >/dev/null 2>&1; then
        echo "online"
    else
        echo "offline"
    fi
}

# Calculate time until next sync
get_next_sync_countdown() {
    local next_sync="$1"
    if [ "$next_sync" = "Unknown" ] || [ -z "$next_sync" ]; then
        echo "Unknown"
        return
    fi
    
    local next_sync_epoch=$(date -d "$next_sync" +%s 2>/dev/null || echo "0")
    local current_epoch=$(date +%s)
    local diff=$((next_sync_epoch - current_epoch))
    
    if [ $diff -le 0 ]; then
        echo "Syncing now..."
    else
        local minutes=$((diff / 60))
        local seconds=$((diff % 60))
        echo "${minutes}m ${seconds}s"
    fi
}

# Display status
display_status() {
    clear_screen
    
    local status_json=$(read_status)
    
    # Parse JSON (basic parsing)
    local last_sync=$(echo "$status_json" | grep -o '"last_sync":"[^"]*"' | cut -d'"' -f4 || echo "Never")
    local status=$(echo "$status_json" | grep -o '"status":"[^"]*"' | cut -d'"' -f4 || echo "unknown")
    local kiosk_id=$(echo "$status_json" | grep -o '"kiosk_id":[0-9]\+' | cut -d: -f2 || echo "N/A")
    local device_id=$(echo "$status_json" | grep -o '"device_id":"[^"]*"' | cut -d'"' -f4 || echo "N/A")
    local is_configured=$(echo "$status_json" | grep -o '"is_configured":[a-z]*' | cut -d: -f2 || echo "false")
    local next_sync=$(echo "$status_json" | grep -o '"next_sync":"[^"]*"' | cut -d'"' -f4 || echo "Unknown")
    local company_name=$(echo "$status_json" | grep -o '"company_name":"[^"]*"' | cut -d'"' -f4 || echo "Unknown")
    local group_name=$(echo "$status_json" | grep -o '"group_name":"[^"]*"' | cut -d'"' -f4 || echo "Unknown")
    
    # Get network info
    local network_status=$(get_network_status)
    local wifi_info=$(get_wifi_info)
    local wifi_name=$(echo "$wifi_info" | cut -d'|' -f1)
    local wifi_signal=$(echo "$wifi_info" | cut -d'|' -f2)
    
    # Get countdown
    local countdown=$(get_next_sync_countdown "$next_sync")
    
    # Display info with box layout
    echo -e "${GREEN}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║              EDUDISPLEJ KIOSK STATUS MONITOR                   ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    
    echo -e "${CYAN}┌─ Device Information ───────────────────────────────────────────┐${NC}"
    echo -e "  ${YELLOW}Device ID:${NC}        $device_id"
    echo -e "  ${YELLOW}Kiosk ID:${NC}         $kiosk_id"
    echo -e "  ${YELLOW}Hostname:${NC}         $(hostname)"
    echo -e "${CYAN}└────────────────────────────────────────────────────────────────┘${NC}"
    echo ""
    
    echo -e "${CYAN}┌─ Organization Information ─────────────────────────────────────┐${NC}"
    echo -e "  ${YELLOW}Company:${NC}          $company_name"
    echo -e "  ${YELLOW}Group:${NC}            $group_name"
    echo -e "${CYAN}└────────────────────────────────────────────────────────────────┘${NC}"
    echo ""
    
    echo -e "${CYAN}┌─ Network Status ───────────────────────────────────────────────┐${NC}"
    if [ "$network_status" = "online" ]; then
        echo -e "  ${YELLOW}Connection:${NC}       ${GREEN}● ONLINE${NC}"
    else
        echo -e "  ${YELLOW}Connection:${NC}       ${RED}● OFFLINE${NC}"
    fi
    echo -e "  ${YELLOW}WiFi Network:${NC}     $wifi_name"
    echo -e "  ${YELLOW}Signal Strength:${NC}  ${wifi_signal}%"
    echo -e "${CYAN}└────────────────────────────────────────────────────────────────┘${NC}"
    echo ""
    
    echo -e "${CYAN}┌─ Sync Status ──────────────────────────────────────────────────┐${NC}"
    if [ "$status" = "success" ]; then
        echo -e "  ${YELLOW}Status:${NC}           ${GREEN}✓ Success${NC}"
    else
        echo -e "  ${YELLOW}Status:${NC}           ${RED}✗ Disconnected${NC}"
    fi
    echo -e "  ${YELLOW}Last Sync:${NC}        $last_sync"
    echo -e "  ${YELLOW}Next Sync:${NC}        $next_sync"
    echo -e "  ${YELLOW}Countdown:${NC}        ${GREEN}$countdown${NC}"
    echo -e "  ${YELLOW}Configured:${NC}       $([ "$is_configured" = "true" ] && echo "${GREEN}Yes${NC}" || echo "${RED}No${NC}")"
    echo -e "${CYAN}└────────────────────────────────────────────────────────────────┘${NC}"
    echo ""
    
    if [ "$is_configured" != "true" ]; then
        echo -e "  ${YELLOW}⚠ Please assign this kiosk in the control panel:${NC}"
        echo -e "  ${BLUE}https://control.edudisplej.sk/admin/${NC}"
        echo ""
    fi
    
    echo -e "${GRAY}Last updated: $(date '+%Y-%m-%d %H:%M:%S')${NC}"
    echo -e "${GRAY}Auto-refresh: 10s | Press Ctrl+C to exit${NC}"
}

# Main loop
main() {
    # Wait 5 seconds before starting
    sleep 5
    
    while true; do
        display_status
        sleep 10
    done
}

main
