#!/bin/bash
set -euo pipefail

# Parse command line arguments
API_TOKEN=""
while [[ $# -gt 0 ]]; do
    case $1 in
        --token=*)
            API_TOKEN="${1#*=}"
            shift
            ;;
        --token)
            API_TOKEN="$2"
            shift 2
            ;;
        *)
            shift
            ;;
    esac
done

# Zakladne nastavenia - Base settings
TARGET_DIR="/opt/edudisplej"
INIT_DIR="${TARGET_DIR}/init"
LOCAL_WEB_DIR="${TARGET_DIR}/localweb"
LIC_DIR="${TARGET_DIR}/lic"
INIT_BASE="https://install.edudisplej.sk/init"
SERVICE_FILE="/etc/systemd/system/edudisplej-kiosk.service"
AUTO_REBOOT="${EDUDISPLEJ_AUTO_REBOOT:-true}"
INSTALL_LOCK_DIR="/tmp/edudisplej-install.lock"

if [ -z "$API_TOKEN" ]; then
    echo "[!] CHYBA - ERROR: Chyba API token. Pouzi: --token=<API_TOKEN>"
    exit 1
fi

AUTH_HEADER=( -H "Authorization: Bearer ${API_TOKEN}" )

# Chybove spravy - Error handling
cleanup_on_error() {
    local exit_code=$?
    if [ $exit_code -ne 0 ]; then
        echo ""
        echo "[!] CHYBA - ERROR: Instalacia zlyhala - Installation failed (kod - code: $exit_code)"
        echo ""
        echo "Riesenia - Solutions:"
        echo "  1. Skontrolujte internetove pripojenie - Check internet connection"
        echo "  2. Skuste znova - Try again"
        echo ""
    fi
    stop_heartbeat
    release_install_lock
}

trap cleanup_on_error EXIT

# Ukazovatel pokroku - Progress indicator
HEARTBEAT_PID=""
start_heartbeat() {
    stop_heartbeat
    (while true; do echo -n "."; sleep 2; done) &
    HEARTBEAT_PID=$!
}

stop_heartbeat() {
    if [ -n "$HEARTBEAT_PID" ] && kill -0 "$HEARTBEAT_PID" 2>/dev/null; then
        kill "$HEARTBEAT_PID" 2>/dev/null || true
        wait "$HEARTBEAT_PID" 2>/dev/null || true
        HEARTBEAT_PID=""
        echo ""
    fi
}

acquire_install_lock() {
    if mkdir "$INSTALL_LOCK_DIR" 2>/dev/null; then
        echo $$ > "${INSTALL_LOCK_DIR}/pid"
        return 0
    fi

    local existing_pid=""
    if [ -f "${INSTALL_LOCK_DIR}/pid" ]; then
        existing_pid="$(cat "${INSTALL_LOCK_DIR}/pid" 2>/dev/null || true)"
    fi

    if [ -n "$existing_pid" ] && ! kill -0 "$existing_pid" 2>/dev/null; then
        rm -rf "$INSTALL_LOCK_DIR"
        if mkdir "$INSTALL_LOCK_DIR" 2>/dev/null; then
            echo $$ > "${INSTALL_LOCK_DIR}/pid"
            return 0
        fi
    fi

    echo "[!] CHYBA - ERROR: Installer is already running (pid: ${existing_pid:-unknown})"
    echo "[!] Dokoncite predchadzajucu instalaciu alebo odstran lock: ${INSTALL_LOCK_DIR}"
    return 1
}

release_install_lock() {
    if [ -d "$INSTALL_LOCK_DIR" ]; then
        rm -rf "$INSTALL_LOCK_DIR" 2>/dev/null || true
    fi
}

wait_for_apt_locks() {
    local timeout_seconds="${1:-120}"
    local waited=0
    while fuser /var/lib/dpkg/lock >/dev/null 2>&1 \
        || fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 \
        || fuser /var/lib/apt/lists/lock >/dev/null 2>&1 \
        || fuser /var/cache/apt/archives/lock >/dev/null 2>&1; do
        if [ "$waited" -ge "$timeout_seconds" ]; then
            echo "[!] CHYBA - ERROR: APT lock timeout after ${timeout_seconds}s"
            return 1
        fi
        sleep 2
        waited=$((waited + 2))
    done
    return 0
}

