#!/bin/bash
# EduDisplej Sync Service - Registration and Module Synchronization
# Enhanced with detailed logging and error reporting
# =============================================================================

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
SYNC_INTERVAL=300  # 5 minutes
CONFIG_DIR="/opt/edudisplej"
LOCAL_WEB_DIR="${CONFIG_DIR}/localweb"
LOOP_FILE="${LOCAL_WEB_DIR}/modules/loop.json"
DOWNLOAD_INFO="${LOCAL_WEB_DIR}/modules/.download_info.json"
DOWNLOAD_SCRIPT="${CONFIG_DIR}/init/edudisplej-download-modules.sh"
STATUS_FILE="${CONFIG_DIR}/sync_status.json"
LOG_DIR="${CONFIG_DIR}/logs"
LOG_FILE="${LOG_DIR}/sync.log"
DEBUG="${EDUDISPLEJ_DEBUG:-false}"  # Enable detailed debug logs via environment variable

# Create directories
mkdir -p "$CONFIG_DIR" "$LOG_DIR"

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
    # Use print_* functions from common.sh wrapped for logging
    log() { print_info "$*" | tee -a "$LOG_FILE"; }
    log_debug() { [ "$DEBUG" = true ] && print_info "[DEBUG] $*" | tee -a "$LOG_FILE" || true; }
    log_error() { print_error "$*" | tee -a "$LOG_FILE" >&2; }
    log_success() { print_success "$*" | tee -a "$LOG_FILE"; }
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

# Sync hardware data (also returns sync interval and update status)
sync_hw_data() {
    local mac=$(get_mac_address)
    local hostname=$(get_hostname)
    local hw_info=$(get_hw_info)
    
    # Get last_update from local loop.json if it exists
    local last_update=""
    if [ -f "$LOOP_FILE" ]; then
        if command -v jq >/dev/null 2>&1; then
            last_update=$(jq -r '.last_update // empty' "$LOOP_FILE" 2>/dev/null)
        fi
    fi
    
    # Build request with last_update if available
    local request_data
    if [ -n "$last_update" ]; then
        request_data="{\"mac\":\"$mac\",\"hostname\":\"$hostname\",\"hw_info\":$hw_info,\"last_update\":\"$last_update\"}"
        log_debug "Sending last_update: $last_update"
    else
        request_data="{\"mac\":\"$mac\",\"hostname\":\"$hostname\",\"hw_info\":$hw_info}"
        log_debug "No local last_update found"
    fi
    
    response=$(curl -s -X POST "$HW_SYNC_API" \
        -H "Content-Type: application/json" \
        -d "$request_data")
    
    if echo "$response" | grep -q '"success":true'; then
        local new_interval
        new_interval=$(echo "$response" | grep -o '"sync_interval":[0-9]*' | cut -d: -f2)
        if [ -n "$new_interval" ]; then
            SYNC_INTERVAL=$new_interval
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
# This compares local loop.json last_update with server's created_at/updated_at
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
    
    log_debug "Local loop last_update: ${local_last_update:-none}"
    
    # Query server for loop configuration
    local response
    response=$(curl -s -X POST "$KIOSK_LOOP_API" -d "device_id=${device_id}" --max-time 30)
    if ! echo "$response" | grep -q '"success":true'; then
        log_error "Loop check failed: $response"
        return 1
    fi
    
    # Get server's loop update timestamp (created_at or updated_at from kiosk_group_modules)
    local server_updated_at
    server_updated_at=$(json_get "$response" "loop_updated_at")
    
    log_debug "Server loop updated_at: ${server_updated_at:-none}"
    
    # Compare timestamps: if no local timestamp, or server is newer, update
    local needs_update=false
    
    if [ -z "$local_last_update" ]; then
        log "No local loop found - downloading from server..."
        needs_update=true
    elif [ -z "$server_updated_at" ]; then
        log_debug "Server has no update timestamp - skipping comparison"
    else
        # Compare timestamps (works with both datetime strings and unix timestamps)
        if [[ "$server_updated_at" > "$local_last_update" ]]; then
            log "Server loop is newer (server: $server_updated_at, local: $local_last_update)"
            needs_update=true
        else
            log_debug "Loop configuration is up-to-date"
        fi
    fi
    
    # Download modules if update is needed
    if [ "$needs_update" = "true" ]; then
        log "Downloading latest loop configuration and modules..."
        if [ -x "$DOWNLOAD_SCRIPT" ]; then
            if bash "$DOWNLOAD_SCRIPT"; then
                log_success "Loop and modules updated successfully"
            else
                log_error "Module update failed"
            fi
        else
            log_error "Download script not found: $DOWNLOAD_SCRIPT"
        fi
    fi
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
    
    http_code=$(curl -s -w "%{http_code}" \
        -X POST "$REGISTRATION_API" \
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
            log "Sync interval updated: ${SYNC_INTERVAL}s"
        fi
        
        # Always check for loop updates if we have a device_id
        if [ -n "$device_id" ] && [ "$device_id" != "unknown" ]; then
            log "Checking for loop configuration changes..."
            check_loop_updates "$device_id"
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

# Sync modules
sync_modules() {
    local kiosk_id=$1
    log "TODO: Implement module sync for kiosk ID: $kiosk_id"
    # Future implementation: download modules from MODULES_API
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
    log "Version: 2.1"
    log "API URL: $REGISTRATION_API"
    log "Sync interval: ${SYNC_INTERVAL}s"
    log "Auto-update: Enabled (daily)"
    log "Debug mode: $DEBUG"
    log "=========================================="
    echo ""
    
    # Run update check on service start
    check_and_update
    
    while true; do
        # Check for daily updates
        check_and_update
        
        if register_and_sync; then
            log "Sync completed successfully"
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