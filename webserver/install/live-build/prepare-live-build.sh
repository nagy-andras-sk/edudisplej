#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_INSTALL_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
INCLUDES_DIR="${SCRIPT_DIR}/config/includes.chroot"

SRC_OFFLINE="${ROOT_INSTALL_DIR}/offline-installer.sh"
SRC_SERVICE="${ROOT_INSTALL_DIR}/edudisplej-offline-installer.service"

DST_OFFLINE="${INCLUDES_DIR}/usr/local/bin/edudisplej-offline-installer.sh"
DST_SERVICE="${INCLUDES_DIR}/etc/systemd/system/edudisplej-offline-installer.service"

if [ ! -f "${SRC_OFFLINE}" ]; then
  echo "[!] Missing source file: ${SRC_OFFLINE}"
  exit 1
fi

if [ ! -f "${SRC_SERVICE}" ]; then
  echo "[!] Missing source file: ${SRC_SERVICE}"
  exit 1
fi

install -d "$(dirname "${DST_OFFLINE}")"
install -d "$(dirname "${DST_SERVICE}")"

install -m 0755 "${SRC_OFFLINE}" "${DST_OFFLINE}"
install -m 0644 "${SRC_SERVICE}" "${DST_SERVICE}"

echo "[✓] Offline installer files copied into live-build includes"
echo "    - ${DST_OFFLINE}"
echo "    - ${DST_SERVICE}"