apt_install_with_retry() {
    local max_attempts="${1:-3}"
    shift
    local attempt=1
    local apt_opts=(
        "-o" "Dpkg::Use-Pty=0"
        "-o" "Dpkg::Options::=--force-confdef"
        "-o" "Dpkg::Options::=--force-confold"
        "-o" "Acquire::Retries=5"
        "-o" "Acquire::http::Timeout=30"
        "-o" "Acquire::https::Timeout=30"
    )
    while [ "$attempt" -le "$max_attempts" ]; do
        if wait_for_apt_locks 180 \
            && DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none NEEDRESTART_MODE=a apt-get "${apt_opts[@]}" update -qq < /dev/null \
            && DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none NEEDRESTART_MODE=a apt-get -y "${apt_opts[@]}" install "$@" < /dev/null; then
            return 0
        fi
        if [ "$attempt" -lt "$max_attempts" ]; then
            echo "[!] VAROVANIE - WARNING: apt install attempt ${attempt}/${max_attempts} failed, retrying..."
            sleep 5
        fi
        attempt=$((attempt + 1))
    done
    return 1
}

fetch_with_retry() {
    local url="$1"
    local output_file="$2"
    local max_attempts="${3:-5}"
    local attempt=1
    while [ "$attempt" -le "$max_attempts" ]; do
        if curl -fsSL --max-time 90 --connect-timeout 10 --retry 2 --retry-delay 2 \
            "${AUTH_HEADER[@]}" "$url" -o "$output_file"; then
            return 0
        fi
        if [ "$attempt" -lt "$max_attempts" ]; then
            echo "    [!] Pokus ${attempt}/${max_attempts} zlyhal - Attempt failed, retrying..."
            sleep 2
        fi
        attempt=$((attempt + 1))
    done
    return 1
}

render_service_for_console_user() {
    local source_path="$1"
    local dest_path="$2"
    local runtime_uid
    runtime_uid="$(id -u "$CONSOLE_USER" 2>/dev/null || echo 1000)"

    sed -e "s/User=edudisplej/User=$CONSOLE_USER/g" \
        -e "s/Group=edudisplej/Group=$CONSOLE_USER/g" \
        -e "s|WorkingDirectory=/home/edudisplej|WorkingDirectory=$USER_HOME|g" \
        -e "s|Environment=HOME=/home/edudisplej|Environment=HOME=$USER_HOME|g" \
        -e "s/Environment=USER=edudisplej/Environment=USER=$CONSOLE_USER/g" \
        -e "s|Environment=XAUTHORITY=/home/edudisplej/.Xauthority|Environment=XAUTHORITY=$USER_HOME/.Xauthority|g" \
        -e "s|Environment=\\\"XAUTHORITY=/home/edudisplej/.Xauthority\\\"|Environment=\\\"XAUTHORITY=$USER_HOME/.Xauthority\\\"|g" \
        -e "s|Environment=XDG_RUNTIME_DIR=/run/user/1000|Environment=XDG_RUNTIME_DIR=/run/user/$runtime_uid|g" \
        "$source_path" > "$dest_path"
}

# Kompletny cleanup starej instalacie - Full cleanup of previous installation
cleanup_existing_installation() {
    echo "[*] Kontrola starej instalacie - Checking previous installation..."

    local has_old_install=false
    if [ -d "$TARGET_DIR" ]; then
        has_old_install=true
    fi

    local existing_services
    existing_services=$(systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '$1 ~ /^edudisplej.*\.service$/ {print $1}' || true)
    if [ -n "$existing_services" ]; then
        has_old_install=true
    fi

    if [ "$has_old_install" != true ]; then
        echo "[*] Stara instalacia nenajdena - No previous installation found"
        return 0
    fi

    echo "[*] Stara instalacia najdena - Previous installation found"
    echo "[*] Zastavujem a deaktivujem sluzby - Stopping and disabling services..."

    while IFS= read -r service_name; do
        [ -z "$service_name" ] && continue
        systemctl stop "$service_name" 2>/dev/null || true
        systemctl disable "$service_name" 2>/dev/null || true
        systemctl reset-failed "$service_name" 2>/dev/null || true
        echo "    [✓] Cleanup service: $service_name"
    done <<< "$existing_services"

    # Remove unit files and symlinks
    if command -v find >/dev/null 2>&1; then
        find /etc/systemd/system -maxdepth 3 -type f -name 'edudisplej*.service' -delete 2>/dev/null || true
        find /etc/systemd/system -maxdepth 4 -type l -name 'edudisplej*.service' -delete 2>/dev/null || true
    fi

    # Kill any remaining runtime processes tied to old installation path
    pkill -f '/opt/edudisplej/' 2>/dev/null || true

    systemctl daemon-reload 2>/dev/null || true
    systemctl daemon-reexec 2>/dev/null || true

    # Always remove target directory for clean reinstall
    if [ -d "$TARGET_DIR" ]; then
        echo "[*] Mazanie existujuceho adresara - Removing existing directory: $TARGET_DIR"
        rm -rf "$TARGET_DIR"
    fi

    echo "[✓] Cleanup dokonceny - Cleanup completed"
}

