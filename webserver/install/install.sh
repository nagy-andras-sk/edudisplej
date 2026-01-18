
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

# --- KIOSK NA TTY1: BEZ GETTY / BEZ AUTOLOGIN ---
# Odstránime prípadnú starú autologin konfiguráciu pre getty@tty1 (ak by tam bola)
if [ -d /etc/systemd/system/getty@tty1.service.d ]; then
  echo "[*] Odstranujem /etc/systemd/system/getty@tty1.service.d (autologin conf)..."
  rm -rf /etc/systemd/system/getty@tty1.service.d
fi

# Vytvoríme systemd službu, ktorá si tty1 výlučne drží
cat > /etc/systemd/system/edudisplej-init.service <<'EOF'
[Unit]
Description=EduDisplej Init (Console Kiosk on tty1)
After=network-online.target
Wants=network-online.target
Conflicts=getty@tty1.service

[Service]
Type=simple
ExecStart=/opt/edudisplej/init/edudisplej-init.sh
WorkingDirectory=/opt/edudisplej/init
Restart=on-failure
RestartSec=2

# TTY pinning
StandardInput=tty
StandardOutput=tty
TTYPath=/dev/tty1
TTYReset=yes
TTYVHangup=yes
#TTYVTDisallocate=yes

[Install]
WantedBy=multi-user.target
EOF

# Systemd reload
systemctl daemon-reload

# Zakážeme getty na tty1 (nech tam nikdy nevyskočí shell)
echo "[*] getty@tty1 disable + stop"
systemctl disable --now getty@tty1.service || true

# Povolíme kiosk službu
echo "[*] edudisplej-init.service enable + start"
systemctl enable --now edudisplej-init.service

# Install minimal kiosk service
echo "[*] Installing minimal kiosk service..."
cp "${INIT_DIR}/chromiumkiosk-minimal.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable chromiumkiosk-minimal.service
echo "[✓] Service enabled"

echo ""
echo "=========================================="
echo "Telepítés kész! / Installation Complete!"
echo "=========================================="
echo ""
echo "Újraindításhoz / To reboot: sudo reboot"
echo "Szolgáltatás indítása / Start service: sudo systemctl start chromiumkiosk-minimal"
echo "Logok / Logs: /opt/edudisplej/kiosk.log, /opt/edudisplej/service.log"
echo ""
echo "[✓] Instalacia dokoncena. Restart za 10 sekund..."
sleep 10
reboot
