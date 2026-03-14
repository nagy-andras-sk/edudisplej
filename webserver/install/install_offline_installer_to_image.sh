#!/bin/bash
set -euo pipefail

# Installs offline installer wizard into a Debian image/system.
# Run as root inside target image or chroot.

ROOT_DIR="${1:-/}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_SRC="${SCRIPT_DIR}/offline-installer.sh"
SERVICE_SRC="${SCRIPT_DIR}/edudisplej-offline-installer.service"

if [ "$(id -u)" -ne 0 ]; then
    echo "[!] Run as root"
    exit 1
fi

# Allow overriding source paths via env variables
SCRIPT_SRC="${OFFLINE_INSTALLER_SCRIPT_SRC:-$SCRIPT_SRC}"
SERVICE_SRC="${OFFLINE_INSTALLER_SERVICE_SRC:-$SERVICE_SRC}"

if [ ! -f "$SCRIPT_SRC" ]; then
    echo "[!] Missing script source: $SCRIPT_SRC"
    exit 1
fi

if [ ! -f "$SERVICE_SRC" ]; then
    echo "[!] Missing service source: $SERVICE_SRC"
    exit 1
fi

install -d "$ROOT_DIR/usr/local/bin"
install -d "$ROOT_DIR/etc/systemd/system"
install -d "$ROOT_DIR/opt/edudisplej"

install -m 0755 "$SCRIPT_SRC" "$ROOT_DIR/usr/local/bin/edudisplej-offline-installer.sh"
install -m 0644 "$SERVICE_SRC" "$ROOT_DIR/etc/systemd/system/edudisplej-offline-installer.service"

rm -f "$ROOT_DIR/opt/edudisplej/.offline_installer_done"

if command -v systemctl >/dev/null 2>&1; then
    # If running in live target system (not image mount), enable directly
    systemctl daemon-reload || true
    systemctl enable edudisplej-offline-installer.service || true
else
    # If preparing offline image mount/chroot, create symlink for enable
    install -d "$ROOT_DIR/etc/systemd/system/multi-user.target.wants"
    ln -sf /etc/systemd/system/edudisplej-offline-installer.service \
        "$ROOT_DIR/etc/systemd/system/multi-user.target.wants/edudisplej-offline-installer.service"
fi

echo "[✓] Offline installer deployed"
