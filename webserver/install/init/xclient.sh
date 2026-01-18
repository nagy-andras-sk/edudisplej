#!/bin/bash
# xclient.sh - Simplified X client wrapper for browser kiosk
# NOTE: This script is legacy code. The system now uses minimal-kiosk.sh for improved reliability.
#       This file is retained for backwards compatibility and manual testing only.
# This script is started by xinit and runs inside the X session

set -u

# -----------------------------------------------------------------------------
# Environment
# -----------------------------------------------------------------------------
export LANG=C.UTF-8
export LC_ALL=C.UTF-8
export DISPLAY=${DISPLAY:-:0}

EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
CONFIG_FILE="${EDUDISPLEJ_HOME}/edudisplej.conf"
LOG_FILE="${EDUDISPLEJ_HOME}/xclient.log"
MAX_LOG_SIZE=2097152  # 2MB max log size

# Clean old log on startup
if [[ -f "$LOG_FILE" ]]; then
    log_size=$(stat -c%s "$LOG_FILE" 2>/dev/null || echo 0)
    if [[ $log_size -gt $MAX_LOG_SIZE ]]; then
        mv "$LOG_FILE" "${LOG_FILE}.old"
    fi
fi

# Simple logging
mkdir -p "${EDUDISPLEJ_HOME}" 2>/dev/null || true
exec >> "$LOG_FILE" 2>&1

echo "=========================================="
echo "Starting at $(date)"
echo "=========================================="
echo "DISPLAY=${DISPLAY}"
echo "PWD=$(pwd)"

# Load configuration
if [[ -f "$CONFIG_FILE" ]]; then
    source "$CONFIG_FILE"
    echo "Config loaded from ${CONFIG_FILE}"
fi

# Default URL
KIOSK_URL="${KIOSK_URL:-https://www.time.is}"
echo "KIOSK_URL=${KIOSK_URL}"

# -----------------------------------------------------------------------------
# Simplified browser detection and startup
# -----------------------------------------------------------------------------

# Detect available browser (chromium-browser only)
detect_browser() {
    # Use chromium-browser exclusively
    if command -v chromium-browser >/dev/null 2>&1; then
        BROWSER_BIN="chromium-browser"
        echo "Browser: chromium-browser"
        return 0
    fi
    
    echo "ERROR: chromium-browser not found"
    return 1
}

# Setup X environment
setup_x_env() {
    # Disable screensaver
    xset s off 2>/dev/null || true
    xset s noblank 2>/dev/null || true
    xset -dpms 2>/dev/null || true
    
    # Hide cursor
    if command -v unclutter >/dev/null 2>&1; then
        pgrep -x unclutter >/dev/null || unclutter -idle 1 -root &
    fi
    
    # Start openbox window manager
    if command -v openbox >/dev/null 2>&1; then
        if ! pgrep -x openbox >/dev/null 2>&1; then
            mkdir -p ~/.config/openbox
            cat > ~/.config/openbox/rc.xml <<'EOF'
<?xml version="1.0" encoding="UTF-8"?>
<openbox_config xmlns="http://openbox.org/3.4/rc">
    <desktops><number>1</number></desktops>
    <margins><top>0</top><bottom>0</bottom><left>0</left><right>0</right></margins>
    <applications>
        <application class="*">
            <decor>no</decor>
            <maximized>yes</maximized>
        </application>
    </applications>
</openbox_config>
EOF
            openbox &
            sleep 1
        fi
    fi
    
    echo "X environment ready"
}

# Collect and save hardware info
save_hwinfo() {
    if [[ -x "${INIT_DIR}/hwinfo.sh" ]]; then
        echo "Collecting hardware info..."
        "${INIT_DIR}/hwinfo.sh" generate 2>/dev/null || true
    fi
}

# Start terminal for debugging (instead of browser)
start_terminal() {
    # Setup environment
    export LIBGL_ALWAYS_SOFTWARE=1
    export XDG_RUNTIME_DIR="/tmp/edudisplej-runtime"
    mkdir -p "$XDG_RUNTIME_DIR" 2>/dev/null || true
    
    setup_x_env
    save_hwinfo
    
    echo "Starting terminal for debugging..."
    
    # Try different terminal emulators in order of preference
    TERMINAL_BIN=""
    if command -v xterm >/dev/null 2>&1; then
        TERMINAL_BIN="xterm"
    elif command -v x-terminal-emulator >/dev/null 2>&1; then
        TERMINAL_BIN="x-terminal-emulator"
    elif command -v lxterminal >/dev/null 2>&1; then
        TERMINAL_BIN="lxterminal"
    elif command -v xfce4-terminal >/dev/null 2>&1; then
        TERMINAL_BIN="xfce4-terminal"
    fi
    
    if [[ -z "$TERMINAL_BIN" ]]; then
        echo "ERROR: No terminal emulator found"
        sleep 30
        return
    fi
    
    echo "Using terminal: ${TERMINAL_BIN}"
    
    # Start terminal in maximized mode
    if [[ "$TERMINAL_BIN" == "xterm" ]]; then
        xterm -maximized -fa 'Monospace' -fs 12 -bg black -fg white &
    else
        "$TERMINAL_BIN" &
    fi
    
    TERMINAL_PID=$!
    echo "Terminal started (PID: ${TERMINAL_PID})"
    
    wait "$TERMINAL_PID"
    echo "Terminal exited, restarting in 5s..."
    sleep 5
}

# -----------------------------------------------------------------------------
# Main
# -----------------------------------------------------------------------------
echo "Starting terminal mode for debugging..."
echo "NOTE: Browser detection skipped, running terminal instead"

while true; do
    start_terminal
done

