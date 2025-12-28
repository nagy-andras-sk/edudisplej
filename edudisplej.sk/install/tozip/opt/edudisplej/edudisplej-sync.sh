#!/bin/bash
# ============================================================================
# EduDisplej Sync Script
# ============================================================================
# Ez a script letölti a szerverről az aktuális init és társai .sh fájlokat,
# majd futtatja az edudisplej-init.sh-t
# ============================================================================

set -e

SERVER_URL="https://edudisplej.sk/edserver/end-kiosk-system-files"
TARGET_DIR="/opt/edudisplej/init"
INIT_SCRIPT="/opt/edudisplej/edudisplej-init.sh"
TEMP_DIR=$(mktemp -d)

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

print_success() {
    echo -e "${GREEN}[✓]${NC} $1"
}
print_error() {
    echo -e "${RED}[✗]${NC} $1"
}
print_info() {
    echo -e "${BLUE}[ℹ]${NC} $1"
}
print_warning() {
    echo -e "${YELLOW}[⚠]${NC} $1"
}

print_info "Fájlok szinkronizálása a szerverről... / Syncing files from server..."

# Letöltés zip formátumban
SYNC_ZIP="$TEMP_DIR/sync.zip"
if command -v wget >/dev/null 2>&1; then
    wget -q "$SERVER_URL" -O "$SYNC_ZIP" || {
        print_error "Letöltés sikertelen / Download failed"
        rm -rf "$TEMP_DIR"
        exit 1
    }
elif command -v curl >/dev/null 2>&1; then
    curl -fsSL "$SERVER_URL" -o "$SYNC_ZIP" || {
        print_error "Letöltés sikertelen / Download failed"
        rm -rf "$TEMP_DIR"
        exit 1
    }
else
    print_error "wget vagy curl szükséges / wget or curl required"
    rm -rf "$TEMP_DIR"
    exit 1
fi
print_success "Letöltés sikeres / Download successful"

# Kicsomagolás
EXTRACT_DIR="$TEMP_DIR/extract"
mkdir -p "$EXTRACT_DIR"
unzip -q "$SYNC_ZIP" -d "$EXTRACT_DIR" || {
    print_error "Kicsomagolás sikertelen / Extraction failed"
    rm -rf "$TEMP_DIR"
    exit 1
}
print_success "Kicsomagolás sikeres / Extraction successful"

# Fájlok cseréje
if [ -d "$EXTRACT_DIR" ]; then
    rm -rf "$TARGET_DIR"/*
    cp -r "$EXTRACT_DIR"/* "$TARGET_DIR/" || {
        print_error "Fájlok másolása sikertelen / File copy failed"
        rm -rf "$TEMP_DIR"
        exit 1
    }
    print_success "Fájlok frissítve / Files updated"
else
    print_error "Nincs mit frissíteni / Nothing to update"
    rm -rf "$TEMP_DIR"
    exit 1
fi

rm -rf "$TEMP_DIR"

# Jogosultságok beállítása
find "$TARGET_DIR" -type f -name "*.sh" -exec chmod 755 {} \;
find "$TARGET_DIR" -type f -name "*.conf" -exec chmod 644 {} \;

print_info "Init script futtatása / Running init script..."
"$INIT_SCRIPT"

exit 0
