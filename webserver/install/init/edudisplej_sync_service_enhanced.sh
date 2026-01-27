#!/bin/bash
# EduDisplej Enhanced Sync Service
# Handles registration and synchronization with control panel
# Includes module synchronization
# =============================================================================

# Configuration
API_BASE_URL="${EDUDISPLEJ_API_URL:-http://control.edudisplej.sk}"
SYNC_INTERVAL=30  # Default 30 seconds
CONFIG_DIR="/opt/edudisplej"
MODULES_DIR="$CONFIG_DIR/modules"
CONFIG_FILE="$CONFIG_DIR/kiosk.conf"

# Ensure directories exist
mkdir -p "$CONFIG_DIR"
mkdir -p "$MODULES_DIR"

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
    
    response=$(curl -s -X POST "$API_BASE_URL/api/registration.php" \
        -H "Content-Type: application/json" \
        -d "{\"mac\":\"$mac\",\"hostname\":\"$hostname\",\"hw_info\":$hw_info}")
    
    if echo "$response" | grep -q '"success":true'; then
        kiosk_id=$(echo "$response" | grep -o '"kiosk_id":[0-9]*' | cut -d: -f2)
        device_id=$(echo "$response" | grep -o '"device_id":"[^"]*"' | cut -d'"' -f4)
        echo "KIOSK_ID=$kiosk_id" > "$CONFIG_FILE"
        echo "DEVICE_ID=$device_id" >> "$CONFIG_FILE"
        echo "MAC=$mac" >> "$CONFIG_FILE"
        echo "Registration successful! Kiosk ID: $kiosk_id, Device ID: $device_id"
        return 0
    else
        echo "Registration failed: $response"
        return 1
    fi
}

# Sync hardware data
sync_hw_data() {
    local mac=$(get_mac_address)
    local hostname=$(get_hostname)
    local hw_info=$(get_hw_info)
    
    response=$(curl -s -X POST "$API_BASE_URL/api/hw_data_sync.php" \
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
        
        echo "HW data sync successful (interval: ${SYNC_INTERVAL}s)"
        return 0
    else
        echo "HW data sync failed: $response"
        return 1
    fi
}

# Sync modules
sync_modules() {
    local mac=$(get_mac_address)
    local device_id=""
    
    # Load device ID from config if exists
    if [ -f "$CONFIG_FILE" ]; then
        source "$CONFIG_FILE"
    fi
    
    response=$(curl -s -X POST "$API_BASE_URL/api/modules_sync.php" \
        -H "Content-Type: application/json" \
        -d "{\"mac\":\"$mac\",\"device_id\":\"$device_id\"}")
    
    if echo "$response" | grep -q '"success":true'; then
        # Parse and save modules
        echo "Modules sync successful"
        
        # Extract modules array and process each module
        # For now, create basic module directories
        mkdir -p "$MODULES_DIR/default"
        mkdir -p "$MODULES_DIR/clock"
        
        # Extract sync_interval from default module if available
        # This is a simplified version - in production you'd use jq to parse JSON properly
        
        # Save modules response for debugging
        echo "$response" > "$MODULES_DIR/.last_sync.json"
        
        return 0
    else
        echo "Modules sync failed: $response"
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
        response=$(curl -s -X POST "$API_BASE_URL/api/screenshot_sync.php" \
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

# Main sync operation
sync_all() {
    echo "$(date): Full sync starting..."
    
    # Sync hardware data (includes public IP)
    if sync_hw_data; then
        echo "✓ Hardware data synced"
    else
        echo "✗ Hardware data sync failed"
    fi
    
    # Sync modules
    if sync_modules; then
        echo "✓ Modules synced"
    else
        echo "✗ Modules sync failed"
    fi
}

# Main sync loop
main() {
    echo "EduDisplej Enhanced Sync Service Starting..."
    echo "API Base URL: $API_BASE_URL"
    
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
        
        if sync_all; then
            echo "Next sync in ${SYNC_INTERVAL} seconds"
        else
            echo "Sync had errors, retrying in ${SYNC_INTERVAL} seconds"
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
        sync_all
        ;;
    sync-hw)
        sync_hw_data
        ;;
    sync-modules)
        sync_modules
        ;;
    screenshot)
        capture_screenshot
        ;;
    *)
        echo "Usage: $0 {start|register|sync|sync-hw|sync-modules|screenshot}"
        echo "  start        - Start sync service loop"
        echo "  register     - Register kiosk once"
        echo "  sync         - Full sync once (hw + modules)"
        echo "  sync-hw      - Sync hardware data only"
        echo "  sync-modules - Sync modules only"
        echo "  screenshot   - Capture and upload screenshot"
        exit 1
        ;;
esac
