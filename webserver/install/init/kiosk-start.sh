#!/bin/bash
# kiosk-start.sh - Wrapper script for systemd service
# Handles first-time initialization and X server startup

set -euo pipefail

# Redirect all output to systemd journal
exec 1> >(logger -t edudisplej-kiosk -p user.info)
exec 2> >(logger -t edudisplej-kiosk -p user.err)

echo "[$(date '+%Y-%m-%d %H:%M:%S')] kiosk-start.sh BEGIN"

KIOSK_CONFIGURED_FLAG="/opt/edudisplej/.kiosk_system_configured"
INIT_SCRIPT="/opt/edudisplej/init/edudisplej-init.sh"

# Function to terminate X server gracefully
terminate_xorg() {
    local xorg_pids
    xorg_pids=$(pgrep Xorg 2>/dev/null || true)
    
    if [ -z "$xorg_pids" ]; then
        return 0
    fi
    
    echo "Terminating existing X server..."
    for pid in $xorg_pids; do
        kill -TERM "$pid" 2>/dev/null || true
    done
    
    # Wait up to 5 seconds for graceful termination
    for i in {1..5}; do
        if ! pgrep Xorg >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    
    # Force kill if still running
    local remaining_pids
    remaining_pids=$(pgrep Xorg 2>/dev/null || true)
    if [ -n "$remaining_pids" ]; then
        echo "Force killing X server..."
        for pid in $remaining_pids; do
            kill -KILL "$pid" 2>/dev/null || true
        done
        sleep 1
    fi
}

# Check if first-time setup is needed
if [ ! -f "$KIOSK_CONFIGURED_FLAG" ]; then
    clear
    echo "==================================================="
    echo "  EduDisplej - First-time initialization"
    echo "==================================================="
    echo ""
    
    # Run init script (with sudo, configured in sudoers)
    if [ -x "$INIT_SCRIPT" ]; then
        sudo "$INIT_SCRIPT"
    else
        echo "ERROR: Init script not found or not executable: $INIT_SCRIPT"
        exit 1
    fi
    
    echo ""
    echo "Initialization complete. Starting X server..."
    sleep 2
fi

# Terminate any existing X server
terminate_xorg

# Start X server
if command -v startx >/dev/null 2>&1; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting X server..."
    exec startx -- :0 vt7 > /tmp/xorg-startup.log 2>&1 && \
    openbox-session & xterm
else
    echo "ERROR: startx not found. Init script may have failed."
    echo "Check logs: sudo journalctl -u edudisplej-kiosk.service"
    sleep 30
    exit 1
fi