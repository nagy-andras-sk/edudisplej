#!/bin/bash
# kiosk.sh - Simplified X server and kiosk setup
# NOTE: This file is being transitioned to minimal-kiosk.sh for improved reliability.
#       The start_kiosk_mode() function now delegates to minimal-kiosk.sh.
#       Other functions in this file are retained for backwards compatibility.

# Source common functions if not already sourced
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "${TRANS_SK+x}" ]]; then
    source "${SCRIPT_DIR}/common.sh"
fi

# =============================================================================
# X Server Functions
# =============================================================================

# Clean up old X sessions
cleanup_x_sessions() {
    print_info "Cleaning up old X sessions..."
    
    # Kill any existing X processes using specific PIDs
    local xorg_pids
    xorg_pids=$(pgrep -x "Xorg" 2>/dev/null || true)
    if [[ -n "$xorg_pids" ]]; then
        for pid in $xorg_pids; do
            kill -TERM "$pid" 2>/dev/null || true
        done
        sleep 1
        for pid in $xorg_pids; do
            if kill -0 "$pid" 2>/dev/null; then
                kill -KILL "$pid" 2>/dev/null || true
            fi
        done
    fi
    
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
    
    # Ensure xclient.sh is executable
    if [[ -f "${INIT_DIR}/xclient.sh" ]]; then
        chmod +x "${INIT_DIR}/xclient.sh"
    else
        print_error "xclient.sh not found at ${INIT_DIR}/xclient.sh"
        return 1
    fi
    
    # Start X server
    print_info "Starting xinit..."
    xinit "${INIT_DIR}/xclient.sh" -- :0 vt1 -nolisten tcp &
    XINIT_PID=$!
    
    # Wait for X to start
    local attempts=0
    while [[ $attempts -lt 30 ]]; do
        if DISPLAY=:0 xdpyinfo &>/dev/null; then
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
# Full Kiosk Start
# =============================================================================

# Start full kiosk mode (X + browser via xclient.sh)
start_kiosk_mode() {
    print_info "Starting minimal kiosk mode..."
    
    # Stop old service if running
    systemctl stop chromiumkiosk-minimal 2>/dev/null || true
    
    # Start minimal kiosk
    if [[ -x "${INIT_DIR}/minimal-kiosk.sh" ]]; then
        "${INIT_DIR}/minimal-kiosk.sh" &
        print_success "Kiosk started"
    else
        print_error "minimal-kiosk.sh not found or not executable"
        return 1
    fi
}

# Stop kiosk mode
stop_kiosk_mode() {
    print_info "Stopping kiosk mode..."

    # Kill processes using specific PIDs - collect in array
    local all_pids=()
    local pids temp_pids
    
    pids=$(pgrep -x "chromium-browser" 2>/dev/null || true)
    if [[ -n "$pids" ]]; then
        readarray -t temp_pids <<< "$pids"
        all_pids+=("${temp_pids[@]}")
    fi
    
    pids=$(pgrep -x "openbox" 2>/dev/null || true)
    if [[ -n "$pids" ]]; then
        readarray -t temp_pids <<< "$pids"
        all_pids+=("${temp_pids[@]}")
    fi
    
    pids=$(pgrep -x "unclutter" 2>/dev/null || true)
    if [[ -n "$pids" ]]; then
        readarray -t temp_pids <<< "$pids"
        all_pids+=("${temp_pids[@]}")
    fi
    
    pids=$(pgrep -x "Xorg" 2>/dev/null || true)
    if [[ -n "$pids" ]]; then
        readarray -t temp_pids <<< "$pids"
        all_pids+=("${temp_pids[@]}")
    fi
    
    pids=$(pgrep -x "xinit" 2>/dev/null || true)
    if [[ -n "$pids" ]]; then
        readarray -t temp_pids <<< "$pids"
        all_pids+=("${temp_pids[@]}")
    fi
    
    # TERM signal first
    for pid in "${all_pids[@]}"; do
        [[ -z "$pid" ]] && continue
        kill -TERM "$pid" 2>/dev/null || true
    done
    
    sleep 1
    
    # Force kill if still running
    for pid in "${all_pids[@]}"; do
        [[ -z "$pid" ]] && continue
        if kill -0 "$pid" 2>/dev/null; then
            kill -KILL "$pid" 2>/dev/null || true
        fi
    done
    
    rm -f /tmp/.X0-lock 2>/dev/null
    rm -rf /tmp/.X11-unix/X0 2>/dev/null
    
    print_success "Kiosk mode stopped"
}

