#!/bin/bash
# minimal-kiosk.sh - Minimal, bulletproof X11 + Chromium kiosk launcher
set -euo pipefail

# Configuration
EDUDISPLEJ_HOME="/opt/edudisplej"
LOG_FILE="${EDUDISPLEJ_HOME}/kiosk.log"
CONFIG_FILE="${EDUDISPLEJ_HOME}/edudisplej.conf"
KIOSK_URL="https://www.time.is"

# Safety limits to prevent infinite loops
MAX_CHROMIUM_RESTARTS=10
MAX_X_RESTARTS=3
CHROMIUM_RESTART_COUNT=0
X_RESTART_COUNT=0
LAST_CHROMIUM_START=0
CHROMIUM_MIN_UPTIME=30  # Chromium must run at least 30s to reset counter

# Logging
exec > >(tee -a "$LOG_FILE") 2>&1
echo "========== Kiosk Start: $(date) =========="
echo "[minimal-kiosk.sh] Starting with PID $$"

# Load config
if [[ -f "$CONFIG_FILE" ]]; then
    # Validate config file is readable and not empty
    if [[ -r "$CONFIG_FILE" && -s "$CONFIG_FILE" ]]; then
        # Source config with explicit variable handling
        set +u  # Temporarily allow unset variables during source
        source "$CONFIG_FILE"
        set -u  # Re-enable unset variable checking
        echo "✓ [minimal-kiosk.sh:$(($LINENO-4))] Config loaded: $CONFIG_FILE"
    else
        echo "⚠ [minimal-kiosk.sh:$LINENO] Config file exists but is not readable or empty: $CONFIG_FILE"
    fi
else
    echo "⚠ [minimal-kiosk.sh:$LINENO] Config file not found: $CONFIG_FILE, using defaults"
fi

KIOSK_URL="${KIOSK_URL:-https://www.time.is}"
echo "✓ [minimal-kiosk.sh:$LINENO] Target URL: $KIOSK_URL"

