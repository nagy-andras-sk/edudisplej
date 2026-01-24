#!/bin/bash
# kiosk-start.sh - Start X server with Openbox on tty1
# Simple and reliable - runs first-time setup if needed, then starts X

set -euo pipefail

INIT_SCRIPT="/opt/edudisplej/init/edudisplej-init.sh"
FLAG="/opt/edudisplej/.kiosk_system_configured"

# First-time setup
if [ ! -f "$FLAG" ]; then
    echo "=== EduDisplej First Boot Setup ==="
    if [ -x "$INIT_SCRIPT" ]; then
        sudo "$INIT_SCRIPT" || exit 1
    else
        echo "ERROR: Init script not executable"
        exit 1
    fi
    echo "=== Setup Complete ==="
fi

# Kill any existing X servers
pkill -9 Xorg 2>/dev/null || true
sleep 1

# Start X on the current VT (systemd already allocated tty1)
# Do NOT specify vt1 explicitly - let X use the VT systemd allocated
exec startx -- :0 2>&1 | tee /tmp/xorg-startup.log