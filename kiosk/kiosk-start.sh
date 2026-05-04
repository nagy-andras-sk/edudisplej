#!/bin/bash
# kiosk-start.sh - Start X server with Openbox on tty1

set -euo pipefail

INIT_SCRIPT="/opt/edudisplej/init/edudisplej-init.sh"
FLAG="/opt/edudisplej/.kiosk_system_configured"
STARTUP_LOG="/tmp/kiosk-startup.log"
RUN_USER="$(whoami)"
RUN_UID="$(id -u "$RUN_USER" 2>/dev/null || echo 1000)"
RUN_HOME="$(getent passwd "$RUN_USER" | cut -d: -f6)"
if [ -z "$RUN_HOME" ]; then
    RUN_HOME="/home/$RUN_USER"
fi

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$STARTUP_LOG"
}

fallback_to_console() {
    log "FALLBACK: Enabling login console on tty1"
    systemctl unmask getty@tty1.service 2>/dev/null || true
    systemctl enable getty@tty1.service 2>/dev/null || true
    systemctl start getty@tty1.service 2>/dev/null || true
}

run_first_boot_setup() {
    if [ -f "$FLAG" ]; then
        return 0
    fi

    log "First boot - running setup"
    if [ ! -x "$INIT_SCRIPT" ]; then
        log "ERROR: Init script not executable: $INIT_SCRIPT"
        return 1
    fi

    if ! sudo "$INIT_SCRIPT" 2>&1 | tee -a "$STARTUP_LOG"; then
        log "ERROR: Setup failed"
        return 1
    fi

    log "Setup complete"
    return 0
}

cleanup_stale_x_state() {
    log "Checking for existing X servers..."
    if pgrep Xorg >/dev/null 2>&1; then
        log "Terminating stale Xorg"
        pkill Xorg 2>/dev/null || true
        sleep 2
        pgrep Xorg >/dev/null 2>&1 && pkill -9 Xorg 2>/dev/null || true
    fi

    [ -f /tmp/.X0-lock ] && rm -f /tmp/.X0-lock 2>/dev/null || true
    [ -S /tmp/.X11-unix/X0 ] && rm -f /tmp/.X11-unix/X0 2>/dev/null || true
}

prepare_runtime_dirs() {
    log "Setting up XDG_RUNTIME_DIR..."
    XDG_RUNTIME_DIR="/run/user/${RUN_UID}"
    if [ ! -d "$XDG_RUNTIME_DIR" ]; then
        mkdir -p "$XDG_RUNTIME_DIR" 2>/dev/null || {
            log "WARNING: Could not create $XDG_RUNTIME_DIR, using /tmp instead"
            XDG_RUNTIME_DIR="/tmp"
        }
    fi

    chmod 0700 "$XDG_RUNTIME_DIR" 2>/dev/null || true
    chown "$RUN_USER:$RUN_USER" "$XDG_RUNTIME_DIR" 2>/dev/null || true
    rm -rf "$XDG_RUNTIME_DIR/dconf" 2>/dev/null || true
    export XDG_RUNTIME_DIR

    # Ensure X authority file exists and is owned by runtime user.
    if [ ! -f "$RUN_HOME/.Xauthority" ]; then
        touch "$RUN_HOME/.Xauthority" 2>/dev/null || true
    fi
    chown "$RUN_USER:$RUN_USER" "$RUN_HOME/.Xauthority" 2>/dev/null || true
    chmod 600 "$RUN_HOME/.Xauthority" 2>/dev/null || true
    export XAUTHORITY="$RUN_HOME/.Xauthority"
}

start_x_session() {
    local current_tty
    current_tty="$(tty || echo unknown)"
    log "Current TTY: $current_tty"
    if [ "$current_tty" != "/dev/tty1" ]; then
        log "WARNING: Not running on tty1"
    fi

    log "Starting X server on vt1 (display :0)..."
    startx -- :0 vt1 -keeptty -nolisten tcp 2>&1 | tee -a "$STARTUP_LOG"
}

main() {
    log "=== EduDisplej Kiosk Start ==="
    log "User: $RUN_USER"
    log "TTY: $(tty || echo unknown)"

    if ! run_first_boot_setup; then
        fallback_to_console
        exit 1
    fi

    cleanup_stale_x_state
    prepare_runtime_dirs

    if ! start_x_session; then
        log "ERROR: X session terminated unexpectedly"
        fallback_to_console
        exit 1
    fi
}

main "$@"