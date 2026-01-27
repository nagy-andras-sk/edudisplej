#!/bin/bash
# EduDisplej Sync Service
# Handles registration and synchronization with control panel
# =============================================================================

# Configuration
API_URL="${EDUDISPLEJ_API_URL:-http://control.edudisplej.sk/api.php}"
SYNC_INTERVAL=300  # Default 5 minutes
CONFIG_DIR="/opt/edudisplej"
CONFIG_FILE="$CONFIG_DIR/kiosk.conf"

# Ensure config directory exists
mkdir -p "$CONFIG_DIR"

# Get MAC address
get_mac_address() {
    # Get the first non-loopback network interface MAC address
    ip link show | grep -A1 "state UP" | grep "link/ether" | head -1 | awk '{print $2}' | tr -d ':'
}

# Get hostname
get_hostname() {
    hostname
}

# Get hardware info
get_hw_info() {
    cat << EOF
{
    "hostname": "$(hostname)",
    "os": "$(lsb_release -ds 2>/dev/null || echo 'Unknown')",
    "kernel": "$(uname -r)",
    "architecture": "$(uname -m)",
    "cpu": "$(grep 'model name' /proc/cpuinfo | head -1 | cut -d: -f2 | xargs)",
    "memory": "$(free -h | awk '/^Mem:/ {print $2}')",
    "uptime": "$(uptime -p)"
}
EOF
}

# Register kiosk
register_kiosk() {
    local mac=$(get_mac_address)
    local hostname=$(get_hostname)
    local hw_info=$(get_hw_info)
    
    echo "Registering kiosk..."
    echo "MAC: $mac"
    echo "Hostname: $hostname"
    
    response=$(curl -s -X POST "$API_URL?action=register" \
        -H "Content-Type: application/json" \
        -d "{\"mac\":\"$mac\",\"hostname\":\"$hostname\",\"hw_info\":$hw_info}")
    
    if echo "$response" | grep -q '"success":true'; then
        kiosk_id=$(echo "$response" | grep -o '"kiosk_id":[0-9]*' | cut -d: -f2)
        echo "KIOSK_ID=$kiosk_id" > "$CONFIG_FILE"
        echo "MAC=$mac" >> "$CONFIG_FILE"
        echo "Registration successful! Kiosk ID: $kiosk_id"
        return 0
    else
        echo "Registration failed: $response"
        return 1
    fi
}

# Sync with server
sync_kiosk() {
    local mac=$(get_mac_address)
    local hostname=$(get_hostname)
    local hw_info=$(get_hw_info)
    
    response=$(curl -s -X POST "$API_URL?action=sync" \
        -H "Content-Type: application/json" \
        -d "{\"mac\":\"$mac\",\"hostname\":\"$hostname\",\"hw_info\":$hw_info}")
    
    if echo "$response" | grep -q '"success":true'; then
        # Extract sync interval
        new_interval=$(echo "$response" | grep -o '"sync_interval":[0-9]*' | cut -d: -f2)
        if [ -n "$new_interval" ]; then
            SYNC_INTERVAL=$new_interval
        fi
        
        # Check if screenshot requested
        screenshot_requested=$(echo "$response" | grep -o '"screenshot_requested":[a-z]*' | cut -d: -f2)
        if [ "$screenshot_requested" = "true" ]; then
            echo "Screenshot requested, capturing..."
            capture_screenshot
        fi
        
        echo "Sync successful (interval: ${SYNC_INTERVAL}s)"
        return 0
    else
        echo "Sync failed: $response"
        return 1
    fi
}

