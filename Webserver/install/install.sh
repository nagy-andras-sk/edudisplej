#!/bin/bash
# ==============================================================================
# EduDisplej Telepítő Script / EduDisplej Installation Script
# ==============================================================================
# Ez a script automatikusan letölti és telepíti az EduDisplej rendszert
# This script automatically downloads and installs the EduDisplej system
#
# Használat / Usage:
#   curl -fsSL http://edudisplej.sk/install/install.sh | sudo bash
#   vagy / or:
#   wget http://edudisplej.sk/install/install.sh && chmod +x install.sh && sudo ./install.sh
# ==============================================================================

set -e  # Exit on error

# ==============================================================================
# Színkódok / Color codes
# ==============================================================================
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# ==============================================================================
# Segéd függvények / Helper functions
# ==============================================================================

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

# Ellenőrzi, hogy a parancs elérhető-e / Check if command is available
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Ellenőrzi, hogy root jogosultsággal fut-e / Check if running as root
check_root() {
    if [[ $EUID -ne 0 ]]; then
        print_error "Ez a script root jogosultságot igényel / This script requires root privileges"
        print_info "Kérjük futtassa: sudo $0 / Please run: sudo $0"
        exit 1
    fi
}

# ==============================================================================
# Főprogram / Main program
# ==============================================================================

print_header "EduDisplej Telepítő / EduDisplej Installer"

# 1. Root jogosultság ellenőrzése / Check root privileges
print_info "Jogosultságok ellenőrzése... / Checking privileges..."
check_root
print_success "Root jogosultság rendben / Root privileges OK"
echo ""

# 2. Szükséges csomagok ellenőrzése és telepítése / Check and install required packages
print_header "Csomagok ellenőrzése / Checking packages"

REQUIRED_PACKAGES=("zip" "unzip" "curl" "wget")
MISSING_PACKAGES=()

for package in "${REQUIRED_PACKAGES[@]}"; do
    print_info "Ellenőrzés: $package / Checking: $package"
    if command_exists "$package"; then
        print_success "$package telepítve / $package installed"
    else
        print_warning "$package hiányzik / $package missing"
        MISSING_PACKAGES+=("$package")
    fi
done

