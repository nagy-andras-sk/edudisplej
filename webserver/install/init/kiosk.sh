#!/bin/bash
# kiosk.sh - Kiosk mode functions (simplified for new .profile-based approach)

# Source common functions if not already sourced
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "${TRANS_SK+x}" ]]; then
    source "${SCRIPT_DIR}/common.sh"
fi

# =============================================================================
# Kiosk Mode Functions
# =============================================================================

# Start kiosk mode (just exit successfully, as kiosk now runs via .profile)
# The actual kiosk is started by autologin → .profile → X server → kiosk-launcher.sh
start_kiosk_mode() {
    print_info "Kiosk mode configured and ready"
    print_info "System will auto-start kiosk after user login on tty1"
    return 0
}

# Stop kiosk mode by killing X and related processes
stop_kiosk_mode() {
    print_info "Stopping kiosk mode..."

    # Kill processes using specific PIDs - collect in array
    local all_pids=()
    local pids temp_pids
    
    pids=$(pgrep -x "midori" 2>/dev/null || true)
    if [[ -n "$pids" ]]; then
        readarray -t temp_pids <<< "$pids"
        all_pids+=("${temp_pids[@]}")
    fi
    
    pids=$(pgrep -x "chromium-browser" 2>/dev/null || true)
    if [[ -n "$pids" ]]; then
        readarray -t temp_pids <<< "$pids"
        all_pids+=("${temp_pids[@]}")
    fi
    
    pids=$(pgrep -x "epiphany-browser" 2>/dev/null || true)
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