# Capture and upload screenshot
capture_screenshot() {
    local mac=$(get_mac_address)
    local screenshot_file="/tmp/edudisplej_screenshot_$(date +%s).png"
    local display="${DISPLAY:-:0}"
    
    # Capture screenshot using scrot or import (ImageMagick)
    if command -v scrot >/dev/null 2>&1; then
        DISPLAY="$display" scrot "$screenshot_file" 2>/dev/null
    elif command -v import >/dev/null 2>&1; then
        DISPLAY="$display" import -window root "$screenshot_file" 2>/dev/null
    else
        echo "Screenshot tool not available (scrot or imagemagick required)"
        return 1
    fi
    
    if [ -f "$screenshot_file" ]; then
        # Convert to base64
        screenshot_base64=$(base64 -w 0 "$screenshot_file")
        
        # Upload
        response=$(curl -s -X POST "$API_URL?action=screenshot" \
            -H "Content-Type: application/json" \
            -d "{\"mac\":\"$mac\",\"screenshot\":\"data:image/png;base64,$screenshot_base64\"}")
        
        # Clean up
        rm -f "$screenshot_file"
        
        if echo "$response" | grep -q '"success":true'; then
            echo "Screenshot uploaded successfully"
            return 0
        else
            echo "Screenshot upload failed: $response"
            return 1
        fi
    else
        echo "Failed to capture screenshot"
        return 1
    fi
}

# Main sync loop
main() {
    echo "EduDisplej Sync Service Starting..."
    echo "API URL: $API_URL"
    
    # Check if already registered
    if [ ! -f "$CONFIG_FILE" ]; then
        echo "Kiosk not registered, registering now..."
        register_kiosk || {
            echo "Failed to register, will retry on next sync"
        }
    fi
    
    # Main loop
    while true; do
        echo "---"
        echo "$(date): Syncing..."
        
        if sync_kiosk; then
            echo "Next sync in ${SYNC_INTERVAL} seconds"
        else
            echo "Sync failed, retrying in ${SYNC_INTERVAL} seconds"
        fi
        
        sleep "$SYNC_INTERVAL"
    done
}

# Handle arguments
case "${1:-}" in
    start)
        main
        ;;
    register)
        register_kiosk
        ;;
    sync)
        sync_kiosk
        ;;
    screenshot)
        capture_screenshot
        ;;
    *)
        echo "Usage: $0 {start|register|sync|screenshot}"
        echo "  start      - Start sync service loop"
        echo "  register   - Register kiosk once"
        echo "  sync       - Sync once"
        echo "  screenshot - Capture and upload screenshot"
        exit 1
        ;;

# Create named pipe for logging
SYNC_LOG_PIPE="/tmp/edudisplej_sync.log"
mkfifo "$SYNC_LOG_PIPE" 2>/dev/null || true

# Logging function
log_sync_status() {
    local status="$1"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] $status" | tee -a "$SYNC_LOG_PIPE"
}

# Override sync functions to use logging
sync_kiosk() {
    local mac=$(get_mac_address)
    local hostname=$(get_hostname)
    local hw_info=$(get_hw_info)
    
    log_sync_status "🔄 Syncing kiosk..."
    
    response=$(curl -s -X POST "$API_URL?action=sync" \
        -H "Content-Type: application/json" \
        -d "{\"mac\":\"$mac\",\"hostname\":\"$hostname\",\"hw_info\":$hw_info}")
    
    if echo "$response" | grep -q '"success":true'; then
        new_interval=$(echo "$response" | grep -o '"sync_interval":[0-9]*' | cut -d: -f2)
        if [ -n "$new_interval" ]; then
            SYNC_INTERVAL=$new_interval
        fi
        
        screenshot_requested=$(echo "$response" | grep -o '"screenshot_requested":[a-z]*' | cut -d: -f2)
        if [ "$screenshot_requested" = "true" ]; then
            log_sync_status "📸 Screenshot requested"
            capture_screenshot
        fi
        
        log_sync_status "✅ Sync OK (${SYNC_INTERVAL}s)"
        return 0
    else
        log_sync_status "❌ Sync failed"
        return 1
    fi
}

# Update main loop
main() {
    log_sync_status "🚀 EduDisplej Sync Service started"
    
    if [ ! -f "$CONFIG_FILE" ]; then
        log_sync_status "📝 Registering kiosk..."
        register_kiosk || log_sync_status "⚠️ Registration failed"
    fi
    
    while true; do
        sync_kiosk
        sleep "$SYNC_INTERVAL"
    done
}