if [ ${#MISSING_PACKAGES[@]} -gt 0 ]; then
    echo ""
    print_info "Hiányzó csomagok telepítése... / Installing missing packages..."
    print_info "Csomagok: ${MISSING_PACKAGES[*]}"
    
    # Update package list
    print_info "Csomaglista frissítése... / Updating package list..."
    apt-get update -qq || {
        print_error "Csomaglista frissítés sikertelen / Package list update failed"
        exit 1
    }
    
    # Install missing packages
    for package in "${MISSING_PACKAGES[@]}"; do
        print_info "Telepítés: $package / Installing: $package"
        apt-get install -y -qq "$package" || {
            print_error "$package telepítése sikertelen / Failed to install $package"
            exit 1
        }
        print_success "$package telepítve / $package installed"
    done
else
    print_success "Minden szükséges csomag telepítve / All required packages are installed"
fi
echo ""

# 3. Fájl letöltés / Download file
print_header "Fájlok letöltése / Downloading files"

# Note: HTTP is used as specified in requirements. For production,
# consider using HTTPS to ensure secure downloads.
DOWNLOAD_URL="http://edudisplej.sk/install/install.zip"
TEMP_DIR=$(mktemp -d)
DOWNLOAD_FILE="${TEMP_DIR}/install.zip"

print_info "Letöltési URL / Download URL: $DOWNLOAD_URL"
print_info "Ideiglenes könyvtár / Temporary directory: $TEMP_DIR"
echo ""

print_info "Letöltés... / Downloading..."
if command_exists wget; then
    wget -q --show-progress "$DOWNLOAD_URL" -O "$DOWNLOAD_FILE" || {
        print_error "Letöltés sikertelen (wget) / Download failed (wget)"
        rm -rf "$TEMP_DIR"
        exit 1
    }
elif command_exists curl; then
    curl -fsSL --progress-bar "$DOWNLOAD_URL" -o "$DOWNLOAD_FILE" || {
        print_error "Letöltés sikertelen (curl) / Download failed (curl)"
        rm -rf "$TEMP_DIR"
        exit 1
    }
else
    print_error "Sem wget, sem curl nem elérhető / Neither wget nor curl available"
    rm -rf "$TEMP_DIR"
    exit 1
fi

print_success "Letöltés sikeres / Download successful"
print_info "Fájl méret / File size: $(du -h "$DOWNLOAD_FILE" | cut -f1)"
echo ""

# 4. Telepítés / Installation
print_header "Telepítés / Installation"

# Célkönyvtár létrehozása / Create target directory
TARGET_DIR="/opt/edudisplej"
print_info "Célkönyvtár / Target directory: $TARGET_DIR"

if [ -d "$TARGET_DIR" ]; then
    print_warning "A célkönyvtár már létezik / Target directory already exists"
    print_info "Meglévő tartalom biztonsági mentése... / Backing up existing content..."
    BACKUP_DIR="${TARGET_DIR}.backup.$(date +%Y%m%d_%H%M%S)"
    mv "$TARGET_DIR" "$BACKUP_DIR" || {
        print_error "Biztonsági mentés sikertelen / Backup failed"
        rm -rf "$TEMP_DIR"
        exit 1
    }
    print_success "Biztonsági mentés: $BACKUP_DIR / Backup created: $BACKUP_DIR"
fi

print_info "Célkönyvtár létrehozása... / Creating target directory..."
mkdir -p "$TARGET_DIR" || {
    print_error "Könyvtár létrehozás sikertelen / Directory creation failed"
    rm -rf "$TEMP_DIR"
    exit 1
}
print_success "Célkönyvtár létrehozva / Target directory created"
echo ""

# Kicsomagolás / Extract
print_info "Fájlok kicsomagolása... / Extracting files..."
EXTRACT_DIR="${TEMP_DIR}/extract"
mkdir -p "$EXTRACT_DIR"

unzip -q "$DOWNLOAD_FILE" -d "$EXTRACT_DIR" || {
    print_error "Kicsomagolás sikertelen / Extraction failed"
    rm -rf "$TEMP_DIR"
    exit 1
}
print_success "Kicsomagolás sikeres / Extraction successful"
echo ""

# Fájlok másolása / Copy files
print_info "Fájlok másolása... / Copying files..."

# A zip fájl tartalma: opt/edudisplej/* vagy közvetlenül a fájlok
if [ -d "${EXTRACT_DIR}/opt/edudisplej" ]; then
    # Ha a zip tartalmazza az opt/edudisplej útvonalat
    cp -r "${EXTRACT_DIR}/opt/edudisplej/"* "$TARGET_DIR/" || {
        print_error "Fájlok másolása sikertelen / File copy failed"
        rm -rf "$TEMP_DIR"
        exit 1
    }
elif [ -f "${EXTRACT_DIR}/edudisplej-init.sh" ]; then
    # Ha közvetlenül a fájlok vannak a zip gyökerében
    cp -r "${EXTRACT_DIR}/"* "$TARGET_DIR/" || {
        print_error "Fájlok másolása sikertelen / File copy failed"
        rm -rf "$TEMP_DIR"
        exit 1
    }
else
    print_error "Ismeretlen zip struktúra / Unknown zip structure"
    rm -rf "$TEMP_DIR"
    exit 1
fi

print_success "Fájlok másolva / Files copied"
echo ""

# 5. Jogosultságok beállítása / Set permissions
print_header "Jogosultságok beállítása / Setting permissions"

print_info "Könyvtárak jogosultságai... / Directory permissions..."
find "$TARGET_DIR" -type d -exec chmod 755 {} \; || {
    print_error "Könyvtár jogosultságok beállítása sikertelen / Directory permission setting failed"
}
print_success "Könyvtárak: 755"

print_info "Script fájlok jogosultságai... / Script file permissions..."
find "$TARGET_DIR" -type f -name "*.sh" -exec chmod 755 {} \; || {
    print_error "Script jogosultságok beállítása sikertelen / Script permission setting failed"
}
print_success "Script fájlok (.sh): 755"

print_info "Config fájlok jogosultságai... / Config file permissions..."
find "$TARGET_DIR" -type f -name "*.conf" -exec chmod 644 {} \; || {
    print_error "Config jogosultságok beállítása sikertelen / Config permission setting failed"
}
print_success "Config fájlok (.conf): 644"
echo ""

# 6. Takarítás / Cleanup
print_header "Takarítás / Cleanup"

print_info "Ideiglenes fájlok törlése... / Removing temporary files..."
rm -rf "$TEMP_DIR" || {
    print_warning "Ideiglenes fájlok törlése sikertelen / Failed to remove temporary files"
}
print_success "Takarítás kész / Cleanup complete"
echo ""

# 7. Befejezés / Completion
print_header "Telepítés sikeres! / Installation successful!"

print_success "Az EduDisplej telepítve lett! / EduDisplej has been installed!"
echo ""
print_info "Telepítési hely / Installation location: $TARGET_DIR"
print_info "Telepített fájlok / Installed files:"
echo ""

# Telepített fájlok listázása / List installed files
find "$TARGET_DIR" -type f | while read -r file; do
    echo "  • ${file#$TARGET_DIR/}"
done

echo ""
print_info "Következő lépések / Next steps:"
echo "  1. Konfiguráld a rendszert / Configure the system:"
echo "     sudo ${TARGET_DIR}/edudisplej-init.sh"
echo ""
echo "  2. Állítsd be a systemd service-t / Set up the systemd service:"
echo "     (Lásd a dokumentációt / See documentation)"
echo ""

print_success "Telepítés befejezve! / Installation complete!"
exit 0
