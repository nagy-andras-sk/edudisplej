#!/bin/bash
# EduDisplej Sync Service - Registration and Module Synchronization
# Enhanced with detailed logging and error reporting
# =============================================================================

# Service version
SERVICE_VERSION="1.0.0"

set -euo pipefail

# Source common functions if available
INIT_DIR="/opt/edudisplej/init"
if [[ -f "${INIT_DIR}/common.sh" ]]; then
    source "${INIT_DIR}/common.sh"
fi

# Configuration
API_BASE_URL="${EDUDISPLEJ_API_URL:-https://control.edudisplej.sk}"
REGISTRATION_API="${API_BASE_URL}/api/registration.php"
MODULES_API="${API_BASE_URL}/api/modules_sync.php"
HW_SYNC_API="${API_BASE_URL}/api/hw_data_sync.php"
KIOSK_LOOP_API="${API_BASE_URL}/api/kiosk_loop.php"
CHECK_GROUP_LOOP_UPDATE_API="${API_BASE_URL}/api/check_group_loop_update.php"
VERSION_CHECK_API="${API_BASE_URL}/api/check_versions.php"
SYNC_INTERVAL=300  # 5 minutes
CONFIG_DIR="/opt/edudisplej"
DATA_DIR="${CONFIG_DIR}/data"
CONFIG_FILE="${DATA_DIR}/config.json"
TOKEN_FILE="${CONFIG_DIR}/lic/token"
LOCAL_WEB_DIR="${CONFIG_DIR}/localweb"
LOOP_FILE="${LOCAL_WEB_DIR}/modules/loop.json"
DOWNLOAD_INFO="${LOCAL_WEB_DIR}/modules/.download_info.json"
DOWNLOAD_SCRIPT="${CONFIG_DIR}/init/edudisplej-download-modules.sh"
CONFIG_MANAGER="${CONFIG_DIR}/init/edudisplej-config-manager.sh"
STATUS_FILE="${CONFIG_DIR}/sync_status.json"
SYNC_STATE_FILE="${CONFIG_DIR}/sync_state.json"
LOG_DIR="${CONFIG_DIR}/logs"
LOG_FILE="${LOG_DIR}/sync.log"
VERSION_FILE="${CONFIG_DIR}/local_versions.json"
DEBUG="${EDUDISPLEJ_DEBUG:-false}"  # Enable detailed debug logs via environment variable

# Sync state tracking
LOOP_CHECK_SERVER_UPDATED_AT=""
LOOP_CHECK_LOCAL_UPDATED_AT=""
LOOP_CHECK_NEEDS_UPDATE="false"
LAST_SCREENSHOT_STATUS="not_run"

# Create directories
mkdir -p "$CONFIG_DIR" "$DATA_DIR" "$LOG_DIR"

# Read API token from license file
get_api_token() {
    if [ -f "$TOKEN_FILE" ]; then
        tr -d '\n\r' < "$TOKEN_FILE"
        return 0
    fi
    return 1
}

# Reset kiosk to unconfigured mode on auth failure
reset_to_unconfigured() {
    log_error "🔒 Authorization failed. Resetting to unconfigured mode..."

    # Remove modules and loop configuration
    rm -rf "${LOCAL_WEB_DIR}/modules" 2>/dev/null || true
    mkdir -p "${LOCAL_WEB_DIR}/modules" 2>/dev/null || true
    rm -f "${LOOP_FILE}" 2>/dev/null || true

    # Create unconfigured page if missing
    local unconfigured_page="${LOCAL_WEB_DIR}/unconfigured.html"
    if [ ! -f "$unconfigured_page" ]; then
        cat > "$unconfigured_page" <<'EOF'
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>EduDisplej - Unconfigured</title>
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background: #0f172a; color: #fff; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .card { text-align: center; max-width: 720px; padding: 40px; background: rgba(255,255,255,0.06); border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        h1 { margin-bottom: 12px; font-size: 28px; }
        p { opacity: 0.9; line-height: 1.5; }
        .small { margin-top: 16px; font-size: 13px; opacity: 0.7; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Ez a kijelző még nincs konfigurálva</h1>
        <p>Kérjük, rendeld hozzá a kijelzőt a vezérlőpultban.</p>
        <p class="small">EduDisplej • control.edudisplej.sk</p>
    </div>
</body>
</html>
EOF
    fi

    # Reset config fields
    update_config_field "company_id" "null" || true
    update_config_field "company_name" "" || true
    update_config_field "token" "" || true

    # Restart kiosk display
    if systemctl is-active --quiet edudisplej-kiosk.service 2>/dev/null; then
        systemctl restart edudisplej-kiosk.service 2>/dev/null || true
    fi
}

# Check for authorization failure in API response
is_auth_error() {
    local response="$1"
    echo "$response" | grep -qi '"message"[[:space:]]*:[[:space:]]*"Invalid API token"\|"Authentication required"\|"Unauthorized"\|"Company license is inactive"\|"No valid license key"'
}

# Logging functions (fallback if common.sh not available)
if ! command -v print_info &> /dev/null; then
    log() {
        local level="INFO"
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] [$level] $*" | tee -a "$LOG_FILE"
    }
    
    log_debug() {
        if [ "$DEBUG" = true ]; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] [DEBUG] $*" | tee -a "$LOG_FILE"
        fi
    }
    
    log_error() {
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] [ERROR] $*" | tee -a "$LOG_FILE" >&2
    }
    
    log_success() {
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] [SUCCESS] $*" | tee -a "$LOG_FILE"
    }
else
    # Use print_* functions from common.sh
    log() { print_info "$*" >> "$LOG_FILE"; }
    log_debug() { [ "$DEBUG" = true ] && print_info "[DEBUG] $*" >> "$LOG_FILE" || true; }
    log_error() { print_error "$*" >> "$LOG_FILE"; }
    log_success() { print_success "$*" >> "$LOG_FILE"; }
