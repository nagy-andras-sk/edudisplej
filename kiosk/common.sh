#!/bin/bash

EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
CONFIG_FILE="${EDUDISPLEJ_HOME}/edudisplej.conf"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

load_config() {
    [ -f "$CONFIG_FILE" ] && source "$CONFIG_FILE" || true
}

get_mac_address() {
    local mac=""
    for iface in /sys/class/net/*; do
        local ifname=$(basename "$iface")
        [ "$ifname" = "lo" ] && continue
        [ -f "$iface/address" ] || continue
        mac=$(cat "$iface/address" 2>/dev/null | tr -d ':' | tr '[:lower:]' '[:upper:]')
        [ -n "$mac" ] && [ "$mac" != "000000000000" ] && echo "$mac" && return 0
    done
    return 1
}

check_internet() {
    curl -fsSL --max-time 8 --connect-timeout 4 --head \
        "https://control.edudisplej.sk/api/health.php" >/dev/null 2>&1 && return 0
    ping -c 1 -W 3 8.8.8.8 &>/dev/null && return 0
    return 1
}

wait_for_internet() {
    local attempt=1
    while [ $attempt -le 15 ]; do
        check_internet && return 0
        sleep 2
        attempt=$((attempt + 1))
    done
    return 1
}

json_get() {
    local json="$1" key="$2"
    if command -v jq >/dev/null 2>&1; then
        echo "$json" | jq -r ".$key // empty" 2>/dev/null
    else
        echo "$json" | tr -d '\n\r' | sed -n "s/.*\"$key\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" | head -1
    fi
}

load_config
