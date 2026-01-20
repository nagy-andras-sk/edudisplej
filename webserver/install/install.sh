#!/bin/bash
set -euo pipefail

TARGET_DIR="/opt/edudisplej"
INIT_DIR="${TARGET_DIR}/init"
LOCAL_WEB_DIR="${TARGET_DIR}/localweb"
INIT_BASE="https://install.edudisplej.sk/init"
SERVICE_FILE="/etc/systemd/system/edudisplej-kiosk.service"

# Cleanup function for graceful exit
cleanup_on_error() {
    local exit_code=$?
    if [ $exit_code -ne 0 ]; then
        echo ""
        echo "[!] =========================================="
        echo "[!] CHYBA: Inštalácia zlyhala (exit code: $exit_code)"
        echo "[!] =========================================="
        echo ""
        echo "Možné riešenia:"
        echo "  1. Skontrolujte internetové pripojenie"
        echo "  2. Skúste spustiť inštaláciu znova"
        echo "  3. Skontrolujte logy vyššie pre detaily"
        echo ""
    fi
    # Stop heartbeat if running
    stop_heartbeat
}

trap cleanup_on_error EXIT

# Heartbeat function to show the script is still running
HEARTBEAT_PID=""
start_heartbeat() {
    stop_heartbeat  # Stop any existing heartbeat
    (
        while true; do
            echo -n "."
            sleep 2
        done
    ) &
    HEARTBEAT_PID=$!
}

stop_heartbeat() {
    if [ -n "$HEARTBEAT_PID" ] && kill -0 "$HEARTBEAT_PID" 2>/dev/null; then
        kill "$HEARTBEAT_PID" 2>/dev/null || true
        wait "$HEARTBEAT_PID" 2>/dev/null || true
        HEARTBEAT_PID=""
        echo ""  # New line after dots
    fi
}

echo "[*] Kontrola opravneni root..."
if [ "$(id -u)" -ne 0 ]; then
  echo "[!] Spusti skript s sudo!"
  exit 1
fi

# Detect architecture
ARCH="$(uname -m)"
echo "[*] Detected architecture: $ARCH"

# Determine kiosk mode based on architecture
if [ "$ARCH" = "armv6l" ]; then
  KIOSK_MODE="epiphany"
  echo "[*] ARMv6 detected - using Epiphany browser kiosk mode"
else
  KIOSK_MODE="chromium"
  echo "[*] Using Chromium browser kiosk mode"
fi

# Kontrola: curl nainstalovany?
if ! command -v curl >/dev/null 2>&1; then
  echo "[*] Instalacia curl..."
  start_heartbeat "Instalujem curl"
  apt-get update -qq && apt-get install -y curl >/dev/null 2>&1
  stop_heartbeat
  echo "[✓] curl nainstalovany"
fi

# Kontrola GUI (len info)
echo "[*] Kontrola GUI prostredia..."
if pgrep -x "Xorg" >/dev/null 2>&1; then
    echo "[*] GUI bezi."
    GUI_AVAILABLE=true
else
    echo "[!] GUI sa nenasiel. Pokracujeme bez grafiky."
    GUI_AVAILABLE=false
fi

# Ak existuje cielovy priecinok, vytvorit zalohu
if [ -d "$TARGET_DIR" ]; then
  BACKUP="${TARGET_DIR}.bak.$(date +%s)"
  echo "[*] Zalohovanie: $TARGET_DIR -> $BACKUP"
  mv "$TARGET_DIR" "$BACKUP"
fi

# Vytvorenie init a localweb priecinkov
mkdir -p "$INIT_DIR" "$LOCAL_WEB_DIR"

echo "[*] Nacitavame zoznam suborov : ${INIT_BASE}/download.php?getfiles"

# Add timeout to prevent freezing
# Separate stderr from file list to avoid mixing error messages with data
FILES_LIST="$(curl -s --max-time 30 --connect-timeout 10 "${INIT_BASE}/download.php?getfiles" 2>/dev/null | tr -d '\r')"
CURL_EXIT_CODE=$?

if [ $CURL_EXIT_CODE -ne 0 ]; then
  echo "[!] Chyba: Nepodarilo sa pripojit k serveru (curl exit code: $CURL_EXIT_CODE)."
  echo "[!] Skontrolujte internetove pripojenie a skuste znova."
  exit 1
fi

if [ -z "$FILES_LIST" ]; then
  echo "[!] Chyba: Server vratil prazdny zoznam suborov."
  echo "[!] Skontrolujte dostupnost servera alebo skuste znova."
  exit 1
fi

echo "[DEBUG] Zoznam suborov:"
echo "$FILES_LIST"

