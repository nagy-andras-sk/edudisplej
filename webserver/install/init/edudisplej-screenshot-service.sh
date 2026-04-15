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
SCREENSHOT_INTERVAL="${SCREENSHOT_INTERVAL:-1800}"  # Default 30 minutes; overridden by server policy
SCREENSHOT_POLL_INTERVAL="${SCREENSHOT_POLL_INTERVAL:-15}"
API_BASE_URL="${EDUDISPLEJ_API_URL:-https://control.edudisplej.sk}"
SCREENSHOT_API="${API_BASE_URL}/api/screenshot_sync.php"
# File written by the sync service with the last sync response (for screenshot policy)
LAST_SYNC_RESPONSE="${CONFIG_DIR}/last_sync_response.json"
KIOSK_CONF="${CONFIG_DIR}/kiosk.conf"

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
    local mac_lines
    local mac
    local device_id_suffix=""

    mac_lines=$(ip -o link show | awk '/link\/ether/ {print $2" "$17}')

    # Prefer interface that matches DEVICE_ID suffix from kiosk.conf when available.
    if [ -f "$KIOSK_CONF" ]; then
        device_id_suffix=$(sed -n 's/^DEVICE_ID=//p' "$KIOSK_CONF" | head -1 | tr -d '\r\n' | tr '[:lower:]' '[:upper:]')
        if [ -n "$device_id_suffix" ]; then
            device_id_suffix="${device_id_suffix: -6}"
            while read -r iface raw_mac; do
                [ -z "$raw_mac" ] && continue
                mac=$(echo "$raw_mac" | tr -d ':' | tr '[:lower:]' '[:upper:]')
                if [ "${mac: -6}" = "$device_id_suffix" ]; then
                    echo "$mac"
                    return 0
                fi
            done <<< "$mac_lines"
        fi
    fi

    # Fallback preference: wired interface first.
    while read -r iface raw_mac; do
        [ "$iface" = "eth0:" ] || continue
        mac=$(echo "$raw_mac" | tr -d ':' | tr '[:lower:]' '[:upper:]')
        if [ -n "$mac" ]; then
            echo "$mac"
            return 0
        fi
    done <<< "$mac_lines"

    # Last fallback: first available MAC.
    mac=$(echo "$mac_lines" | awk 'NR==1 {print $2}' | tr -d ':' | tr '[:lower:]' '[:upper:]')
    echo "$mac"
}

# Read last sync response to check if screenshot is enabled and active
is_screenshot_active() {
    if [ ! -f "$LAST_SYNC_RESPONSE" ]; then
        return 1
    fi

    local enabled="false"
    local requested="false"
    local interval="0"

    if command -v jq >/dev/null 2>&1; then
        enabled=$(jq -r '.screenshot_enabled // false' "$LAST_SYNC_RESPONSE" 2>/dev/null)
        requested=$(jq -r '.screenshot_requested // false' "$LAST_SYNC_RESPONSE" 2>/dev/null)
        interval=$(jq -r '.screenshot_interval_seconds // 0' "$LAST_SYNC_RESPONSE" 2>/dev/null)
    else
        enabled=$(grep -o '"screenshot_enabled"[[:space:]]*:[[:space:]]*true' "$LAST_SYNC_RESPONSE" 2>/dev/null | head -1)
        [ -n "$enabled" ] && enabled="true" || enabled="false"
        requested=$(grep -o '"screenshot_requested"[[:space:]]*:[[:space:]]*true' "$LAST_SYNC_RESPONSE" 2>/dev/null | head -1)
        [ -n "$requested" ] && requested="true" || requested="false"
        interval=$(grep -o '"screenshot_interval_seconds"[[:space:]]*:[[:space:]]*[0-9]*' "$LAST_SYNC_RESPONSE" 2>/dev/null | grep -o '[0-9]*$' | head -1)
        [ -n "$interval" ] || interval="0"
    fi

    if [ "$enabled" != "true" ]; then
        log_debug "Screenshot policy disabled in last sync response"
        return 1
    fi

    if [ "$requested" = "true" ] || [ "${interval:-0}" -gt 0 ]; then
        log_debug "Screenshot active from last sync response (interval=${interval:-0})"
        return 0
    fi

    return 1
}

