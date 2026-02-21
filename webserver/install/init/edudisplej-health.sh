#!/bin/bash
# EduDisplej Health Check Service
# Monitors system and other services health, reports to control panel
# =============================================================================

SERVICE_VERSION="1.0.0"

set -euo pipefail

# Source common functions if available
INIT_DIR="/opt/edudisplej/init"
if [[ -f "${INIT_DIR}/common.sh" ]]; then
    source "${INIT_DIR}/common.sh"
fi

# Configuration
API_BASE_URL="${EDUDISPLEJ_API_URL:-https://control.edudisplej.sk}"
HEALTH_CHECK_API="${API_BASE_URL}/api/health/report.php"
CONFIG_DIR="/opt/edudisplej"
DATA_DIR="${CONFIG_DIR}/data"
CONFIG_FILE="${DATA_DIR}/config.json"
TOKEN_FILE="${CONFIG_DIR}/lic/token"
HEALTH_STATUS_FILE="${CONFIG_DIR}/health_status.json"
LOG_DIR="${CONFIG_DIR}/logs"
LOG_FILE="${LOG_DIR}/health.log"
DEBUG="${EDUDISPLEJ_DEBUG:-false}"

# Health check intervals (in seconds)
HEALTH_CHECK_INTERVAL="${HEALTH_CHECK_INTERVAL:-300}"  # Default: 5 minutes
FAST_LOOP_INTERVAL=5  # When fast loop is enabled

# Create directories
mkdir -p "$CONFIG_DIR" "$DATA_DIR" "$LOG_DIR"

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

# Get system health information
get_system_health() {
    local cpu_temp="N/A"
    local cpu_usage="0"
    local memory_usage="0"
    local disk_usage="0"
    local uptime="0"
    local rpi_model="unknown"
    local os_version="unknown"
    
    # CPU temperature (Raspberry Pi specific)
    if [ -f "/sys/class/thermal/thermal_zone0/temp" ]; then
        cpu_temp=$(cat /sys/class/thermal/thermal_zone0/temp 2>/dev/null | awk '{printf "%.1f", $1/1000}' || echo "N/A")
    fi
    
    # CPU usage (last minute average)
    cpu_usage=$(grep 'cpu ' /proc/stat | awk '{usage=($2+$4)*100/($2+$4+$5)} END {printf "%.1f", usage}' 2>/dev/null || echo "0")
    
    # Memory usage
    memory_usage=$(free | grep Mem | awk '{printf "%.1f", ($3/$2)*100}' 2>/dev/null || echo "0")
    
    # Disk usage (root partition)
    disk_usage=$(df -h / | tail -1 | awk '{print $(NF-1)}' | tr -d '%' 2>/dev/null || echo "0")
    
    # Uptime in seconds
    uptime=$(awk '{print int($1)}' /proc/uptime 2>/dev/null || echo "0")

    if [ -f "/proc/device-tree/model" ]; then
        rpi_model=$(tr -d '\0' < /proc/device-tree/model 2>/dev/null || echo "unknown")
    fi

    if command -v lsb_release >/dev/null 2>&1; then
        os_version=$(lsb_release -ds 2>/dev/null | sed 's/^\"//;s/\"$//' || echo "unknown")
    elif [ -f /etc/os-release ]; then
        os_version=$(grep '^PRETTY_NAME=' /etc/os-release | head -1 | cut -d= -f2- | sed 's/^\"//;s/\"$//' || echo "unknown")
    fi
    
    # Check if system is under stress
    local health_status="healthy"
    if (( $(echo "$cpu_temp > 80" | bc -l 2>/dev/null || echo "0") )); then
        health_status="warning"
    fi
    if (( $(echo "$memory_usage > 90" | bc -l 2>/dev/null || echo "0") )); then
        health_status="critical"
    fi
    if (( $(echo "$disk_usage > 90" | bc -l 2>/dev/null || echo "0") )); then
        health_status="critical"
    fi
    
    cat << EOF
{
    "cpu_temp": "$cpu_temp",
    "cpu_usage": $cpu_usage,
    "memory_usage": $memory_usage,
    "disk_usage": $disk_usage,
    "uptime": $uptime,
    "rpi_model": "${rpi_model}",
    "os_version": "${os_version}",
    "status": "$health_status"
}
EOF
}

