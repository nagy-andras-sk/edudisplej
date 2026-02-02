#!/bin/bash
# Screenshot Capture and Upload Script
# Takes a screenshot and uploads it to the control panel

# Service version
SERVICE_VERSION="1.0.0"

set -euo pipefail

CONFIG_DIR="/opt/edudisplej"
API_BASE_URL="${EDUDISPLEJ_API_URL:-https://control.edudisplej.sk}"
SCREENSHOT_API="${API_BASE_URL}/api/screenshot_sync.php"
LOG_FILE="${CONFIG_DIR}/logs/screenshot.log"
KIOSK_CONF="${CONFIG_DIR}/kiosk.conf"

# Logging
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

# Get MAC address
get_mac_address() {
    ip link show | grep -A1 "state UP" | grep "link/ether" | head -1 | awk '{print $2}' | tr -d ':'
}

# Capture screenshot
capture_screenshot() {
    local temp_file="/tmp/screenshot_$(date +%s).png"
    
    log "Capturing screenshot..."
    
    # Use scrot or import to capture screenshot
    if command -v scrot >/dev/null 2>&1; then
        DISPLAY=:0 scrot "$temp_file" 2>/dev/null || return 1
    elif command -v import >/dev/null 2>&1; then
        DISPLAY=:0 import -window root "$temp_file" 2>/dev/null || return 1
    else
        log "ERROR: No screenshot tool available (scrot or imagemagick)"
        return 1
    fi
    
    if [ ! -f "$temp_file" ]; then
        log "ERROR: Screenshot file not created"
        return 1
    fi
    
    echo "$temp_file"
}

# Upload screenshot
upload_screenshot() {
    local screenshot_file="$1"
    local mac=$(get_mac_address)
    
    if [ -z "$mac" ]; then
        log "ERROR: Could not determine MAC address"
        return 1
    fi
    
    log "Uploading screenshot..."
    
    # Convert to base64
    local base64_data=$(base64 -w 0 "$screenshot_file")
    
    # Prepare JSON request
    local request_data="{\"mac\":\"$mac\",\"screenshot\":\"data:image/png;base64,$base64_data\"}"
    
    # Upload to API
    local response=$(curl -s -X POST "$SCREENSHOT_API" \
        -H "Content-Type: application/json" \
        -d "$request_data" \
        --max-time 30)
    
    if echo "$response" | grep -q '"success":true'; then
        log "Screenshot uploaded successfully"
        rm -f "$screenshot_file"
        return 0
    else
        log "ERROR: Screenshot upload failed: $response"
        rm -f "$screenshot_file"
        return 1
    fi
}

# Main
main() {
    mkdir -p "$(dirname "$LOG_FILE")"
    
    log "=== Screenshot capture started ==="
    
    # Capture screenshot
    screenshot_file=$(capture_screenshot)
    if [ $? -ne 0 ]; then
        log "ERROR: Screenshot capture failed"
        exit 1
    fi
    
    log "Screenshot captured: $screenshot_file"
    
    # Upload screenshot
    if upload_screenshot "$screenshot_file"; then
        log "=== Screenshot process completed successfully ==="
        exit 0
    else
        log "=== Screenshot process failed ==="
        exit 1
    fi
}

main
