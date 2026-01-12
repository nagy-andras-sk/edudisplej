
#!/bin/bash
# xclient.sh - X client wrapper for Openbox + Chromium
# This script is started by xinit and runs inside the X session
# All text is in Slovak (without diacritics) or English

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

# Clean old log on startup to prevent disk fill (keep only current session)
if [[ -f "$LOG_FILE" ]]; then
    local size
    size=$(stat -f%z "$LOG_FILE" 2>/dev/null || stat -c%s "$LOG_FILE" 2>/dev/null || echo 0)
    if [[ $size -gt $MAX_LOG_SIZE ]]; then
        mv "$LOG_FILE" "${LOG_FILE}.old"
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Log rotated (previous log moved to xclient.log.old)" > "$LOG_FILE"
    fi
fi

# Log: stdout+stderr with timestamps
mkdir -p "${EDUDISPLEJ_HOME}" >/dev/null 2>&1 || true
exec > >(awk '{ print strftime("[%Y-%m-%d %H:%M:%S]"), $0 }' | tee -a "$LOG_FILE") 2>&1

echo "[xclient] Starting at $(date)"
echo "[xclient] DISPLAY=${DISPLAY}"

# Load configuration (if present)
if [[ -f "$CONFIG_FILE" ]]; then
    # shellcheck source=/dev/null
    source "$CONFIG_FILE"
fi

# Default URL if not set (prefer local clock)
KIOSK_URL="${KIOSK_URL:-file:///opt/edudisplej/localweb/clock.html}"
echo "[xclient] KIOSK_URL: ${KIOSK_URL}"

# -----------------------------------------------------------------------------
# Helpers
# -----------------------------------------------------------------------------
x_alive() {
    xset q >/dev/null 2>&1
}

detect_browser() {
    local candidates=()
    # Prefer externally provided BROWSER_BIN, then chromium-browser, chromium
    [[ -n "${BROWSER_BIN:-}" ]] && candidates+=("$BROWSER_BIN")
    candidates+=("chromium-browser" "chromium")

    for candidate in "${candidates[@]}"; do
        [[ -z "$candidate" ]] && continue
        if command -v "$candidate" >/dev/null 2>&1; then
            BROWSER_BIN="$candidate"
            export BROWSER_BIN
            echo "[xclient] Browser set to: ${BROWSER_BIN}"
            return 0
        fi
    done
    echo "[xclient] ERROR: Chromium not found!"
    return 1
}

prepare_runtime() {
    export LIBGL_ALWAYS_SOFTWARE=1
    export QT_X11_NO_MITSHM=1
    export XDG_RUNTIME_DIR="/tmp/edudisplej-runtime"
    mkdir -p "$XDG_RUNTIME_DIR" "${EDUDISPLEJ_HOME}/chromium-profile" || true
    chmod 700 "$XDG_RUNTIME_DIR" 2>/dev/null || true
    # A futtató user tipikusan edudisplej; ha root, ne chown-oljunk hibásan
    if id -u edudisplej >/dev/null 2>&1; then
        chown edudisplej:edudisplej "$XDG_RUNTIME_DIR" "${EDUDISPLEJ_HOME}/chromium-profile" 2>/dev/null || true
    fi
}

get_chromium_flags() {
    local profile_dir="${EDUDISPLEJ_HOME}/chromium-profile"
    echo "--kiosk \
--noerrdialogs \
--disable-infobars \
--start-maximized \
--incognito \
--no-sandbox \
--disable-dev-shm-usage \
--disable-gpu \
--use-gl=swiftshader \
--ozone-platform=x11 \
--user-data-dir=${profile_dir} \
--no-first-run \
--no-default-browser-check \
--password-store=basic \
--disable-translate \
--disable-sync \
--disable-features=Translate,OptimizationHints,MediaRouter,BackForwardCache \
--enable-low-end-device-mode \
--renderer-process-limit=1"
}

clear_browser_state() {
    # Avoid restore prompts or locked profiles
    rm -rf ~/.config/chromium/Default/Preferences.lock 2>/dev/null || true
    rm -rf "${EDUDISPLEJ_HOME}/chromium-profile/Singleton*" 2>/dev/null || true
}