# Check if services are running
check_service_health() {
    local services=("edudisplej-kiosk" "edudisplej-sync" "edudisplej-display")
    local service_status="[]"
    local all_services_ok=true
    
    echo "{"
    echo '  "services": ['
    
    local first=true
    for service in "${services[@]}"; do
        # Check if service exists
        if ! systemctl list-unit-files | grep -q "^${service}"; then
            log_debug "Service $service not found in systemctl"
            continue
        fi
        
        local is_active="false"
        local is_enabled="false"
        
        if systemctl is-active --quiet "$service"; then
            is_active="true"
        else
            all_services_ok=false
        fi
        
        if systemctl is-enabled --quiet "$service" 2>/dev/null; then
            is_enabled="true"
        fi
        
        if [ "$first" = true ]; then
            first=false
        else
            echo ","
        fi
        
        echo -n "    {\"name\": \"$service\", \"active\": $is_active, \"enabled\": $is_enabled}"
    done
    
    echo ""
    echo "  ],"
    echo "  \"all_ok\": $all_services_ok"
    echo "}"
}

# Check network connectivity
check_network_health() {
    local internet_ok="false"
    local api_ok="false"
    local ssid=""
    local signal=""
    local local_ip=""
    
    # Check internet connectivity
    if ping -c 1 -W 2 8.8.8.8 >/dev/null 2>&1; then
        internet_ok="true"
    fi
    
    # Check API connectivity
    local token
    token=$(get_api_token) || { reset_to_unconfigured; return; }
    if curl -sf --max-time 5 --connect-timeout 3 -H "Authorization: Bearer $token" "${API_BASE_URL}/api/health.php" >/dev/null 2>&1; then
        api_ok="true"
    fi

    if command -v iwgetid >/dev/null 2>&1; then
        ssid=$(iwgetid -r 2>/dev/null || echo "")
    fi

    if command -v iwconfig >/dev/null 2>&1; then
        signal=$(iwconfig 2>/dev/null | grep -Eo 'Signal level=[^ ]+' | head -1 | cut -d= -f2 || echo "")
    fi

    local_ip=$(hostname -I 2>/dev/null | awk '{print $1}' || echo "")
    
    cat << EOF
{
    "internet": $internet_ok,
    "api_server": $api_ok,
    "wifi_name": "${ssid}",
    "wifi_signal": "${signal}",
    "local_ip": "${local_ip}"
}
EOF
}