fi

# Use shared functions from common.sh if available, otherwise define fallbacks
if ! command -v get_mac_address &> /dev/null; then
    get_mac_address() {
        local mac=$(ip link show | grep -A1 "state UP" | grep "link/ether" | head -1 | awk '{print $2}' | tr -d ':')
        log_debug "Detected MAC address: $mac"
        echo "$mac"
    }
fi

if ! command -v get_hostname &> /dev/null; then
    get_hostname() {
        local host=$(hostname)
        log_debug "Detected hostname: $host"
        echo "$host"
    }
fi

if ! command -v get_hw_info &> /dev/null; then
    get_hw_info() {
        log_debug "Collecting hardware information..."
        cat << EOF
{
    "hostname": "$(hostname)",
    "os": "$(lsb_release -ds 2>/dev/null || echo 'Unknown')",
    "kernel": "$(uname -r)",
    "architecture": "$(uname -m)",
    "cpu": "$(grep 'model name' /proc/cpuinfo | head -1 | cut -d: -f2 | xargs || echo 'Unknown')",
    "memory": "$(free -h | awk '/^Mem:/ {print $2}')",
    "uptime": "$(uptime -p)"
}
EOF
    }
fi

if ! command -v get_tech_info &> /dev/null; then
    get_tech_info() {
        local version="unknown"
        local screen_resolution="unknown"
        local screen_status="unknown"
        
        if [ -f "/opt/edudisplej/VERSION" ]; then
            version=$(cat /opt/edudisplej/VERSION 2>/dev/null || echo "unknown")
        fi
        
        if command -v xrandr &>/dev/null; then
            screen_resolution=$(DISPLAY=:0 xrandr 2>/dev/null | grep '\*' | awk '{print $1}' | head -1)
            [ -z "$screen_resolution" ] && screen_resolution="unknown"
        elif command -v xdpyinfo &>/dev/null; then
            screen_resolution=$(DISPLAY=:0 xdpyinfo 2>/dev/null | grep dimensions | awk '{print $2}')
            [ -z "$screen_resolution" ] && screen_resolution="unknown"
        fi
        
        if command -v xset &>/dev/null; then
            local dpms_status=$(DISPLAY=:0 xset q 2>/dev/null | grep "Monitor is" | awk '{print $3}')
            if [ "$dpms_status" = "On" ]; then
                screen_status="on"
            elif [ "$dpms_status" = "Off" ]; then
                screen_status="off"
            else
                if DISPLAY=:0 xset q &>/dev/null; then
                    screen_status="on"
                else
                    screen_status="unknown"
                fi
            fi
        fi
        
        echo "{\"version\":\"$version\",\"screen_resolution\":\"$screen_resolution\",\"screen_status\":\"$screen_status\"}"
    }
fi

# Parse JSON value (use shared function or fallback)
if ! command -v json_get &> /dev/null; then
    json_get() {
        local json="$1"
        local key="$2"
        if command -v jq >/dev/null 2>&1; then
            echo "$json" | jq -r ".$key // empty" 2>/dev/null
        else
            echo "$json" | tr -d '\n\r' | sed -n "s/.*\"$key\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" | head -1
        fi
    }
fi

json_escape() {
    echo "$1" | sed 's/\\/\\\\/g; s/"/\\"/g; s/\t/\\t/g; s/\r/\\r/g; s/\n/\\n/g'
}

timestamp_to_epoch() {
    local ts="$1"
    if [ -z "$ts" ]; then
        echo "0"
        return
    fi

    if date -d "$ts" +%s >/dev/null 2>&1; then
        date -d "$ts" +%s
    else
        echo "0"
    fi
}

is_server_newer() {
    local server_ts="$1"
    local local_ts="$2"
    local server_epoch
    local local_epoch
    server_epoch=$(timestamp_to_epoch "$server_ts")
    local_epoch=$(timestamp_to_epoch "$local_ts")

    if [ "$server_epoch" -gt 0 ] && [ "$local_epoch" -gt 0 ]; then
        [ "$server_epoch" -gt "$local_epoch" ]
    else
        [[ "$server_ts" > "$local_ts" ]]
    fi
}

# Config.json management functions
init_config_file() {
    if [ ! -f "$CONFIG_FILE" ]; then
        log "Initializing centralized config file: $CONFIG_FILE"
        cat > "$CONFIG_FILE" <<'CONFIGEOF'
{
    "company_name": "",
    "company_id": null,
    "kiosk_id": null,
    "device_id": "",
    "token": "",
    "sync_interval": 300,
    "last_update": "",
    "last_sync": "",
    "screenshot_mode": "sync",
    "screenshot_enabled": false,
    "last_screenshot": "",
    "module_versions": {},
    "service_versions": {}
}
CONFIGEOF
        chmod 644 "$CONFIG_FILE"
        log_success "Config file created"
    fi
}

# Update config.json field
update_config_field() {
    local key="$1"
    local value="$2"
    
    init_config_file
    
    if command -v jq >/dev/null 2>&1; then
        local temp_file=$(mktemp)
        
        # Handle different value types
        if [[ "$value" =~ ^[0-9]+$ ]] && [ "$key" != "device_id" ] && [ "$key" != "token" ]; then
            # Numeric value
            jq --arg k "$key" --argjson v "$value" '.[$k] = $v' "$CONFIG_FILE" > "$temp_file" && mv "$temp_file" "$CONFIG_FILE"
        elif [ "$value" = "true" ] || [ "$value" = "false" ]; then
            # Boolean value
            jq --arg k "$key" --argjson v "$value" '.[$k] = $v' "$CONFIG_FILE" > "$temp_file" && mv "$temp_file" "$CONFIG_FILE"
        elif [ "$value" = "null" ]; then
            # Null value
            jq --arg k "$key" '.[$k] = null' "$CONFIG_FILE" > "$temp_file" && mv "$temp_file" "$CONFIG_FILE"
        else
            # String value
            jq --arg k "$key" --arg v "$value" '.[$k] = $v' "$CONFIG_FILE" > "$temp_file" && mv "$temp_file" "$CONFIG_FILE"
        fi
        
        log_debug "Updated config: $key = $value"
    fi
}