start_unclutter() {
    if command -v unclutter >/dev/null 2>&1; then
        if ! pgrep -x unclutter >/dev/null 2>&1; then
            unclutter -idle 0.5 -root &
            echo "[xclient] Unclutter started"
        fi
    fi
}

start_openbox_if_needed() {
    if command -v openbox >/dev/null 2>&1; then
        if ! pgrep -x openbox >/dev/null 2>&1; then
            openbox &
            echo "[xclient] Openbox started"
            sleep 1
        else
            echo "[xclient] Openbox already running"
        fi
    fi
}

set_background() {
    if command -v xsetroot >/dev/null 2>&1; then
        xsetroot -solid white 2>/dev/null || true
        echo "[xclient] Background set to white"
    fi
}

setup_x_env() {
    # Disable screensaver / DPMS
    xset s off 2>/dev/null || true
    xset s noblank 2>/dev/null || true
    xset -dpms 2>/dev/null || true
    xset dpms 0 0 0 2>/dev/null || true

    mkdir -p /opt/edudisplej/localweb 2>/dev/null || true
    set_background
    start_unclutter
    start_openbox_if_needed
}

# -----------------------------------------------------------------------------
# Chromium start/restart loop
# -----------------------------------------------------------------------------
start_chromium() {
    local max_attempts=3
    local attempt=1
    local delay=15

    while [[ $attempt -le $max_attempts ]]; do
        # Ensure X is alive before each attempt
        if ! x_alive; then
            echo "[xclient] X not available on ${DISPLAY}; waiting..."
            sleep 3
            continue
        fi

        echo "[xclient] Starting ${BROWSER_BIN:-chromium} (attempt ${attempt}/${max_attempts})..."
        # Stop any remnants using specific PIDs
        local old_pids
        old_pids=$(pgrep -x "chromium" 2>/dev/null; pgrep -x "chromium-browser" 2>/dev/null)
        if [[ -n "$old_pids" ]]; then
            echo "[xclient] Stopping old browser processes: $old_pids"
            for pid in $old_pids; do
                kill -TERM "$pid" 2>/dev/null || true
            done
            sleep 2
            # Force kill if still running
            for pid in $old_pids; do
                if kill -0 "$pid" 2>/dev/null; then
                    kill -KILL "$pid" 2>/dev/null || true
                fi
            done
            sleep 1
        fi

        clear_browser_state
        prepare_runtime
        setup_x_env

        local flags
        flags="$(get_chromium_flags)"
        echo "[xclient] Command: ${BROWSER_BIN:-chromium} ${flags} ${KIOSK_URL}"
        "${BROWSER_BIN:-chromium}" ${flags} "${KIOSK_URL}" &
        BROWSER_PID=$!

        # short settle time
        sleep 5

        if kill -0 "$BROWSER_PID" >/dev/null 2>&1; then
            echo "[xclient] Browser started successfully (PID: ${BROWSER_PID})"
            # Wait until it exits, then restart (keep-alive)
            wait "$BROWSER_PID"
            echo "[xclient] Browser exited; restarting after ${delay}s"
            attempt=1
        else
            echo "[xclient] Browser failed to start, retrying..."
            ((attempt++))
        fi

        sleep "$delay"
    done

    echo "[xclient] Browser failed to start after ${max_attempts} attempts"
    return 1
}

# -----------------------------------------------------------------------------
# Main
# -----------------------------------------------------------------------------
# Basic X availability check before doing anything
if ! x_alive; then
    echo "[xclient] WARNING: X connection test failed on ${DISPLAY}. Ensure Xorg/Xwayland is running."
fi

if detect_browser; then
    while true; do
        start_chromium
        echo "[xclient] Waiting before restart..."
        sleep 10
    done
else
    # Set a visible red background to indicate failure
    if command -v xsetroot >/dev/null 2>&1; then
        xsetroot -solid red 2>/dev/null || true
    fi
    echo "[xclient] Exiting (browser not found)."
    exit 1
fi