# Count total files for progress tracking
# More robust counting: count non-empty lines with at least one semicolon
TOTAL_FILES=0
while IFS= read -r line; do
    if [[ -n "$line" && "$line" == *";"* ]]; then
        TOTAL_FILES=$((TOTAL_FILES + 1))
    fi
done <<< "$FILES_LIST"

if [ $TOTAL_FILES -eq 0 ]; then
    echo "[!] Chyba: Ziadne subory na stiahnutie."
    exit 1
fi

CURRENT_FILE=0

echo ""
echo "=========================================="
echo "Stahovanie suborov: ${TOTAL_FILES} suborov"
echo "=========================================="
echo ""

# Stiahnutie jednotlivo + CRLF oprava + kontrola shebang
# DÔLEŽITÉ: while bez pipe (aby exit vo vnútri ukončil skript)
while IFS=";" read -r NAME SIZE MODIFIED; do
    [ -z "${NAME:-}" ] && continue

    CURRENT_FILE=$((CURRENT_FILE + 1))
    PERCENT=$((CURRENT_FILE * 100 / TOTAL_FILES))
    
    echo "[${CURRENT_FILE}/${TOTAL_FILES}] (${PERCENT}%) Stahovanie: ${NAME}"
    echo "    Velkost: ${SIZE} bajtov"
    
    # Download with timeout and progress indication
    if curl -sL --max-time 60 --connect-timeout 10 \
        "${INIT_BASE}/download.php?streamfile=${NAME}" \
        -o "${INIT_DIR}/${NAME}" 2>&1; then
        echo "    [OK] Stiahnuty uspesne"
    else
        echo "[!] Chyba: Stahovanie $NAME zlyhalo."
        echo "[!] Skontrolujte internetove pripojenie."
        # Try once more
        echo "[*] Skusam znova..."
        sleep 2
        if curl -sL --max-time 60 --connect-timeout 10 \
            "${INIT_BASE}/download.php?streamfile=${NAME}" \
            -o "${INIT_DIR}/${NAME}" 2>&1; then
            echo "    [OK] Stiahnuty uspesne pri druhom pokuse"
        else
            echo "[!] Chyba: Stahovanie $NAME zlyhalo aj pri druhom pokuse."
            exit 1
        fi
    fi
    echo ""

    # Oprava konca riadkov (CRLF -> LF)
    sed -i 's/\r$//' "${INIT_DIR}/${NAME}"

    # Ha .sh fajl, ellenorizzuk a shebang-et
    if [[ "${NAME}" == *.sh ]]; then
        chmod +x "${INIT_DIR}/${NAME}"
        FIRST_LINE="$(head -n1 "${INIT_DIR}/${NAME}" || true)"
        if [[ "${FIRST_LINE}" != "#!"* ]]; then
            echo "[!] Chyba shebang, pridavam: #!/bin/bash"
            sed -i '1i #!/bin/bash' "${INIT_DIR}/${NAME}"
        fi
    elif [[ "${NAME}" == *.html ]]; then
      # HTML subory presun do localweb priecinka
      cp -f "${INIT_DIR}/${NAME}" "${LOCAL_WEB_DIR}/${NAME}"
    fi
done <<< "$FILES_LIST"

# Kontrola: edudisplej-init.sh existuje?
if [ ! -f "${INIT_DIR}/edudisplej-init.sh" ]; then
    echo "[!] Chyba: edudisplej-init.sh sa nenachadza medzi stiahnutymi subormi."
    exit 1
fi

# Nastavenie opravneni
chmod -R 755 "$TARGET_DIR"

# Urcenie konzoloveho pouzivatela (zvycajne pi)
CONSOLE_USER="$(awk -F: '$3==1000{print $1}' /etc/passwd | head -n1 || true)"
[ -z "${CONSOLE_USER}" ] && CONSOLE_USER="pi"

# Get user home directory
USER_HOME="$(getent passwd "$CONSOLE_USER" | cut -d: -f6)"
if [ -z "$USER_HOME" ]; then
    echo "[!] Could not determine home directory for user: $CONSOLE_USER"
    exit 1
fi
echo "[*] User: $CONSOLE_USER, Home: $USER_HOME"

# Save kiosk mode preference and user info for init script to use
echo "$KIOSK_MODE" > "${TARGET_DIR}/.kiosk_mode"
echo "$CONSOLE_USER" > "${TARGET_DIR}/.console_user"
echo "$USER_HOME" > "${TARGET_DIR}/.user_home"
echo "[*] Kiosk mode preference saved: $KIOSK_MODE"
echo "[*] Console user saved: $CONSOLE_USER"
echo "[*] Packages and kiosk configuration will be set up by init script on first boot"

