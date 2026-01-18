#!/bin/bash
# xclient.sh - Simplified X client wrapper for browser kiosk
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
KIOSK_URL="${KIOSK_URL:-file:///opt/edudisplej/localweb/clock.html}"
echo "KIOSK_URL=${KIOSK_URL}"

# -----------------------------------------------------------------------------
# Simplified browser detection and startup
# -----------------------------------------------------------------------------

# Detect available browser (priority: epiphany → chromium → firefox)
detect_browser() {
    # Try Epiphany first (lightweight, works on older ARM)
    if command -v epiphany-browser >/dev/null 2>&1; then
        BROWSER_BIN="epiphany-browser"
        echo "Browser: epiphany-browser"
        return 0
    fi
    
    # Try Chromium
    if command -v chromium-browser >/dev/null 2>&1; then
        BROWSER_BIN="chromium-browser"
        echo "Browser: chromium-browser"
        return 0
    fi
    
    if command -v chromium >/dev/null 2>&1; then
        BROWSER_BIN="chromium"
        echo "Browser: chromium"
        return 0
    fi
    
    # Try Firefox as last resort
    if command -v firefox-esr >/dev/null 2>&1; then
        BROWSER_BIN="firefox-esr"
        echo "Browser: firefox-esr"
        return 0
    fi
    
    echo "ERROR: No browser found"
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

# Start browser with minimal configuration
start_browser() {
    # Setup environment
    export LIBGL_ALWAYS_SOFTWARE=1
    export XDG_RUNTIME_DIR="/tmp/edudisplej-runtime"
    mkdir -p "$XDG_RUNTIME_DIR" 2>/dev/null || true
    
    setup_x_env
    save_hwinfo
    
    # Clean old browser processes using specific PIDs
    local old_pids=""
    old_pids=$(pgrep -x chromium 2>/dev/null || true)
    old_pids="$old_pids $(pgrep -x chromium-browser 2>/dev/null || true)"
    old_pids="$old_pids $(pgrep -x epiphany-browser 2>/dev/null || true)"
    old_pids="$old_pids $(pgrep -x firefox-esr 2>/dev/null || true)"
    
    if [[ -n "$old_pids" ]] && [[ "$old_pids" != " " ]]; then
        echo "Stopping old browser processes: $old_pids"
        for pid in $old_pids; do
            [[ -z "$pid" ]] && continue
            kill -TERM "$pid" 2>/dev/null || true
        done
        sleep 1
    fi
    
    echo "Starting browser: ${BROWSER_BIN}"
    
    case "$BROWSER_BIN" in
        *epiphany*)
            # Epiphany: simple and lightweight
            epiphany-browser --application-mode "${KIOSK_URL}" &
            ;;
        *chromium*)
            # Chromium: minimal flags
            ${BROWSER_BIN} --kiosk \
                --no-sandbox \
                --disable-gpu \
                --disable-infobars \
                --no-error-dialogs \
                --incognito \
                --no-first-run \
                --disable-translate \
                "${KIOSK_URL}" &
            ;;
        *firefox*)
            # Firefox: kiosk mode
            firefox-esr --kiosk --private-window "${KIOSK_URL}" &
            ;;
    esac
    
    BROWSER_PID=$!
    sleep 3
    
    if kill -0 "$BROWSER_PID" 2>/dev/null; then
        echo "Browser started (PID: ${BROWSER_PID})"
        wait "$BROWSER_PID"
        echo "Browser exited, restarting in 10s..."
    else
        echo "Browser failed to start"
    fi
    
    sleep 10
}

# -----------------------------------------------------------------------------
# Main
# -----------------------------------------------------------------------------
echo "Detecting browser..."
if ! detect_browser; then
    echo "ERROR: No browser found. Cannot start kiosk."
    exit 1
fi

echo "Starting kiosk mode..."
while true; do
    start_browser
done

