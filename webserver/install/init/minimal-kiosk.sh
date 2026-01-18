#!/bin/bash
# minimal-kiosk.sh - Minimal, bulletproof X11 + Chromium kiosk launcher
set -euo pipefail

# Configuration
EDUDISPLEJ_HOME="/opt/edudisplej"
LOG_FILE="${EDUDISPLEJ_HOME}/kiosk.log"
CONFIG_FILE="${EDUDISPLEJ_HOME}/edudisplej.conf"
KIOSK_URL="https://www.time.is"

# Logging
exec > >(tee -a "$LOG_FILE") 2>&1
echo "========== Kiosk Start: $(date) =========="

# Load config
if [[ -f "$CONFIG_FILE" ]]; then
    # Validate config file is readable and not empty
    if [[ -r "$CONFIG_FILE" && -s "$CONFIG_FILE" ]]; then
        # Source config with explicit variable handling
        set +u  # Temporarily allow unset variables during source
        source "$CONFIG_FILE"
        set -u  # Re-enable unset variable checking
        echo "✓ Config loaded: $CONFIG_FILE"
    else
        echo "⚠ Config file exists but is not readable or empty: $CONFIG_FILE"
    fi
fi

KIOSK_URL="${KIOSK_URL:-https://www.time.is}"
echo "✓ Target URL: $KIOSK_URL"

# Step 1: Install dependencies
install_dependencies() {
    echo "[1/5] Checking dependencies..."
    
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
        echo "   Installing: ${missing[*]}"
        if ! apt-get update -qq 2>&1; then
            echo "✗ Failed to update package lists"
            echo "⚠ Continuing with existing package cache..."
        fi
        
        if apt-get install -y "${missing[@]}" 2>&1; then
            echo "✓ Dependencies installed"
        else
            echo "✗ Failed to install some packages: ${missing[*]}"
            echo "⚠ Please check network connection and package availability"
            return 1
        fi
    else
        echo "✓ All dependencies present"
    fi
}

# Step 2: Clean previous X sessions
cleanup_x() {
    echo "[2/5] Cleaning previous X sessions..."
    
    # Kill existing processes using specific PIDs
    local xorg_pids chromium_pids openbox_pids
    
    chromium_pids=$(pgrep chromium-browser 2>/dev/null || true)
    if [[ -n "$chromium_pids" ]]; then
        for pid in $chromium_pids; do
            kill -TERM "$pid" 2>/dev/null || true
        done
    fi
    
    openbox_pids=$(pgrep openbox 2>/dev/null || true)
    if [[ -n "$openbox_pids" ]]; then
        for pid in $openbox_pids; do
            kill -TERM "$pid" 2>/dev/null || true
        done
    fi
    
    xorg_pids=$(pgrep Xorg 2>/dev/null || true)
    if [[ -n "$xorg_pids" ]]; then
        for pid in $xorg_pids; do
            kill -TERM "$pid" 2>/dev/null || true
        done
    fi
    
    sleep 1
    
    # Force kill if still running
    chromium_pids=$(pgrep chromium-browser 2>/dev/null || true)
    if [[ -n "$chromium_pids" ]]; then
        for pid in $chromium_pids; do
            kill -KILL "$pid" 2>/dev/null || true
        done
    fi
    
    openbox_pids=$(pgrep openbox 2>/dev/null || true)
    if [[ -n "$openbox_pids" ]]; then
        for pid in $openbox_pids; do
            kill -KILL "$pid" 2>/dev/null || true
        done
    fi
    
    xorg_pids=$(pgrep Xorg 2>/dev/null || true)
    if [[ -n "$xorg_pids" ]]; then
        for pid in $xorg_pids; do
            kill -KILL "$pid" 2>/dev/null || true
        done
    fi
    
    # Remove locks
    rm -f /tmp/.X0-lock
    rm -rf /tmp/.X11-unix/X0
    
    echo "✓ X cleanup done"
}

# Step 3: Create minimal openbox config
setup_openbox() {
    echo "[3/5] Setting up openbox..."
    
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
    
    echo "✓ Openbox configured"
}

# Step 4: Start X server and window manager
start_x() {
    echo "[4/5] Starting X server..."
    
    export DISPLAY=:0
    
    # Start X in background
    startx /usr/bin/openbox-session -- :0 vt1 -nolisten tcp &
    
    # Wait for X to be ready
    for i in {1..30}; do
        if xdpyinfo -display :0 >/dev/null 2>&1; then
            echo "✓ X server ready (${i}s)"
            return 0
        fi
        sleep 1
    done
    
    echo "✗ X server failed to start"
    return 1
}

# Step 5: Configure X and start Chromium
start_chromium() {
    echo "[5/5] Starting Chromium kiosk..."
    
    export DISPLAY=:0
    
    # Disable screensaver
    xset s off
    xset s noblank
    xset -dpms
    echo "✓ Screensaver disabled"
    
    # Hide cursor
    unclutter -idle 1 -root &
    echo "✓ Cursor hidden"
    
    # Wait for window manager
    sleep 2
    
    # Monitor and restart Chromium in a loop (no recursion)
    while true; do
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
        echo "✓ Chromium started (PID: $CHROMIUM_PID)"
        
        # Monitor Chromium
        while kill -0 $CHROMIUM_PID 2>/dev/null; do
            sleep 10
        done
        
        echo "✗ Chromium exited, restarting in 10s..."
        sleep 10
    done
}

# Main execution
main() {
    install_dependencies
    cleanup_x
    setup_openbox
    
    if ! start_x; then
        echo "FATAL: Cannot start X server"
        exit 1
    fi
    
    start_chromium
}

main
