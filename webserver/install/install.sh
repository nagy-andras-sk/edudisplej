#!/bin/bash
set -euo pipefail

TARGET_DIR="/opt/edudisplej"
INIT_DIR="${TARGET_DIR}/init"
LOCAL_WEB_DIR="${TARGET_DIR}/localweb"
INIT_BASE="https://install.edudisplej.sk/init"

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
  apt-get update && apt-get install -y curl
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
FILES_LIST="$(curl -s "${INIT_BASE}/download.php?getfiles" | tr -d '\r')"

if [ -z "$FILES_LIST" ]; then
  echo "[!] Chyba: Nepodarilo sa nacitat zoznam suborov."
  exit 1
fi

echo "[DEBUG] Zoznam suborov:"
echo "$FILES_LIST"

# Stiahnutie jednotlivo + CRLF oprava + kontrola shebang
# DÔLEŽITÉ: while bez pipe (aby exit vo vnútri ukončil skript)
while IFS=";" read -r NAME SIZE MODIFIED; do
    [ -z "${NAME:-}" ] && continue

    echo "[*] Stahovanie: $NAME ($SIZE bajtov)"
    curl -sL "${INIT_BASE}/download.php?streamfile=${NAME}" -o "${INIT_DIR}/${NAME}" || {
        echo "[!] Chyba: Stahovanie $NAME zlyhalo."
        exit 1
    }

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

# Install and enable edudisplej-init service
echo "[*] Installing edudisplej-init systemd service..."
if [ -f "${INIT_DIR}/edudisplej-init.service" ]; then
    cp "${INIT_DIR}/edudisplej-init.service" /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable edudisplej-init.service
    echo "[*] edudisplej-init service enabled"
else
    echo "[!] Warning: edudisplej-init.service not found"
fi

# Configure autologin on tty1
echo "[*] Configuring autologin for $CONSOLE_USER on tty1..."
mkdir -p /etc/systemd/system/getty@tty1.service.d
cat > /etc/systemd/system/getty@tty1.service.d/autologin.conf <<EOF
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin $CONSOLE_USER --noclear %I 38400 linux
EOF
systemctl daemon-reload
echo "[*] Autologin configured for $CONSOLE_USER"

echo ""
echo "=========================================="
echo "Telepítés kész! / Installation Complete!"
echo "=========================================="
echo ""
echo "Files downloaded and configured!"
echo "Kiosk mode: $KIOSK_MODE"
echo "User: $CONSOLE_USER"
echo ""
echo "After reboot, the init script will:"
echo "  - Install required packages (X11, browser, utilities)"
echo "  - Configure kiosk mode automatically"
echo "  - Start the display system"
echo ""
echo "Logok / Logs: /opt/edudisplej/session.log, /opt/edudisplej/apt.log"
echo ""
echo "[✓] Instalacia dokoncena. Restart za 10 sekund..."
sleep 10
reboot