# === SYSTEMD SERVICE INSTALLATION ===

echo "[*] Installing systemd service..."

# Copy and customize service file
if [ -f "${INIT_DIR}/edudisplej-kiosk.service" ]; then
    # Customize service file with actual user
    sed -e "s/User=edudisplej/User=$CONSOLE_USER/g" \
        -e "s/Group=edudisplej/Group=$CONSOLE_USER/g" \
        -e "s|WorkingDirectory=/home/edudisplej|WorkingDirectory=$USER_HOME|g" \
        -e "s|Environment=HOME=/home/edudisplej|Environment=HOME=$USER_HOME|g" \
        -e "s/Environment=USER=edudisplej/Environment=USER=$CONSOLE_USER/g" \
        "${INIT_DIR}/edudisplej-kiosk.service" > "$SERVICE_FILE"
    chmod 644 "$SERVICE_FILE"
    echo "[*] Service file installed for user: $CONSOLE_USER"
    
    # Also ensure wrapper script is executable
    if [ -f "${INIT_DIR}/kiosk-start.sh" ]; then
        chmod +x "${INIT_DIR}/kiosk-start.sh"
        echo "[*] Wrapper script configured"
    fi
else
    echo "[!] ERROR: Service file not found at ${INIT_DIR}/edudisplej-kiosk.service"
    echo "[!] Systemd service installation failed. System will not auto-start."
    echo "[!] Manual configuration will be required."
    # Continue anyway, but warn user
fi

# Create sudoers configuration for init script
echo "[*] Configuring passwordless sudo for init script..."
mkdir -p /etc/sudoers.d
cat > /etc/sudoers.d/edudisplej <<EOF
# Allow console user to run init script without password
$CONSOLE_USER ALL=(ALL) NOPASSWD: /opt/edudisplej/init/edudisplej-init.sh
EOF
chmod 0440 /etc/sudoers.d/edudisplej
echo "[*] Sudoers configuration complete"

# Disable getty@tty1 (kiosk service will take over)
echo "[*] Disabling getty@tty1..."
systemctl disable getty@tty1.service 2>/dev/null || true
echo "[*] getty@tty1 disabled"

# Enable and start kiosk service (only if service file exists)
if [ -f "$SERVICE_FILE" ]; then
    echo "[*] Enabling kiosk service..."
    systemctl daemon-reload
    systemctl enable edudisplej-kiosk.service 2>/dev/null || true
    echo "[*] Systemd service installed and enabled"
else
    echo "[!] WARNING: Systemd service not enabled (service file missing)"
    echo "[!] System will not auto-start on boot"
fi

echo ""
echo "=========================================="
echo "Telepítés kész! / Installation Complete!"
echo "=========================================="
echo ""
echo "[✓] Vsetky subory uspesne stiahnuté a nakonfigurovane!"
echo ""
echo "Konfigurácia:"
echo "  - Kiosk mód: $KIOSK_MODE"
echo "  - Používateľ: $CONSOLE_USER"
echo "  - Domovský adresár: $USER_HOME"
echo ""
echo "Po reštarte systém automaticky:"
echo "  1. Nainštaluje potrebné balíčky (X11, browser, utility)"
echo "  2. Nakonfiguruje kiosk mód"
echo "  3. Spustí displej systém"
echo ""
echo "Log súbory:"
echo "  - Init log: /opt/edudisplej/session.log"
echo "  - APT log: /opt/edudisplej/apt.log"
echo ""
echo "=========================================="
echo ""

# Offer manual restart option
# Use explicit timeout check to handle different failure modes
if read -t 30 -p "Restartovať teraz? [Y/n] (automaticky za 30s): " response; then
    # User provided input before timeout
    :
else
    # Timeout or other read failure - check exit code
    READ_EXIT=$?
    if [ $READ_EXIT -gt 128 ]; then
        # Timeout (exit code > 128 typically indicates timeout)
        response="y"
        echo "(timeout - automaticky restartujem)"
    else
        # Other failure (interrupted, EOF, etc.) - default to yes for safety
        response="y"
        echo "(interrupted - automaticky restartujem)"
    fi
fi
echo ""

case "$response" in
    [nN]|[nN][oO])
        echo "[*] Reštart preskočený."
        echo "[*] Pre dokončenie inštalácie spustite manuálne:"
        echo "    sudo reboot"
        ;;
    *)
        echo "[*] Zastavujem služby pred reštartom..."
        # Stop any running services gracefully
        systemctl stop getty@tty1.service 2>/dev/null || true
        
        echo "[*] Synchronizujem disky..."
        sync
        
        echo "[*] Reštartujem systém..."
        sleep 3
        reboot
        ;;
esac