# Get config.json field
get_config_field() {
    local key="$1"
    
    if [ ! -f "$CONFIG_FILE" ]; then
        echo ""
        return 1
    fi
    
    if command -v jq >/dev/null 2>&1; then
        jq -r ".$key // empty" "$CONFIG_FILE" 2>/dev/null
    else
        # Fallback without jq
        grep "\"$key\"" "$CONFIG_FILE" | sed 's/.*: *"\?\([^",]*\)"\?.*/\1/' | head -1
    fi
}

# Sync hardware data (also returns sync interval and update status)
sync_hw_data() {
    local mac=$(get_mac_address)
    local hostname=$(get_hostname)
    local hw_info=$(get_hw_info)
    
    # Get technical info (version, screen resolution, screen status)
    local tech_info=$(get_tech_info)
    local version=$(json_get "$tech_info" "version")
    local screen_resolution=$(json_get "$tech_info" "screen_resolution")
    local screen_status=$(json_get "$tech_info" "screen_status")
    
    # Get last_update from local loop.json if it exists
    local last_update=""
    if [ -f "$LOOP_FILE" ]; then
        if command -v jq >/dev/null 2>&1; then
            last_update=$(jq -r '.last_update // empty' "$LOOP_FILE" 2>/dev/null)
        fi
    fi
    
    # Build request with last_update and tech info if available
    local request_data
    if [ -n "$last_update" ]; then
        request_data="{\"mac\":\"$mac\",\"hostname\":\"$hostname\",\"hw_info\":$hw_info,\"last_update\":\"$last_update\",\"version\":\"$version\",\"screen_resolution\":\"$screen_resolution\",\"screen_status\":\"$screen_status\"}"
        log_debug "Sending last_update: $last_update with tech info (v:$version, res:$screen_resolution, status:$screen_status)"
    else
        request_data="{\"mac\":\"$mac\",\"hostname\":\"$hostname\",\"hw_info\":$hw_info,\"version\":\"$version\",\"screen_resolution\":\"$screen_resolution\",\"screen_status\":\"$screen_status\"}"
        log_debug "No local last_update found, sending tech info (v:$version, res:$screen_resolution, status:$screen_status)"
    fi
    
    local token
    token=$(get_api_token) || { reset_to_unconfigured; return 1; }

    response=$(curl -s -X POST "$HW_SYNC_API" \
        -H "Authorization: Bearer $token" \
        -H "Content-Type: application/json" \
        -d "$request_data")

    if is_auth_error "$response"; then
        reset_to_unconfigured
        return 1
    fi
    
    if echo "$response" | grep -q '"success":true'; then
        local new_interval
        new_interval=$(echo "$response" | grep -o '"sync_interval":[0-9]*' | cut -d: -f2)
        if [ -n "$new_interval" ]; then
            SYNC_INTERVAL=$new_interval
            update_config_field "sync_interval" "$new_interval"
        fi
        
        # Update screenshot_enabled from server response
        local screenshot_enabled=$(json_get "$response" "screenshot_enabled")
        if [ -n "$screenshot_enabled" ]; then
            update_config_field "screenshot_enabled" "$screenshot_enabled"
        fi
        
        # Update company information
        local company_id=$(json_get "$response" "company_id")
        local company_name=$(json_get "$response" "company_name")
        local token=$(json_get "$response" "token")
        
        if [ -n "$company_id" ] && [ "$company_id" != "null" ]; then
            update_config_field "company_id" "$company_id"
        fi
        if [ -n "$company_name" ]; then
            update_config_field "company_name" "$company_name"
        fi
        if [ -n "$token" ]; then
            update_config_field "token" "$token"
        fi
        
        # Check if update is needed
        local needs_update
        needs_update=$(json_get "$response" "needs_update")
        if [ "$needs_update" = "true" ]; then
            local update_reason=$(json_get "$response" "update_reason")
            log "⚠ Update needed: $update_reason"
            
            # Trigger module download
            if [ -x "$DOWNLOAD_SCRIPT" ]; then
                log "Downloading latest modules due to server update..."
                if bash "$DOWNLOAD_SCRIPT"; then
                    log_success "Modules updated successfully due to server change"
                    update_config_field "last_update" "$(date '+%Y-%m-%d %H:%M:%S')"
                else
                    log_error "Module update failed"
                fi
            else
                log_error "Download script not found: $DOWNLOAD_SCRIPT"
            fi
        fi
        
        return 0
    else
        log_error "HW data sync failed: $response"
        return 1
    fi
}

