#!/bin/bash

set -euo pipefail

INIT_SCRIPT="/opt/edudisplej/init/edudisplej-init.sh"
FLAG="/opt/edudisplej/.kiosk_system_configured"
LOG="/tmp/kiosk-startup.log"
RUN_USER="$(whoami)"
RUN_UID="$(id -u "$RUN_USER")"
RUN_HOME="$(getent passwd "$RUN_USER" | cut -d: -f6)"
[ -z "$RUN_HOME" ] && RUN_HOME="/home/$RUN_USER"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"; }

if [ ! -f "$FLAG" ]; then
    log "First boot - running setup"
    [ ! -x "$INIT_SCRIPT" ] && log "ERROR: Init script not found" && exit 1
    sudo "$INIT_SCRIPT" 2>&1 | tee -a "$LOG" || { log "ERROR: Setup failed"; exit 1; }
fi

XORG_PID=$(pgrep Xorg 2>/dev/null || true)
if [ -n "$XORG_PID" ]; then
    kill "$XORG_PID" 2>/dev/null || true
    sleep 2
    kill -0 "$XORG_PID" 2>/dev/null && kill -9 "$XORG_PID" 2>/dev/null || true
fi
rm -f /tmp/.X0-lock 2>/dev/null || true
rm -f /tmp/.X11-unix/X0 2>/dev/null || true

XDG_RUNTIME_DIR="/run/user/${RUN_UID}"
if [ ! -d "$XDG_RUNTIME_DIR" ]; then
    mkdir -p "$XDG_RUNTIME_DIR" 2>/dev/null || XDG_RUNTIME_DIR="/tmp"
fi
chmod 0700 "$XDG_RUNTIME_DIR" 2>/dev/null || true
chown "$RUN_USER:$RUN_USER" "$XDG_RUNTIME_DIR" 2>/dev/null || true
export XDG_RUNTIME_DIR

touch "$RUN_HOME/.Xauthority" 2>/dev/null || true
chown "$RUN_USER:$RUN_USER" "$RUN_HOME/.Xauthority" 2>/dev/null || true
chmod 600 "$RUN_HOME/.Xauthority" 2>/dev/null || true
export XAUTHORITY="$RUN_HOME/.Xauthority"

log "Starting X session"
startx -- :0 vt1 -keeptty -nolisten tcp 2>&1 | tee -a "$LOG"
