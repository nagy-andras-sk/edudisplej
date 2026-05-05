#!/bin/bash

set -euo pipefail

LOG="/tmp/edudisplej-watchdog.log"
CHECK_INTERVAL=10
MAX_MISSES=3
MISS_COUNT=0
LAST_RESTART=0
COOLDOWN=60
GRACE=$(($(date +%s) + 90))

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG"; }

log "Watchdog started"

while true; do
    now=$(date +%s)
    x_ok=0
    ob_ok=0

    pgrep -x Xorg >/dev/null 2>&1 && x_ok=1
    pgrep -x openbox >/dev/null 2>&1 && ob_ok=1

    if pgrep -x surf >/dev/null 2>&1; then
        MISS_COUNT=0
    else
        MISS_COUNT=$((MISS_COUNT + 1))
    fi

    if [ "$now" -lt "$GRACE" ]; then
        sleep "$CHECK_INTERVAL"
        continue
    fi

    if [ "$x_ok" -eq 0 ] || [ "$ob_ok" -eq 0 ] || [ "$MISS_COUNT" -ge "$MAX_MISSES" ]; then
        if [ $((now - LAST_RESTART)) -ge "$COOLDOWN" ]; then
            log "Restarting edudisplej-kiosk.service"
            systemctl restart edudisplej-kiosk.service 2>/dev/null || true
            LAST_RESTART=$now
            MISS_COUNT=0
            GRACE=$((now + 90))
        fi
    fi

    sleep "$CHECK_INTERVAL"
done
