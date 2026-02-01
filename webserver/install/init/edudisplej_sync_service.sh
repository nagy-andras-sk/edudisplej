#!/bin/bash
# EduDisplej Sync Service - Registration and Module Synchronization
# Enhanced with detailed logging and error reporting
# =============================================================================

set -euo pipefail

# Configuration
API_BASE_URL="${EDUDISPLEJ_API_URL:-https://control.edudisplej.sk}"
REGISTRATION_API="${API_BASE_URL}/api/registration.php"
MODULES_API="${API_BASE_URL}/api/modules_sync.php"
SYNC_INTERVAL=300  # 5 minutes
CONFIG_DIR="/opt/edudisplej"
STATUS_FILE="${CONFIG_DIR}/sync_status.json"
LOG_DIR="${CONFIG_DIR}/logs"
LOG_FILE="${LOG_DIR}/sync.log"
DEBUG="${EDUDISPLEJ_DEBUG:-false}"  # Enable detailed debug logs via environment variable

# Create directories
mkdir -p "$CONFIG_DIR" "$LOG_DIR"

# Logging functions
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

# Get MAC address
get_mac_address() {
    local mac=$(ip link show | grep -A1 "state UP" | grep "link/ether" | head -1 | awk '{print $2}' | tr -d ':')
    log_debug "Detected MAC address: $mac"
    echo "$mac"
}

# Get hostname
get_hostname() {
    local host=$(hostname)
    log_debug "Detected hostname: $host"
    echo "$host"
}

# Get hardware info
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
    
    if echo "$response" | grep -q '"success":true'; then
        # Extract fields
        kiosk_id=$(echo "$response" | grep -o '"kiosk_id":[0-9]*' | cut -d: -f2 || echo "0")
        device_id=$(echo "$response" | grep -o '"device_id":"[^"]*"' | cut -d'"' -f4 || echo "unknown")
        is_configured=$(echo "$response" | grep -o '"is_configured":[a-z]*' | cut -d: -f2 || echo "false")
        company_assigned=$(echo "$response" | grep -o '"company_assigned":[a-z]*' | cut -d: -f2 || echo "false")
        
        log_success "✓ Sync successful!"
        log "  Kiosk ID: $kiosk_id"
        log "  Device ID: $device_id"
        log "  Configured: $is_configured"
        log "  Company assigned: $company_assigned"
        
        # Write status file
        write_success_status "$kiosk_id" "$device_id" "$is_configured"
        
        # Sync modules if configured
        if [ "$is_configured" = "true" ]; then
            log "Device is configured - syncing modules..."
            sync_modules "$kiosk_id"
        else
            log "Device not yet configured - waiting for admin assignment"
            log "Visit: https://control.edudisplej.sk/admin/"
        fi
        
        return 0
    else
        # Extract error message
        error_msg=$(echo "$response" | grep -o '"message":"[^"]*"' | cut -d'"' -f4 || echo "Unknown error")
        
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

# Main loop
main() {
    log "=========================================="
    log "EduDisplej Sync Service Started"
    log "=========================================="
    log "Version: 2.0"
    log "API URL: $REGISTRATION_API"
    log "Sync interval: ${SYNC_INTERVAL}s"
    log "Debug mode: $DEBUG"
    log "=========================================="
    echo ""
    
    while true; do
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
