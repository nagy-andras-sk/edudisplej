#!/bin/bash
set -euo pipefail

# Zakladne nastavenia / Alapbeallitasok
TARGET_DIR="/opt/edudisplej"
INIT_DIR="${TARGET_DIR}/init"
LOCAL_WEB_DIR="${TARGET_DIR}/localweb"
INIT_BASE="https://install.edudisplej.sk/init"
SERVICE_FILE="/etc/systemd/system/edudisplej-kiosk.service"

# Chybove spravy / Hibakezelés
cleanup_on_error() {
    local exit_code=$?
    if [ $exit_code -ne 0 ]; then
        echo ""
        echo "[!] CHYBA: Instalacia zlyhala (kod: $exit_code)"
        echo ""
        echo "Riesenia / Megoldasok:"
        echo "  1. Skontrolujte internetove pripojenie"
        echo "  2. Skuste znova"
        echo ""
    fi
    stop_heartbeat
}

trap cleanup_on_error EXIT

# Ukazovatel pokroku / Folaymat jelzo
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

# Kontrola root opravneni / Root jogok ellenorzese
echo "[*] Kontrola opravneni root..."
if [ "$(id -u)" -ne 0 ]; then
  echo "[!] Spusti skript s sudo!"
  exit 1
fi

# Detekcia architektury / Architektura felismerese
ARCH="$(uname -m)"
echo "[*] Architektura / Architektura: $ARCH"
# Midori böngésző - összes architektúrához
KIOSK_MODE="midori"

# Instalacia curl ak chyba / Curl telepites ha hianyzik
if ! command -v curl >/dev/null 2>&1; then
  echo "[*] Instalacia curl..."
  start_heartbeat
  apt-get update -qq && apt-get install -y curl >/dev/null 2>&1
  stop_heartbeat
  echo "[✓] curl nainstalovany"
fi

# Vzdy prepisat cielovy priecinok / Mindig felulirjuk a celkonyvtart
if [ -d "$TARGET_DIR" ]; then
  echo "[*] Mazanie existujuceho adresara / Meglevo konyvtar torlese: $TARGET_DIR"
  rm -rf "$TARGET_DIR"
fi

# Vytvorenie priecinkov / Konyvtarak letrehozasa
mkdir -p "$INIT_DIR" "$LOCAL_WEB_DIR"

# Nacitanie zoznamu suborov / Fajlok listajanak letoltese
echo "[*] Nacitavame zoznam suborov..."

FILES_LIST="$(curl -s --max-time 30 --connect-timeout 10 "${INIT_BASE}/download.php?getfiles" 2>/dev/null | tr -d '\r')"
CURL_EXIT_CODE=$?

if [ $CURL_EXIT_CODE -ne 0 ]; then
  echo "[!] Chyba pripojenia k serveru (kod: $CURL_EXIT_CODE)"
  exit 1
fi

if [ -z "$FILES_LIST" ]; then
  echo "[!] Server vratil prazdny zoznam"
  exit 1
fi

# Pocet suborov / Fajlok szama
TOTAL_FILES=0
while IFS= read -r line; do
    if [[ -n "$line" && "$line" == *";"* ]]; then
        TOTAL_FILES=$((TOTAL_FILES + 1))
    fi
done <<< "$FILES_LIST"

if [ $TOTAL_FILES -eq 0 ]; then
    echo "[!] Ziadne subory na stiahnutie"
    exit 1
fi

CURRENT_FILE=0
echo ""
echo "=========================================="
echo "Stahovanie: ${TOTAL_FILES} suborov"
echo "=========================================="
echo ""

# Stiahnutie suborov / Fajlok letoltese
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
                echo "    [!] Velkost nesedi (ocakavane: ${SIZE}, skutocne: ${ACTUAL_SIZE})"
                if [ $attempt -lt $MAX_DOWNLOAD_ATTEMPTS ]; then
                    sleep 2
                    rm -f "${INIT_DIR}/${NAME}"
                fi
            fi
        else
            if [ $attempt -lt $MAX_DOWNLOAD_ATTEMPTS ]; then
                echo "    [!] Pokus ${attempt}/${MAX_DOWNLOAD_ATTEMPTS} zlyhal, skusam znova..."
                sleep 2
            fi
        fi
    done
    
    if [ "$DOWNLOAD_SUCCESS" != "true" ]; then
        echo ""
        echo "[!] CHYBA: Subor $NAME sa nepodarilo stiahnut po ${MAX_DOWNLOAD_ATTEMPTS} pokusoch"
        echo ""
        exit 1
    fi

    # Oprava konca riadkov / Sorvegek javitasa
    sed -i 's/\r$//' "${INIT_DIR}/${NAME}"

    # Kontrola a oprava shebang / Shebang ellenorzes es javitas
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

