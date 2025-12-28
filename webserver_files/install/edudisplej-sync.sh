#!/bin/bash
# edudisplej-sync.sh
# Letölti a https://edudisplej.sk/edserver/end-kiosk-system-files/ tartalmát rekurzívan az /opt/edudisplej/local mappába

set -e

SYNC_URL="https://edudisplej.sk/edserver/end-kiosk-system-files/"
TARGET_DIR="/opt/edudisplej/local"

mkdir -p "$TARGET_DIR"

# Letöltés rekurzívan wget-tel
wget -q -N -r -np -nH --cut-dirs=3 --reject "index.html*" --no-parent "$SYNC_URL" -P "$TARGET_DIR"

# Sikeres szinkronizáció logolása
logger "[edudisplej-sync] Fájlok szinkronizálva: $SYNC_URL -> $TARGET_DIR"
