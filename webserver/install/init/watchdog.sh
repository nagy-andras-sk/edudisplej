#!/bin/bash
# watchdog.sh - Chromium watchdog service
# Monitors chromium process and restarts if it crashes

EDUDISPLEJ_HOME="/opt/edudisplej"
WATCHDOG_LOG="${EDUDISPLEJ_HOME}/watchdog.log"
WATCHDOG_PID_FILE="${EDUDISPLEJ_HOME}/.watchdog.pid"
MAX_LOG_SIZE=1048576  # 1MB max log size

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

# Check if chromium is running
is_chromium_running() {
    pgrep -x "chromium" >/dev/null 2>&1 || pgrep -x "chromium-browser" >/dev/null 2>&1
}

# Get chromium PID
get_chromium_pid() {
    pgrep -x "chromium" 2>/dev/null || pgrep -x "chromium-browser" 2>/dev/null | head -1
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
    
    local restart_count=0
    local last_restart=0
    local restart_threshold=3  # Max restarts in time window
    local time_window=60       # Time window in seconds
    
    while true; do
        if ! is_chromium_running; then
            local now
            now=$(date +%s)
            
            # Check if we're restarting too frequently
            if [[ $((now - last_restart)) -lt $time_window ]]; then
                ((restart_count++))
                if [[ $restart_count -ge $restart_threshold ]]; then
                    log_msg "ERROR: Too many restarts ($restart_count in ${time_window}s). Waiting 5 minutes before retry."
                    sleep 300
                    restart_count=0
                fi
            else
                restart_count=1
            fi
            
            last_restart=$now
            
            log_msg "Chromium not running. Restarting... (attempt $restart_count)"
            
            # Restart chromium through xclient.sh
            if [[ -f "${EDUDISPLEJ_HOME}/init/xclient.sh" ]]; then
                # Kill any zombie processes first
                local zombie_pids
                zombie_pids=$(pgrep -x "chromium" 2>/dev/null; pgrep -x "chromium-browser" 2>/dev/null)
                if [[ -n "$zombie_pids" ]]; then
                    for pid in $zombie_pids; do
                        kill -9 "$pid" 2>/dev/null
                    done
                    sleep 2
                fi
                
                # Don't restart xclient.sh, it should be running in a loop already
                # Just log the event
                log_msg "Chromium process died. xclient.sh should restart it automatically."
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
