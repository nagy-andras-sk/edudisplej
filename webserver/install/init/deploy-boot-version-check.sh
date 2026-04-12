#!/bin/bash
# Deploy Boot-time Version Check Service
# Telepítési segédlet - Boot verziófigyelésszolgáltatás üzembe helyezése
# =============================================================================

set -euo pipefail

# Configuration
INIT_DIR="/opt/edudisplej/init"
SYSTEMD_DIR="/etc/systemd/system"
BOOT_UPDATE_SCRIPT="${INIT_DIR}/edudisplej-boot-update.sh"
BOOT_SERVICE_FILE="${INIT_DIR}/edudisplej-boot-version-check.service"
SERVICE_NAME="edudisplej-boot-version-check.service"
INSTALLED_SERVICE="${SYSTEMD_DIR}/${SERVICE_NAME}"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Log function
log_msg() {
    local level="$1"
    shift
    local message="$*"
    
    case "$level" in
        INFO)
            echo -e "${BLUE}[INFO]${NC} $message"
            ;;
        SUCCESS)
            echo -e "${GREEN}[SUCCESS]${NC} ✅ $message"
            ;;
        WARNING)
            echo -e "${YELLOW}[WARNING]${NC} ⚠️  $message"
            ;;
        ERROR)
            echo -e "${RED}[ERROR]${NC} ❌ $message"
            ;;
    esac
}

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    log_msg ERROR "This script must be run as root (use: sudo $0)"
    exit 1
fi

log_msg INFO "========================================"
log_msg INFO "Boot-time Version Check Service Deployment"
log_msg INFO "========================================"

# Check if source files exist
if [ ! -f "$BOOT_UPDATE_SCRIPT" ]; then
    log_msg ERROR "Boot update script not found: $BOOT_UPDATE_SCRIPT"
    exit 1
fi

if [ ! -f "$BOOT_SERVICE_FILE" ]; then
    log_msg ERROR "Boot service file not found: $BOOT_SERVICE_FILE"
    exit 1
fi

log_msg SUCCESS "Source files found"

# Make script executable
log_msg INFO "Making boot update script executable..."
chmod +x "$BOOT_UPDATE_SCRIPT"
log_msg SUCCESS "Boot update script is executable"

# Copy service file to systemd
log_msg INFO "Copying service file to $SYSTEMD_DIR..."
cp "$BOOT_SERVICE_FILE" "$INSTALLED_SERVICE"
chmod 644 "$INSTALLED_SERVICE"
log_msg SUCCESS "Service file installed: $INSTALLED_SERVICE"

# Reload systemd daemon
log_msg INFO "Reloading systemd daemon..."
systemctl daemon-reload
log_msg SUCCESS "systemd daemon reloaded"

# Enable the service to start at boot
log_msg INFO "Enabling boot-time version check service..."
systemctl enable "$SERVICE_NAME"
log_msg SUCCESS "Service enabled (will start on next boot)"

# Display service status
log_msg INFO "Service status:"
systemctl status "$SERVICE_NAME" --no-pager || true

log_msg INFO ""
log_msg SUCCESS "========================================"
log_msg SUCCESS "Deployment completed successfully!"
log_msg SUCCESS "========================================"
log_msg INFO ""
log_msg INFO "The boot-time version check service is now active and will:"
log_msg INFO "  ✓ Check core version on every boot"
log_msg INFO "  ✓ Compare with server version"
log_msg INFO "  ✓ Auto-update the core if needed"
log_msg INFO "  ✓ Log all activities to: /opt/edudisplej/logs/boot_update.log"
log_msg INFO ""
log_msg INFO "To view the log of the last boot check:"
log_msg INFO "  tail -f /opt/edudisplej/logs/boot_update.log"
log_msg INFO ""
log_msg INFO "To view systemd journal logs:"
log_msg INFO "  journalctl -u $SERVICE_NAME -n 20 --no-pager"
log_msg INFO ""
log_msg WARNING "On next reboot, the system will automatically check and update if needed."

exit 0
