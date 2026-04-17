#!/bin/bash
# Global EduDisplej Service Improvement Script
# This script improves service resilience across all edudisplej devices

set -e

echo "[*] === EduDisplej Service Global Fix ==="
echo "[*] This will update systemd services to be more resilient"
echo ""

# Backup directory
BACKUP_DIR="/etc/systemd/system/backups_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"

echo "[*] Creating backups in $BACKUP_DIR..."
cp /etc/systemd/system/edudisplej-kiosk.service "$BACKUP_DIR/" || true
cp /etc/systemd/system/edudisplej-watchdog.service "$BACKUP_DIR/" || true

# Update edudisplej-kiosk.service
echo "[*] Updating edudisplej-kiosk.service..."
cat > /tmp/kiosk_service_patch.txt << 'SERVICE_EOF'
[Unit]
Description=EduDisplej Kiosk Mode - Display System
Documentation=https://github.com/nagy-andras-sk/edudisplej
After=network-online.target systemd-user-sessions.service
Wants=network-online.target
Conflicts=getty@tty1.service
OnFailure=getty@tty1.service
StartLimitIntervalSec=300
StartLimitBurst=5

[Service]
Type=simple
User=edudisplej
Group=edudisplej
WorkingDirectory=/home/edudisplej
Environment=HOME=/home/edudisplej
Environment=USER=edudisplej
Environment=DISPLAY=:0
Environment=XDG_RUNTIME_DIR=/run/user/1000

# TTY configuration for tty1 (main console)
StandardInput=tty
StandardOutput=journal
StandardError=journal
TTYPath=/dev/tty1
TTYReset=yes
TTYVHangup=yes
TTYVTDisallocate=yes

# Run wrapper script to handle initialization and X startup
ExecStart=/opt/edudisplej/init/kiosk-start.sh

# Enhanced restart policy - now always restarts for better reliability
Restart=always
RestartSec=10
StartLimitIntervalSec=300
StartLimitBurst=5

# Health check - if process dies, systemd will restart it immediately
Type=notify
NotifyAccess=main

# Security and resource limits
# Allow access to graphics and input devices
SupplementaryGroups=video input tty

[Install]
WantedBy=multi-user.target
SERVICE_EOF

if [ -f /tmp/kiosk_service_patch.txt ]; then
    sudo cp /tmp/kiosk_service_patch.txt /etc/systemd/system/edudisplej-kiosk.service || \
    cp /tmp/kiosk_service_patch.txt /etc/systemd/system/edudisplej-kiosk.service || \
    echo "[!] Warning: Could not update kiosk service - permission denied"
fi

# Update edudisplej-watchdog.service for consistency
echo "[*] Updating edudisplej-watchdog.service..."
cat > /tmp/watchdog_service_patch.txt << 'SERVICE_EOF'
[Unit]
Description=EduDisplej X Environment Watchdog
After=edudisplej-kiosk.service
Wants=edudisplej-kiosk.service

[Service]
Type=simple
User=root
ExecStart=/opt/edudisplej/init/edudisplej-watchdog.sh

# Always restart the watchdog - it's critical for service health
Restart=always
RestartSec=5
StartLimitIntervalSec=120
StartLimitBurst=10

# Logging configuration
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
SERVICE_EOF

if [ -f /tmp/watchdog_service_patch.txt ]; then
    sudo cp /tmp/watchdog_service_patch.txt /etc/systemd/system/edudisplej-watchdog.service || \
    cp /tmp/watchdog_service_patch.txt /etc/systemd/system/edudisplej-watchdog.service || \
    echo "[!] Warning: Could not update watchdog service - permission denied"
fi

# Reload systemd
echo "[*] Reloading systemd daemon..."
sudo systemctl daemon-reload || systemctl daemon-reload || echo "[!] Warning: daemon-reload may have failed"

echo ""
echo "[*] === Verification ==="
echo "[*] Kiosk service restart settings:"
systemctl show -p Restart -p RestartSec -p StartLimitIntervalSec edudisplej-kiosk

echo ""
echo "[*] Watchdog service restart settings:"
systemctl show -p Restart -p RestartSec -p StartLimitIntervalSec edudisplej-watchdog

echo ""
echo "[*] Current service status:"
systemctl status edudisplej-kiosk edudisplej-watchdog | grep -E "Active|Restart"

echo ""
echo "[+] Service improvement complete!"
echo "[*] Backups saved to: $BACKUP_DIR"
echo "[*] Next steps:"
echo "    1. Monitor service logs: journalctl -u edudisplej-kiosk -u edudisplej-watchdog -f"
echo "    2. If issues persist, check: /var/log/Xorg.0.log and application logs"
echo "    3. For deployment to all devices, push these service files via your deployment mechanism"
