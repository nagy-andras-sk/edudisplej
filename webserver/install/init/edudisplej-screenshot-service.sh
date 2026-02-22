#!/bin/bash
# EduDisplej Screenshot Service
# Separate service for handling screenshots independently
# Reads configuration from /opt/edudisplej/data/config.json
# Saves temp files to /tmp, uploads via API
# =============================================================================

SERVICE_VERSION="1.0.0"

set -euo pipefail

# Configuration
CONFIG_DIR="/opt/edudisplej"
DATA_DIR="${CONFIG_DIR}/data"
CONFIG_FILE="${DATA_DIR}/config.json"
TEMP_DIR="/tmp/edudisplej-screenshots"
TOKEN_FILE="${CONFIG_DIR}/lic/token"
SCREENSHOT_INTERVAL="${SCREENSHOT_INTERVAL:-15}"  # Default 15 seconds; overridden by server policy
API_BASE_URL="${EDUDISPLEJ_API_URL:-https://control.edudisplej.sk}"
SCREENSHOT_API="${API_BASE_URL}/api/screenshot_sync.php"
# File written by the sync service with the last sync response (for screenshot policy)
LAST_SYNC_RESPONSE="${CONFIG_DIR}/last_sync_response.json"

# Create temp directory
mkdir -p "$TEMP_DIR"
chmod 755 "$TEMP_DIR" 2>/dev/null || true

# Logging functions - use echo only (systemd journal handles it)
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [INFO] $*"
}

log_error() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [ERROR] $*" >&2
}

log_debug() {
    if [ "${DEBUG:-false}" = "true" ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] [DEBUG] $*"
    fi
}

get_api_token() {
    if [ -f "$TOKEN_FILE" ]; then
        tr -d '\n\r' < "$TOKEN_FILE"
        return 0
    fi
    return 1
}

is_auth_error() {
    local response="$1"
    echo "$response" | grep -qi '"message"[[:space:]]*:[[:space:]]*"Invalid API token"\|"Authentication required"\|"Unauthorized"\|"Company license is inactive"\|"No valid license key"'
}

reset_to_unconfigured() {
    log_error "Authorization failed - switching to unconfigured mode"
    rm -rf "/opt/edudisplej/localweb/modules" 2>/dev/null || true
    mkdir -p "/opt/edudisplej/localweb/modules" 2>/dev/null || true
    if systemctl is-active --quiet edudisplej-kiosk.service 2>/dev/null; then
        systemctl restart edudisplej-kiosk.service 2>/dev/null || true
    fi
}

# Get MAC address
get_mac_address() {
    local mac=$(ip link show | grep -A1 "state UP" | grep "link/ether" | head -1 | awk '{print $2}' | tr -d ':')
    echo "$mac"
}

# Read config.json to check if screenshot is enabled OR server requested one
is_screenshot_active() {
    # 1. Check server-side screenshot flags from last sync response
    if [ -f "$LAST_SYNC_RESPONSE" ]; then
        local enabled=""
        local requested=""
        if command -v jq >/dev/null 2>&1; then
            enabled=$(jq -r '.screenshot_enabled // false' "$LAST_SYNC_RESPONSE" 2>/dev/null)
            requested=$(jq -r '.screenshot_requested // false' "$LAST_SYNC_RESPONSE" 2>/dev/null)
        else
            enabled=$(grep -o '"screenshot_enabled"[[:space:]]*:[[:space:]]*true' "$LAST_SYNC_RESPONSE" 2>/dev/null | head -1)
            [ -n "$enabled" ] && enabled="true" || enabled="false"
            requested=$(grep -o '"screenshot_requested"[[:space:]]*:[[:space:]]*true' "$LAST_SYNC_RESPONSE" 2>/dev/null | head -1)
            [ -n "$requested" ] && requested="true" || requested="false"
        fi
        if [ "$enabled" = "true" ]; then
            log_debug "screenshot_enabled=true from last sync response"
            return 0
        fi
        if [ "$requested" = "true" ]; then
            log_debug "screenshot_requested=true from last sync response"
            return 0
        fi
    fi

    # 2. Fallback: check local config.json screenshot_enabled flag
    is_screenshot_enabled
}

# Read screenshot interval from server policy (last sync response)
get_screenshot_interval() {
    local interval="$SCREENSHOT_INTERVAL"
    if [ -f "$LAST_SYNC_RESPONSE" ]; then
        local server_interval=""
        if command -v jq >/dev/null 2>&1; then
            server_interval=$(jq -r '.screenshot_interval_seconds // empty' "$LAST_SYNC_RESPONSE" 2>/dev/null)
        else
            server_interval=$(grep -o '"screenshot_interval_seconds"[[:space:]]*:[[:space:]]*[0-9]*' "$LAST_SYNC_RESPONSE" \
                | grep -o '[0-9]*$' | head -1)
        fi
        if [[ "$server_interval" =~ ^[0-9]+$ ]] && [ "$server_interval" -ge 1 ]; then
            interval="$server_interval"
        fi
    fi
    echo "$interval"
}