# Check if kiosk is configured
check_kiosk_configuration() {
    local configured="false"
    local kiosk_id="null"
    local company_id="null"
    local device_id="null"
    local mac=""
    local hostname=""

    hostname=$(hostname 2>/dev/null || echo "")
    mac=$(ip link show 2>/dev/null | awk '/link\/ether/ {print $2; exit}' | tr -d ':' || echo "")
    
    if [ -f "$CONFIG_FILE" ]; then
        configured="true"

        if command -v jq >/dev/null 2>&1; then
            kiosk_id=$(jq -r '.kiosk_id // "null"' "$CONFIG_FILE" 2>/dev/null || echo "null")
            company_id=$(jq -r '.company_id // "null"' "$CONFIG_FILE" 2>/dev/null || echo "null")
            device_id=$(jq -r '.device_id // "null"' "$CONFIG_FILE" 2>/dev/null || echo "null")
        else
            kiosk_id=$(sed -n 's/.*"kiosk_id"[[:space:]]*:[[:space:]]*\([0-9]\+\).*/\1/p' "$CONFIG_FILE" | head -1)
            company_id=$(sed -n 's/.*"company_id"[[:space:]]*:[[:space:]]*\([0-9]\+\).*/\1/p' "$CONFIG_FILE" | head -1)
            device_id=$(sed -n 's/.*"device_id"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$CONFIG_FILE" | head -1)
            [ -z "$kiosk_id" ] && kiosk_id="null"
            [ -z "$company_id" ] && company_id="null"
            [ -z "$device_id" ] && device_id="null"
        fi
    fi

    if [ "$device_id" = "" ] || [ "$device_id" = "null" ]; then
        device_id="null"
    fi
    
    cat << EOF
{
    "configured": $configured,
    "kiosk_id": $kiosk_id,
    "company_id": $company_id,
    "device_id": "$device_id",
    "mac": "${mac}",
    "hostname": "${hostname}"
}
EOF
}

# Check if sync is working
check_sync_health() {
    local last_sync="null"
    local next_sync="null"
    local sync_ok="false"
    
    local status_file="${CONFIG_DIR}/sync_status.json"
    if [ -f "$status_file" ]; then
        last_sync=$(grep -o '"last_sync":"[^"]*"' "$status_file" | cut -d'"' -f4 || echo "null")
        next_sync=$(grep -o '"next_sync":"[^"]*"' "$status_file" | cut -d'"' -f4 || echo "null")
        
        # Check if last sync was within last 24 hours
        if [ "$last_sync" != "null" ] && [ -n "$last_sync" ]; then
            local last_sync_epoch=$(date -d "$last_sync" +%s 2>/dev/null || echo "0")
            local current_epoch=$(date +%s)
            local diff=$((current_epoch - last_sync_epoch))
            
            if [ $diff -lt 86400 ]; then  # 24 hours
                sync_ok="true"
            fi
        fi
    fi
    
    cat << EOF
{
    "last_sync": "$last_sync",
    "next_sync": "$next_sync",
    "sync_ok": $sync_ok
}
EOF
}

# Get fast loop status
get_fast_loop_status() {
    local fast_loop_enabled="false"
    local fast_loop_file="${CONFIG_DIR}/.fast_loop_enabled"
    
    if [ -f "$fast_loop_file" ]; then
        fast_loop_enabled="true"
    fi
    
    cat << EOF
{
    "enabled": $fast_loop_enabled,
    "interval": $FAST_LOOP_INTERVAL
}
EOF
}

# Compile overall health status
compile_health_status() {
    log_debug "Compiling health status..."
    
    local system_health=$(get_system_health)
    local service_health=$(check_service_health)
    local network_health=$(check_network_health)
    local kiosk_config=$(check_kiosk_configuration)
    local sync_health=$(check_sync_health)
    local fast_loop=$(get_fast_loop_status)
    
    # Determine overall health
    local overall_status="healthy"
    
    # Parse system health status
    local sys_status=$(echo "$system_health" | grep -o '"status":"[^"]*"' | cut -d'"' -f4)
    if [ "$sys_status" = "warning" ]; then
        overall_status="warning"
    elif [ "$sys_status" = "critical" ]; then
        overall_status="critical"
    fi
    
    # Check network
    local internet=$(echo "$network_health" | grep -o '"internet":[a-z]*' | cut -d: -f2)
    if [ "$internet" != "true" ]; then
        overall_status="critical"
    fi
    
    # Create health status JSON
    cat > "$HEALTH_STATUS_FILE" << EOF
{
    "timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "status": "$overall_status",
    "system": $system_health,
    "services": $service_health,
    "network": $network_health,
    "kiosk": $kiosk_config,
    "sync": $sync_health,
    "fast_loop": $fast_loop,
    "version": "$SERVICE_VERSION"
}
EOF
    
    log_success "Health status compiled: $overall_status"
}

# Send health status to API
send_health_report() {
    if [ ! -f "$HEALTH_STATUS_FILE" ]; then
        log_error "Health status file not found"
        return 1
    fi
    
    local token
    token=$(get_api_token) || { reset_to_unconfigured; return 1; }

    local response=$(curl -s -X POST \
        -H "Authorization: Bearer $token" \
        -H "Content-Type: application/json" \
        -d @"$HEALTH_STATUS_FILE" \
        --max-time 10 \
        --connect-timeout 5 \
        "$HEALTH_CHECK_API" 2>/dev/null || echo "{\"success\": false}")

    if is_auth_error "$response"; then
        reset_to_unconfigured
        return 1
    fi
    
    if echo "$response" | grep -q '"success"[[:space:]]*:[[:space:]]*true'; then
        log_success "Health report sent successfully"
        return 0
    else
        log_error "Failed to send health report: $response"
        return 1
    fi
}

# Main health check loop
main() {
    log "Health check service started (version: $SERVICE_VERSION)"
    
    # Initial compile of health status
    compile_health_status
    send_health_report
    
    # Main loop
    while true; do
        # Determine check interval
        local check_interval=$HEALTH_CHECK_INTERVAL
        if [ -f "${CONFIG_DIR}/.fast_loop_enabled" ]; then
            check_interval=$FAST_LOOP_INTERVAL
        fi
        
        # Wait for next check
        sleep "$check_interval"
        
        # Compile and send health status
        compile_health_status
        send_health_report
    done
}

# Trap signals
trap 'log "Health check service stopped"; exit 0' SIGTERM SIGINT

# Run main loop
main