# Check loop changes and update modules if needed
# This checks kiosk_group_modules updated_at in the group and company where device belongs
# Enhanced with security: API only responds if device truly belongs to company
check_loop_updates() {
    local device_id="$1"
    [ -z "$device_id" ] && return 0
    
    # Get local last_update timestamp from loop.json
    local local_last_update=""
    if [ -f "$LOOP_FILE" ]; then
        if command -v jq >/dev/null 2>&1; then
            local_last_update=$(jq -r '.last_update // empty' "$LOOP_FILE" 2>/dev/null)
        fi
    fi

    LOOP_CHECK_LOCAL_UPDATED_AT="$local_last_update"
    LOOP_CHECK_SERVER_UPDATED_AT=""
    LOOP_CHECK_NEEDS_UPDATE="false"
    
    log_debug "Local loop last_update: ${local_last_update:-none}"
    
    # Query server for group loop configuration with security check
    # API verifies device belongs to company before responding
    local response
    local token
    token=$(get_api_token) || { reset_to_unconfigured; return 1; }

    response=$(curl -s -X POST "$CHECK_GROUP_LOOP_UPDATE_API" \
        -H "Authorization: Bearer $token" \
        -H "Content-Type: application/json" \
        -d "{\"device_id\":\"${device_id}\"}" \
        --max-time 30)

    if is_auth_error "$response"; then
        reset_to_unconfigured
        return 1
    fi
    
    # Check for authorization errors first
    if echo "$response" | grep -q '"message":"Unauthorized"'; then
        log_error "⚠️ Loop check UNAUTHORIZED: Device does not belong to any company or group access denied"
        return 1
    fi
    
    if ! echo "$response" | grep -q '"success":true'; then
        log_error "Loop check failed: $response"
        return 1
    fi
    
    # Extract response data
    local config_source
    config_source=$(json_get "$response" "config_source")
    local server_updated_at
    server_updated_at=$(json_get "$response" "loop_updated_at")
    local company_name
    company_name=$(json_get "$response" "company_name")
    local group_id
    group_id=$(json_get "$response" "group_id")

    LOOP_CHECK_SERVER_UPDATED_AT="$server_updated_at"
    
    log "📋 Loop version check: Company='$company_name', Source='$config_source', Group='${group_id:-none}'"
    log_debug "Server loop updated_at: ${server_updated_at:-none} (from $config_source)"
    
    # Compare timestamps: if no local timestamp, or server is newer, update
    local needs_update=false
    
    if [ -z "$local_last_update" ]; then
        log "🔄 No local loop found - downloading initial configuration from server..."
        needs_update=true
    elif [ -z "$server_updated_at" ]; then
        log_debug "Server has no update timestamp - skipping comparison"
    else
        # Compare timestamps (prefers epoch comparison, falls back to string compare)
        if is_server_newer "$server_updated_at" "$local_last_update"; then
            log "⬆️ Server loop is newer - update required (server: $server_updated_at, local: $local_last_update)"
            needs_update=true
        else
            log_debug "✓ Loop configuration is up-to-date"
        fi
    fi

    LOOP_CHECK_NEEDS_UPDATE="$needs_update"
    
    # Download modules if update is needed
    if [ "$needs_update" = "true" ]; then
        log "📥 Downloading latest loop configuration and modules from kiosk_group_modules..."
        if [ -x "$DOWNLOAD_SCRIPT" ]; then
            if bash "$DOWNLOAD_SCRIPT"; then
                log_success "✅ Loop and modules updated successfully"
                update_config_field "last_update" "$(date '+%Y-%m-%d %H:%M:%S')"
                
                # Restart browser to apply new loop configuration
                log "🔄 Restarting kiosk display to apply new configuration..."
                if systemctl is-active --quiet edudisplej-kiosk.service 2>/dev/null; then
                    if systemctl restart edudisplej-kiosk.service 2>/dev/null; then
                        log_success "✅ Kiosk display restarted successfully"
                    else
                        log_error "❌ Failed to restart kiosk display service"
                    fi
                else
                    log "⚠️ Kiosk display service not active - skipping restart"
                fi
            else
                log_error "❌ Module update failed"
            fi
        else
            log_error "❌ Download script not found: $DOWNLOAD_SCRIPT"
        fi
    fi
}

# Screenshot capture and upload function
capture_and_upload_screenshot() {
    LAST_SCREENSHOT_STATUS="running"
    log "📸 Screenshot: Capturing..."
    
    if ! command -v scrot >/dev/null 2>&1; then
        log_error "📸 Screenshot failed: 'scrot' not installed (run: apt-get install scrot)"
        LAST_SCREENSHOT_STATUS="error"
        return 0
    fi
    
    # Try to capture screenshot
    local temp_file="/tmp/edudisplej_screenshot_$$.png"
    if ! DISPLAY=:0 timeout 5 scrot "$temp_file" 2>/dev/null; then
        log_error "📸 Screenshot failed: Unable to capture screen (check DISPLAY=:0 and X server)"
        LAST_SCREENSHOT_STATUS="error"
        rm -f "$temp_file"
        return 0
    fi
    
    if [ ! -f "$temp_file" ] || [ ! -s "$temp_file" ]; then
        log_error "📸 Screenshot failed: File empty or not created"
        LAST_SCREENSHOT_STATUS="error"
        rm -f "$temp_file"
        return 0
    fi
    
    # Get MAC address for filename
    local mac=$(get_mac_address)
    local timestamp=$(date '+%Y%m%d%H%M%S')
    local filename="scrn_edudisplej${mac}_${timestamp}.png"
    
    # Encode to base64 and upload
    local base64_data=$(base64 -w 0 "$temp_file" 2>/dev/null)
    if [ -z "$base64_data" ]; then
        log_error "📸 Screenshot failed: Unable to encode to base64"
        LAST_SCREENSHOT_STATUS="error"
        rm -f "$temp_file"
        return 0
    fi
    
    log "📸 Uploading screenshot ($filename)..."
    
    # Upload screenshot
    local screenshot_api="${API_BASE_URL}/api/screenshot_sync.php"
    local token
    token=$(get_api_token) || { reset_to_unconfigured; return 1; }

    local upload_response=$(curl -s -X POST "$screenshot_api" \
        -H "Authorization: Bearer $token" \
        -H "Content-Type: application/json" \
        -d "{\"mac\":\"$mac\",\"filename\":\"$filename\",\"screenshot\":\"data:image/png;base64,$base64_data\"}" \
        --max-time 30 --connect-timeout 10 2>&1)

    if is_auth_error "$upload_response"; then
        reset_to_unconfigured
        LAST_SCREENSHOT_STATUS="error"
        return 1
    fi
    
    if echo "$upload_response" | grep -q '"success":true'; then
        log_success "📸 Screenshot uploaded successfully: $filename"
        # Update last_screenshot time in config
        local now=$(date '+%Y-%m-%d %H:%M:%S')
        update_config_field "last_screenshot" "$now"
        LAST_SCREENSHOT_STATUS="success"
    else
        log_error "📸 Screenshot upload failed: $upload_response"
        LAST_SCREENSHOT_STATUS="error"
    fi
    
    # Cleanup
    rm -f "$temp_file"
    return 0
}