# Read config.json to check if screenshot is enabled (legacy flag)
is_screenshot_enabled() {
    if [ ! -f "$CONFIG_FILE" ]; then
        log_debug "Config file not found: $CONFIG_FILE"
        return 1
    fi

    local mode=""
    if command -v jq >/dev/null 2>&1; then
        mode=$(jq -r '.screenshot_mode // empty' "$CONFIG_FILE" 2>/dev/null)
    else
        mode=$(grep -o '"screenshot_mode":"[^"]*"' "$CONFIG_FILE" | cut -d'"' -f4 | head -1)
    fi

    if [ "$mode" = "sync" ]; then
        log_debug "Screenshot mode is 'sync' - handled by sync service"
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
    local temp_file="${TEMP_DIR}/screen_$$.png"
    
    log_debug "Capturing screenshot to $temp_file..."
    
    # Remove old temp file if exists
    rm -f "$temp_file"
    
    # Use scrot to capture screenshot
    if command -v scrot >/dev/null 2>&1; then
        DISPLAY=:0 scrot "$temp_file" 2>/dev/null || {
            log_error "Failed to capture screenshot with scrot"
            return 1
        }
    else
        log_error "scrot not installed. Install with: apt-get install -y scrot"
        return 1
    fi
    
    if [ ! -f "$temp_file" ]; then
        log_error "Screenshot file not created at $temp_file"
        return 1
    fi
    
    log_debug "Screenshot captured successfully: $temp_file ($(du -h "$temp_file" | cut -f1))"
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
    
    log_debug "Uploading screenshot: $filename (from $screenshot_file)"
    
    # Convert to base64
    local base64_data=$(base64 -w 0 "$screenshot_file" 2>/dev/null)
    if [ -z "$base64_data" ]; then
        log_error "Failed to encode screenshot to base64"
        rm -f "$screenshot_file"
        return 1
    fi
    
    # Prepare JSON request
    local request_data="{\"mac\":\"$mac\",\"filename\":\"$filename\",\"screenshot\":\"data:image/png;base64,$base64_data\"}"
    
    log_debug "Sending to API: $SCREENSHOT_API"
    
    # Upload to API with better error handling
    local token
    token=$(get_api_token) || { reset_to_unconfigured; return 1; }

    local response=$(curl -s -X POST "$SCREENSHOT_API" \
        -H "Authorization: Bearer $token" \
        -H "Content-Type: application/json" \
        -d "$request_data" \
        --max-time 30 --connect-timeout 10 2>&1)

    if is_auth_error "$response"; then
        reset_to_unconfigured
        rm -f "$screenshot_file"
        return 1
    fi
    
    local curl_code=$?
    if [ $curl_code -ne 0 ]; then
        log_error "Curl error ($curl_code) uploading screenshot: $response"
        rm -f "$screenshot_file"
        return 1
    fi
    
    if echo "$response" | grep -q '"success":true'; then
        log "✓ Screenshot uploaded: $filename"
        rm -f "$screenshot_file"
        return 0
    else
        log_error "API error - Upload failed: $response"
        rm -f "$screenshot_file"
        return 1
    fi
}

# Update last screenshot time in config (silent, non-critical)
update_last_screenshot_time() {
    if [ ! -f "$CONFIG_FILE" ]; then
        return 0
    fi
    
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    if command -v jq >/dev/null 2>&1; then
        # Try to update, but don't fail if we can't write
        local temp_file=$(mktemp)
        if jq --arg ts "$timestamp" '.last_screenshot = $ts' "$CONFIG_FILE" > "$temp_file" 2>/dev/null; then
            if mv "$temp_file" "$CONFIG_FILE" 2>/dev/null; then
                chmod 664 "$CONFIG_FILE" 2>/dev/null || true
            else
                # Failed to move (permission issue) - just delete temp file
                rm -f "$temp_file"
                log_debug "Could not update last_screenshot time (permission denied - expected on some setups)"
            fi
        else
            rm -f "$temp_file"
        fi
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
        # Determine screenshot interval from server policy
        local current_interval
        current_interval=$(get_screenshot_interval)

        # Check if screenshot is active (server-requested OR locally enabled)
        if is_screenshot_active; then
            log_debug "Screenshot active - capturing (interval: ${current_interval}s)..."
            
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
            log_debug "Screenshot not active (not requested, not enabled) - skipping"
        fi
        
        # Wait for next cycle using server-defined interval
        sleep "$current_interval"
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
