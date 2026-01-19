#!/bin/bash
# kiosk-start.sh - Wrapper script for systemd service
# Handles first-time initialization and X server startup

set -euo pipefail

KIOSK_CONFIGURED_FLAG="/opt/edudisplej/.kiosk_system_configured"
INIT_SCRIPT="/opt/edudisplej/init/edudisplej-init.sh"

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

# Kill any existing X server and wait for clean termination
xorg_pids=$(pgrep Xorg 2>/dev/null || true)
if [ -n "$xorg_pids" ]; then
    echo "Terminating existing X server..."
    for pid in $xorg_pids; do
        kill -TERM "$pid" 2>/dev/null || true
    done
    
    # Wait up to 5 seconds for graceful termination
    for i in {1..5}; do
        if ! pgrep Xorg >/dev/null 2>&1; then
            break
        fi
        sleep 1
    done
    
    # Force kill if still running
    xorg_pids=$(pgrep Xorg 2>/dev/null || true)
    if [ -n "$xorg_pids" ]; then
        echo "Force killing X server..."
        for pid in $xorg_pids; do
            kill -KILL "$pid" 2>/dev/null || true
        done
        sleep 1
    fi
fi

# Start X server
if command -v startx >/dev/null 2>&1; then
    exec startx -- :0 vt1
else
    echo "ERROR: startx not found. Init script may have failed."
    echo "Check logs: sudo journalctl -u edudisplej-kiosk.service"
    sleep 30
    exit 1
fi
