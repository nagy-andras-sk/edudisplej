#!/bin/bash
# kiosk.sh - X server and Midori kiosk setup
# All text is in Slovak (without diacritics) or English

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
    
    # Kill any existing X processes
    pkill -9 Xorg 2>/dev/null
    pkill -9 xinit 2>/dev/null
    pkill -9 chromium 2>/dev/null
    
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
# Midori Functions
# =============================================================================

# Get Midori flags for kiosk mode
get_midori_flags() {
    local url="${1:-$KIOSK_URL}"
    echo "--private --plain --no-plugins --app ${url}"
}

# Start Midori in kiosk mode
start_midori_kiosk() {
    local url="${1:-$KIOSK_URL}"
    local max_attempts=3
    local attempt=1
    local delay=15
    
    print_info "$(t kiosk_starting_midori)"
    
    while [[ $attempt -le $max_attempts ]]; do
        print_info "Attempt ${attempt}/${max_attempts}..."
        
        # Kill any existing Midori instances
        pkill -9 midori 2>/dev/null
        sleep 1
        
        # Clear Midori session data
        rm -rf ~/.config/midori/session* 2>/dev/null
        rm -rf ~/.cache/midori 2>/dev/null
        
        # Start Midori
        export DISPLAY=:0
        midori $(get_midori_flags "$url") &
        MIDORI_PID=$!
        
        # Wait and check if Midori is running
        sleep 5
        if kill -0 $MIDORI_PID 2>/dev/null; then
            print_success "Midori started successfully"
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

# Start full kiosk mode (X + Midori)
start_kiosk_mode() {
    local url="${1:-$KIOSK_URL}"
    
    print_info "$(t boot_starting_kiosk)"
    
    # Start X server
    if ! start_x_server; then
        print_error "Could not start X server"
        return 1
    fi
    
    # Start Midori
    if ! start_midori_kiosk "$url"; then
        print_error "Could not start Midori"
        return 1
    fi
    
    return 0
}

# Stop kiosk mode
stop_kiosk_mode() {
    print_info "Stopping kiosk mode..."
    
    pkill -9 midori 2>/dev/null
    pkill -9 openbox 2>/dev/null
    pkill -9 unclutter 2>/dev/null
    pkill -9 Xorg 2>/dev/null
    pkill -9 xinit 2>/dev/null
    
    rm -f /tmp/.X0-lock 2>/dev/null
    rm -rf /tmp/.X11-unix/X0 2>/dev/null
    
    print_success "Kiosk mode stopped"
}