# Register kiosk and sync
register_and_sync() {
    local mac=$(get_mac_address)
    local hostname=$(get_hostname)
    local hw_info=$(get_hw_info)
    
    log "=========================================="
    log "Starting sync cycle..."
    log "=========================================="
    log_debug "MAC: $mac"
    log_debug "Hostname: $hostname"
    log_debug "API URL: $REGISTRATION_API"
    
    # Prepare request body
    local request_body="{\"mac\":\"$mac\",\"hostname\":\"$hostname\",\"hw_info\":$hw_info}"
    log_debug "Request body: $request_body"
    
    # Create temp file for response
    local response_file=$(mktemp)
    local headers_file=$(mktemp)
    
    # Make API call with detailed logging
    log "Calling registration API..."
    
    local token
    token=$(get_api_token) || { reset_to_unconfigured; return 1; }

    http_code=$(curl -s -w "%{http_code}" \
        -X POST "$REGISTRATION_API" \
        -H "Authorization: Bearer $token" \
        -H "Content-Type: application/json" \
        -d "$request_body" \
        --max-time 30 \
        --connect-timeout 10 \
        -o "$response_file" \
        -D "$headers_file" \
        2>&1 || echo "000")
    
    response=$(cat "$response_file" 2>/dev/null || echo '{"success":false,"message":"Empty response"}')
    
    log_debug "HTTP Status Code: $http_code"
    log_debug "Response Headers:"
    log_debug "$(cat "$headers_file" 2>/dev/null)"
    log_debug "Response Body: $response"
    
    # Clean up temp files
    rm -f "$response_file" "$headers_file"
    
    # Check HTTP status
    if [ "$http_code" != "200" ]; then
        log_error "HTTP request failed with status code: $http_code"
        
        case "$http_code" in
            000)
                log_error "Connection failed - no response from server"
                log_error "Check network connectivity and API URL"
                ;;
            404)
                log_error "API endpoint not found (404)"
                log_error "Check API URL: $REGISTRATION_API"
                ;;
            500)
                log_error "Server error (500) - API backend issue"
                log_error "Check server logs at control panel"
                ;;
            503)
                log_error "Service unavailable (503)"
                ;;
            *)
                log_error "Unexpected HTTP status: $http_code"
                ;;
        esac
        
        write_error_status "HTTP $http_code error" "$response"
        return 1
    fi
    
    # Parse JSON response
    if is_auth_error "$response"; then
        reset_to_unconfigured
        return 1
    fi
    log "Parsing API response..."
    
    # Check for debug information and display it
    if echo "$response" | grep -q '"debug"'; then
        log "=== DEBUG INFORMATION ==="
        
        # Extract and display debug keys
        if command -v jq >/dev/null 2>&1; then
            # Use jq if available for nice formatting - handles nested structures properly
            echo "$response" | jq -r '.debug | to_entries[] | "\(.key): \(.value)"' 2>/dev/null | while IFS= read -r line; do
                log_debug "$line"
            done
        else
            # Fallback: log that debug section exists but needs jq for full parsing
            log_debug "Debug information available in response (install 'jq' for formatted output)"
            log_debug "Raw response: $response"
        fi
        
        log "=== END DEBUG ==="
    fi
    
    if command -v jq >/dev/null 2>&1; then
        success=$(echo "$response" | jq -r '.success // false' 2>/dev/null || echo "false")
        kiosk_id=$(echo "$response" | jq -r '.kiosk_id // 0' 2>/dev/null || echo "0")
        device_id=$(echo "$response" | jq -r '.device_id // "unknown"' 2>/dev/null || echo "unknown")
        is_configured=$(echo "$response" | jq -r '.is_configured // false' 2>/dev/null || echo "false")
        company_assigned=$(echo "$response" | jq -r '.company_assigned // false' 2>/dev/null || echo "false")
        company_name=$(echo "$response" | jq -r '.company_name // "Unknown"' 2>/dev/null || echo "Unknown")
        group_name=$(echo "$response" | jq -r '.group_name // "Unknown"' 2>/dev/null || echo "Unknown")
        error_msg=$(echo "$response" | jq -r '.message // "Unknown error"' 2>/dev/null || echo "Unknown error")
    else
        compact_response=$(echo "$response" | tr -d '\n\r')
        success=$(echo "$compact_response" | sed -n 's/.*"success"[[:space:]]*:[[:space:]]*\(true\|false\).*/\1/p' | head -1)
        [ -z "$success" ] && success="false"
        kiosk_id=$(echo "$compact_response" | sed -n 's/.*"kiosk_id"[[:space:]]*:[[:space:]]*\([0-9]\+\).*/\1/p' | head -1)
        [ -z "$kiosk_id" ] && kiosk_id="0"
        device_id=$(echo "$compact_response" | sed -n 's/.*"device_id"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -1)
        [ -z "$device_id" ] && device_id="unknown"
        is_configured=$(echo "$compact_response" | sed -n 's/.*"is_configured"[[:space:]]*:[[:space:]]*\(true\|false\).*/\1/p' | head -1)
        [ -z "$is_configured" ] && is_configured="false"
        company_assigned=$(echo "$compact_response" | sed -n 's/.*"company_assigned"[[:space:]]*:[[:space:]]*\(true\|false\).*/\1/p' | head -1)
        [ -z "$company_assigned" ] && company_assigned="false"
        company_name=$(echo "$compact_response" | sed -n 's/.*"company_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -1)
        [ -z "$company_name" ] && company_name="Unknown"
        group_name=$(echo "$compact_response" | sed -n 's/.*"group_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -1)
        [ -z "$group_name" ] && group_name="Unknown"
        error_msg=$(echo "$compact_response" | sed -n 's/.*"message"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -1)
        [ -z "$error_msg" ] && error_msg="Unknown error"
    fi

    if [ "$success" = "true" ]; then
        # Extract fields already parsed above
        
        log_success "✓ Sync successful!"
        log "  Kiosk ID: $kiosk_id"
        log "  Device ID: $device_id"
        log "  Company: $company_name"
        log "  Group: $group_name"
        log "  Configured: $is_configured"
        log "  Company assigned: $company_assigned"
        
        # Create kiosk.conf with device_id
        KIOSK_CONF="${CONFIG_DIR}/kiosk.conf"
        if [ ! -f "$KIOSK_CONF" ] || ! grep -q "DEVICE_ID=" "$KIOSK_CONF" 2>/dev/null; then
            log "Creating kiosk configuration file..."
            cat > "$KIOSK_CONF" <<EOF
# EduDisplej Kiosk Configuration
# Auto-generated by sync service
DEVICE_ID=$device_id
KIOSK_ID=$kiosk_id
EOF
            chmod 644 "$KIOSK_CONF"
            log_success "✓ Created kiosk.conf with DEVICE_ID=$device_id"
        fi
        
        # Write status file
        write_success_status "$kiosk_id" "$device_id" "$is_configured" "$company_name" "$group_name"
        
        # Update sync interval from server
        if sync_hw_data; then
            log "Sync interval: ${SYNC_INTERVAL}s"
        fi
        
        # Always check for loop updates if we have a device_id
        if [ -n "$device_id" ] && [ "$device_id" != "unknown" ]; then
            log "Checking for loop configuration changes..."
            check_loop_updates "$device_id"
            
            # Get loop version for logging and DB update
            local loop_updated_at=""
            if [ -f "$LOOP_FILE" ]; then
                if command -v jq >/dev/null 2>&1; then
                    loop_updated_at=$(jq -r '.last_update // empty' "$LOOP_FILE" 2>/dev/null)
                fi
            fi
            
            # Update sync timestamps on server
            local timestamp_update_api="${API_BASE_URL}/api/update_sync_timestamp.php"
            local sync_timestamp=$(date '+%Y-%m-%d %H:%M:%S')
            
            local timestamp_data="{\"mac\":\"$mac\",\"last_sync\":\"$sync_timestamp\""
            if [ -n "$loop_updated_at" ]; then
                timestamp_data="${timestamp_data},\"loop_last_update\":\"$loop_updated_at\""
                log "Loop version: $loop_updated_at (local)"
            fi
            timestamp_data="${timestamp_data}}"
            
            local token
            token=$(get_api_token) || { reset_to_unconfigured; return 1; }

            curl -s -X POST "$timestamp_update_api" \
                -H "Authorization: Bearer $token" \
                -H "Content-Type: application/json" \
                -d "$timestamp_data" \
                --max-time 10 >/dev/null 2>&1 || log_debug "Failed to update sync timestamp"
            
            log "Last sync: $sync_timestamp"
            
            # Collect and upload logs
            log_debug "Uploading logs to server..."
            collect_and_upload_logs "$device_id"
        fi
        
        # Notify if not fully configured
        if [ "$is_configured" = "false" ]; then
            log "Note: Device not fully configured yet"
            log "Visit: https://control.edudisplej.sk/admin/"
        fi
        
        return 0
    else
        log_error "✗ Sync failed: $error_msg"
        log_error "Full response: $response"
        
        write_error_status "$error_msg" "$response"
        write_sync_state "error" "$error_msg"
        return 1
    fi
}

# Write success status
write_success_status() {
    local kiosk_id=$1
    local device_id=$2
    local is_configured=$3
    local company_name=$4
    local group_name=$5
    
    # Calculate next sync time with proper fallback for BSD date
    local next_sync
    if date -d "+${SYNC_INTERVAL} seconds" '+%Y-%m-%d %H:%M:%S' >/dev/null 2>&1; then
        # GNU date
        next_sync=$(date -d "+${SYNC_INTERVAL} seconds" '+%Y-%m-%d %H:%M:%S')
    elif date -v +${SYNC_INTERVAL}S '+%Y-%m-%d %H:%M:%S' >/dev/null 2>&1; then
        # BSD date
        next_sync=$(date -v +${SYNC_INTERVAL}S '+%Y-%m-%d %H:%M:%S')
    else
        # Fallback: just use current time
        next_sync=$(date '+%Y-%m-%d %H:%M:%S')
    fi
    
    cat > "$STATUS_FILE" <<EOF
{
    "last_sync": "$(date '+%Y-%m-%d %H:%M:%S')",
    "status": "success",
    "kiosk_id": $kiosk_id,
    "device_id": "$device_id",
    "company_name": "$company_name",
    "group_name": "$group_name",
    "is_configured": $is_configured,
    "next_sync": "$next_sync",
    "error": null
}
EOF
    log_debug "Status file updated: $STATUS_FILE"
    
    # Update centralized config.json
    update_config_field "device_id" "$device_id"
    update_config_field "kiosk_id" "$kiosk_id"
    update_config_field "company_name" "$company_name"
    update_config_field "last_sync" "$(date '+%Y-%m-%d %H:%M:%S')"
}

# Write error status
write_error_status() {
    local error_msg=$1
    local response=$2
    
    # Try to use jq for proper JSON handling if available
    if command -v jq >/dev/null 2>&1; then
        jq -n \
            --arg last_sync "$(date '+%Y-%m-%d %H:%M:%S')" \
            --arg status "error" \
            --arg error "$error_msg" \
            --arg response "$response" \
            --arg next_retry "$(date -d "+60 seconds" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -v +60S '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date '+%Y-%m-%d %H:%M:%S')" \
            '{last_sync: $last_sync, status: $status, error: $error, response: $response, next_retry: $next_retry}' \
            > "$STATUS_FILE"
    else
        # Fallback: escape JSON special characters manually
        local escaped_response=$(echo "$response" | sed 's/\\/\\\\/g; s/"/\\"/g; s/\t/\\t/g' | tr -d '\n\r')
        
        cat > "$STATUS_FILE" <<EOF
{
    "last_sync": "$(date '+%Y-%m-%d %H:%M:%S')",
    "status": "error",
    "error": "$error_msg",
    "response": "$escaped_response",
    "next_retry": "$(date -d "+60 seconds" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -v +60S '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date '+%Y-%m-%d %H:%M:%S')"
}
EOF
    fi
    log_debug "Error status written: $STATUS_FILE"
}

write_sync_state() {
    local status="$1"
    local error_msg="$2"

    local loop_local=""
    if [ -f "$LOOP_FILE" ] && command -v jq >/dev/null 2>&1; then
        loop_local=$(jq -r '.last_update // empty' "$LOOP_FILE" 2>/dev/null)
    fi

    local sync_interval
    sync_interval=$(get_config_field "sync_interval")
    local screenshot_mode
    screenshot_mode=$(get_config_field "screenshot_mode")
    local screenshot_enabled
    screenshot_enabled=$(get_config_field "screenshot_enabled")

    local error_escaped
    error_escaped=$(json_escape "$error_msg")

    cat > "$SYNC_STATE_FILE" <<EOF
{
    "timestamp": "$(date -u '+%Y-%m-%dT%H:%M:%SZ')",
    "status": "${status}",
    "error": "${error_escaped}",
    "device_id": "$(get_config_field "device_id")",
    "kiosk_id": "$(get_config_field "kiosk_id")",
    "company_id": "$(get_config_field "company_id")",
    "sync_interval": ${sync_interval:-0},
    "loop_last_update_local": "${loop_local}",
    "loop_last_update_server": "${LOOP_CHECK_SERVER_UPDATED_AT}",
    "loop_needs_update": ${LOOP_CHECK_NEEDS_UPDATE},
    "screenshot_mode": "${screenshot_mode}",
    "screenshot_enabled": ${screenshot_enabled:-false},
    "screenshot_status": "${LAST_SCREENSHOT_STATUS}"
}
EOF
}

# Sync modules
sync_modules() {
    local kiosk_id=$1
    log "TODO: Implement module sync for kiosk ID: $kiosk_id"
    # Future implementation: download modules from MODULES_API
}

# Collect and upload logs to server
collect_and_upload_logs() {
    local device_id="$1"
    [ -z "$device_id" ] && return 0
    
    log_debug "Collecting logs for upload..."
    
    local logs_json="["
    local first=true
    
    # Collect recent errors and warnings from sync log
    if [ -f "$LOG_FILE" ]; then
        while IFS= read -r line; do
            # Only send ERROR and WARNING logs
            if echo "$line" | grep -qE "\[ERROR\]|\[WARNING\]"; then
                # Extract log level and message
                local timestamp=$(echo "$line" | sed -n 's/^\[\([^]]*\)\].*/\1/p')
                local level=$(echo "$line" | sed -n 's/.*\[\(ERROR\|WARNING\)\].*/\1/p' | tr '[:upper:]' '[:lower:]')
                local message=$(echo "$line" | sed 's/^[^]]*\] \[[^]]*\] //')
                
                # Build JSON entry
                if [ "$first" = true ]; then
                    first=false
                else
                    logs_json+=","
                fi
                
                # Escape quotes in message
                message=$(echo "$message" | sed 's/"/\\"/g' | tr -d '\n\r')
                
                logs_json+="{\"type\":\"sync\",\"level\":\"$level\",\"message\":\"$message\",\"timestamp\":\"$timestamp\"}"
            fi
        done < <(tail -100 "$LOG_FILE" 2>/dev/null)
    fi
    
    # Collect systemd service errors if available
    if command -v journalctl >/dev/null 2>&1; then
        local service_logs=$(journalctl -u edudisplej-kiosk.service -u edudisplej-sync.service --since "5 minutes ago" -p err -n 20 --no-pager 2>/dev/null || true)
        if [ -n "$service_logs" ]; then
            while IFS= read -r line; do
                if [ -n "$line" ]; then
                    if [ "$first" = false ]; then
                        logs_json+=","
                    fi
                    first=false
                    
                    local message=$(echo "$line" | sed 's/"/\\"/g' | tr -d '\n\r')
                    logs_json+="{\"type\":\"systemd\",\"level\":\"error\",\"message\":\"$message\"}"
                fi
            done <<< "$service_logs"
        fi
    fi
    
    logs_json+="]"
    
    # Only send if we have logs
    if [ "$logs_json" = "[]" ]; then
        log_debug "No error/warning logs to upload"
        return 0
    fi
    
    # Send logs to server
    local mac=$(get_mac_address)
    local request_body="{\"mac\":\"$mac\",\"device_id\":\"$device_id\",\"logs\":$logs_json}"
    
    local token
    token=$(get_api_token) || { reset_to_unconfigured; return 1; }

    local response=$(curl -s -X POST "${API_BASE_URL}/api/log_sync.php" \
        -H "Authorization: Bearer $token" \
        -H "Content-Type: application/json" \
        -d "$request_body" \
        --max-time 30)

    if is_auth_error "$response"; then
        reset_to_unconfigured
        return 1
    fi
    
    if echo "$response" | grep -q '"success":true'; then
        local logs_inserted=$(json_get "$response" "logs_inserted")
        log_debug "Uploaded $logs_inserted logs to server"
    else
        log_debug "Log upload failed (non-critical)"
    fi
}

# Check for service updates (version check)
check_service_updates() {
    log_debug "Checking for service updates..."
    
    # Create local version file if it doesn't exist
    if [ ! -f "$VERSION_FILE" ]; then
        log "Creating local version file..."
        echo "{\"edudisplej_sync_service.sh\": \"$SERVICE_VERSION\"}" > "$VERSION_FILE"
    fi
    
    # Query server for current versions
    local response
    local token
    token=$(get_api_token) || { reset_to_unconfigured; return 1; }

    response=$(curl -s -X GET "$VERSION_CHECK_API" \
        -H "Authorization: Bearer $token" \
        --max-time 10 2>/dev/null || echo '{"success":false}')
    
    if ! echo "$response" | grep -q '"success":true'; then
        log_debug "Version check failed or unavailable"
        return 0
    fi
    
    # Check if this service needs update
    local server_version
    if command -v jq >/dev/null 2>&1; then
        server_version=$(echo "$response" | jq -r '.versions["edudisplej_sync_service.sh"] // empty')
    else
        server_version=$(echo "$response" | grep -o '"edudisplej_sync_service.sh":"[^"]*"' | cut -d'"' -f4)
    fi
    
    if [ -n "$server_version" ] && [ "$server_version" != "$SERVICE_VERSION" ]; then
        log "⚠ Service update available: $SERVICE_VERSION -> $server_version"
        log "Downloading updated service..."
        
        # Download updated service
        local temp_file="/tmp/edudisplej_sync_service.sh.new"
        if curl -s -o "$temp_file" "${API_BASE_URL%/api*}/install/init/edudisplej_sync_service.sh?token=${token}" \
            -H "Authorization: Bearer $token" \
            --max-time 30; then
            # Verify it's a valid script
            if head -1 "$temp_file" | grep -q "^#!/bin/bash"; then
                # Backup current version
                cp "${INIT_DIR}/edudisplej_sync_service.sh" "${INIT_DIR}/edudisplej_sync_service.sh.backup"
                # Replace with new version
                cp "$temp_file" "${INIT_DIR}/edudisplej_sync_service.sh"
                chmod +x "${INIT_DIR}/edudisplej_sync_service.sh"
                rm -f "$temp_file"
                
                log_success "Service updated to version $server_version"
                log "Restarting service..."
                
                # Update local version file
                if command -v jq >/dev/null 2>&1; then
                    jq --arg v "$server_version" '.["edudisplej_sync_service.sh"] = $v' "$VERSION_FILE" > "${VERSION_FILE}.tmp" && mv "${VERSION_FILE}.tmp" "$VERSION_FILE"
                fi
                
                # Restart the service
                systemctl restart edudisplej-sync.service 2>/dev/null || true
                exit 0
            else
                log_error "Downloaded file is not a valid script"
                rm -f "$temp_file"
            fi
        else
            log_error "Failed to download updated service"
        fi
    else
        log_debug "Service is up to date (version $SERVICE_VERSION)"
    fi
}

# Check for system updates (runs daily)
check_and_update() {
    local update_check_file="/tmp/edudisplej_update_check"
    local update_interval=$((24 * 3600))  # 24 hours
    
    # Create file if it doesn't exist
    if [ ! -f "$update_check_file" ]; then
        touch "$update_check_file"
    fi
    
    # Check if update check has been done in the last 24 hours
    local last_check=$(stat -c %Y "$update_check_file" 2>/dev/null || echo 0)
    local current_time=$(date +%s)
    local time_diff=$((current_time - last_check))
    
    if [ $time_diff -ge $update_interval ]; then
        log "Checking for system updates..."
        
        # Run update.sh in background (so it doesn't block sync)
        if [ -x "/opt/edudisplej/init/update.sh" ]; then
            log "Running system update (this may take a few minutes)..."
            if bash "/opt/edudisplej/init/update.sh" >> "$LOG_DIR/update.log" 2>&1; then
                log_success "System update completed successfully"
            else
                log_error "System update failed (non-critical - sync will continue)"
            fi
        fi
        
        # Update check timestamp
        touch "$update_check_file"
    else
        # Calculate remaining time until next update check
        local remaining=$((update_interval - time_diff))
        log_debug "Next update check in $remaining seconds (~$(($remaining / 3600)) hours)"
    fi
}

# Main loop
main() {
    log "=========================================="
    log "EduDisplej Sync Service Started"
    log "=========================================="
    log "Version: $SERVICE_VERSION"
    log "API URL: $REGISTRATION_API"
    log "Sync interval: ${SYNC_INTERVAL}s"
    log "Auto-update: Enabled (daily)"
    log "Debug mode: $DEBUG"
    log "=========================================="
    echo ""

    init_config_file
    if [ -z "$(get_config_field "screenshot_mode")" ]; then
        update_config_field "screenshot_mode" "sync"
    fi
    
    # Run update check on service start
    check_and_update
    
    # Check for service updates
    check_service_updates
    
    while true; do
        # Check for daily updates
        check_and_update
        
        # Check for service updates (every sync cycle)
        check_service_updates
        
        if register_and_sync; then
            log "Sync completed successfully"
            # Take screenshot after sync
            capture_and_upload_screenshot
            write_sync_state "success" ""
            log "Waiting $SYNC_INTERVAL seconds until next sync..."
        else
            log_error "Sync failed - retrying in 60 seconds..."
            sleep 60
            continue
        fi
        
        echo ""
        sleep "$SYNC_INTERVAL"
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