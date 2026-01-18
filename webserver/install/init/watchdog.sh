#!/bin/bash
# watchdog.sh - Browser watchdog service
# Monitors browser process and restarts if it crashes
# Falls back to Firefox ESR if browser crashes repeatedly

EDUDISPLEJ_HOME="/opt/edudisplej"
WATCHDOG_LOG="${EDUDISPLEJ_HOME}/watchdog.log"
WATCHDOG_PID_FILE="${EDUDISPLEJ_HOME}/.watchdog.pid"
CRASH_HISTORY_FILE="${EDUDISPLEJ_HOME}/.crash_history"
BROWSER_FALLBACK_FILE="${EDUDISPLEJ_HOME}/.browser_fallback"
MAX_LOG_SIZE=2097152  # 2MB max log size (consistent with other scripts)
CRASH_THRESHOLD=5     # Number of crashes to trigger fallback
CRASH_WINDOW=300      # Time window in seconds (5 minutes)

# Log rotation - keep log file small
rotate_log() {
    if [[ -f "$WATCHDOG_LOG" ]]; then
        local size
        size=$(stat -f%z "$WATCHDOG_LOG" 2>/dev/null || stat -c%s "$WATCHDOG_LOG" 2>/dev/null || echo 0)
        if [[ $size -gt $MAX_LOG_SIZE ]]; then
            # Keep only last 500 lines
            tail -n 500 "$WATCHDOG_LOG" > "${WATCHDOG_LOG}.tmp"
            mv "${WATCHDOG_LOG}.tmp" "$WATCHDOG_LOG"
        fi
    fi
}

# Write log with timestamp
log_msg() {
    rotate_log
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$WATCHDOG_LOG"
}

# Check if any browser is running
is_browser_running() {
    pgrep -x "chromium" >/dev/null 2>&1 || \
    pgrep -x "chromium-browser" >/dev/null 2>&1 || \
    pgrep -x "epiphany-browser" >/dev/null 2>&1 || \
    pgrep -x "firefox-esr" >/dev/null 2>&1
}

# Get browser PID
get_browser_pid() {
    pgrep -x "chromium" 2>/dev/null || \
    pgrep -x "chromium-browser" 2>/dev/null || \
    pgrep -x "epiphany-browser" 2>/dev/null || \
    pgrep -x "firefox-esr" 2>/dev/null | head -1
}

# Record crash timestamp
record_crash() {
    local now=$(date +%s)
    echo "$now" >> "$CRASH_HISTORY_FILE"
    
    # Clean up old crash records (older than crash window)
    if [[ -f "$CRASH_HISTORY_FILE" ]]; then
        local temp_file="${CRASH_HISTORY_FILE}.tmp"
        while read -r timestamp; do
            if [[ $((now - timestamp)) -lt $CRASH_WINDOW ]]; then
                echo "$timestamp" >> "$temp_file"
            fi
        done < "$CRASH_HISTORY_FILE"
        mv "$temp_file" "$CRASH_HISTORY_FILE" 2>/dev/null || true
    fi
}

# Count crashes in the window
count_recent_crashes() {
    if [[ ! -f "$CRASH_HISTORY_FILE" ]]; then
        echo 0
        return
    fi
    
    local now=$(date +%s)
    local count=0
    while read -r timestamp; do
        if [[ -n "$timestamp" ]] && [[ $((now - timestamp)) -lt $CRASH_WINDOW ]]; then
            ((count++))
        fi
    done < "$CRASH_HISTORY_FILE"
    echo "$count"
}

# Install Firefox ESR
install_firefox_esr() {
    log_msg "Installing Firefox ESR as fallback browser..."
    
    if command -v firefox-esr >/dev/null 2>&1; then
        log_msg "Firefox ESR already installed"
        return 0
    fi
    
    # Install Firefox ESR
    apt-get update >> "${EDUDISPLEJ_HOME}/apt.log" 2>&1
    apt-get install -y firefox-esr >> "${EDUDISPLEJ_HOME}/apt.log" 2>&1
    
    if command -v firefox-esr >/dev/null 2>&1; then
        log_msg "Firefox ESR installed successfully"
        return 0
    else
        log_msg "ERROR: Failed to install Firefox ESR"
        return 1
    fi
}

# Switch to Firefox ESR
switch_to_firefox() {
    log_msg "Switching to Firefox ESR due to repeated crashes"
    
    # Install Firefox ESR if not present
    if ! install_firefox_esr; then
        log_msg "ERROR: Cannot switch to Firefox ESR - installation failed"
        return 1
    fi
    
    # Mark fallback mode
    echo "firefox-esr" > "$BROWSER_FALLBACK_FILE"
    
    # Update config to use Firefox ESR
    local config_file="${EDUDISPLEJ_HOME}/edudisplej.conf"
    if [[ -f "$config_file" ]]; then
        # Add or update BROWSER_BIN
        if grep -q "^BROWSER_BIN=" "$config_file" 2>/dev/null; then
            sed -i 's|^BROWSER_BIN=.*|BROWSER_BIN="firefox-esr"|' "$config_file"
        else
            echo 'BROWSER_BIN="firefox-esr"' >> "$config_file"
        fi
        log_msg "Updated config to use Firefox ESR"
    fi
    
    # Restart the service to apply changes
    log_msg "Restarting chromiumkiosk.service to apply Firefox ESR..."
    systemctl restart chromiumkiosk.service 2>&1 | tee -a "$WATCHDOG_LOG"
    
    # Clear crash history after switching
    rm -f "$CRASH_HISTORY_FILE"
    
    return 0
}

