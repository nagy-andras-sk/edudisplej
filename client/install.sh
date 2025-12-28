#!/bin/bash
# ==============================================================================
# EDUDISPLEJ INSTALLER v. 28 12 2025
# ==============================================================================
# Robust, idempotent installer for EduDisplej digital signage system
# Designed for Debian/Ubuntu/Raspberry Pi OS with systemd
#
# Usage:
#   sudo ./install.sh [--lang=sk|en] [--port=8080] [--source-url=URL]
# ==============================================================================

set -euo pipefail  # Exit on error, undefined variable, pipe failure

# ==============================================================================
# Configuration
# ==============================================================================

VERSION="28 12 2025"
LOG_FILE="/var/log/edudisplej-installer.log"
DEFAULT_PORT=8080
DEFAULT_SOURCE_URL="http://edudisplej.sk/install"
INSTALL_DIR="/opt/edudisplej"
WEBSERVER_DIR="${INSTALL_DIR}/wserver"
SYSTEM_DIR="${INSTALL_DIR}/system"
CONFIG_FILE="/etc/edudisplej.conf"

# Parse command line arguments
LANG="sk"
HTTP_PORT="$DEFAULT_PORT"
SOURCE_URL="$DEFAULT_SOURCE_URL"

for arg in "$@"; do
    case $arg in
        --lang=*)
            LANG="${arg#*=}"
            ;;
        --port=*)
            HTTP_PORT="${arg#*=}"
            ;;
        --source-url=*)
            SOURCE_URL="${arg#*=}"
            ;;
        --help)
            echo "EDUDISPLEJ INSTALLER v. ${VERSION}"
            echo "Usage: sudo $0 [--lang=sk|en] [--port=8080] [--source-url=URL]"
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
NC='\033[0m' # No Color

# ==============================================================================
# Translations
# ==============================================================================

# Slovak messages
declare -A MSG_SK=(
    ["banner"]="EDUDISPLEJ INSTALLER v. ${VERSION}"
    ["root_check"]="Root jogosultsag ellenorzese..."
    ["root_ok"]="Root jogosultsag OK"
    ["root_fail"]="HIBA: Root jogosultsag ellenorzese - Root jogosultsag szukseges a telepiteshez"
    ["install_packages"]="Csomagok telepitese..."
    ["create_dirs"]="Konyvtarstruktura letrehozasa..."
    ["setup_webserver"]="Lokalis webszerver beallitasa..."
    ["set_hostname"]="Hostnev beallitasa..."
    ["register_service"]="Szolgaltatas regisztralasa..."
    ["download_files"]="Rendszerfajlok letoltese..."
    ["success"]="Sikeres telepites. Ujrainditas..."
    ["package_installed"]="Csomag telepitve"
    ["package_installing"]="Telepites"
    ["dir_created"]="Konyvtar letrehozva"
    ["webserver_configured"]="Webszerver beallitva"
    ["hostname_set"]="Hostnev beallitva"
    ["service_registered"]="Szolgaltatas regisztralva"
    ["files_downloaded"]="Fajlok letoltve"
    ["rebooting"]="Ujrainditas 10 masodperc mulva..."
)

# English messages
declare -A MSG_EN=(
    ["banner"]="EDUDISPLEJ INSTALLER v. ${VERSION}"
    ["root_check"]="Checking root privileges..."
    ["root_ok"]="Root privileges OK"
    ["root_fail"]="ERROR: Root privilege check - Root privileges required for installation"
    ["install_packages"]="Installing packages..."
    ["create_dirs"]="Creating directory structure..."
    ["setup_webserver"]="Setting up local webserver..."
    ["set_hostname"]="Setting hostname..."
    ["register_service"]="Registering service..."
    ["download_files"]="Downloading system files..."
    ["success"]="Installation successful. Rebooting..."
    ["package_installed"]="Package installed"
    ["package_installing"]="Installing"
    ["dir_created"]="Directory created"
    ["webserver_configured"]="Webserver configured"
    ["hostname_set"]="Hostname set"
    ["service_registered"]="Service registered"
    ["files_downloaded"]="Files downloaded"
    ["rebooting"]="Rebooting in 10 seconds..."
)

# ==============================================================================
# Helper functions
# ==============================================================================

log_message() {
    local message="$1"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $message" >> "$LOG_FILE"
}

get_msg() {
    local key="$1"
    if [[ "$LANG" == "sk" ]]; then
        echo "${MSG_SK[$key]}"
    else
        echo "${MSG_EN[$key]}"
    fi
}

print_step() {
    local message="$1"
    echo ""
    echo -e "${BLUE}>>> ${message}${NC}"
    log_message "STEP: $message"
}

print_success() {
    local message="$1"
    echo -e "${GREEN}[✓]${NC} $message"
    log_message "SUCCESS: $message"
}

print_error() {
    local step="$1"
    local reason="$2"
    echo -e "${RED}[✗] HIBA: ${step} - ${reason}${NC}"
    log_message "ERROR: ${step} - ${reason}"
    exit 1
}

