#!/bin/bash
set -euo pipefail

# Zakladne nastavenia - Base settings
TARGET_DIR="/opt/edudisplej"
INIT_DIR="${TARGET_DIR}/init"
LOCAL_WEB_DIR="${TARGET_DIR}/localweb"
INIT_BASE="https://install.edudisplej.sk/init"
SERVICE_FILE="/etc/systemd/system/edudisplej-kiosk.service"

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
    if ! grep -q "^[[:space:]]*dtoverlay=vc4-fkms-v3d" "$CONFIG_FILE"; then
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

# Detekcia architektury - Architecture detection
ARCH="$(uname -m)"
echo "[*] Architektura - Architecture: $ARCH"
if [ "$ARCH" = "armv6l" ]; then
  KIOSK_MODE="epiphany"
  fix_armv6_boot_config
else
  KIOSK_MODE="chromium"
fi

# Instalacia curl ak chyba - Install curl if missing
if ! command -v curl >/dev/null 2>&1; then
  echo "[*] Instalacia curl - Installing curl..."
  start_heartbeat
  apt-get update -qq && apt-get install -y curl >/dev/null 2>&1
  stop_heartbeat
  echo "[✓] curl nainstalovany - curl installed"
fi

# Vzdy prepisat cielovy priecinok - Always overwrite target directory
if [ -d "$TARGET_DIR" ]; then
  echo "[*] Mazanie existujuceho adresara - Removing existing directory: $TARGET_DIR"
  rm -rf "$TARGET_DIR"
fi

# Vytvorenie priecinkov - Create directories
mkdir -p "$INIT_DIR" "$LOCAL_WEB_DIR"

# Nacitanie zoznamu suborov - Loading file list
echo "[*] Nacitavame zoznam suborov - Loading file list..."

FILES_LIST="$(curl -s --max-time 30 --connect-timeout 10 "${INIT_BASE}/download.php?getfiles" 2>/dev/null | tr -d '\r')"
CURL_EXIT_CODE=$?

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
        if curl -sL --max-time 60 --connect-timeout 10 \
            "${INIT_BASE}/download.php?streamfile=${NAME}" \
            -o "${INIT_DIR}/${NAME}" 2>&1; then
            
            ACTUAL_SIZE=$(stat -c%s "${INIT_DIR}/${NAME}" 2>/dev/null || echo "0")
            
            if [ "$ACTUAL_SIZE" -eq "$SIZE" ]; then
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

# Nastavenie opravneni - Set permissions
chmod -R 755 "$TARGET_DIR"

# Urcenie pouzivatela - Determine user
CONSOLE_USER="$(awk -F: '$3==1000{print $1}' /etc/passwd | head -n1 || true)"
[ -z "${CONSOLE_USER}" ] && CONSOLE_USER="pi"

USER_HOME="$(getent passwd "$CONSOLE_USER" | cut -d: -f6)"
if [ -z "$USER_HOME" ]; then
    echo "[!] Domovsky adresar nenajdeny pre - Home directory not found for: $CONSOLE_USER"
    exit 1
fi
echo "[*] Pouzivatel - User: $CONSOLE_USER, Domov - Home: $USER_HOME"

# Ulozenie nastaveni - Save settings
echo "$KIOSK_MODE" > "${TARGET_DIR}/.kiosk_mode"
echo "$CONSOLE_USER" > "${TARGET_DIR}/.console_user"
echo "$USER_HOME" > "${TARGET_DIR}/.user_home"

# Instalacia systemd sluzby - Install systemd service
echo "[*] Instalacia systemd sluzby - Installing systemd service..."

if [ -f "${INIT_DIR}/edudisplej-kiosk.service" ]; then
    sed -e "s/User=edudisplej/User=$CONSOLE_USER/g" \
        -e "s/Group=edudisplej/Group=$CONSOLE_USER/g" \
        -e "s|WorkingDirectory=/home/edudisplej|WorkingDirectory=$USER_HOME|g" \
        -e "s|Environment=HOME=/home/edudisplej|Environment=HOME=$USER_HOME|g" \
        -e "s/Environment=USER=edudisplej/Environment=USER=$CONSOLE_USER/g" \
        "${INIT_DIR}/edudisplej-kiosk.service" > "$SERVICE_FILE"
    chmod 644 "$SERVICE_FILE"
    
    if [ -f "${INIT_DIR}/kiosk-start.sh" ]; then
        chmod +x "${INIT_DIR}/kiosk-start.sh"
    fi
else
    echo "[!] CHYBA - ERROR: Service subor nenajdeny - Service file not found"
fi

# Sudo konfiguracia - Sudo configuration
echo "[*] Konfiguracia sudo - Configuring sudo..."
mkdir -p /etc/sudoers.d
cat > /etc/sudoers.d/edudisplej <<EOF
$CONSOLE_USER ALL=(ALL) NOPASSWD: /opt/edudisplej/init/edudisplej-init.sh
EOF
chmod 0440 /etc/sudoers.d/edudisplej

# Deaktivacia getty@tty1 - Disable getty
systemctl disable getty@tty1.service 2>/dev/null || true

# Aktivacia sluzby - Enable service
if [ -f "$SERVICE_FILE" ]; then
    systemctl daemon-reload
    systemctl enable edudisplej-kiosk.service 2>/dev/null || true
fi

# Watchdog sluzba - Watchdog service
echo "[*] Instalacia watchdog - Installing watchdog..."
if [ -f "${INIT_DIR}/edudisplej-watchdog.service" ]; then
    cp "${INIT_DIR}/edudisplej-watchdog.service" /etc/systemd/system/
    chmod 644 /etc/systemd/system/edudisplej-watchdog.service
    
    if [ -f "${INIT_DIR}/edudisplej-watchdog.sh" ]; then
        chmod +x "${INIT_DIR}/edudisplej-watchdog.sh"
    fi
    
    systemctl daemon-reload
    systemctl enable edudisplej-watchdog.service 2>/dev/null || true
fi

echo ""
echo "=========================================="
echo "Instalacia ukoncena - Installation completed!"
echo "=========================================="
echo ""
echo "[✓] Konfiguracia - Configuration:"
echo "  - Kiosk mod - Kiosk mode: $KIOSK_MODE"
echo "  - Pouzivatel - User: $CONSOLE_USER"
echo ""
echo "Po restarte system automaticky spusti displej"
echo "After restart, the system will automatically start the display"
echo ""
echo "=========================================="
echo ""

# Restart - Reboot
if read -t 30 -p "Restartovat teraz? [Y/n] (automaticky za 30s) - Restart now? [Y/n] (auto in 30s): " response; then
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
