#!/bin/bash
# EduDisplej Sync Service - Registration and Module Synchronization
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

# Create directories
mkdir -p "$CONFIG_DIR" "$LOG_DIR"

# Logging function
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

# Get MAC address
get_mac_address() {
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
    
    log "Syncing with server..."
    log "MAC: $mac | Hostname: $hostname"
    
    # Call registration API
    response=$(curl -s -X POST "$REGISTRATION_API" \
        -H "Content-Type: application/json" \
        -d "{\"mac\":\"$mac\",\"hostname\":\"$hostname\",\"hw_info\":$hw_info}" \
        --max-time 30 || echo '{"success":false,"message":"Connection failed"}')
    
    log "Response: $response"
    
    # Parse response and update status file
    if echo "$response" | grep -q '"success":true'; then
        kiosk_id=$(echo "$response" | grep -o '"kiosk_id":[0-9]*' | cut -d: -f2 || echo "0")
        device_id=$(echo "$response" | grep -o '"device_id":"[^"]*"' | cut -d'"' -f4 || echo "unknown")
        is_configured=$(echo "$response" | grep -o '"is_configured":[a-z]*' | cut -d: -f2 || echo "false")
        
        # Write status file
        cat > "$STATUS_FILE" <<EOF
{
    "last_sync": "$(date '+%Y-%m-%d %H:%M:%S')",
    "status": "success",
    "kiosk_id": $kiosk_id,
    "device_id": "$device_id",
    "is_configured": $is_configured,
    "next_sync": "$(date -d "+${SYNC_INTERVAL} seconds" '+%Y-%m-%d %H:%M:%S')"
}
EOF
        
        log "✓ Sync successful! Kiosk ID: $kiosk_id | Device ID: $device_id"
        
        # Sync modules if configured
        if [ "$is_configured" = "true" ]; then
            sync_modules "$kiosk_id"
        fi
        
        return 0
    else
        # Write failed status
        cat > "$STATUS_FILE" <<EOF
{
    "last_sync": "$(date '+%Y-%m-%d %H:%M:%S')",
    "status": "failed",
    "kiosk_id": 0,
    "device_id": "unknown",
    "is_configured": false,
    "error": "$(echo "$response" | grep -o '"message":"[^"]*"' | cut -d'"' -f4 || echo 'Connection failed')",
    "next_sync": "$(date -d "+60 seconds" '+%Y-%m-%d %H:%M:%S')"
}
EOF
        log "✗ Sync failed: $response"
        return 1
    fi
}

# Sync modules
sync_modules() {
    local kiosk_id=$1
    log "Syncing modules for kiosk ID: $kiosk_id"
    
    # TODO: Implement module sync logic
    # - Call modules_sync API
    # - Download module files
    # - Update local HTML loader
}

# Main loop
main() {
    log "=== EduDisplej Sync Service Started ==="
    
    while true; do
        if register_and_sync; then
            log "Waiting $SYNC_INTERVAL seconds until next sync..."
            sleep "$SYNC_INTERVAL"
        else
            log "Sync failed, retrying in 60 seconds..."
            sleep 60
        fi
    done
}

# Handle service commands
case "${1:-start}" in
    start)
        main
        ;;
    *)
        log "Usage: $0 {start}"
        exit 1
        ;;
esac