# Step 1: Install dependencies
install_dependencies() {
    echo "[1/5] [minimal-kiosk.sh:$LINENO] Checking dependencies..."
    
    local packages=(
        "xorg"
        "x11-xserver-utils"
        "xinit"
        "openbox"
        "chromium-browser"
        "unclutter"
    )
    
    local missing=()
    for pkg in "${packages[@]}"; do
        if ! dpkg -l | grep -q "^ii  $pkg"; then
            missing+=("$pkg")
        fi
    done
    
    if [[ ${#missing[@]} -gt 0 ]]; then
        echo "   [minimal-kiosk.sh:$LINENO] Installing: ${missing[*]}"
        if ! apt-get update -qq 2>&1; then
            echo "✗ [minimal-kiosk.sh:$LINENO] Failed to update package lists"
            echo "⚠ Continuing with existing package cache..."
        fi
        
        if apt-get install -y "${missing[@]}" 2>&1; then
            echo "✓ [minimal-kiosk.sh:$LINENO] Dependencies installed"
        else
            echo "✗ [minimal-kiosk.sh:$LINENO] Failed to install some packages: ${missing[*]}"
            echo "⚠ Please check network connection and package availability"
            return 1
        fi
    else
        echo "✓ [minimal-kiosk.sh:$LINENO] All dependencies present"
    fi
}

# Step 2: Clean previous X sessions
cleanup_x() {
    echo "[2/5] [minimal-kiosk.sh:$LINENO] Cleaning previous X sessions..."
    
    # Kill existing processes using specific PIDs
    local xorg_pids chromium_pids openbox_pids
    
    chromium_pids=$(pgrep chromium-browser 2>/dev/null || true)
    if [[ -n "$chromium_pids" ]]; then
        echo "   [minimal-kiosk.sh:$LINENO] Terminating chromium-browser processes: $chromium_pids"
        for pid in $chromium_pids; do
            kill -TERM "$pid" 2>/dev/null || true
        done
    fi
    
    openbox_pids=$(pgrep openbox 2>/dev/null || true)
    if [[ -n "$openbox_pids" ]]; then
        echo "   [minimal-kiosk.sh:$LINENO] Terminating openbox processes: $openbox_pids"
        for pid in $openbox_pids; do
            kill -TERM "$pid" 2>/dev/null || true
        done
    fi
    
    xorg_pids=$(pgrep Xorg 2>/dev/null || true)
    if [[ -n "$xorg_pids" ]]; then
        echo "   [minimal-kiosk.sh:$LINENO] Terminating Xorg processes: $xorg_pids"
        for pid in $xorg_pids; do
            kill -TERM "$pid" 2>/dev/null || true
        done
    fi
    
    sleep 1
    
    # Force kill if still running
    chromium_pids=$(pgrep chromium-browser 2>/dev/null || true)
    if [[ -n "$chromium_pids" ]]; then
        echo "   [minimal-kiosk.sh:$LINENO] Force killing chromium-browser: $chromium_pids"
        for pid in $chromium_pids; do
            kill -KILL "$pid" 2>/dev/null || true
        done
    fi
    
    openbox_pids=$(pgrep openbox 2>/dev/null || true)
    if [[ -n "$openbox_pids" ]]; then
        echo "   [minimal-kiosk.sh:$LINENO] Force killing openbox: $openbox_pids"
        for pid in $openbox_pids; do
            kill -KILL "$pid" 2>/dev/null || true
        done
    fi
    
    xorg_pids=$(pgrep Xorg 2>/dev/null || true)
    if [[ -n "$xorg_pids" ]]; then
        echo "   [minimal-kiosk.sh:$LINENO] Force killing Xorg: $xorg_pids"
        for pid in $xorg_pids; do
            kill -KILL "$pid" 2>/dev/null || true
        done
    fi
    
    # Remove locks
    rm -f /tmp/.X0-lock
    rm -rf /tmp/.X11-unix/X0
    
    echo "✓ [minimal-kiosk.sh:$LINENO] X cleanup done"
}

# Step 3: Create minimal openbox config
setup_openbox() {
    echo "[3/5] [minimal-kiosk.sh:$LINENO] Setting up openbox..."
    
    mkdir -p ~/.config/openbox
    cat > ~/.config/openbox/rc.xml <<'EOF'
<?xml version="1.0" encoding="UTF-8"?>
<openbox_config xmlns="http://openbox.org/3.4/rc">
  <desktops><number>1</number></desktops>
  <margins><top>0</top><bottom>0</bottom><left>0</left><right>0</right></margins>
  <applications>
    <application class="*">
      <decor>no</decor>
      <maximized>yes</maximized>
    </application>
  </applications>
</openbox_config>
EOF
    
    echo "✓ [minimal-kiosk.sh:$LINENO] Openbox configured"
}

# Step 4: Start X server and window manager
start_x() {
    echo "[4/5] [minimal-kiosk.sh:$LINENO] Starting X server..."
    
    # Check if we've exceeded max restarts
    if [[ $X_RESTART_COUNT -ge $MAX_X_RESTARTS ]]; then
        echo "✗ [minimal-kiosk.sh:$LINENO] X server restart limit reached ($MAX_X_RESTARTS)"
        echo "✗ [minimal-kiosk.sh:$LINENO] FATAL: Too many X server failures, stopping to prevent infinite loop"
        exit 1
    fi
    
    ((X_RESTART_COUNT++))
    
    export DISPLAY=:0
    
    # Verify startx is available
    if ! command -v startx >/dev/null 2>&1; then
        echo "✗ [minimal-kiosk.sh:$LINENO] startx command not found"
        return 1
    fi
    
    # Start X in background
    echo "   [minimal-kiosk.sh:$LINENO] Starting X server (attempt $X_RESTART_COUNT/$MAX_X_RESTARTS)..."
    startx /usr/bin/openbox-session -- :0 vt1 -nolisten tcp &
    
    # Wait for X to be ready
    for i in {1..30}; do
        if xdpyinfo -display :0 >/dev/null 2>&1; then
            echo "✓ [minimal-kiosk.sh:$LINENO] X server ready (${i}s)"
            return 0
        fi
        sleep 1
    done
    
    echo "✗ [minimal-kiosk.sh:$LINENO] X server failed to start after 30s"
    return 1
}

# Step 5: Configure X and start Chromium
start_chromium() {
    echo "[5/5] [minimal-kiosk.sh:$LINENO] Starting Chromium kiosk..."
    
    export DISPLAY=:0
    
    # Verify chromium-browser is available
    if ! command -v chromium-browser >/dev/null 2>&1; then
        echo "✗ [minimal-kiosk.sh:$LINENO] chromium-browser command not found"
        echo "✗ [minimal-kiosk.sh:$LINENO] FATAL: Cannot start kiosk without browser"
        exit 1
    fi
    
    # Disable screensaver
    xset s off
    xset s noblank
    xset -dpms
    echo "✓ [minimal-kiosk.sh:$LINENO] Screensaver disabled"
    
    # Hide cursor
    unclutter -idle 1 -root &
    echo "✓ [minimal-kiosk.sh:$LINENO] Cursor hidden"
    
    # Wait for window manager
    sleep 2
    
    # Monitor and restart Chromium in a loop with safety limits
    while true; do
        # Check restart limit
        if [[ $CHROMIUM_RESTART_COUNT -ge $MAX_CHROMIUM_RESTARTS ]]; then
            echo "✗ [minimal-kiosk.sh:$LINENO] Chromium restart limit reached ($MAX_CHROMIUM_RESTARTS)"
            echo "✗ [minimal-kiosk.sh:$LINENO] FATAL: Too many Chromium failures, stopping to prevent infinite loop"
            exit 1
        fi
        
        ((CHROMIUM_RESTART_COUNT++))
        LAST_CHROMIUM_START=$(date +%s)
        
        echo "   [minimal-kiosk.sh:$LINENO] Starting Chromium (attempt $CHROMIUM_RESTART_COUNT/$MAX_CHROMIUM_RESTARTS)..."
        
        # Start Chromium
        chromium-browser \
            --kiosk \
            --no-sandbox \
            --disable-gpu \
            --disable-infobars \
            --no-first-run \
            --incognito \
            --noerrdialogs \
            --disable-translate \
            --disable-features=TranslateUI \
            --disable-session-crashed-bubble \
            --check-for-update-interval=31536000 \
            "$KIOSK_URL" &
        
        CHROMIUM_PID=$!
        echo "✓ [minimal-kiosk.sh:$LINENO] Chromium started (PID: $CHROMIUM_PID, attempt: $CHROMIUM_RESTART_COUNT)"
        
        # Monitor Chromium
        while kill -0 $CHROMIUM_PID 2>/dev/null; do
            sleep 10
        done
        
        # Calculate uptime
        local now=$(date +%s)
        local uptime=$((now - LAST_CHROMIUM_START))
        
        echo "✗ [minimal-kiosk.sh:$LINENO] Chromium exited after ${uptime}s"
        
        # Reset counter if Chromium ran for long enough
        if [[ $uptime -ge $CHROMIUM_MIN_UPTIME ]]; then
            echo "   [minimal-kiosk.sh:$LINENO] Chromium ran for ${uptime}s (>= ${CHROMIUM_MIN_UPTIME}s), resetting restart counter"
            CHROMIUM_RESTART_COUNT=0
        fi
        
        echo "   [minimal-kiosk.sh:$LINENO] Restarting in 10s..."
        sleep 10
    done
}

# Main execution
main() {
    echo "[minimal-kiosk.sh:$LINENO] === Main execution started ==="
    
    if ! install_dependencies; then
        echo "✗ [minimal-kiosk.sh:$LINENO] FATAL: Failed to install dependencies"
        exit 1
    fi
    
    cleanup_x
    setup_openbox
    
    if ! start_x; then
        echo "✗ [minimal-kiosk.sh:$LINENO] FATAL: Cannot start X server"
        exit 1
    fi
    
    start_chromium
}

main