# Oprava boot konfiguracie pre ARMv6 - Fix boot config for ARMv6
# Reference: Issue #47 - Pi Zero/Pi1 black screen fix
fix_armv6_boot_config() {
    echo "[*] Detekcia ARMv6: Oprava boot konfiguracie - ARMv6 detected: Fixing boot config..."
    
    # Find config file location
    CONFIG_FILE=""
    if [ -f "/boot/firmware/config.txt" ]; then
        CONFIG_FILE="/boot/firmware/config.txt"
    elif [ -f "/boot/config.txt" ]; then
        CONFIG_FILE="/boot/config.txt"
    else
        echo "[!] VAROVANIE - WARNING: Boot config file not found, skipping fix"
        return 0
    fi
    
    echo "[*] Pouzivam - Using: $CONFIG_FILE"
    
    # Create backup
    BACKUP_FILE="${CONFIG_FILE}.backup.$(date +%Y%m%d_%H%M%S)"
    if ! cp "$CONFIG_FILE" "$BACKUP_FILE"; then
        echo "[!] CHYBA - ERROR: Failed to create backup - Nepodarilo sa vytvorit zalohu"
        return 1
    fi
    echo "[*] Zaloha vytvorena - Backup created: $BACKUP_FILE"
    
    # Check if fix is already applied (idempotent)
    if grep -q "# ARMv6 fix - Issue #47" "$CONFIG_FILE"; then
        echo "[*] Oprava uz aplikovana - Fix already applied, skipping"
        return 0
    fi
    
    # Disable full KMS if present
    if grep -q "^dtoverlay=vc4-kms-v3d" "$CONFIG_FILE"; then
        sed -i 's/^dtoverlay=vc4-kms-v3d/#dtoverlay=vc4-kms-v3d # Disabled for ARMv6 - Issue #47/' "$CONFIG_FILE"
        echo "[*] Deaktivovany full KMS - Disabled full KMS (vc4-kms-v3d)"
    fi
    
    # Add fake KMS if not present
    if ! grep -q "^dtoverlay=vc4-fkms-v3d" "$CONFIG_FILE"; then
        cat >> "$CONFIG_FILE" <<'EOF'

# ARMv6 fix - Issue #47
# Pi Zero/Pi 1 models don't work well with full KMS driver (vc4-kms-v3d)
# This causes black screen even though Xorg is running
# Solution: Use fake KMS (vc4-fkms-v3d) instead
dtoverlay=vc4-fkms-v3d
EOF
        echo "[*] Pridany fake KMS - Added fake KMS (vc4-fkms-v3d)"
    else
        echo "[*] Fake KMS uz pritomny - Fake KMS already present"
    fi
    
    echo "[✓] Boot config opraveny - Boot config fixed for ARMv6"
}

# Kontrola root opravneni - Check root permissions
echo "[*] Kontrola opravneni root - Checking root permissions..."
if [ "$(id -u)" -ne 0 ]; then
  echo "[!] Spusti skript s sudo - Run script with sudo!"
  exit 1
fi

if ! acquire_install_lock; then
    exit 1
fi

# Detekcia architektury - Architecture detection
ARCH="$(uname -m)"
echo "[*] Architektura - Architecture: $ARCH"
# Always use surf browser
KIOSK_MODE="surf"

# Apply ARMv6 boot config fix if needed
if [ "$ARCH" = "armv6l" ]; then
  fix_armv6_boot_config
fi

# Always perform cleanup of previous installation before fresh install
cleanup_existing_installation

# Instalacia zakladnych nastroje - Install basic tools
MISSING_TOOLS=()
if ! command -v curl >/dev/null 2>&1; then
  MISSING_TOOLS+=("curl")
fi
if ! command -v surf >/dev/null 2>&1; then
  MISSING_TOOLS+=("surf")
fi
if ! command -v jq >/dev/null 2>&1; then
  MISSING_TOOLS+=("jq")
fi
if ! command -v scrot >/dev/null 2>&1; then
  MISSING_TOOLS+=("scrot")
