#!/bin/bash
# kiosk.sh - X server and kiosk browser setup
# All text is in Slovak (without diacritics) or English

# Source common functions if not already sourced
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "${TRANS_SK+x}" ]]; then
    source "${SCRIPT_DIR}/common.sh"
fi

DEFAULT_BROWSER_CANDIDATES=("chromium-browser" "chromium")
if [[ ${#BROWSER_CANDIDATES[@]} -eq 0 ]]; then
    BROWSER_CANDIDATES=("${DEFAULT_BROWSER_CANDIDATES[@]}")
fi

if [[ -z "${BROWSER_BIN:-}" ]]; then
    BROWSER_BIN=""
fi

# =============================================================================
# X Server Functions
# =============================================================================

# Clean up old X sessions
cleanup_x_sessions() {
    print_info "Cleaning up old X sessions..."
    
    # Kill any existing X processes using specific PIDs
    local xorg_pids
    xorg_pids=$(pgrep -x "Xorg" 2>/dev/null)
    if [[ -n "$xorg_pids" ]]; then
        for pid in $xorg_pids; do
            kill -TERM "$pid" 2>/dev/null || true
        done
    fi
    
    local xinit_pids
    xinit_pids=$(pgrep -x "xinit" 2>/dev/null)
    if [[ -n "$xinit_pids" ]]; then
        for pid in $xinit_pids; do
            kill -TERM "$pid" 2>/dev/null || true
        done
    fi
    
    local chromium_pids
    chromium_pids=$(pgrep -x "chromium" 2>/dev/null)
    chromium_pids="$chromium_pids $(pgrep -x "chromium-browser" 2>/dev/null)"
    if [[ -n "$chromium_pids" ]] && [[ "$chromium_pids" != " " ]]; then
        for pid in $chromium_pids; do
            [[ -z "$pid" ]] && continue
            kill -TERM "$pid" 2>/dev/null || true
        done
    fi
    
    sleep 1
    
    # Force kill if still running
    for pid in $xorg_pids $xinit_pids $chromium_pids; do
        [[ -z "$pid" ]] && continue
        if kill -0 "$pid" 2>/dev/null; then
            kill -KILL "$pid" 2>/dev/null || true
        fi
    done
    
    # Remove X lock files
    rm -f /tmp/.X0-lock 2>/dev/null
    rm -rf /tmp/.X11-unix/X0 2>/dev/null
    
    sleep 1
}

# Start X server
start_x_server() {
    print_info "$(t kiosk_starting_x)"
    
    # Verify xinit is installed
    if ! command -v xinit &> /dev/null; then
        print_error "xinit not found. Attempting to install..."
        apt-get update -y && apt-get install -y xinit xserver-xorg x11-utils 2>/dev/null
        if ! command -v xinit &> /dev/null; then
            print_error "Failed to install xinit - cannot start X server"
            return 1
        fi
        print_success "xinit installed successfully"
    fi
    
    cleanup_x_sessions
    
    export DISPLAY=:0
    
    # Start X server - direct xinit for reliable operation during system boot
    xinit "${INIT_DIR}/xclient.sh" -- :0 vt1 -nolisten tcp &
    
    # Wait for X to start
    local attempts=0
    while [[ $attempts -lt 30 ]]; do
        if xdpyinfo &>/dev/null; then
            print_success "X server started successfully"
            return 0
        fi
        sleep 0.5
        ((attempts++))
    done
    
    print_error "Failed to start X server"
    return 1
}

# =============================================================================
# Browser Functions
# =============================================================================

detect_browser() {
    if [[ -n "${BROWSER_BIN:-}" ]] && command -v "$BROWSER_BIN" >/dev/null 2>&1; then
        return 0
    fi

    for candidate in "${BROWSER_CANDIDATES[@]}"; do
        if command -v "$candidate" >/dev/null 2>&1; then
            BROWSER_BIN="$candidate"
            return 0
        fi
    done

    print_error "No supported browser found (tried: ${BROWSER_CANDIDATES[*]})"
    return 1
}

get_browser_flags() {
    local url="${1:-$KIOSK_URL}"
    local profile_dir="${EDUDISPLEJ_HOME:-/opt/edudisplej}/chromium-profile"
    case "$BROWSER_BIN" in
        chromium-browser|chromium)
            echo "--kiosk --noerrdialogs --disable-infobars --start-maximized --incognito --no-sandbox --disable-dev-shm-usage --disable-gpu --use-gl=swiftshader --ozone-platform=x11 --user-data-dir=${profile_dir} --no-first-run --no-default-browser-check --password-store=basic --disable-translate --disable-sync --disable-features=Translate,OptimizationHints,MediaRouter,BackForwardCache --enable-low-end-device-mode --renderer-process-limit=1 ${url}"
            ;;
        *)
            echo "${url}"
            ;;
    esac
}

start_browser_kiosk() {
    local url="${1:-$KIOSK_URL}"
    local max_attempts=3
    local attempt=1
    local delay=15

    if ! detect_browser; then
        return 1
    fi

    print_info "$(t kiosk_starting_browser) ${BROWSER_BIN}"
    
    while [[ $attempt -le $max_attempts ]]; do
        print_info "Attempt ${attempt}/${max_attempts}..."
        
        # Kill any existing browser instances using specific PIDs
        local browser_pids
        browser_pids=$(pgrep -x "$BROWSER_BIN" 2>/dev/null)
        if [[ -n "$browser_pids" ]]; then
            for pid in $browser_pids; do
                kill -TERM "$pid" 2>/dev/null || true
            done
            sleep 1
            # Force kill if still running
            for pid in $browser_pids; do
                if kill -0 "$pid" 2>/dev/null; then
                    kill -KILL "$pid" 2>/dev/null || true
                fi
            done
            sleep 1
        fi
        
        # Clear per-browser session data
        case "$BROWSER_BIN" in
            chromium|chromium-browser)
                rm -rf ~/.config/chromium/Default/Preferences.lock 2>/dev/null
                ;;
        esac
        
        # Start browser
        export DISPLAY=:0
        "$BROWSER_BIN" $(get_browser_flags "$url") &
        BROWSER_PID=$!
        
        # Wait and check if browser is running
        sleep 5
        if kill -0 $BROWSER_PID 2>/dev/null; then
            print_success "Browser started successfully"
            return 0
        fi
        
        print_warning "$(t kiosk_retry)"
        sleep $delay
        ((attempt++))
    done
    
    print_error "$(t kiosk_failed)"
    return 1
}

