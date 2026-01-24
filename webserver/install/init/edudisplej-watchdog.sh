#!/bin/bash
# EduDisplej X Environment Watchdog
# Monitors X environment and reports status

set -euo pipefail

WATCHDOG_LOG="/tmp/edudisplej-watchdog.log"
CHECK_INTERVAL=10  # seconds

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
    # Midori böngésző ellenőrzése
    if pgrep -x midori >/dev/null; then
        return 0
    fi
    return 1
}

log "=== EduDisplej Watchdog Started ==="

while true; do
    log "--- Status Check ---"
    
    if check_x_server; then
        log "✓ X Server running"
    else
        log "✗ X Server NOT running"
    fi
    
    if check_openbox; then
        log "✓ Openbox running"
    else
        log "✗ Openbox NOT running"
    fi
    
    if check_xterm; then
        log "✓ xterm running"
    else
        log "✗ xterm NOT running"
    fi
    
    if check_browser; then
        log "✓ Browser running (Midori)"
    else
        log "✗ Browser NOT running"
    fi
    
    # Log file sizes
    [ -f /tmp/openbox-autostart.log ] && log "Openbox log: $(wc -l /tmp/openbox-autostart.log | awk '{print $1}') lines"
    [ -f /tmp/kiosk-launcher.log ] && log "Launcher log: $(wc -l /tmp/kiosk-launcher.log | awk '{print $1}') lines"
    
    sleep "$CHECK_INTERVAL"
done
