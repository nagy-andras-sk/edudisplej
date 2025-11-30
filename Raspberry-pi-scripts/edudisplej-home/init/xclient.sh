#!/bin/bash
# xclient.sh - X client wrapper for Openbox + Chromium
# This script is started by xinit and runs inside the X session
# All text is in Slovak (without diacritics) or English

# Set display
export DISPLAY=:0

# Path to init directory
EDUDISPLEJ_HOME="/home/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
CONFIG_FILE="${EDUDISPLEJ_HOME}/edudisplej.conf"

# Load configuration
if [[ -f "$CONFIG_FILE" ]]; then
    source "$CONFIG_FILE"
fi

# Default URL if not set
KIOSK_URL="${KIOSK_URL:-https://www.edudisplej.sk/edserver/demo/client}"

# =============================================================================
# X Environment Setup
# =============================================================================

# Disable screensaver
xset s off 2>/dev/null
xset s noblank 2>/dev/null
xset -dpms 2>/dev/null

# Disable screen blanking
xset dpms 0 0 0 2>/dev/null

# Set background to black
if command -v xsetroot &> /dev/null; then
    xsetroot -solid black 2>/dev/null
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
# Chromium Kiosk
# =============================================================================

# Get Chromium flags
get_chromium_flags() {
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
          --use-mock-keychain"
}

# Clear Chromium crash data to prevent "restore session" prompts
rm -rf ~/.config/chromium/Singleton* 2>/dev/null
rm -rf ~/.config/chromium/Default/Preferences 2>/dev/null
rm -rf ~/.config/chromium/.org.chromium.Chromium.* 2>/dev/null

# Function to start Chromium with retry logic
start_chromium() {
    local max_attempts=3
    local attempt=1
    local delay=20
    
    while [[ $attempt -le $max_attempts ]]; do
        echo "[xclient] Starting Chromium (attempt ${attempt}/${max_attempts})..."
        
        # Kill any existing Chromium instances
        pkill -9 chromium 2>/dev/null
        pkill -9 chromium-browser 2>/dev/null
        sleep 1
        
        # Start Chromium
        chromium-browser $(get_chromium_flags) "${KIOSK_URL}" &
        CHROMIUM_PID=$!
        
        # Wait a bit and check if still running
        sleep 5
        
        if kill -0 $CHROMIUM_PID 2>/dev/null; then
            echo "[xclient] Chromium started successfully (PID: ${CHROMIUM_PID})"
            
            # Wait for Chromium to exit
            wait $CHROMIUM_PID
            
            # If Chromium exits, restart it
            echo "[xclient] Chromium exited, restarting..."
            ((attempt++))
        else
            echo "[xclient] Chromium failed to start, retrying..."
            ((attempt++))
        fi
        
        sleep $delay
    done
    
    echo "[xclient] Chromium failed to start after ${max_attempts} attempts"
    return 1
}

# Main loop - keep Chromium running
while true; do
    start_chromium
    echo "[xclient] Waiting before restart..."
    sleep 10
done