# Start watchdog
start_watchdog() {
    # Check if already running
    if [[ -f "$WATCHDOG_PID_FILE" ]]; then
        local old_pid
        old_pid=$(cat "$WATCHDOG_PID_FILE" 2>/dev/null)
        if [[ -n "$old_pid" ]] && kill -0 "$old_pid" 2>/dev/null; then
            log_msg "Watchdog already running with PID $old_pid"
            return 0
        fi
    fi
    
    # Save watchdog PID
    echo $$ > "$WATCHDOG_PID_FILE"
    
    log_msg "Watchdog started (PID: $$)"
    
    # Check if we're already in fallback mode
    if [[ -f "$BROWSER_FALLBACK_FILE" ]]; then
        local fallback_browser=$(cat "$BROWSER_FALLBACK_FILE" 2>/dev/null)
        log_msg "Running in fallback mode with: $fallback_browser"
    fi
    
    local restart_count=0
    local last_restart=0
    local restart_threshold=3  # Max restarts before waiting
    local time_window=60       # Time window in seconds
    
    while true; do
        if ! is_browser_running; then
            local now
            now=$(date +%s)
            
            # Record crash
            record_crash
            local crash_count=$(count_recent_crashes)
            
            log_msg "Browser not running. Recent crashes: $crash_count in last ${CRASH_WINDOW}s"
            
            # Check if we should switch to Firefox ESR
            if [[ $crash_count -ge $CRASH_THRESHOLD ]]; then
                if [[ ! -f "$BROWSER_FALLBACK_FILE" ]]; then
                    log_msg "CRITICAL: Too many crashes ($crash_count in ${CRASH_WINDOW}s). Switching to Firefox ESR..."
                    if switch_to_firefox; then
                        log_msg "Successfully switched to Firefox ESR"
                        # Wait for service restart
                        sleep 10
                        continue
                    else
                        log_msg "ERROR: Failed to switch to Firefox ESR, continuing with current browser"
                    fi
                fi
            fi
            
            # Check if we're restarting too frequently
            if [[ $((now - last_restart)) -lt $time_window ]]; then
                ((restart_count++))
                if [[ $restart_count -ge $restart_threshold ]]; then
                    log_msg "WARNING: Too many restarts ($restart_count in ${time_window}s). Waiting 2 minutes before retry."
                    sleep 120
                    restart_count=0
                fi
            else
                restart_count=1
            fi
            
            last_restart=$now
            
            log_msg "Browser not running. Restarting... (attempt $restart_count)"
            
            # Restart browser through xclient.sh
            if [[ -f "${EDUDISPLEJ_HOME}/init/xclient.sh" ]]; then
                # Kill any zombie processes first using graceful termination
                local zombie_pids
                zombie_pids=$(pgrep -x "chromium" 2>/dev/null; pgrep -x "chromium-browser" 2>/dev/null; pgrep -x "epiphany-browser" 2>/dev/null; pgrep -x "firefox-esr" 2>/dev/null)
                if [[ -n "$zombie_pids" ]]; then
                    for pid in $zombie_pids; do
                        [[ -z "$pid" ]] && continue
                        kill -TERM "$pid" 2>/dev/null || true
                    done
                    sleep 2
                    # Force kill if still running
                    for pid in $zombie_pids; do
                        [[ -z "$pid" ]] && continue
                        if kill -0 "$pid" 2>/dev/null; then
                            kill -KILL "$pid" 2>/dev/null || true
                        fi
                    done
                    sleep 1
                fi
                
                # Don't restart xclient.sh, it should be running in a loop already
                # Just log the event
                log_msg "Browser process died. xclient.sh should restart it automatically."
            else
                log_msg "ERROR: xclient.sh not found!"
            fi
        fi
        
        # Check every 10 seconds
        sleep 10
    done
}

# Stop watchdog
stop_watchdog() {
    if [[ -f "$WATCHDOG_PID_FILE" ]]; then
        local pid
        pid=$(cat "$WATCHDOG_PID_FILE" 2>/dev/null)
        if [[ -n "$pid" ]]; then
            if kill -0 "$pid" 2>/dev/null; then
                kill "$pid" 2>/dev/null
                log_msg "Watchdog stopped (PID: $pid)"
            fi
            rm -f "$WATCHDOG_PID_FILE"
        fi
    fi
}

# Main
case "${1:-start}" in
    start)
        start_watchdog
        ;;
    stop)
        stop_watchdog
        ;;
    restart)
        stop_watchdog
        sleep 2
        start_watchdog
        ;;
    status)
        if [[ -f "$WATCHDOG_PID_FILE" ]]; then
            local pid
            pid=$(cat "$WATCHDOG_PID_FILE" 2>/dev/null)
            if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
                echo "Watchdog is running (PID: $pid)"
                exit 0
            else
                echo "Watchdog PID file exists but process not running"
                exit 1
            fi
        else
            echo "Watchdog is not running"
            exit 1
        fi
        ;;
    *)
        echo "Usage: $0 {start|stop|restart|status}"
        exit 1
        ;;
esac