fi
if ! command -v python3 >/dev/null 2>&1; then
    MISSING_TOOLS+=("python3")
fi
if ! dpkg -s ca-certificates >/dev/null 2>&1; then
    MISSING_TOOLS+=("ca-certificates")
fi

if [ ${#MISSING_TOOLS[@]} -gt 0 ]; then
  echo "[*] Instalacia zakladnych nastroje - Installing basic tools: ${MISSING_TOOLS[*]}"
  start_heartbeat
    if ! apt_install_with_retry 3 "${MISSING_TOOLS[@]}" >/dev/null 2>&1; then
        stop_heartbeat
        echo "[!] CHYBA - ERROR: Failed to install required base tools"
        exit 1
    fi
  stop_heartbeat
  echo "[✓] Zakladne nastroje nainstalovane - Basic tools installed"
fi

# Vytvorenie priecinkov - Create directories
echo "[*] Vytvaranie adresarov - Creating directories..."
mkdir -p "$INIT_DIR" "$LOCAL_WEB_DIR" "$LIC_DIR" "${TARGET_DIR}/data" "${TARGET_DIR}/data/screenshots" "${TARGET_DIR}/logs"
echo "[✓] Adresare vytvorene - Directories created"

# Save API token if provided
if [ -n "$API_TOKEN" ]; then
    echo "[*] Ukladanie API tokenu - Saving API token..."
    echo "$API_TOKEN" > "${LIC_DIR}/token"
    chmod 600 "${LIC_DIR}/token"
    echo "[✓] API token ulozeny - API token saved"
fi

# Nacitanie zoznamu suborov - Loading file list
echo "[*] Nacitavame zoznam suborov - Loading file list..."

FILES_LIST=""
CURL_EXIT_CODE=1
for attempt in 1 2 3 4 5; do
    FILES_LIST="$(curl -s --max-time 30 --connect-timeout 10 "${AUTH_HEADER[@]}" "${INIT_BASE}/download.php?getfiles&token=${API_TOKEN}" 2>/dev/null | tr -d '\r')"
    CURL_EXIT_CODE=$?
    if [ $CURL_EXIT_CODE -eq 0 ] && [ -n "$FILES_LIST" ]; then
        break
    fi
    if [ $attempt -lt 5 ]; then
        echo "[!] VAROVANIE: getfiles attempt ${attempt}/5 failed, retrying..."
        sleep 2
    fi
done

if [ $CURL_EXIT_CODE -ne 0 ]; then
  echo "[!] Chyba pripojenia k serveru - Server connection error (kod - code: $CURL_EXIT_CODE)"
  exit 1
fi

if [ -z "$FILES_LIST" ]; then
  echo "[!] Server vratil prazdny zoznam - Server returned empty list"
  exit 1
fi

# Pocet suborov - File count
TOTAL_FILES=0
while IFS= read -r line; do
    if [[ -n "$line" && "$line" == *";"* ]]; then
        TOTAL_FILES=$((TOTAL_FILES + 1))
    fi
done <<< "$FILES_LIST"

if [ $TOTAL_FILES -eq 0 ]; then
    echo "[!] Ziadne subory na stiahnutie - No files to download"
    exit 1
fi

CURRENT_FILE=0
echo ""
echo "=========================================="
echo "Stahovanie - Downloading: ${TOTAL_FILES} suborov - files"
echo "=========================================="
echo ""

# Stiahnutie suborov - Download files
while IFS=";" read -r NAME SIZE MODIFIED; do
    [ -z "${NAME:-}" ] && continue

    CURRENT_FILE=$((CURRENT_FILE + 1))
    PERCENT=$((CURRENT_FILE * 100 / TOTAL_FILES))
    
    echo "[${CURRENT_FILE}/${TOTAL_FILES}] (${PERCENT}%) ${NAME} (${SIZE} bajtov)"
    
    MAX_DOWNLOAD_ATTEMPTS=3
    DOWNLOAD_SUCCESS=false
    
    for attempt in $(seq 1 $MAX_DOWNLOAD_ATTEMPTS); do
        if fetch_with_retry "${INIT_BASE}/download.php?streamfile=${NAME}&token=${API_TOKEN}" "${INIT_DIR}/${NAME}" 3; then
            
            ACTUAL_SIZE=$(stat -c%s "${INIT_DIR}/${NAME}" 2>/dev/null || echo "0")
            
            if [[ "$SIZE" =~ ^[0-9]+$ ]] && [ "$ACTUAL_SIZE" -eq "$SIZE" ]; then
                DOWNLOAD_SUCCESS=true
                break
            else
                echo "    [!] Velkost nesedi - Size mismatch (ocakavane - expected: ${SIZE}, skutocne - actual: ${ACTUAL_SIZE})"
                if [ $attempt -lt $MAX_DOWNLOAD_ATTEMPTS ]; then
                    sleep 2
                    rm -f "${INIT_DIR}/${NAME}"
                fi
            fi
        else
            if [ $attempt -lt $MAX_DOWNLOAD_ATTEMPTS ]; then
                echo "    [!] Pokus ${attempt}/${MAX_DOWNLOAD_ATTEMPTS} zlyhal, skusam znova - Attempt failed, retrying..."
                sleep 2
            fi
        fi
    done
    
    if [ "$DOWNLOAD_SUCCESS" != "true" ]; then
        echo ""
        echo "[!] CHYBA - ERROR: Subor $NAME sa nepodarilo stiahnut - File failed to download po - after ${MAX_DOWNLOAD_ATTEMPTS} pokusoch - attempts"
        echo ""
        exit 1
    fi

    # Oprava konca riadkov - Fix line endings
    sed -i 's/\r$//' "${INIT_DIR}/${NAME}"

    # Kontrola a oprava shebang - Check and fix shebang
    if [[ "${NAME}" == *.sh ]]; then
        chmod +x "${INIT_DIR}/${NAME}"
        FIRST_LINE="$(head -n1 "${INIT_DIR}/${NAME}" || true)"
        if [[ "${FIRST_LINE}" != "#!"* ]]; then
            sed -i '1i #!/bin/bash' "${INIT_DIR}/${NAME}"
        fi
    elif [[ "${NAME}" == *.html ]]; then
      cp -f "${INIT_DIR}/${NAME}" "${LOCAL_WEB_DIR}/${NAME}"
    fi
done <<< "$FILES_LIST"

# Kontrola edudisplej-init.sh - Check for init script
if [ ! -f "${INIT_DIR}/edudisplej-init.sh" ]; then
    echo "[!] Chyba - Error: edudisplej-init.sh chyba - missing"
    exit 1
fi

# Urcenie pouzivatela - Determine user
CONSOLE_USER="$(awk -F: '$3==1000{print $1}' /etc/passwd | head -n1 || true)"
[ -z "${CONSOLE_USER}" ] && CONSOLE_USER="pi"

USER_HOME="$(getent passwd "$CONSOLE_USER" | cut -d: -f6)"
if [ -z "$USER_HOME" ]; then
    echo "[!] Domovsky adresar nenajdeny pre - Home directory not found for: $CONSOLE_USER"
    exit 1
fi
echo "[*] Pouzivatel - User: $CONSOLE_USER, Domov - Home: $USER_HOME"

    # Validate required runtime files
    REQUIRED_FILES=(
        "edudisplej-init.sh"
        "edudisplej_sync_service.sh"
        "edudisplej-download-modules.sh"
        "edudisplej-config-manager.sh"
        "kiosk-start.sh"
    )
    for required in "${REQUIRED_FILES[@]}"; do
        if [ ! -s "${INIT_DIR}/${required}" ]; then
            echo "[!] CHYBA - ERROR: Required file missing or empty: ${INIT_DIR}/${required}"
            exit 1
        fi
    done

# Nastavenie opravneni - Set permissions
chmod -R 755 "$TARGET_DIR"

# Setup screenshot service directories and permissions
echo "[*] Konfiguracia oprávnení - Configuring permissions..."
chmod 755 "${TARGET_DIR}/data" "${TARGET_DIR}/localweb" "${TARGET_DIR}/lic" "${TARGET_DIR}/data/screenshots"

# Set proper ownership for data directory and config (CONSOLE_USER owns all)
chown -R "$CONSOLE_USER:$CONSOLE_USER" "${TARGET_DIR}/data"
chmod 755 "${TARGET_DIR}/data"
chmod 755 "${TARGET_DIR}/data/screenshots"

# Ensure logs directory is writable for user-space services (health/command executor)
mkdir -p "${TARGET_DIR}/logs"
chown -R "$CONSOLE_USER:$CONSOLE_USER" "${TARGET_DIR}/logs"
chmod 755 "${TARGET_DIR}/logs"

# config.json should be readable/writable by CONSOLE_USER
[ -f "${TARGET_DIR}/data/config.json" ] && chmod 664 "${TARGET_DIR}/data/config.json"

echo "[✓] Oprávnenia nastavené - Permissions configured"

# Ulozenie nastaveni - Save settings
echo "surf" > "${TARGET_DIR}/.kiosk_mode"
echo "$CONSOLE_USER" > "${TARGET_DIR}/.console_user"
echo "$USER_HOME" > "${TARGET_DIR}/.user_home"

# Configure hostname immediately during install (running as root)
if [ -x "${INIT_DIR}/edudisplej-hostname.sh" ]; then
    echo "[*] Konfiguracia hostname - Configuring hostname..."
    if bash "${INIT_DIR}/edudisplej-hostname.sh"; then
        echo "[✓] Hostname nakonfigurovany - Hostname configured"
    else
        echo "[!] VAROVANIE: Hostname konfiguracia zlyhala - Hostname configuration failed"
    fi
fi

# ============================================================================
# SERVICE INSTALLATION / SZOLGALTATASOK TELEPITESE
# ============================================================================

install_services_from_structure() {
    echo ""
    echo "=========================================="
    echo "Service fajlok telepitese / Installing services"
    echo "=========================================="
    echo ""
    
    # Try to download structure.json
    if ! command -v python3 >/dev/null 2>&1; then
        echo "[!] VAROVANIE: python3 nie je dostupny, pouzivam staru metodu"
        return 1
    fi
    
    echo "[*] Sťahujem structure.json / Downloading structure.json..."
    STRUCTURE_JSON=""
    structure_tmp="$(mktemp)"
    if fetch_with_retry "${INIT_BASE}/download.php?getstructure&token=${API_TOKEN}" "$structure_tmp" 4; then
        STRUCTURE_JSON="$(tr -d '\r' < "$structure_tmp")"
    fi
    rm -f "$structure_tmp"
    
    if [ -z "$STRUCTURE_JSON" ]; then
        echo "[!] Nemozem stiahnut structure.json, pouzivam staru metodu"
        return 1
    fi

    # Cache structure.json locally for runtime version comparisons
    echo "$STRUCTURE_JSON" > "${TARGET_DIR}/structure.json"
    chmod 644 "${TARGET_DIR}/structure.json"
    
    # Extract services using Python
    SERVICES_JSON=$(echo "$STRUCTURE_JSON" | python3 -c "
import json
import sys
try:
    data = json.load(sys.stdin)
    if 'services' in data:
        for svc in data['services']:
            print(f\"{svc['source']}|{svc['name']}|{svc.get('enabled', False)}|{svc.get('autostart', False)}|{svc.get('description', '')}\")
except Exception as e:
    print(f'ERROR: {e}', file=sys.stderr)
    sys.exit(1)
")
    
    if [ $? -ne 0 ]; then
        echo "[!] Chyba pri parsovani services z structure.json"
        return 1
    fi
    
    if [ -z "$SERVICES_JSON" ]; then
        echo "[*] Ziadne services na instalaciu"
        return 0
    fi
    
    # Render and copy each service file
    SERVICE_COUNT=0
    SERVICE_TOTAL=$(echo "$SERVICES_JSON" | wc -l)
    
    while IFS='|' read -r source name enabled autostart description; do
        SERVICE_COUNT=$((SERVICE_COUNT + 1))
        
        echo "[$SERVICE_COUNT/$SERVICE_TOTAL] $name"
        echo "  Popis / Description: $description"
        
        # Copy service file from init/ to systemd directory
        SOURCE_PATH="/opt/edudisplej/init/$source"
        DEST_PATH="/etc/systemd/system/$name"
        
        if [ ! -f "$SOURCE_PATH" ]; then
            echo "  [!] CHYBA: Service subor nenajdeny: $SOURCE_PATH"
            continue
        fi
        
        if render_service_for_console_user "$SOURCE_PATH" "$DEST_PATH"; then
            chmod 644 "$DEST_PATH"
            echo "  [✓] Service rendered for user: $CONSOLE_USER"
        else
            echo "  [!] CHYBA: Nepodarilo sa pripravit service file"
            continue
        fi
        
        # Verify service exists (check if file exists first, systemctl may be slow)
        if [ -f "$DEST_PATH" ]; then
            echo "  [✓] Service file existuje: $DEST_PATH"
            
            # Double-check with systemctl (optional, may fail on some systems)
            if systemctl list-unit-files "$name" >/dev/null 2>&1; then
                echo "  [✓] Service rozpoznany v systemd"
            else
                # Not critical - systemd sometimes needs time to recognize new units
                echo "  [*] Service subor nainstalovany (systemd ho rozpozna po reloade)"
            fi
        else
            echo "  [!] CHYBA: Service file neexistuje: $DEST_PATH"
            continue
        fi
        
        echo ""
        
    done <<< "$SERVICES_JSON"

    if systemctl daemon-reload 2>/dev/null; then
        echo "[✓] systemd daemon-reload"
    else
        echo "[!] VAROVANIE: daemon-reload zlyhal"
    fi

    # Enable/start services in a separate pass after single daemon-reload
    while IFS='|' read -r source name enabled autostart description; do
        [ -z "${name:-}" ] && continue

        if [ "$enabled" = "True" ] || [ "$enabled" = "true" ]; then
            if systemctl enable "$name" 2>/dev/null; then
                echo "  [✓] Service enabled: $name"
            else
                echo "  [!] VAROVANIE: enable zlyhal pre $name"
            fi
        fi

        if [ "$autostart" = "True" ] || [ "$autostart" = "true" ]; then
            if [ "$name" != "edudisplej-kiosk.service" ]; then
                systemctl stop "$name" 2>/dev/null || true
                sleep 1
                if systemctl start "$name" 2>/dev/null; then
                    echo "  [✓] Service spusteny: $name"
                else
                    echo "  [!] CHYBA: Nepodarilo sa spustit service: $name"
                fi
            else
                echo "  [*] Service sa spusti po restarte / Will start after reboot"
            fi
        fi
    done <<< "$SERVICES_JSON"
    
    echo "[✓] Service instalacia dokoncena"
    echo ""
    return 0
}

# Try to install services from structure.json
if ! install_services_from_structure; then
    # Fallback to old method if structure.json is not available
    echo "[*] Pouzivam staru metodu instalacie services / Using old method..."
    
    # Instalacia systemd sluzby - Install systemd service
    echo "[*] Instalacia systemd sluzby - Installing systemd service..."
    
    if [ -f "${INIT_DIR}/edudisplej-kiosk.service" ]; then
        render_service_for_console_user "${INIT_DIR}/edudisplej-kiosk.service" "$SERVICE_FILE"
        chmod 644 "$SERVICE_FILE"
        
        if [ -f "${INIT_DIR}/kiosk-start.sh" ]; then
            chmod +x "${INIT_DIR}/kiosk-start.sh"
        fi
    else
        echo "[!] CHYBA - ERROR: Service subor nenajdeny - Service file not found"
    fi
fi

# Ensure core services are always present and active even when structure download fails
echo ""
echo "[*] Overujem klucove sluzby - Ensuring core services..."

CORE_SERVICES=(
    "edudisplej-kiosk.service"
    "edudisplej-sync.service"
    "edudisplej-watchdog.service"
    "edudisplej-screenshot-service.service"
    "edudisplej-command-executor.service"
    "edudisplej-health.service"
)

# Copy/render service files first
for service in "${CORE_SERVICES[@]}"; do
    source_file="${INIT_DIR}/${service}"
    target_file="/etc/systemd/system/${service}"

    if [ -f "$source_file" ]; then
        if render_service_for_console_user "$source_file" "$target_file"; then
            chmod 644 "$target_file" 2>/dev/null || true
            echo "  [✓] Service file copied: $service"
        fi
    fi

done

systemctl daemon-reload 2>/dev/null || true

# Enable/start services after daemon-reload
for service in "${CORE_SERVICES[@]}"; do
    if [ -f "/etc/systemd/system/${service}" ] || systemctl list-unit-files "$service" >/dev/null 2>&1; then
        if systemctl enable "$service" 2>/dev/null; then
            echo "  [✓] Enabled: $service"
        else
            echo "  [!] VAROVANIE: enable zlyhal pre $service"
        fi

        if [ "$service" = "edudisplej-kiosk.service" ]; then
            echo "  [*] Kiosk service enabled (will run after reboot)"
        else
            if systemctl start "$service" 2>/dev/null; then
                echo "  [✓] Started: $service"
            else
                echo "  [!] VAROVANIE: start zlyhal pre $service"
            fi
        fi
    fi
done

# Hard safety check for fleet installs: kiosk service must be enabled
if systemctl is-enabled edudisplej-kiosk.service >/dev/null 2>&1; then
    echo "[✓] Kiosk service is enabled for next boot"
else
    echo "[!] VAROVANIE: kiosk service was not enabled, forcing enable..."
    if systemctl enable edudisplej-kiosk.service 2>/dev/null; then
        echo "[✓] Kiosk service force-enabled"
    else
        echo "[!] CHYBA: failed to enable kiosk service"
    fi
fi

# Sudo konfiguracia - Sudo configuration
echo "[*] Konfiguracia sudo - Configuring sudo..."
mkdir -p /etc/sudoers.d
cat > /etc/sudoers.d/edudisplej <<EOF
# EduDisplej - Passwordless sudo for system scripts
$CONSOLE_USER ALL=(ALL) NOPASSWD: /opt/edudisplej/init/edudisplej-init.sh
$CONSOLE_USER ALL=(ALL) NOPASSWD: /opt/edudisplej/init/edudisplej-hostname.sh
$CONSOLE_USER ALL=(ALL) NOPASSWD: /opt/edudisplej/init/update.sh
$CONSOLE_USER ALL=(ALL) NOPASSWD: /opt/edudisplej/init/edudisplej_terminal_script.sh
$CONSOLE_USER ALL=(ALL) NOPASSWD: /opt/edudisplej/init/edudisplej-download-modules.sh
EOF
chmod 0440 /etc/sudoers.d/edudisplej

# Verify sudoers syntax
if visudo -c -f /etc/sudoers.d/edudisplej >/dev/null 2>&1; then
    echo "[✓] Sudoers konfiguracia overena - Sudoers configuration verified"
else
    echo "[!] VAROVANIE: Sudoers syntax check failed, removing file"
    rm -f /etc/sudoers.d/edudisplej
fi

# Deaktivacia getty@tty1 - Disable getty
systemctl disable getty@tty1.service 2>/dev/null || true

# ============================================================================
# INITIALIZE CENTRALIZED DATA DIRECTORY / CENTRALIZOVANY DATOVY ADRESAR
# ============================================================================

echo ""
echo "[*] Inicializujem centralizovany data adresar - Initializing centralized data directory..."

DATA_DIR="${TARGET_DIR}/data"
CONFIG_FILE="${DATA_DIR}/config.json"

# Data directory was already created at line 166, just verify
if [ ! -d "$DATA_DIR" ]; then
    mkdir -p "$DATA_DIR"
fi
echo "[✓] Data adresar pripraveny - Data directory ready: $DATA_DIR"

# Initialize config.json using config manager
if [ -x "${INIT_DIR}/edudisplej-config-manager.sh" ]; then
    echo "[*] Inicializujem config.json - Initializing config.json..."
    bash "${INIT_DIR}/edudisplej-config-manager.sh" init
    echo "[✓] Config.json inicializovany - config.json initialized"
else
    echo "[!] VAROVANIE: Config manager nenajdeny - Config manager not found"
    echo "[!] Config.json bude vytvoreny pri prvom sync - Config.json will be created on first sync"
fi

echo ""

# NOTE:
# Do NOT create .kiosk_system_configured during install.
# First boot must run edudisplej-init.sh once to complete system setup
# (install packages, create .xinitrc and Openbox autostart, finalize kiosk runtime config).

echo ""
echo "=========================================="
echo "Instalacia ukoncena - Installation completed!"
echo "=========================================="
echo ""
echo "[✓] Konfiguracia - Configuration:"
echo "  - Kiosk mod - Kiosk mode: surf"
echo "  - Pouzivatel - User: $CONSOLE_USER"
echo ""
echo "Po restarte system automaticky spusti displej"
echo "After restart, the system will automatically start the display"
echo ""
echo "=========================================="
echo ""

# Restart - Reboot
if [ "${AUTO_REBOOT}" = "false" ] || [ "${AUTO_REBOOT}" = "0" ]; then
    response="n"
    echo "[*] Auto reboot disabled via EDUDISPLEJ_AUTO_REBOOT=${AUTO_REBOOT}"
elif read -t 30 -p "Restartovat teraz? [Y/n] (automaticky za 30s) - Restart now? [Y/n] (auto in 30s): " response; then
    :
else
    READ_EXIT=$?
    if [ $READ_EXIT -gt 128 ]; then
        response="y"
        echo "(automaticky restartujem - auto restarting)"
    else
        response="y"
    fi
fi
echo ""

case "$response" in
    [nN]|[nN][oO])
        echo "[*] Restart preskoceny - Restart skipped. Spustite - Run: sudo reboot"
        ;;
    *)
        echo "[*] Zastavujem sluzby - Stopping services..."
        systemctl stop getty@tty1.service 2>/dev/null || true
        echo "[*] Synchronizujem disky - Syncing disks..."
        sync
        echo "[*] Restartujem - Restarting..."
        sleep 3
        reboot
        ;;
esac
