#!/bin/bash
# kiosk.sh - X server and Chromium kiosk setup
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
# Chromium Functions
# =============================================================================

# Get Chromium flags for kiosk mode
get_chromium_flags() {
    local url="${1:-$KIOSK_URL}"
    
    echo "--kiosk \
          --start-fullscreen \
          --start-maximized \
          --noerrdialogs \
          --disable-infobars \
          --disable-session-crashed-bubble \
          --disable-restore-session-state \
          --disable-features=TranslateUI \
          --incognito \
          --no-first-run \
          --disable-pinch \
          --overscroll-history-navigation=0 \
          --check-for-update-interval=31536000 \
          --disable-backgrounding-occluded-windows \
          --disable-component-update \
          --disable-breakpad \
          --disable-client-side-phishing-detection \
          --disable-default-apps \
          --disable-extensions \
          --disable-hang-monitor \
          --disable-popup-blocking \
          --disable-prompt-on-repost \
          --disable-sync \
          --disable-translate \
          --metrics-recording-only \
          --no-default-browser-check \
          --password-store=basic \
          --use-mock-keychain \
          ${url}"
}

# Start Chromium in kiosk mode
start_chromium_kiosk() {
    local url="${1:-$KIOSK_URL}"
    local max_attempts=3
    local attempt=1
    local delay=20
    
    print_info "$(t kiosk_starting_chromium)"
    
    while [[ $attempt -le $max_attempts ]]; do
        print_info "Attempt ${attempt}/${max_attempts}..."
        
        # Kill any existing Chromium instances
        pkill -9 chromium 2>/dev/null
        sleep 1
        
        # Clear Chromium crash data
        rm -rf ~/.config/chromium/Singleton* 2>/dev/null
        rm -rf ~/.config/chromium/Default/Preferences 2>/dev/null
        
        # Start Chromium
        export DISPLAY=:0
        chromium-browser $(get_chromium_flags "$url") &
        
        # Wait and check if Chromium is running
        sleep 5
        if pgrep -x "chromium" > /dev/null || pgrep -x "chromium-browser" > /dev/null; then
            print_success "Chromium started successfully"
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

# Start full kiosk mode (X + Chromium)
start_kiosk_mode() {
    local url="${1:-$KIOSK_URL}"
    
    print_info "$(t boot_starting_kiosk)"
    
    # Start X server
    if ! start_x_server; then
        print_error "Could not start X server"
        return 1
    fi
    
    # Start Chromium
    if ! start_chromium_kiosk "$url"; then
        print_error "Could not start Chromium"
        return 1
    fi
    
    return 0
}

# Stop kiosk mode
stop_kiosk_mode() {
    print_info "Stopping kiosk mode..."
    
    pkill -9 chromium 2>/dev/null
    pkill -9 chromium-browser 2>/dev/null
    pkill -9 openbox 2>/dev/null
    pkill -9 unclutter 2>/dev/null
    pkill -9 Xorg 2>/dev/null
    pkill -9 xinit 2>/dev/null
    
    rm -f /tmp/.X0-lock 2>/dev/null
    rm -rf /tmp/.X11-unix/X0 2>/dev/null
    
    print_success "Kiosk mode stopped"
}