# Kontrola edudisplej-init.sh / Ellenorzes
if [ ! -f "${INIT_DIR}/edudisplej-init.sh" ]; then
    echo "[!] Chyba: edudisplej-init.sh chyba"
    exit 1
fi

# Nastavenie opravneni / Jogok beallitasa
chmod -R 755 "$TARGET_DIR"

# Urcenie pouzivatela / Felhasznalo meghatarozasa
CONSOLE_USER="$(awk -F: '$3==1000{print $1}' /etc/passwd | head -n1 || true)"
[ -z "${CONSOLE_USER}" ] && CONSOLE_USER="pi"

USER_HOME="$(getent passwd "$CONSOLE_USER" | cut -d: -f6)"
if [ -z "$USER_HOME" ]; then
    echo "[!] Domovsky adresar nenajdeny pre: $CONSOLE_USER"
    exit 1
fi
echo "[*] Pouzivatel / Felhasznalo: $CONSOLE_USER, Domov: $USER_HOME"

# Ulozenie nastaveni / Beallitasok mentese
echo "$KIOSK_MODE" > "${TARGET_DIR}/.kiosk_mode"
echo "$CONSOLE_USER" > "${TARGET_DIR}/.console_user"
echo "$USER_HOME" > "${TARGET_DIR}/.user_home"

# Instalacia systemd sluzby / Systemd szolgaltatas telepitese
echo "[*] Instalacia systemd sluzby..."

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
    echo "[!] CHYBA: Service subor nenajdeny"
fi

# Sudo konfiguracia / Sudo konfiguracio
echo "[*] Konfiguracia sudo..."
mkdir -p /etc/sudoers.d
cat > /etc/sudoers.d/edudisplej <<EOF
$CONSOLE_USER ALL=(ALL) NOPASSWD: /opt/edudisplej/init/edudisplej-init.sh
EOF
chmod 0440 /etc/sudoers.d/edudisplej

# Deaktivacia getty@tty1 / Getty letiltasa
systemctl disable getty@tty1.service 2>/dev/null || true

# Aktivacia sluzby / Szolgaltatas aktivalasa
if [ -f "$SERVICE_FILE" ]; then
    systemctl daemon-reload
    systemctl enable edudisplej-kiosk.service 2>/dev/null || true
fi

# Watchdog sluzba / Watchdog szolgaltatas
echo "[*] Instalacia watchdog..."
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
echo "Instalacia ukoncena / Telepites kesz!"
echo "=========================================="
echo ""
echo "[✓] Konfiguracia / Konfiguracio:"
echo "  - Kiosk mod / Kiosk mod: $KIOSK_MODE"
echo "  - Pouzivatel / Felhasznalo: $CONSOLE_USER"
echo ""
echo "Po restarte system automaticky spusti displej"
echo "Restart utan a rendszer automatikusan elindul"
echo ""
echo "=========================================="
echo ""

# Restart / Ujrainditas
if read -t 30 -p "Restartovat teraz? [Y/n] (automaticky za 30s): " response; then
    :
else
    READ_EXIT=$?
    if [ $READ_EXIT -gt 128 ]; then
        response="y"
        echo "(automaticky restartujem)"
    else
        response="y"
    fi
fi
echo ""

case "$response" in
    [nN]|[nN][oO])
        echo "[*] Restart preskoceny. Spustite: sudo reboot"
        ;;
    *)
        echo "[*] Zastavujem sluzby..."
        systemctl stop getty@tty1.service 2>/dev/null || true
        echo "[*] Synchronizujem disky..."
        sync
        echo "[*] Restartujem..."
        sleep 3
        reboot
        ;;
esac
