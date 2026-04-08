#!/bin/bash
# Remote installation fix script
# Cleans up and runs fresh install with fixed script

set -e

TOKEN="${1:-}"
DEVICE_IP="${2:-}"

if [ -z "$TOKEN" ] || [ -z "$DEVICE_IP" ]; then
    echo "Usage: $0 <api_token> <device_ip>"
    exit 1
fi

echo "=== Remote Fix for EduDisplej Install ==="
echo "Device: $DEVICE_IP"
echo "Token: ${TOKEN:0:20}..."
echo ""

# Step 1: Connect and cleanup
echo "[1] Cleanup starej installacie..."
ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no "edudisplej@${DEVICE_IP}" << 'EOF'
sudo systemctl stop edudisplej-kiosk.service 2>/dev/null || true
sudo systemctl stop edudisplej-init.service 2>/dev/null || true
sudo rm -rf /opt/edudisplej 2>/dev/null || true
sudo rm -rf /tmp/edudisplej-install.lock 2>/dev/null || true
echo "[✓] Cleanup done"
EOF

# Step 2: Fresh install with fixed script (it will auto-reboot on non-TTY)
echo ""
echo "[2] Spustam frisu instalaciu s javienym scriptom..."
echo "[*] Install bude trvat 3-5 minut a system sa sam restartuje..."
echo ""

ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no "edudisplej@${DEVICE_IP}" << EOF
curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash -s -- --token=${TOKEN}
EOF

echo ""
echo "[✓] Install skonceny - System sa restaruje"
echo "[*] Skontrolujte device za 2-3 minuty"
