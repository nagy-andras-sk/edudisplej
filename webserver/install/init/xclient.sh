#!/bin/bash
# xclient.sh - X client wrapper for Openbox + Chromium
# This script is started by xinit and runs inside the X session
# All text is in Slovak (without diacritics) or English

# Set locale and display for GUI apps
export LANG=C.UTF-8
export LC_ALL=C.UTF-8
export DISPLAY=:0

# Path to init directory
EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
CONFIG_FILE="${EDUDISPLEJ_HOME}/edudisplej.conf"

# Load configuration
if [[ -f "$CONFIG_FILE" ]]; then
    source "$CONFIG_FILE"
fi

# Default URL if not set (prefer local clock)
KIOSK_URL="${KIOSK_URL:-file:///opt/edudisplej/localweb/clock.html}"

# Log file for debugging
LOG_FILE="${EDUDISPLEJ_HOME}/xclient.log"
exec 2>"$LOG_FILE"
echo "[xclient] Starting at $(date)" | tee -a "$LOG_FILE"
echo "[xclient] KIOSK_URL: ${KIOSK_URL}" | tee -a "$LOG_FILE"

# =============================================================================
# X Environment Setup
# =============================================================================

# Disable screensaver
xset s off 2>/dev/null
xset s noblank 2>/dev/null
xset -dpms 2>/dev/null

# Disable screen blanking
xset dpms 0 0 0 2>/dev/null

# Ensure localweb directory exists
mkdir -p /opt/edudisplej/localweb 2>/dev/null || true

# Set background to white initially (loading screen)
if command -v xsetroot &> /dev/null; then
    xsetroot -solid white 2>/dev/null
    echo "[xclient] Background set to white" | tee -a "$LOG_FILE"
fi

# =============================================================================
# Hide Cursor
# =============================================================================

# Start unclutter to hide cursor when idle
if command -v unclutter &> /dev/null; then
    unclutter -idle 0.5 -root &
fi

# =============================================================================
# Window Manager
# =============================================================================

# Start Openbox window manager
if command -v openbox &> /dev/null; then
    openbox &
    sleep 1
fi

# =============================================================================
# Chromium Kiosk
# =============================================================================

# Detect browser (prefer BROWSER_BIN env, then chromium-browser, chromium)
detect_browser() {
    local candidates=("${BROWSER_BIN:-}" "chromium-browser" "chromium")
    for candidate in "${candidates[@]}"; do
        [[ -z "$candidate" ]] && continue
        if command -v "$candidate" &> /dev/null; then
            BROWSER_BIN="$candidate"
            echo "[xclient] Browser set to: ${BROWSER_BIN}" | tee -a "$LOG_FILE"
            return 0
        fi
    done
    echo "[xclient] ERROR: Chromium not found!" | tee -a "$LOG_FILE"
    xsetroot -solid red 2>/dev/null
    sleep 30
    return 1
}

prepare_runtime() {
    export LIBGL_ALWAYS_SOFTWARE=1
    export QT_X11_NO_MITSHM=1
    export XDG_RUNTIME_DIR="/tmp/edudisplej-runtime"
    mkdir -p "$XDG_RUNTIME_DIR" "${EDUDISPLEJ_HOME}/chromium-profile" || true
    chmod 700 "$XDG_RUNTIME_DIR" 2>/dev/null || true
    chown edudisplej:edudisplej "$XDG_RUNTIME_DIR" "${EDUDISPLEJ_HOME}/chromium-profile" 2>/dev/null || true
}

get_chromium_flags() {
    local profile_dir="${EDUDISPLEJ_HOME}/chromium-profile"
    echo "--kiosk --noerrdialogs --disable-infobars --start-maximized --incognito --no-sandbox --disable-dev-shm-usage --disable-gpu --user-data-dir=${profile_dir} --no-first-run --no-default-browser-check --password-store=basic --use-mock-keychain --disable-translate --disable-sync --disable-features=Translate,OptimizationHints,MediaRouter,BackForwardCache --enable-low-end-device-mode --renderer-process-limit=1 --no-zygote --single-process --enable-features=OverlayScrollbar"
}

clear_browser_state() {
    # Avoid restore prompts or locked profiles
    rm -rf ~/.config/chromium/Default/Preferences.lock 2>/dev/null
    rm -rf "${EDUDISPLEJ_HOME}/chromium-profile/Singleton*" 2>/dev/null
}

start_chromium() {
    local max_attempts=3
    local attempt=1
    local delay=15

    while [[ $attempt -le $max_attempts ]]; do
        echo "[xclient] Starting ${BROWSER_BIN:-chromium} (attempt ${attempt}/${max_attempts})..." | tee -a "$LOG_FILE"

        pkill -9 chromium 2>/dev/null
        pkill -9 chromium-browser 2>/dev/null
        sleep 1

        clear_browser_state
        prepare_runtime

        echo "[xclient] Command: ${BROWSER_BIN:-chromium} $(get_chromium_flags) ${KIOSK_URL}" | tee -a "$LOG_FILE"
        "${BROWSER_BIN:-chromium}" $(get_chromium_flags) "${KIOSK_URL}" >> "$LOG_FILE" 2>&1 &
        BROWSER_PID=$!

        sleep 5

        if kill -0 $BROWSER_PID 2>/dev/null; then
            echo "[xclient] Browser started successfully (PID: ${BROWSER_PID})"
            wait $BROWSER_PID
            echo "[xclient] Browser exited, restarting..."
            attempt=1
        else
            echo "[xclient] Browser failed to start, retrying..."
            ((attempt++))
        fi

        sleep $delay
    done

    echo "[xclient] Browser failed to start after ${max_attempts} attempts"
    return 1
}

# Main loop - keep browser running
if detect_browser; then
    while true; do
        start_chromium
        echo "[xclient] Waiting before restart..." | tee -a "$LOG_FILE"
        sleep 10
    done
else
    exit 1
fi
