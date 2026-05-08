#!/bin/bash

set -euo pipefail

INIT_DIR="/opt/edudisplej/init"
[ -f "${INIT_DIR}/common.sh" ] && source "${INIT_DIR}/common.sh" || true

API_BASE="${EDUDISPLEJ_API_URL:-https://control.edudisplej.sk}"
REGISTRATION_API="${API_BASE}/api/registration.php"
DEVICE_SYNC_API="${API_BASE}/api/v1/device/sync.php"
LOOP_CHECK_API="${API_BASE}/api/check_group_loop_update.php"
TIMESTAMP_API="${API_BASE}/api/update_sync_timestamp.php"
DOWNLOAD_SCRIPT="${INIT_DIR}/edudisplej-download-modules.sh"

CONFIG_DIR="/opt/edudisplej"
TOKEN_FILE="${CONFIG_DIR}/lic/token"
LOOP_FILE="${CONFIG_DIR}/localweb/modules/loop.json"
LOG_FILE="${CONFIG_DIR}/logs/sync.log"
STATUS_FILE="${CONFIG_DIR}/sync_status.json"
SYNC_INTERVAL=300
DEVICE_ID=""

mkdir -p "${CONFIG_DIR}/logs"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"; }

get_token() {
    [ -f "$TOKEN_FILE" ] || return 1
    tr -d '\n\r' < "$TOKEN_FILE"
}

get_hw_info() {
    local uptime cpu mem disk
    uptime=$(awk '{print int($1)}' /proc/uptime 2>/dev/null || echo 0)
    cpu=$(awk '/^cpu /{u=($2+$4)*100/($2+$4+$5); printf "%.1f", u}' /proc/stat 2>/dev/null || echo 0)
    mem=$(free 2>/dev/null | awk '/^Mem:/ {printf "%.1f", ($3/$2)*100}' || echo 0)
    disk=$(df -h / 2>/dev/null | tail -1 | awk '{print $(NF-1)}' | tr -d '%' || echo 0)
    cat << EOF
{"hostname":"$(hostname)","kernel":"$(uname -r)","architecture":"$(uname -m)","uptime_seconds":${uptime},"cpu_usage":${cpu},"memory_usage":${mem},"disk_usage":${disk}}
EOF
}

is_auth_error() {
    echo "$1" | grep -qi '"Invalid API token"\|"Authentication required"\|"Unauthorized"\|"inactive"'
}

reset_to_unconfigured() {
    log "Auth error - resetting to unconfigured"
    rm -rf "${CONFIG_DIR}/localweb/modules" 2>/dev/null || true
    mkdir -p "${CONFIG_DIR}/localweb/modules" 2>/dev/null || true
    systemctl restart edudisplej-kiosk.service 2>/dev/null || true
}

sync_loop_if_needed() {
    local device_id="$1"
    local local_updated=""
    [ -f "$LOOP_FILE" ] && command -v jq >/dev/null 2>&1 && \
        local_updated=$(jq -r '.last_update // empty' "$LOOP_FILE" 2>/dev/null)

    local token
    token=$(get_token) || { reset_to_unconfigured; return 1; }

    local response
    response=$(curl -s -X POST "$LOOP_CHECK_API" \
        -H "Authorization: Bearer $token" \
        -H "Content-Type: application/json" \
        -d "{\"device_id\":\"${device_id}\"}" \
        --max-time 30 2>/dev/null)

    is_auth_error "$response" && { reset_to_unconfigured; return 1; }
    echo "$response" | grep -q '"success":true' || { log "Loop check failed"; return 1; }

    local server_updated
    server_updated=$(json_get "$response" "loop_updated_at")

    local needs_update=false
    [ -z "$local_updated" ] && needs_update=true
    [ -n "$server_updated" ] && [ -n "$local_updated" ] && \
        [ "$server_updated" \> "$local_updated" ] && needs_update=true

    if [ "$needs_update" = "true" ]; then
        log "Loop update needed, downloading..."
        [ -x "$DOWNLOAD_SCRIPT" ] && bash "$DOWNLOAD_SCRIPT" && \
            systemctl restart edudisplej-kiosk.service 2>/dev/null || true
    fi
}