print_info() {
    local message="$1"
    echo -e "${BLUE}[i]${NC} $message"
    log_message "INFO: $message"
}

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# ==============================================================================
# Installation steps
# ==============================================================================

step_0_banner() {
    echo ""
    echo "=============================================="
    echo "  $(get_msg banner)"
    echo "=============================================="
    echo ""
    log_message "Installation started - Version: ${VERSION}"
    log_message "Language: ${LANG}, Port: ${HTTP_PORT}, Source: ${SOURCE_URL}"
}

step_1_check_root() {
    print_step "$(get_msg root_check)"
    
    if [[ $EUID -ne 0 ]]; then
        print_error "$(get_msg root_check)" "$(get_msg root_fail)"
    fi
    
    print_success "$(get_msg root_ok)"
}

step_2_install_packages() {
    print_step "$(get_msg install_packages)"
    
    local packages=("apache2" "openbox" "chromium")
    
    # Check if chromium-browser package name should be used
    if apt-cache show chromium-browser >/dev/null 2>&1; then
        packages=("apache2" "openbox" "chromium-browser")
    elif apt-cache show chromium >/dev/null 2>&1; then
        packages=("apache2" "openbox" "chromium")
    fi
    
    # Update package list if needed
    if [[ ! -f /var/cache/apt/pkgcache.bin ]] || [[ $(find /var/cache/apt/pkgcache.bin -mtime +7 2>/dev/null) ]]; then
        print_info "Updating package list..."
        DEBIAN_FRONTEND=noninteractive apt-get update -qq || {
            print_error "$(get_msg install_packages)" "Failed to update package list"
        }
    fi
    
    for package in "${packages[@]}"; do
        if ! dpkg -l | grep -q "^ii  ${package}"; then
            print_info "$(get_msg package_installing): ${package}"
            DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$package" || {
                print_error "$(get_msg install_packages)" "Failed to install ${package}"
            }
            print_success "$(get_msg package_installed): ${package}"
        else
            print_success "$(get_msg package_installed): ${package}"
        fi
    done
}

step_3_create_directories() {
    print_step "$(get_msg create_dirs)"
    
    local dirs=("$INSTALL_DIR" "$WEBSERVER_DIR" "$SYSTEM_DIR")
    
    for dir in "${dirs[@]}"; do
        if [[ ! -d "$dir" ]]; then
            mkdir -p "$dir" || {
                print_error "$(get_msg create_dirs)" "Failed to create ${dir}"
            }
            print_success "$(get_msg dir_created): ${dir}"
        else
            print_info "$(get_msg dir_created): ${dir} (already exists)"
        fi
    done
}

