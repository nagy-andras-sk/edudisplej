#!/bin/bash
#
# EduDisplej Installation - Direct Local Fix (After Remote Hang)
# 
# This script should be run ON THE DEVICE to finish broken installation
# Usage: bash /tmp/fix_install.sh
#

set -e

echo "=========================================="
echo "EduDisplej Installation Fix"
echo "=========================================="
echo ""

# Check if running as root or with sudo
if [ "$(id -u)" -ne 0 ]; then
    echo "[!] CHYBA: Skript vyžaduje root prístup"
    echo "    RUN: sudo bash /tmp/fix_install.sh"
    exit 1
fi

TARGET_DIR="/opt/edudisplej"

# Read token from existing location if available
API_TOKEN=""
if [ -f "${TARGET_DIR}/lic/token" ]; then
    API_TOKEN="$(cat "${TARGET_DIR}/lic/token" 2>/dev/null || true)"
fi

if [ -z "$API_TOKEN" ]; then
    # Try to read from /tmp if saved there
    if [ -f "/tmp/edudisplej_install_token" ]; then
        API_TOKEN="$(cat /tmp/edudisplej_install_token)"
    fi
fi

if [ -z "$API_TOKEN" ]; then
    echo "[!] CHYBA: API token nenajdeny!"
    echo "Pouzi:"
    echo "  API_TOKEN=<token> sudo bash /tmp/fix_install.sh"
    exit 1
fi

echo "[*] Pouzivam token: ${API_TOKEN:0:20}..."
echo ""

# Kill any hanging processes
echo "[*] Zastavujem visiace procesy..."
pkill -f 'bash.*install' 2>/dev/null || true
pkill -f curl 2>/dev/null || true
sleep 1

# Cleanup lock
echo "[*] Cistim lock..."
rm -rf /tmp/edudisplej-install.lock 2>/dev/null || true

# Full cleanup if needed
if [ -d "$TARGET_DIR" ]; then
    echo "[*] Zastavujem sluzby..."
    systemctl stop edudisplej-kiosk.service 2>/dev/null || true
    systemctl stop edudisplej-init.service 2>/dev/null || true
    
    echo "[*] Mazem instalaciju..."
    rm -rf "$TARGET_DIR" 2>/dev/null || true
fi

# Download fresh installer from server
echo ""
echo "=========================================="
echo "Stahovanie cerstvej instalacie..."
echo "=========================================="
echo ""

INSTALLER="/tmp/edudisplej-install-fresh.sh"
if curl -fsSL --max-time 30 --connect-timeout 10 \
    -H "Authorization: Bearer ${API_TOKEN}" \
    https://install.edudisplej.sk/install.sh -o "$INSTALLER"; then
    echo "[✓] Installer stiahnuty"
else
    echo "[!] CHYBA: Nepodarilo sa stiahnut installer"
    exit 1
fi

# Make executable
chmod +x "$INSTALLER"

# Run it with AUTO_REBOOT=true to skip interactive prompt
echo ""
echo "=========================================="
echo "Spustam instalaciu..."
echo "=========================================="
echo ""

export EDUDISPLEJ_AUTO_REBOOT=true
bash "$INSTALLER" --token="$API_TOKEN"
