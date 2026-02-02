#!/bin/bash
# EduDisplej Screenshot Service
# Separate service for handling screenshots independently
# Reads configuration from /opt/edudisplej/data/config.json
# =============================================================================

SERVICE_VERSION="1.0.0"

set -euo pipefail

# Configuration
CONFIG_DIR="/opt/edudisplej"
DATA_DIR="${CONFIG_DIR}/data"
CONFIG_FILE="${DATA_DIR}/config.json"
LOG_DIR="${CONFIG_DIR}/logs"
LOG_FILE="${LOG_DIR}/screenshot-service.log"
SCREENSHOT_INTERVAL=15  # Default 15 seconds
API_BASE_URL="${EDUDISPLEJ_API_URL:-https://control.edudisplej.sk}"
SCREENSHOT_API="${API_BASE_URL}/api/screenshot_sync.php"

# Create directories
mkdir -p "$DATA_DIR" "$LOG_DIR"

# Logging functions
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [INFO] $*" | tee -a "$LOG_FILE"
}

log_error() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [ERROR] $*" | tee -a "$LOG_FILE" >&2
}

log_debug() {
    if [ "${DEBUG:-false}" = "true" ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] [DEBUG] $*" | tee -a "$LOG_FILE"
    fi
}

# Get MAC address
get_mac_address() {
    local mac=$(ip link show | grep -A1 "state UP" | grep "link/ether" | head -1 | awk '{print $2}' | tr -d ':')
    echo "$mac"
}

# Read config.json to check if screenshot is enabled
is_screenshot_enabled() {
    if [ ! -f "$CONFIG_FILE" ]; then
        log_debug "Config file not found: $CONFIG_FILE"
        return 1
    fi
    
    if command -v jq >/dev/null 2>&1; then
        local enabled=$(jq -r '.screenshot_enabled // false' "$CONFIG_FILE" 2>/dev/null)
        if [ "$enabled" = "true" ]; then
            return 0
        fi
    else
        # Fallback without jq
        if grep -q '"screenshot_enabled"[[:space:]]*:[[:space:]]*true' "$CONFIG_FILE" 2>/dev/null; then
            return 0
        fi
    fi
    
    return 1
}

# Capture screenshot
capture_screenshot() {
    local temp_file="/tmp/screen.png"
    
    log_debug "Capturing screenshot..."
    
    # Remove old temp file if exists
    rm -f "$temp_file"
    
    # Use scrot to capture screenshot
    if command -v scrot >/dev/null 2>&1; then
        DISPLAY=:0 scrot "$temp_file" 2>/dev/null || return 1
    else
        log_error "scrot not installed"
        return 1
    fi
    
    if [ ! -f "$temp_file" ]; then
        log_error "Screenshot file not created"
        return 1
    fi
    
    echo "$temp_file"
}

# Upload screenshot with proper filename format
upload_screenshot() {
    local screenshot_file="$1"
    local mac=$(get_mac_address)
    
    if [ -z "$mac" ]; then
        log_error "Could not determine MAC address"
        return 1
    fi
    
    # Generate filename: scrn_edudisplejmac_YYYYMMDDHHMMSS.png
    local timestamp=$(date '+%Y%m%d%H%M%S')
    local filename="scrn_edudisplej${mac}_${timestamp}.png"
    
    log "Uploading screenshot: $filename"
    
    # Convert to base64
    local base64_data=$(base64 -w 0 "$screenshot_file" 2>/dev/null)
    
    # Prepare JSON request
    local request_data="{\"mac\":\"$mac\",\"filename\":\"$filename\",\"screenshot\":\"data:image/png;base64,$base64_data\"}"
    
    # Upload to API
    local response=$(curl -s -X POST "$SCREENSHOT_API" \
        -H "Content-Type: application/json" \
        -d "$request_data" \
        --max-time 30)
    
    if echo "$response" | grep -q '"success":true'; then
        log "Screenshot uploaded successfully: $filename"
        rm -f "$screenshot_file"
        return 0
    else
        log_error "Screenshot upload failed: $response"
        rm -f "$screenshot_file"
        return 1
    fi
}

# Update last screenshot time in config
update_last_screenshot_time() {
    if [ ! -f "$CONFIG_FILE" ]; then
        return 0
    fi
    
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    if command -v jq >/dev/null 2>&1; then
        local temp_file=$(mktemp)
        jq --arg ts "$timestamp" '.last_screenshot = $ts' "$CONFIG_FILE" > "$temp_file" && mv "$temp_file" "$CONFIG_FILE"
    fi
}

# Main screenshot loop
main() {
    log "=========================================="
    log "EduDisplej Screenshot Service Started"
    log "=========================================="
    log "Version: $SERVICE_VERSION"
    log "Config file: $CONFIG_FILE"
    log "Screenshot interval: ${SCREENSHOT_INTERVAL}s"
    log "=========================================="
    echo ""
    
    while true; do
        # Check if screenshot is enabled in config
        if is_screenshot_enabled; then
            log_debug "Screenshot enabled - capturing..."
            
            # Capture screenshot
            screenshot_file=$(capture_screenshot)
            if [ $? -eq 0 ] && [ -n "$screenshot_file" ]; then
                log_debug "Screenshot captured: $screenshot_file"
                
                # Upload screenshot
                if upload_screenshot "$screenshot_file"; then
                    update_last_screenshot_time
                fi
            else
                log_error "Failed to capture screenshot"
            fi
        else
            log_debug "Screenshot disabled in config - skipping"
        fi
        
        # Wait for next cycle
        sleep "$SCREENSHOT_INTERVAL"
    done
}

# Handle service commands
case "${1:-start}" in
    start)
        main
        ;;
    *)
        echo "Usage: $0 {start}"
        exit 1
        ;;
esac
