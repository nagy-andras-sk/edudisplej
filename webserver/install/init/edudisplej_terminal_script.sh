#!/bin/bash
# EduDisplej Terminal Script - Fully automatic kiosk initialization
# =============================================================================

set -u  # Error on undefined variables, but allow non-zero exit codes

# ===== LOGGING =====
TERMINAL_LOG="/opt/edudisplej/logs/terminal_script.log"
mkdir -p "$(dirname "$TERMINAL_LOG")" 2>/dev/null || true

log_terminal() {
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] $*" | tee -a "$TERMINAL_LOG"
}

log_terminal "====== Terminal script started (AUTOMATIC MODE) ======"
log_terminal "UID: $(id -u), USER: $(whoami)"
log_terminal "DISPLAY: ${DISPLAY:-not set}"
log_terminal "HOME: $HOME"

# ===== CHECK FOR ROOT PRIVILEGES =====
if [ "$(id -u)" -ne 0 ]; then
    log_terminal "ERROR: Script not running as root!"
    echo "This script requires ROOT privileges!"
    exit 1
fi

log_terminal "Running as root - proceeding..."

EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
LOCAL_WEB_DIR="${EDUDISPLEJ_HOME}/localweb"
DISPLAY="${DISPLAY:-:0}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

clear

# Display banner
cat << 'EOF'
▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄
██░▄▄▄██░▄▄▀██░██░██░▄▄▀█▄░▄██░▄▄▄░██░▄▄░██░█████░▄▄▄█████░██
██░▄▄▄██░██░██░██░██░██░██░███▄▄▄▀▀██░▀▀░██░█████░▄▄▄█████░██
██░▀▀▀██░▀▀░██▄▀▀▄██░▀▀░█▀░▀██░▀▀▀░██░█████░▀▀░██░▀▀▀██░▀▀░██
▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀
  Kiosk Terminal / Kiosk Terminál
  ═══════════════════════════════════════════════════════════════
  AUTOMATIC MODE - No keyboard required
  AUTOMATIKUS ÜZEMMÓD - Nincs billentyűzet szükséges

EOF

# System information
echo -e "${BLUE}System Information / Rendszer Információ:${NC}"
echo "─────────────────────────────────────────────────────"
echo "  Hostname:    $(hostname)"
echo "  Date/Time:   $(date '+%Y-%m-%d %H:%M:%S')"
echo "  Display:     ${DISPLAY}"
echo "  User:        $(whoami)"
IP=$(hostname -I 2>/dev/null | awk '{print $1}')
[ -n "$IP" ] && echo "  IP Address:  $IP"
echo ""

# ===== STEP 1: WAIT FOR DEVICE REGISTRATION =====
log_terminal "Starting Step 1: Wait for device registration"

echo -e "${YELLOW}[1/3] Waiting for device registration...${NC}"
echo "─────────────────────────────────────────────────────"

KIOSK_CONF="${EDUDISPLEJ_HOME}/kiosk.conf"
MAX_WAIT=120  # Wait up to 2 minutes
WAIT_COUNT=0

if [ -f "$KIOSK_CONF" ]; then
    echo -e "${GREEN}✓ Device already registered!${NC}"
    log_terminal "Device is already registered"
else
    echo "Sync service is registering this device..."
    echo "Please wait, this may take a moment..."
    echo ""
    
    while [ ! -f "$KIOSK_CONF" ] && [ $WAIT_COUNT -lt $MAX_WAIT ]; do
        echo -n "."
        sleep 1
        ((WAIT_COUNT++))
    done
    echo ""
    
    if [ -f "$KIOSK_CONF" ]; then
        echo -e "${GREEN}✓ Device registered after $WAIT_COUNT seconds!${NC}"
        log_terminal "Device registered successfully after $WAIT_COUNT seconds"
    else
        echo -e "${YELLOW}⚠ Device still registering (timeout at $MAX_WAIT seconds)${NC}"
        echo "The sync service will continue in the background..."
        log_terminal "Device registration timeout after $MAX_WAIT seconds"
    fi
fi

echo ""
sleep 2

# ===== STEP 2: DOWNLOAD MODULES =====
log_terminal "Starting Step 2: Download modules"

echo -e "${YELLOW}[2/3] Downloading modules and loop configuration...${NC}"
echo "─────────────────────────────────────────────────────"

DOWNLOAD_SCRIPT="${INIT_DIR}/edudisplej-download-modules.sh"

if [ ! -x "$DOWNLOAD_SCRIPT" ]; then
    log_terminal "ERROR: Download script not found: $DOWNLOAD_SCRIPT"
    echo -e "${RED}✗ Download script not found!${NC}"
    echo ""
    sleep 5
else
    log_terminal "Running: bash $DOWNLOAD_SCRIPT"
    if bash "$DOWNLOAD_SCRIPT"; then
        log_terminal "Module download completed successfully"
        echo -e "${GREEN}✓ Modules downloaded successfully!${NC}"
    else
        DOWNLOAD_EXIT=$?
        log_terminal "Module download failed with exit code: $DOWNLOAD_EXIT"
        echo -e "${RED}✗ Module download failed (exit code: $DOWNLOAD_EXIT)${NC}"
    fi
fi

echo ""
sleep 2

# ===== STEP 3: LAUNCH BROWSER =====
log_terminal "Starting Step 3: Launch browser"

echo -e "${YELLOW}[3/3] Launching display browser...${NC}"
echo "─────────────────────────────────────────────────────"

LOOP_PLAYER="${LOCAL_WEB_DIR}/loop_player.html"
WAITING_PAGE="${INIT_DIR}/waiting_registration.html"

# Check if device is registered, if not show waiting page
if [ ! -f "$KIOSK_CONF" ]; then
    if [ -f "$WAITING_PAGE" ]; then
        log_terminal "Device not registered - launching waiting page"
        echo "Device not registered yet."
        echo "Showing waiting screen until admin assigns this device..."
        echo ""
        
        # Create a temporary version of waiting page with hostname injected
        HOSTNAME=$(hostname)
        TEMP_WAITING_PAGE="/tmp/waiting_registration_with_hostname.html"
        cp "$WAITING_PAGE" "$TEMP_WAITING_PAGE"
        
        # Inject hostname into the page
        sed -i "s/loading\.\.\./$HOSTNAME/g" "$TEMP_WAITING_PAGE"
        
        surf -F "file://${TEMP_WAITING_PAGE}"
        exit 0
    fi
fi

# Device is registered, check for loop player
if [ ! -f "$LOOP_PLAYER" ]; then
    log_terminal "ERROR: Loop player not found: $LOOP_PLAYER"
    echo -e "${RED}✗ Loop player not found!${NC}"
    echo "Expected: $LOOP_PLAYER"
    echo ""
    sleep 10
    exit 1
fi

echo "Starting surf browser..."
echo "Target: file://${LOOP_PLAYER}"
log_terminal "Launching: surf -F file://${LOOP_PLAYER}"
echo ""

# Launch surf fullscreen
surf -F "file://${LOOP_PLAYER}"

# If surf exits, log it
log_terminal "Surf browser exited"
echo -e "${YELLOW}Browser closed${NC}"

# Keep terminal alive briefly for cleanup
sleep 5

log_terminal "====== Terminal script completed ======"

