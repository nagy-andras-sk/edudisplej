#!/bin/bash
# xclient.sh - X client wrapper for Openbox + Midori
# This script is started by xinit and runs inside the X session
# All text is in Slovak (without diacritics) or English

# Set display
export DISPLAY=:0

# Path to init directory
EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
CONFIG_FILE="${EDUDISPLEJ_HOME}/edudisplej.conf"

# Load configuration
if [[ -f "$CONFIG_FILE" ]]; then
    source "$CONFIG_FILE"
fi

# Default URL if not set
KIOSK_URL="${KIOSK_URL:-https://www.edudisplej.sk/edserver/demo/client}"

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
# Midori Kiosk
# =============================================================================

# Check if Midori is installed
if ! command -v midori &> /dev/null; then
    echo "[xclient] ERROR: Midori not found!" | tee -a "$LOG_FILE"
    # Set background to red to indicate error
    xsetroot -solid red 2>/dev/null
    sleep 30
    exit 1
fi

echo "[xclient] Midori found at: $(which midori)" | tee -a "$LOG_FILE"

# Get Midori flags
get_midori_flags() {
    echo "--private --plain --no-plugins --app"
}

# Clear Midori session data to avoid restore prompts
rm -rf ~/.config/midori/session* 2>/dev/null
rm -rf ~/.cache/midori 2>/dev/null

# Function to start Midori with retry logic
start_midori() {
    local max_attempts=3
    local attempt=1
    local delay=15
    
    while [[ $attempt -le $max_attempts ]]; do
        echo "[xclient] Starting Midori (attempt ${attempt}/${max_attempts})..." | tee -a "$LOG_FILE"
        
        # Kill any existing Midori instances
        pkill -9 midori 2>/dev/null
        sleep 1
        
        # Start Midori with logging
        echo "[xclient] Command: midori $(get_midori_flags) ${KIOSK_URL}" | tee -a "$LOG_FILE"
        midori $(get_midori_flags) "${KIOSK_URL}" >> "$LOG_FILE" 2>&1 &
        MIDORI_PID=$!
        
        # Wait a bit and check if still running
        sleep 5
        
        if kill -0 $MIDORI_PID 2>/dev/null; then
            echo "[xclient] Midori started successfully (PID: ${MIDORI_PID})"
            
            # Wait for Midori to exit and then restart
            wait $MIDORI_PID
            echo "[xclient] Midori exited, restarting..."
            attempt=1
        else
            echo "[xclient] Midori failed to start, retrying..."
            ((attempt++))
        fi
        
        sleep $delay
    done
    
    echo "[xclient] Midori failed to start after ${max_attempts} attempts"
    return 1
}

# Main loop - keep Midori running
while true; do
    start_midori
    echo "[xclient] Waiting before restart..."
    sleep 10
done
