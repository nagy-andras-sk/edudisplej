#!/bin/bash
# update.sh - Aktualizacia systemu / Rendszer frissites

set -euo pipefail

# Zakladne nastavenia / Alapbeallitasok
TARGET_DIR="/opt/edudisplej"
INIT_DIR="${TARGET_DIR}/init"
LOCAL_WEB_DIR="${TARGET_DIR}/localweb"
INIT_BASE="https://install.edudisplej.sk/init"
BACKUP_DIR="${TARGET_DIR}.backup.$(date +%s)"

# Kontrola root opravneni / Root jogok ellenorzese
echo "[*] Kontrola opravneni root..."
if [ "$(id -u)" -ne 0 ]; then
  echo "[!] Spusti skript s sudo!"
  exit 1
fi

# Kontrola ci je system nainstalovany / Ellenorzes hogy telepitve van-e
if [ ! -d "$TARGET_DIR" ]; then
  echo "[!] EduDisplej nie je nainstalovany v $TARGET_DIR"
  echo "[!] Najprv spustite install.sh"
  exit 1
fi

# Kontrola curl / Curl ellenorzes
if ! command -v curl >/dev/null 2>&1; then
  echo "[!] curl nie je nainstalovany. Instalujem..."
  apt-get update -qq && apt-get install -y curl >/dev/null 2>&1
fi

echo ""
echo "=========================================="
echo "EduDisplej - Aktualizacia / Frissites"
echo "=========================================="
echo ""
echo "[*] Vytvaranie zalohy..."
echo "    Zaloha: $BACKUP_DIR"

# Vytvorenie zalohy / Biztonsagi mentes
cp -r "$TARGET_DIR" "$BACKUP_DIR"

# Nacitanie zoznamu suborov / Fajlok listajanak letoltese
echo "[*] Nacitavame zoznam suborov..."

FILES_LIST="$(curl -s --max-time 30 --connect-timeout 10 "${INIT_BASE}/download.php?getfiles" 2>/dev/null | tr -d '\r')"
CURL_EXIT_CODE=$?

if [ $CURL_EXIT_CODE -ne 0 ]; then
  echo "[!] Chyba pripojenia k serveru (kod: $CURL_EXIT_CODE)"
  echo "[!] Zaloha zachovana v: $BACKUP_DIR"
  exit 1
fi

if [ -z "$FILES_LIST" ]; then
  echo "[!] Server vratil prazdny zoznam"
  echo "[!] Zaloha zachovana v: $BACKUP_DIR"
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
        echo "[!] Obnovujem zalohu..."
        rm -rf "$TARGET_DIR"
        mv "$BACKUP_DIR" "$TARGET_DIR"
        echo "[!] Zaloha obnovena"
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

# Nastavenie opravneni / Jogok beallitasa
chmod -R 755 "$TARGET_DIR"

echo ""
echo "=========================================="
echo "[✓] Aktualizacia dokoncena / Frissites kesz!"
echo "=========================================="
echo ""
echo "[*] Zaloha: $BACKUP_DIR"
echo ""

# Restart sluzieb / Szolgaltatasok ujrainditasa
echo "[*] Restartujem sluzby / Szolgaltatasok ujrainditasa..."

# Najdenie vsetkych sluzieb edudisplej / Osszes edudisplej szolgaltatas megkeresese
SERVICES=$(systemctl list-units --type=service --all | grep '^[[:space:]]*edudisplej' | awk '{print $1}')

if [ -n "$SERVICES" ]; then
    echo "[*] Zastavujem sluzby..."
    for service in $SERVICES; do
        echo "    - $service"
        systemctl stop "$service" 2>/dev/null || true
    done
    
    sleep 2
    
    echo "[*] Spustam sluzby..."
    for service in $SERVICES; do
        echo "    - $service"
        systemctl start "$service" 2>/dev/null || true
    done
    
    echo ""
    echo "[✓] Sluzby restartovane"
else
    echo "[!] Ziadne sluzby edudisplej nenajdene"
    echo "[!] Mozno je potrebny manualy restart"
fi

# Restart display surface / Restart zobrazenej plochy
echo ""
echo "[*] Restartujem zobrazenu plochu / Restarting display surface..."

# Stop kiosk processes - use multiple pgrep calls for reliability
echo "[*] Zastavujem kiosk procesy..."
declare -a KIOSK_PIDS=()

for process in surf xterm openbox Xorg xinit; do
    pids=$(pgrep -x "$process" 2>/dev/null || true)
    if [ -n "$pids" ]; then
        while IFS= read -r pid; do
            KIOSK_PIDS+=("$pid")
        done <<< "$pids"
    fi
done

if [ ${#KIOSK_PIDS[@]} -gt 0 ]; then
    # TERM signal first
    for pid in "${KIOSK_PIDS[@]}"; do
        [ -n "$pid" ] && kill -TERM "$pid" 2>/dev/null || true
    done
    
    sleep 2
    
    # Force kill if still running
    for pid in "${KIOSK_PIDS[@]}"; do
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            kill -KILL "$pid" 2>/dev/null || true
        fi
    done
    
    echo "[✓] Kiosk procesy zastavene"
else
    echo "[*] Ziadne kiosk procesy nenajdene"
fi

# Restart kiosk service to reload display
if systemctl is-enabled edudisplej-kiosk.service >/dev/null 2>&1; then
    echo "[*] Restartujem edudisplej-kiosk.service..."
    systemctl restart edudisplej-kiosk.service 2>/dev/null || true
    echo "[✓] Displej restartovany"
else
    echo "[!] edudisplej-kiosk.service nie je aktivovana"
fi

echo ""
echo "=========================================="
echo "[✓] Hotovo / Kesz!"
echo "=========================================="
echo ""
echo "Stary backup mozete zmazat pomocou:"
echo "  sudo rm -rf $BACKUP_DIR"
echo ""