# Read screenshot interval from server policy (last sync response)
get_screenshot_interval() {
    local interval="0"
    if [ -f "$LAST_SYNC_RESPONSE" ]; then
        local enabled="false"
        local server_interval="0"
        if command -v jq >/dev/null 2>&1; then
            enabled=$(jq -r '.screenshot_enabled // false' "$LAST_SYNC_RESPONSE" 2>/dev/null)
            server_interval=$(jq -r '.screenshot_interval_seconds // 0' "$LAST_SYNC_RESPONSE" 2>/dev/null)
        else
            enabled=$(grep -o '"screenshot_enabled"[[:space:]]*:[[:space:]]*true' "$LAST_SYNC_RESPONSE" 2>/dev/null | head -1)
            [ -n "$enabled" ] && enabled="true" || enabled="false"
            server_interval=$(grep -o '"screenshot_interval_seconds"[[:space:]]*:[[:space:]]*[0-9]*' "$LAST_SYNC_RESPONSE" 2>/dev/null | grep -o '[0-9]*$' | head -1)
            [ -n "$server_interval" ] || server_interval="0"
        fi
        if [ "$enabled" = "true" ] && [[ "$server_interval" =~ ^[0-9]+$ ]] && [ "$server_interval" -ge 1 ]; then
            interval="$server_interval"
        fi
    fi
    echo "$interval"
}

get_last_screenshot_epoch() {
    if [ ! -f "$CONFIG_FILE" ]; then
        echo 0
        return 0
    fi

    local last_screenshot=""
    if command -v jq >/dev/null 2>&1; then
        last_screenshot=$(jq -r '.last_screenshot // empty' "$CONFIG_FILE" 2>/dev/null)
    else
        last_screenshot=$(grep -o '"last_screenshot"[[:space:]]*:[[:space:]]*"[^"]*"' "$CONFIG_FILE" 2>/dev/null | head -1 | cut -d'"' -f4)
    fi

    if [ -z "$last_screenshot" ]; then
        echo 0
        return 0
    fi

    date -d "$last_screenshot" +%s 2>/dev/null || echo 0
}

is_capture_due() {
    local interval_seconds="$1"
    local last_capture_epoch
    local now_epoch

    last_capture_epoch=$(get_last_screenshot_epoch)
    now_epoch=$(date +%s)

    if [ "$last_capture_epoch" -le 0 ]; then
        return 0
    fi

    if [ "$interval_seconds" -le 0 ]; then
        return 0
    fi

    [ $((now_epoch - last_capture_epoch)) -ge "$interval_seconds" ]
}

# Legacy local config checks are intentionally not used for activation.
is_screenshot_enabled() {
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
    
    # Prepare JSON request in a temp file to avoid argv size limits.
    local payload_file
    payload_file=$(mktemp)
    printf '{"mac":"%s","filename":"%s","screenshot":"data:image/png;base64,%s"}' "$mac" "$filename" "$base64_data" > "$payload_file"
    
    log_debug "Sending to API: $SCREENSHOT_API"
    
    # Upload to API with better error handling
    local token
    token=$(get_api_token) || { reset_to_unconfigured; return 1; }

    local response=$(curl -s -X POST "$SCREENSHOT_API" \
        -H "Authorization: Bearer $token" \
        -H "Content-Type: application/json" \
        --data-binary "@$payload_file" \
        --max-time 30 --connect-timeout 10 2>&1)

    if is_auth_error "$response"; then
        reset_to_unconfigured
        rm -f "$payload_file"
        rm -f "$screenshot_file"
        return 1
    fi
    
    local curl_code=$?
    if [ $curl_code -ne 0 ]; then
        log_error "Curl error ($curl_code) uploading screenshot: $response"
        rm -f "$payload_file"
        rm -f "$screenshot_file"
        return 1
    fi
    
    if echo "$response" | grep -q '"success":true'; then
        log "✓ Screenshot uploaded: $filename"
        rm -f "$payload_file"
        rm -f "$screenshot_file"
        return 0
    else
        log_error "API error - Upload failed: $response"
        rm -f "$payload_file"
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
            if is_capture_due "$current_interval"; then
                log_debug "Screenshot active - capturing (interval: ${current_interval}s)..."

                # Capture screenshot
                if screenshot_file=$(capture_screenshot); then
                    log_debug "Screenshot captured: $screenshot_file"

                    # Upload screenshot
                    if upload_screenshot "$screenshot_file"; then
                        update_last_screenshot_time
                    fi
                else
                    log_error "Failed to capture screenshot"
                fi
            else
                log_debug "Screenshot active but not due yet (interval: ${current_interval}s)"
            fi
        else
            log_debug "Screenshot not active (not requested, not enabled) - skipping"
        fi
        
        # Poll frequently so watch mode can switch quickly without waiting for the idle interval
        sleep "$SCREENSHOT_POLL_INTERVAL"
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