sync_hw() {
    local token mac hw version last_update
    token=$(get_token) || return 1
    mac=$(get_mac_address 2>/dev/null || hostname -I | awk '{print $1}')
    hw=$(get_hw_info)
    version=$(cat "${CONFIG_DIR}/VERSION" 2>/dev/null || echo "unknown")
    last_update=""
    [ -f "$LOOP_FILE" ] && command -v jq >/dev/null 2>&1 && \
        last_update=$(jq -r '.last_update // empty' "$LOOP_FILE" 2>/dev/null)

    local data="{\"mac\":\"$mac\",\"hostname\":\"$(hostname)\",\"hw_info\":${hw},\"version\":\"$version\""
    [ -n "$last_update" ] && data="${data},\"last_update\":\"$last_update\""
    data="${data}}"

    local response
    response=$(curl -s -X POST "$DEVICE_SYNC_API" \
        -H "Authorization: Bearer $token" \
        -H "Content-Type: application/json" \
        -d "$data" --max-time 30 2>/dev/null)

    is_auth_error "$response" && { reset_to_unconfigured; return 1; }

    if echo "$response" | grep -q '"sync_interval"'; then
        local new_interval
        new_interval=$(echo "$response" | grep -o '"sync_interval":[0-9]*' | cut -d: -f2)
        [ -n "$new_interval" ] && SYNC_INTERVAL=$new_interval
    fi
}

do_sync() {
    local token mac hw
    token=$(get_token) || { log "No token found, skipping sync"; return 1; }
    mac=$(get_mac_address 2>/dev/null || hostname -I | awk '{print $1}')
    hw=$(get_hw_info)

    local body="{\"mac\":\"$mac\",\"hostname\":\"$(hostname)\",\"hw_info\":${hw}}"
    local response_file
    response_file=$(mktemp)

    local http_code
    http_code=$(curl -s -w "%{http_code}" \
        -X POST "$REGISTRATION_API" \
        -H "Authorization: Bearer $token" \
        -H "Content-Type: application/json" \
        -d "$body" --max-time 30 --connect-timeout 10 \
        -o "$response_file" 2>/dev/null || echo "000")

    local response
    response=$(cat "$response_file" 2>/dev/null || echo '{}')
    rm -f "$response_file"

    if [ "$http_code" != "200" ]; then
        log "Registration failed HTTP $http_code"
        return 1
    fi

    is_auth_error "$response" && { reset_to_unconfigured; return 1; }
    echo "$response" | grep -q '"success":true' || { log "Registration failed: $response"; return 1; }

    local device_id kiosk_id is_configured
    device_id=$(json_get "$response" "device_id")
    kiosk_id=$(json_get "$response" "kiosk_id")
    is_configured=$(json_get "$response" "is_configured")
    DEVICE_ID="$device_id"

    log "Sync OK - device=$device_id kiosk=$kiosk_id configured=$is_configured"

    cat > "$STATUS_FILE" << EOF
{"last_sync":"$(date '+%Y-%m-%d %H:%M:%S')","device_id":"$device_id","kiosk_id":"$kiosk_id","is_configured":$is_configured}
EOF

    # If the server says the kiosk is not yet configured, remove any cached
    # loop content so that the waiting_registration.html screen is displayed.
    if [ "$is_configured" = "false" ]; then
        if [ -f "$LOOP_FILE" ]; then
            log "Kiosk not configured – removing loop.json to show waiting screen"
            rm -f "$LOOP_FILE" 2>/dev/null || true
            systemctl restart edudisplej-kiosk.service 2>/dev/null || true
        fi
    fi

    sync_hw

    if [ -n "$device_id" ] && [ "$device_id" != "unknown" ]; then
        sync_loop_if_needed "$device_id"

        curl -s -X POST "$TIMESTAMP_API" \
            -H "Authorization: Bearer $token" \
            -H "Content-Type: application/json" \
            -d "{\"mac\":\"$mac\",\"last_sync\":\"$(date '+%Y-%m-%d %H:%M:%S')\"}" \
            --max-time 10 >/dev/null 2>&1 || true
    fi
}

log "EduDisplej Sync Service started"

while true; do
    do_sync || log "Sync failed, retrying in 60s"
    [ $? -ne 0 ] && sleep 60 && continue
    log "Next sync in ${SYNC_INTERVAL}s"
    sleep "$SYNC_INTERVAL"
done
