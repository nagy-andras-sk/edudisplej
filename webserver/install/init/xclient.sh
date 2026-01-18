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
    log_size=$(stat -f%z "$LOG_FILE" 2>/dev/null || stat -c%s "$LOG_FILE" 2>/dev/null || echo 0)
    if [[ $log_size -gt $MAX_LOG_SIZE ]]; then
        mv "$LOG_FILE" "${LOG_FILE}.old"
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Log rotated (previous log moved to xclient.log.old)" > "$LOG_FILE"
    fi
fi

# Log: stdout+stderr with timestamps
mkdir -p "${EDUDISPLEJ_HOME}" >/dev/null 2>&1 || true
touch "$LOG_FILE" 2>/dev/null || true

# Redirect output to log file (simple, direct approach)
exec 1> >(tee -a "$LOG_FILE") 2>&1 || {
    # Fallback if tee fails - direct write
    exec 1>>"$LOG_FILE" 2>&1
}

echo "[xclient] =========================================="
echo "[xclient] Starting at $(date)"
echo "[xclient] =========================================="
echo "[xclient] DISPLAY=${DISPLAY}"
echo "[xclient] UID=$(id -u), GID=$(id -g)"
echo "[xclient] PWD=$(pwd)"
echo "[xclient] HOME=${HOME}"

# Load configuration (if present)
if [[ -f "$CONFIG_FILE" ]]; then
    # shellcheck source=/dev/null
    source "$CONFIG_FILE"
    echo "[xclient] Config loaded from ${CONFIG_FILE}"
else
    echo "[xclient] Config file not found: ${CONFIG_FILE}"
fi

# Default URL if not set (prefer local clock)
KIOSK_URL="${KIOSK_URL:-file:///opt/edudisplej/localweb/clock.html}"
echo "[xclient] KIOSK_URL=${KIOSK_URL}"

# -----------------------------------------------------------------------------
# Helpers
# -----------------------------------------------------------------------------
x_alive() {
    xset q >/dev/null 2>&1
}

# Check if system has NEON support (ARM only)
has_neon_support() {
    local arch
    arch=$(uname -m)
    
    # Only check on ARM systems
    if [[ "$arch" != "armv"* && "$arch" != "aarch64" ]]; then
        return 0  # Non-ARM systems don't need NEON check
    fi
    
    # Check for NEON support in /proc/cpuinfo
    if grep -qi 'neon' /proc/cpuinfo 2>/dev/null; then
        echo "[xclient] ARM system with NEON support detected"
        return 0
    else
        echo "[xclient] ARM system WITHOUT NEON support detected - modern Chromium will not work"
        return 1
    fi
}

detect_browser() {
    local candidates=()
    
    # Prefer externally provided BROWSER_BIN, then try actual binaries
    [[ -n "${BROWSER_BIN:-}" ]] && candidates+=("$BROWSER_BIN")
    
    # On ARM systems without NEON, prioritize Epiphany (lightweight, works on older Pi)
    if ! has_neon_support; then
        echo "[xclient] System lacks NEON support - prioritizing Epiphany browser"
        candidates+=(
            "epiphany-browser"
            # Try older Chromium versions that might be installed
            "/usr/lib/chromium-browser/chromium-browser"
            "/usr/lib/chromium/chromium"
            "chromium-browser"
            "chromium"
        )
    else
        # Try real chromium binaries first, then epiphany (lightweight, works on older Pi)
        candidates+=(
            "/usr/lib/chromium-browser/chromium-browser"
            "/usr/lib/chromium/chromium"
            "chromium-browser"
            "chromium"
            "epiphany-browser"
        )
    fi

    for candidate in "${candidates[@]}"; do
        [[ -z "$candidate" ]] && continue
        # Check if it's an actual executable file or command
        if [[ -x "$candidate" ]] || command -v "$candidate" >/dev/null 2>&1; then
            BROWSER_BIN="$candidate"
            export BROWSER_BIN
            echo "[xclient] Browser set to: ${BROWSER_BIN}"
            # Verify it's not just a shell wrapper
            local browser_type
            browser_type=$(file "$BROWSER_BIN" 2>/dev/null || echo "unknown")
            echo "[xclient] Browser type: ${browser_type}"
            return 0
        fi
    done
    echo "[xclient] ERROR: Browser not found (chromium/epiphany)"
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
    echo "[xclient] Runtime environment prepared (XDG_RUNTIME_DIR=${XDG_RUNTIME_DIR})"
}

