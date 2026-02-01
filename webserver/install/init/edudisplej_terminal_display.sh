#!/bin/bash
# EduDisplej Terminal Status Display
# Real-time sync status viewer for kiosk terminal
# =============================================================================

set -euo pipefail

CONFIG_DIR="/opt/edudisplej"
STATUS_FILE="${CONFIG_DIR}/sync_status.json"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
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

# Display status
display_status() {
    clear_screen
    
    local status_json=$(read_status)
    
    # Parse JSON (basic parsing)
    local last_sync=$(echo "$status_json" | grep -o '"last_sync":"[^"]*"' | cut -d'"' -f4 || echo "Never")
    local status=$(echo "$status_json" | grep -o '"status":"[^"]*"' | cut -d'"' -f4 || echo "unknown")
    local kiosk_id=$(echo "$status_json" | grep -o '"kiosk_id":[0-9]*' | cut -d: -f2 || echo "N/A")
    local device_id=$(echo "$status_json" | grep -o '"device_id":"[^"]*"' | cut -d'"' -f4 || echo "N/A")
    local is_configured=$(echo "$status_json" | grep -o '"is_configured":[a-z]*' | cut -d: -f2 || echo "false")
    local next_sync=$(echo "$status_json" | grep -o '"next_sync":"[^"]*"' | cut -d'"' -f4 || echo "Unknown")
    
    # Display info
    echo -e "${BOLD}Device Information:${NC}"
    echo -e "  Kiosk ID:     ${BLUE}$kiosk_id${NC}"
    echo -e "  Device ID:    ${BLUE}$device_id${NC}"
    echo -e "  Hostname:     ${BLUE}$(hostname)${NC}"
    echo ""
    
    echo -e "${BOLD}Sync Status:${NC}"
    if [ "$status" = "success" ]; then
        echo -e "  Status:       ${GREEN}✓ Connected${NC}"
    else
        echo -e "  Status:       ${RED}✗ Disconnected${NC}"
    fi
    echo -e "  Last Sync:    $last_sync"
    echo -e "  Next Sync:    $next_sync"
    echo ""
    
    echo -e "${BOLD}Configuration:${NC}"
    if [ "$is_configured" = "true" ]; then
        echo -e "  Configured:   ${GREEN}✓ Yes${NC}"
        echo ""
        echo -e "${BOLD}Active Modules:${NC}"
        
        # Parse active modules (simplified)
        # TODO: Better JSON parsing for modules array
        echo -e "  ${YELLOW}→${NC} Loading modules..."
        
    else
        echo -e "  Configured:   ${YELLOW}⚠ Waiting for configuration${NC}"
        echo ""
        echo -e "  ${YELLOW}Please assign this kiosk in the control panel:${NC}"
        echo -e "  https://control.edudisplej.sk/admin/"
    fi
    
    echo ""
    echo -e "${BOLD}=========================================${NC}"
    echo -e "Auto-refresh: 10s | Press Ctrl+C to exit"
    echo ""
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
