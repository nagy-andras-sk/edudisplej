#!/bin/bash
# EduDisplej X Environment Watchdog
# Monitors X environment and performs self-healing restarts

set -euo pipefail

WATCHDOG_LOG="/tmp/edudisplej-watchdog.log"
CHECK_INTERVAL=10  # seconds
MAX_UI_MISSES=3
UI_MISS_COUNT=0
MAX_CRITICAL_MISSES=2
CRITICAL_MISS_COUNT=0
LAST_RESTART_EPOCH=0
RESTART_COOLDOWN=60
STARTUP_GRACE_SECONDS=90
GRACE_UNTIL_EPOCH=0

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$WATCHDOG_LOG"
}

check_x_server() {
    if pgrep -x Xorg >/dev/null; then
        return 0
    fi
    return 1
}

check_openbox() {
    if pgrep -x openbox >/dev/null; then
        return 0
    fi
    return 1
}

check_xterm() {
    if pgrep -x xterm >/dev/null; then
        return 0
    fi
    return 1
}

check_browser() {
    # UI is considered alive if surf or xterm exists.
    if pgrep -x surf >/dev/null || pgrep -x xterm >/dev/null; then
        return 0
    fi
    return 1
}

restart_kiosk() {
    local reason="$1"
    local now
    now=$(date +%s)

    if [ $((now - LAST_RESTART_EPOCH)) -lt "$RESTART_COOLDOWN" ]; then
        log "↺ Restart skipped (cooldown active): $reason"
        return
    fi

    log "↻ Self-heal restart triggered: $reason"
    systemctl restart edudisplej-kiosk.service && {
        LAST_RESTART_EPOCH=$now
        UI_MISS_COUNT=0
        CRITICAL_MISS_COUNT=0
        GRACE_UNTIL_EPOCH=$((now + STARTUP_GRACE_SECONDS))
        log "✓ edudisplej-kiosk.service restarted"
        return
    }

    log "✗ Failed to restart edudisplej-kiosk.service"
}

log "=== EduDisplej Watchdog Started ==="
GRACE_UNTIL_EPOCH=$(( $(date +%s) + STARTUP_GRACE_SECONDS ))

while true; do
    log "--- Status Check ---"

    x_ok=0
    ob_ok=0

    if check_x_server; then
        log "✓ X Server running"
        x_ok=1
    else
        log "✗ X Server NOT running"
    fi

    if check_openbox; then
        log "✓ Openbox running"
        ob_ok=1
    else
        log "✗ Openbox NOT running"
    fi

    if check_xterm; then
        log "✓ xterm running"
    else
        log "✗ xterm NOT running"
    fi

    if check_browser; then
        log "✓ UI process running (surf/xterm)"
        UI_MISS_COUNT=0
    else
        UI_MISS_COUNT=$((UI_MISS_COUNT + 1))
        log "✗ UI process missing (surf/xterm) - miss $UI_MISS_COUNT/$MAX_UI_MISSES"
    fi

    if [ "$x_ok" -eq 1 ] && [ "$ob_ok" -eq 1 ]; then
        CRITICAL_MISS_COUNT=0
    else
        CRITICAL_MISS_COUNT=$((CRITICAL_MISS_COUNT + 1))
        log "✗ Critical process miss $CRITICAL_MISS_COUNT/$MAX_CRITICAL_MISSES"
    fi

    now=$(date +%s)
    if [ "$now" -lt "$GRACE_UNTIL_EPOCH" ]; then
        log "… Startup grace active, self-heal actions temporarily paused"
        sleep "$CHECK_INTERVAL"
        continue
    fi

    if [ "$CRITICAL_MISS_COUNT" -ge "$MAX_CRITICAL_MISSES" ]; then
        restart_kiosk "critical process missing repeatedly (X/Openbox)"
    elif [ "$UI_MISS_COUNT" -ge "$MAX_UI_MISSES" ]; then
        restart_kiosk "UI process missing for ${UI_MISS_COUNT} checks"
    fi

    # Log file sizes
    [ -f /tmp/openbox-autostart.log ] && log "Openbox log: $(wc -l /tmp/openbox-autostart.log | awk '{print $1}') lines"
    [ -f /tmp/kiosk-launcher.log ] && log "Launcher log: $(wc -l /tmp/kiosk-launcher.log | awk '{print $1}') lines"

    sleep "$CHECK_INTERVAL"
done