step_4_setup_webserver() {
    print_step "$(get_msg setup_webserver)"
    
    # Create Apache virtual host configuration
    local vhost_file="/etc/apache2/sites-available/edudisplej.conf"
    
    cat > "$vhost_file" << EOF
# EduDisplej Local Webserver Configuration
# Listens only on localhost:${HTTP_PORT}
Listen 127.0.0.1:${HTTP_PORT}

<VirtualHost 127.0.0.1:${HTTP_PORT}>
    ServerName localhost
    DocumentRoot ${WEBSERVER_DIR}
    
    <Directory ${WEBSERVER_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/edudisplej-error.log
    CustomLog \${APACHE_LOG_DIR}/edudisplej-access.log combined
</VirtualHost>
EOF
    
    # Disable default site if enabled
    if [[ -L /etc/apache2/sites-enabled/000-default.conf ]]; then
        a2dissite 000-default.conf >/dev/null 2>&1 || true
    fi
    
    # Enable edudisplej site
    a2ensite edudisplej.conf >/dev/null 2>&1 || {
        print_error "$(get_msg setup_webserver)" "Failed to enable Apache site"
    }
    
    # Ensure Apache only listens on localhost
    if ! grep -q "^Listen 127.0.0.1:${HTTP_PORT}" /etc/apache2/ports.conf 2>/dev/null; then
        # Remove any existing Listen directives for this port
        sed -i "/^Listen ${HTTP_PORT}/d" /etc/apache2/ports.conf 2>/dev/null || true
        sed -i "/^Listen 80/d" /etc/apache2/ports.conf 2>/dev/null || true
    fi
    
    # Restart Apache
    systemctl restart apache2 || {
        print_error "$(get_msg setup_webserver)" "Failed to restart Apache"
    }
    
    print_success "$(get_msg webserver_configured): 127.0.0.1:${HTTP_PORT}"
}

step_5_set_hostname() {
    print_step "$(get_msg set_hostname)"
    
    local current_hostname=$(hostname)
    
    # Only set hostname if it's default or not already edudisplej
    if [[ "$current_hostname" != edudisplej-* ]]; then
        # Generate random 8-character suffix (lowercase + numbers)
        local random_suffix=$(cat /dev/urandom | tr -dc 'a-z0-9' | fold -w 8 | head -n 1)
        local new_hostname="edudisplej-${random_suffix}"
        
        # Set hostname
        hostnamectl set-hostname "$new_hostname" || {
            print_error "$(get_msg set_hostname)" "Failed to set hostname"
        }
        
        # Update /etc/hosts
        if ! grep -q "$new_hostname" /etc/hosts; then
            echo "127.0.1.1    $new_hostname" >> /etc/hosts
        fi
        
        print_success "$(get_msg hostname_set): ${new_hostname}"
    else
        print_info "$(get_msg hostname_set): ${current_hostname} (already set)"
    fi
}

step_6_create_service() {
    print_step "$(get_msg register_service)"
    
    local service_file="/etc/systemd/system/edudisplej.service"
    
    cat > "$service_file" << EOF
[Unit]
Description=EduDisplej Kiosk System
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/bin/bash ${SYSTEM_DIR}/edudisplej-init.sh
Restart=on-failure
RestartSec=10
StandardOutput=journal
StandardError=journal
User=root
Environment="DISPLAY=:0"
Environment="HOME=${INSTALL_DIR}"

[Install]
WantedBy=multi-user.target
EOF
    
    # Reload systemd
    systemctl daemon-reload || {
        print_error "$(get_msg register_service)" "Failed to reload systemd"
    }
    
    # Enable service
    systemctl enable edudisplej.service >/dev/null 2>&1 || {
        print_error "$(get_msg register_service)" "Failed to enable service"
    }
    
    print_success "$(get_msg service_registered): edudisplej.service"
}

step_7_download_files() {
    print_step "$(get_msg download_files)"
    
    # Create configuration file
    cat > "$CONFIG_FILE" << EOF
# EduDisplej Configuration File
# Auto-generated by installer on $(date)

# Installation paths
EDUDISPLEJ_HOME=${INSTALL_DIR}
EDUDISPLEJ_SYSTEM=${SYSTEM_DIR}
EDUDISPLEJ_WEBSERVER=${WEBSERVER_DIR}

# Webserver settings
EDUDISPLEJ_HTTP_PORT=${HTTP_PORT}

# Source URL for system files
EDUDISPLEJ_SOURCE_URL=${SOURCE_URL}

# Language (sk or en)
EDUDISPLEJ_LANG=${LANG}

# Installation date
INSTALL_DATE=$(date '+%Y-%m-%d %H:%M:%S')
INSTALL_VERSION=${VERSION}
EOF
    
    # Download system files from source URL
    local files_to_download=(
        "end-kiosk-system-files/edudisplej-init.sh"
        "end-kiosk-system-files/edudisplej.conf"
        "end-kiosk-system-files/init/common.sh"
        "end-kiosk-system-files/init/kiosk.sh"
        "end-kiosk-system-files/init/network.sh"
        "end-kiosk-system-files/init/display.sh"
        "end-kiosk-system-files/init/language.sh"
        "end-kiosk-system-files/init/xclient.sh"
    )
    
    # Create init directory
    mkdir -p "${SYSTEM_DIR}/init"
    
    for file in "${files_to_download[@]}"; do
        local url="${SOURCE_URL}/${file}"
        local dest_file
        
        if [[ "$file" == *"/init/"* ]]; then
            dest_file="${SYSTEM_DIR}/init/$(basename "$file")"
        else
            dest_file="${SYSTEM_DIR}/$(basename "$file")"
        fi
        
        print_info "Downloading: $(basename "$file")"
        
        if command_exists wget; then
            wget -q -O "$dest_file" "$url" || {
                print_error "$(get_msg download_files)" "Failed to download ${file}"
            }
        elif command_exists curl; then
            curl -fsSL -o "$dest_file" "$url" || {
                print_error "$(get_msg download_files)" "Failed to download ${file}"
            }
        else
            print_error "$(get_msg download_files)" "Neither wget nor curl available"
        fi
        
        # Set executable permission for .sh files
        if [[ "$file" == *.sh ]]; then
            chmod +x "$dest_file"
        fi
    done
    
    print_success "$(get_msg files_downloaded)"
}

step_8_reboot() {
    print_step "$(get_msg success)"
    
    # Create a simple placeholder for webserver content
    cat > "${WEBSERVER_DIR}/index.html" << 'EOF'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EduDisplej</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: Arial, sans-serif;
            color: white;
        }
        .container {
            text-align: center;
            padding: 2rem;
        }
        h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        p {
            font-size: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>EduDisplej</h1>
        <p>System is ready!</p>
        <p>Systém je pripravený!</p>
    </div>
</body>
</html>
EOF
    
    print_info "$(get_msg rebooting)"
    log_message "Installation completed successfully. Rebooting..."
    
    # Sync filesystem
    sync
    
    # Schedule reboot
    sleep 10
    reboot
}

# ==============================================================================
# Main execution
# ==============================================================================

main() {
    # Initialize log file
    touch "$LOG_FILE"
    chmod 644 "$LOG_FILE"
    
    step_0_banner
    step_1_check_root
    step_2_install_packages
    step_3_create_directories
    step_4_setup_webserver
    step_5_set_hostname
    step_6_create_service
    step_7_download_files
    step_8_reboot
}

# Run main function
main "$@"
