
#!/bin/bash
TARGET_DIR="/opt/edudisplej"
INIT_DIR="${TARGET_DIR}/init"
INIT_BASE="https://install.edudisplej.sk/init"

echo "[*] Kontrola opravneni root..."
if [ "$(id -u)" -ne 0 ]; then
  echo "[!] Spusti skript s sudo!"
  exit 1
fi

# Ellenorzes: curl telepitve van?
if ! command -v curl >/dev/null; then
  echo "[*] Instalacia curl..."
  apt-get update && apt-get install -y curl
fi

# GUI ellenorzes (csak info)
echo "[*] Kontrola GUI prostredia..."
if pgrep -x "Xorg" >/dev/null; then
    echo "[*] GUI bezi."
    GUI_AVAILABLE=true
else
    echo "[!] GUI sa nenasiel. Pokracujeme bez grafiky."
    GUI_AVAILABLE=false
fi

# Ha letezik a celkonyvtar, backup keszitese
if [ -d "$TARGET_DIR" ]; then
  BACKUP="${TARGET_DIR}.bak.$(date +%s)"
  echo "[*] Zalohovanie: $TARGET_DIR -> $BACKUP"
  mv "$TARGET_DIR" "$BACKUP"
fi

# Init konyvtar letrehozasa
mkdir -p "$INIT_DIR"

echo "[*] Nacitavame zoznam suborov : ${INIT_BASE}/download.php?getfiles"
FILES_LIST=$(curl -s "${INIT_BASE}/download.php?getfiles" | tr -d '\r')

if [ -z "$FILES_LIST" ]; then
  echo "[!] Chyba: Nepodarilo sa nacitat zoznam suborov."
  exit 1
fi

echo "[DEBUG] Zoznam suborov:"
echo "$FILES_LIST"

# Letoltes egyenkent + CRLF javitas + shebang ellenorzes
echo "$FILES_LIST" | while IFS=";" read -r NAME SIZE MODIFIED; do
    [ -z "$NAME" ] && continue

    echo "[*] Stahovanie: $NAME ($SIZE bajtov)"
    curl -sL "${INIT_BASE}/download.php?streamfile=${NAME}" -o "${INIT_DIR}/${NAME}" || {
        echo "[!] Chyba: Stahovanie $NAME zlyhalo."
        exit 1
    }

    # Sorvegek javitasa (CRLF -> LF)
    sed -i 's/\r$//' "${INIT_DIR}/${NAME}"

    # Ha .sh fajl, ellenorizzuk a shebang-et
    if [[ "${NAME}" == *.sh ]]; then
        chmod +x "${INIT_DIR}/${NAME}"
        FIRST_LINE=$(head -n1 "${INIT_DIR}/${NAME}")
        if [[ "$FIRST_LINE" != "#!"* ]]; then
            echo "[!] Shebang hianyzik, hozzaadjuk: #!/bin/bash"
            sed -i '1i #!/bin/bash' "${INIT_DIR}/${NAME}"
        fi
    fi
done

# Ellenorzes: edudisplej-init.sh letezik?
if [ ! -f "${INIT_DIR}/edudisplej-init.sh" ]; then
    echo "[!] Chyba: edudisplej-init.sh sa nenachadza medzi stiahnutymi subormi."
    exit 1
fi

# Jogosultsagok beallitasa
chmod -R 755 "$TARGET_DIR"

# Konzol felhasznalo meghatarozasa (altalaban pi)
CONSOLE_USER=$(awk -F: '$3==1000{print $1}' /etc/passwd | head -n1)
[ -z "$CONSOLE_USER" ] && CONSOLE_USER="pi"

echo "[*] Nastavenie autologinu pre uzivatela: $CONSOLE_USER"
mkdir -p /etc/systemd/system/getty@tty1.service.d
cat > /etc/systemd/system/getty@tty1.service.d/autologin.conf <<EOF
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin $CONSOLE_USER --noclear %I \$TERM
EOF

systemctl daemon-reload
systemctl restart getty@tty1.service

# Systemd service letrehozasa (ha van GUI, XAUTHORITY beallitva)

cat > /etc/systemd/system/edudisplej-init.service <<EOF
[Unit]
Description=EduDisplej Init (Console Mode)
After=multi-user.target network-online.target

[Service]
ExecStart=${INIT_DIR}/edudisplej-init.sh
WorkingDirectory=${INIT_DIR}
Restart=always
StandardInput=tty
StandardOutput=tty
TTYPath=/dev/tty1

[Install]
WantedBy=multi-user.target
EOF


# Systemd ujratoltese es engedelyezese
systemctl daemon-reload
systemctl enable --now edudisplej-init.service

echo "[✓] Instalacia dokoncena. Restart za 10 sekund..."
sleep 10
reboot