get_chromium_flags() {
    local profile_dir="${EDUDISPLEJ_HOME}/chromium-profile"
    # Raspberry Pi optimized flags - avoid flags that cause crashes
    echo "--kiosk \
--noerrdialogs \
--disable-infobars \
--start-maximized \
--incognito \
--no-sandbox \
--disable-dev-shm-usage \
--disable-gpu \
--disable-software-rasterizer \
--disable-extensions \
--disable-plugins \
--user-data-dir=${profile_dir} \
--no-first-run \
--no-default-browser-check \
--password-store=basic \
--disable-translate \
--disable-sync \
--disable-component-extensions \
--disable-background-timer-throttling \
--disable-backgrounding-occluded-windows \
--disable-breakpad \
--disable-client-side-phishing-detection \
--disable-default-apps \
--disable-hang-monitor \
--disable-ipc-flooding-protection \
--disable-popup-blocking \
--disable-prompt-on-repost \
--disable-sync \
--metrics-recording-only \
--mute-audio \
--no-default-browser-check \
--no-pings \
--safebrowsing-disable-auto-update \
--enable-automation \
--disable-features=Translate,OptimizationHints,MediaRouter,BackForwardCache"
}

get_browser_flags() {
    case "$BROWSER_BIN" in
        *epiphany-browser*)
            # Epiphany: no special flags needed, just pass URL directly
            echo ""
            ;;
        *)
            get_chromium_flags
            ;;
    esac
}

clear_browser_state() {
    # Avoid restore prompts or locked profiles
    rm -rf ~/.config/chromium/Default/Preferences.lock 2>/dev/null || true
    rm -rf "${EDUDISPLEJ_HOME}/chromium-profile/Singleton*" 2>/dev/null || true
}

# Show a simple on-screen error dialog (best-effort)
show_error_overlay() {
    local msg="$1"
    if command -v xmessage >/dev/null 2>&1; then
        xmessage -center -timeout 15 "$msg" >/dev/null 2>&1 || true
    elif command -v zenity >/dev/null 2>&1; then
        zenity --info --text="$msg" --timeout=15 >/dev/null 2>&1 || true
    else
        echo "[xclient] ERROR OVERLAY: $msg"
    fi
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
                        # Setup openbox config (force overwrite to avoid leading blanks)
                        mkdir -p ~/.config/openbox 2>/dev/null || true
                        cat > ~/.config/openbox/rc.xml <<'EOF'
<?xml version="1.0" encoding="UTF-8"?>
<openbox_config xmlns="http://openbox.org/3.4/rc" xmlns:xi="http://www.w3.org/2001/XInclude">
    <desktops>
        <number>1</number>
        <firstdesk>1</firstdesk>
        <names>
            <name>Desktop 1</name>
        </names>
        <popupTime>400</popupTime>
    </desktops>
    <margins>
        <top>0</top><bottom>0</bottom><left>0</left><right>0</right>
    </margins>
    <applications>
        <application name="chromium" type="normal">
            <decor>no</decor>
            <maximized>yes</maximized>
        </application>
        <application name="chromium-browser" type="normal">
            <decor>no</decor>
            <maximized>yes</maximized>
        </application>
    </applications>
</openbox_config>
EOF
                        echo "[xclient] Openbox config installed"

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
    echo "[xclient] X environment setup complete"
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
        old_pids=$(pgrep -x "chromium" 2>/dev/null)
        old_pids="$old_pids $(pgrep -x "chromium-browser" 2>/dev/null)"
        if [[ -n "$old_pids" ]] && [[ "$old_pids" != " " ]]; then
            echo "[xclient] Stopping old browser processes: $old_pids"
            for pid in $old_pids; do
                [[ -z "$pid" ]] && continue
                kill -TERM "$pid" 2>/dev/null || true
            done
            sleep 2
            # Force kill if still running
            for pid in $old_pids; do
                [[ -z "$pid" ]] && continue
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
        flags="$(get_browser_flags)"
        echo "[xclient] Command: ${BROWSER_BIN:-chromium} ${flags} ${KIOSK_URL}"
        "${BROWSER_BIN:-chromium}" ${flags} "${KIOSK_URL}" &
        BROWSER_PID=$!

        # short settle time
        sleep 5

        if kill -0 "$BROWSER_PID" >/dev/null 2>&1; then
            echo "[xclient] Browser started successfully (PID: ${BROWSER_PID})"
            # Wait until it exits, then restart (keep-alive)
            wait "$BROWSER_PID"
            local exit_code=$?
            echo "[xclient] Browser exited with code ${exit_code}; restarting after ${delay}s"
            if [[ $exit_code -ne 0 ]]; then
                show_error_overlay "Chromium crashed (exit ${exit_code}). Restarting..."
            fi
            # If we've successfully started once, keep attempting
            attempt=1
        else
            echo "[xclient] Browser failed to start, retrying..."
            show_error_overlay "Nem sikerult elinditani a Chromiummot. Probalkozas: ${attempt}/${max_attempts}"
            ((attempt++))
        fi

        sleep "$delay"
    done

    echo "[xclient] Browser failed to start after ${max_attempts} attempts"
    show_error_overlay "A Chromium nem indult el ${max_attempts} probalkozas utan. Ellenorizd a telepitest vagy a halozatot."
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
    done
else
    echo "[xclient] ERROR: No browser found. System will not start kiosk."
    exit 1
fi
