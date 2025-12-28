#!/bin/bash
# ==============================================================================
# EDUDISPLEJ UNINSTALLER
# ==============================================================================
# Removes EduDisplej system from the device
#
# Usage:
#   sudo ./uninstall.sh [--keep-config]
# ==============================================================================

set -euo pipefail

# ==============================================================================
# Configuration
# ==============================================================================

LOG_FILE="/var/log/edudisplej-uninstaller.log"
INSTALL_DIR="/opt/edudisplej"
CONFIG_FILE="/etc/edudisplej.conf"
SERVICE_FILE="/etc/systemd/system/edudisplej.service"
VHOST_FILE="/etc/apache2/sites-available/edudisplej.conf"

KEEP_CONFIG=false

# Parse arguments
for arg in "$@"; do
    case $arg in
        --keep-config)
            KEEP_CONFIG=true
            ;;
        --help)
            echo "EduDisplej Uninstaller"
            echo "Usage: sudo $0 [--keep-config]"
            echo ""
            echo "Options:"
            echo "  --keep-config    Keep configuration files"
            exit 0
            ;;
    esac
done

# ==============================================================================
# Color codes
# ==============================================================================
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

# ==============================================================================
# Helper functions
# ==============================================================================

log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
}

print_success() {
    echo -e "${GREEN}[✓]${NC} $1"
    log_message "SUCCESS: $1"
}

print_error() {
    echo -e "${RED}[✗]${NC} $1"
    log_message "ERROR: $1"
}

print_info() {
    echo -e "${BLUE}[i]${NC} $1"
    log_message "INFO: $1"
}

print_warning() {
    echo -e "${YELLOW}[⚠]${NC} $1"
    log_message "WARNING: $1"
}

# ==============================================================================
# Uninstallation steps
# ==============================================================================

echo ""
echo "=============================================="
echo "  EduDisplej Uninstaller"
echo "=============================================="
echo ""

# Check root
if [[ $EUID -ne 0 ]]; then
    print_error "This script must be run as root"
    exit 1
fi

log_message "Uninstallation started"

# Stop and disable service
if systemctl is-active --quiet edudisplej.service 2>/dev/null; then
    print_info "Stopping edudisplej.service..."
    systemctl stop edudisplej.service
    print_success "Service stopped"
fi

if systemctl is-enabled --quiet edudisplej.service 2>/dev/null; then
    print_info "Disabling edudisplej.service..."
    systemctl disable edudisplej.service
    print_success "Service disabled"
fi

# Remove service file
if [[ -f "$SERVICE_FILE" ]]; then
    print_info "Removing systemd service file..."
    rm -f "$SERVICE_FILE"
    systemctl daemon-reload
    print_success "Service file removed"
fi

# Disable Apache site
if [[ -L /etc/apache2/sites-enabled/edudisplej.conf ]]; then
    print_info "Disabling Apache site..."
    a2dissite edudisplej.conf >/dev/null 2>&1 || true
    print_success "Apache site disabled"
fi

# Remove Apache vhost
if [[ -f "$VHOST_FILE" ]]; then
    print_info "Removing Apache vhost configuration..."
    rm -f "$VHOST_FILE"
    print_success "Apache vhost removed"
fi

# Restart Apache
if systemctl is-active --quiet apache2 2>/dev/null; then
    print_info "Restarting Apache..."
    systemctl restart apache2 || true
    print_success "Apache restarted"
fi

# Remove installation directory
if [[ -d "$INSTALL_DIR" ]]; then
    print_info "Removing installation directory: $INSTALL_DIR"
    rm -rf "$INSTALL_DIR"
    print_success "Installation directory removed"
fi

# Remove config file (unless --keep-config specified)
if [[ -f "$CONFIG_FILE" ]]; then
    if [[ "$KEEP_CONFIG" == true ]]; then
        print_warning "Keeping configuration file: $CONFIG_FILE"
    else
        print_info "Removing configuration file: $CONFIG_FILE"
        rm -f "$CONFIG_FILE"
        print_success "Configuration file removed"
    fi
fi

# Clean up X server processes
if pgrep -x Xorg >/dev/null 2>&1; then
    print_info "Cleaning up X server processes..."
    pkill -x Xorg || true
    print_success "X server processes cleaned"
fi

if pgrep -x chromium >/dev/null 2>&1; then
    print_info "Cleaning up Chromium processes..."
    pkill -x chromium || true
    pkill -x chromium-browser || true
    print_success "Chromium processes cleaned"
fi

echo ""
print_success "Uninstallation completed!"
log_message "Uninstallation completed"

if [[ "$KEEP_CONFIG" == true ]]; then
    echo ""
    print_info "Configuration preserved at: $CONFIG_FILE"
fi

echo ""
print_info "To reinstall, run the installer script again."
echo ""