# =============================================================================
# Full Kiosk Start
# =============================================================================

# Start full kiosk mode (X + browser)
start_kiosk_mode() {
    local url="${1:-$KIOSK_URL}"
    
    print_info "$(t boot_starting_kiosk)"
    
    # Start X server
    if ! start_x_server; then
        print_error "Could not start X server"
        return 1
    fi
    
    # Start selected browser
    if ! start_browser_kiosk "$url"; then
        print_error "Could not start browser"
        return 1
    fi
    
    return 0
}

# Stop kiosk mode
stop_kiosk_mode() {
    print_info "Stopping kiosk mode..."

    # Kill processes using specific PIDs
    local chromium_pids
    chromium_pids=$(pgrep -x "chromium" 2>/dev/null)
    chromium_pids="$chromium_pids $(pgrep -x "chromium-browser" 2>/dev/null)"
    for pid in $chromium_pids; do
        [[ -z "$pid" ]] && continue
        kill -TERM "$pid" 2>/dev/null || true
    done
    
    local openbox_pids
    openbox_pids=$(pgrep -x "openbox" 2>/dev/null)
    for pid in $openbox_pids; do
        [[ -z "$pid" ]] && continue
        kill -TERM "$pid" 2>/dev/null || true
    done
    
    local unclutter_pids
    unclutter_pids=$(pgrep -x "unclutter" 2>/dev/null)
    for pid in $unclutter_pids; do
        [[ -z "$pid" ]] && continue
        kill -TERM "$pid" 2>/dev/null || true
    done
    
    local xorg_pids
    xorg_pids=$(pgrep -x "Xorg" 2>/dev/null)
    for pid in $xorg_pids; do
        [[ -z "$pid" ]] && continue
        kill -TERM "$pid" 2>/dev/null || true
    done
    
    local xinit_pids
    xinit_pids=$(pgrep -x "xinit" 2>/dev/null)
    for pid in $xinit_pids; do
        [[ -z "$pid" ]] && continue
        kill -TERM "$pid" 2>/dev/null || true
    done
    
    sleep 1
    
    # Force kill if still running
    for pid in $chromium_pids $openbox_pids $unclutter_pids $xorg_pids $xinit_pids; do
        [[ -z "$pid" ]] && continue
        if kill -0 "$pid" 2>/dev/null; then
            kill -KILL "$pid" 2>/dev/null || true
        fi
    done
    
    rm -f /tmp/.X0-lock 2>/dev/null
    rm -rf /tmp/.X11-unix/X0 2>/dev/null
    
    print_success "Kiosk mode stopped"
}
