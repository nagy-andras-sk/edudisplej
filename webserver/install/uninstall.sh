#!/bin/bash
# ==============================================================================
# DEPRECATED: Legacy Uninstaller
# ==============================================================================
# This is the old uninstaller, kept for backward compatibility.
# For new installations, use: https://edudisplej.sk/client/uninstall.sh
# ==============================================================================
# Ez a script eltavolitja az EduDisplej rendszert, torli a service-t es minden fajlt
# This script removes the EduDisplej system, deletes the service and all files
# ==============================================================================

set -e

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

print_header() {
    echo ""
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo ""
}

# Ellenőrzi, hogy root jogosultsággal fut-e
if [[ $EUID -ne 0 ]]; then
    print_error "Ez a script root jogosultságot igényel / This script requires root privileges"
    print_info "Kérjük futtassa: sudo $0 / Please run: sudo $0"
    exit 1
fi

print_header "EduDisplej Eltávolító / EduDisplej Uninstaller"

# 1. Service leállítása és törlése
print_info "Service leállítása és törlése / Stopping and removing service..."
if systemctl is-active --quiet edudisplej.service; then
    systemctl stop edudisplej.service && print_success "Service leállítva / Service stopped"
fi
if systemctl is-enabled --quiet edudisplej.service; then
    systemctl disable edudisplej.service && print_success "Service letiltva / Service disabled"
fi
if [ -f /etc/systemd/system/edudisplej.service ]; then
    rm -f /etc/systemd/system/edudisplej.service && print_success "Service fájl törölve / Service file deleted"
    systemctl daemon-reload
fi

# 2. Fájlok törlése
TARGET_DIR="/opt/edudisplej"
if [ -d "$TARGET_DIR" ]; then
    rm -rf "$TARGET_DIR" && print_success "EduDisplej fájlok törölve / EduDisplej files deleted"
else
    print_info "EduDisplej könyvtár nem található / EduDisplej directory not found"
fi

# 3. Biztonsági mentések törlése
BACKUP_PATTERN="/opt/edudisplej.backup.*"
for backup in $BACKUP_PATTERN; do
    if [ -d "$backup" ]; then
        rm -rf "$backup" && print_success "Biztonsági mentés törölve: $backup / Backup deleted: $backup"
    fi
}

print_header "Eltávolítás kész! / Uninstall complete!"
print_success "Az EduDisplej eltávolítva lett! / EduDisplej has been removed!"
exit 0
