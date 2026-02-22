#!/bin/bash
# kiosk-start.sh - Start X server with Openbox on tty1
# Simple and reliable - runs first-time setup if needed, then starts X

set -euo pipefail

INIT_SCRIPT="/opt/edudisplej/init/edudisplej-init.sh"
FLAG="/opt/edudisplej/.kiosk_system_configured"
STARTUP_LOG="/tmp/kiosk-startup.log"

# Log function
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$STARTUP_LOG"
}

log "=== EduDisplej Kiosk Start ==="
log "User: $(whoami)"
log "TTY: $(tty)"

RUN_USER="$(whoami)"
RUN_UID="$(id -u "$RUN_USER" 2>/dev/null || echo 1000)"

# First-time setup
if [ ! -f "$FLAG" ]; then
    log "First boot - running setup"
    if [ -x "$INIT_SCRIPT" ]; then
        sudo "$INIT_SCRIPT" 2>&1 | tee -a "$STARTUP_LOG" || {
            log "ERROR: Setup failed"
            exit 1
        }
    else
        log "ERROR: Init script not executable: $INIT_SCRIPT"
        exit 1
    fi
    log "Setup complete"
fi

# Kill any existing X servers
log "Checking for existing X servers..."
if pgrep Xorg >/dev/null 2>&1; then
    log "Killing existing X server"
    pkill -9 Xorg 2>/dev/null || true
    sleep 2
fi

# Clean up stale lock files
if [ -f /tmp/.X0-lock ]; then
    log "Removing stale X lock file"
    rm -f /tmp/.X0-lock
fi

# Clean up stale X socket
if [ -S /tmp/.X11-unix/X0 ]; then
    log "Removing stale X socket"
    rm -f /tmp/.X11-unix/X0
fi

# Setup XDG_RUNTIME_DIR and permissions
log "Setting up XDG_RUNTIME_DIR..."
XDG_RUNTIME_DIR="/run/user/${RUN_UID}"
if [ ! -d "$XDG_RUNTIME_DIR" ]; then
    mkdir -p "$XDG_RUNTIME_DIR" 2>/dev/null || {
        log "WARNING: Could not create $XDG_RUNTIME_DIR, using /tmp instead"
        XDG_RUNTIME_DIR="/tmp"
    }
fi

# Fix permissions on XDG_RUNTIME_DIR
chmod 0700 "$XDG_RUNTIME_DIR" 2>/dev/null || true
chown "$RUN_USER:$RUN_USER" "$XDG_RUNTIME_DIR" 2>/dev/null || true

# Remove dconf directory if it exists (to prevent permission errors)
rm -rf "$XDG_RUNTIME_DIR/dconf" 2>/dev/null || true

export XDG_RUNTIME_DIR

# Verify we're on the correct TTY
CURRENT_TTY=$(tty)
log "Current TTY: $CURRENT_TTY"
if [ "$CURRENT_TTY" != "/dev/tty1" ]; then
    log "WARNING: Not running on tty1! This may cause display issues."
fi

# Start X on vt1 (main console) - this is where display will appear
log "Starting X server on vt1 (display :0)..."
log "X server will be visible on the main physical display"

# Start X server and openbox (in background, not with exec)
# If X or openbox crashes, the service will restart them
startx -- :0 vt1 -keeptty -nolisten tcp 2>&1 | tee -a "$STARTUP_LOG" &
STARTX_PID=$!

log "X server started (PID: $STARTX_PID)"

# Keep this script running so the service stays alive
# If X crashes, systemd will restart the entire service
while true; do
    sleep 60
done