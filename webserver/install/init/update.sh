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

# Kontrola jq / jq ellenorzes
if ! command -v jq >/dev/null 2>&1; then
  echo "[!] jq nie je nainstalovany. Instalujem..."
  apt-get update -qq && apt-get install -y jq >/dev/null 2>&1
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

# Kontrola ci server podporuje structure.json / Ellenorzes hogy a szerver tamogatja-e a structure.json-t
echo "[*] Kontrolujem dostupnost structure.json..."
STRUCTURE_JSON=""
if curl -sf --max-time 10 --connect-timeout 5 "${INIT_BASE}/download.php?getstructure" >/dev/null 2>&1; then
    echo "[*] Pouzivam novu metodu (structure.json)..."
    USE_STRUCTURE=true
    
    # Stiahnuť structure.json / Structure.json letoltese
    STRUCTURE_JSON="$(curl -s --max-time 30 --connect-timeout 10 "${INIT_BASE}/download.php?getstructure" 2>/dev/null | tr -d '\r')"
    CURL_EXIT_CODE=$?
    
    if [ $CURL_EXIT_CODE -ne 0 ] || [ -z "$STRUCTURE_JSON" ]; then
        echo "[!] Chyba pri stiahnovani structure.json, prepínam na staru metodu..."
        USE_STRUCTURE=false
    fi
else
    echo "[*] Server nepodporuje structure.json, pouzivam staru metodu..."
    USE_STRUCTURE=false
fi

if [ "$USE_STRUCTURE" = true ]; then
    # Nova metoda: pouzivat structure.json / Uj modszer: structure.json hasznalata
    
    # Kontrola ci je nainstalovaný jq, inak použijeme python3
    if ! command -v jq >/dev/null 2>&1; then
        if ! command -v python3 >/dev/null 2>&1; then
            echo "[!] CHYBA: Ani jq ani python3 nie su nainstalovane!"
            echo "[!] Instalujem python3..."
            apt-get update -qq && apt-get install -y python3 >/dev/null 2>&1 || {
                echo "[!] Nepodarilo sa nainstalovat python3"
                echo "[!] Prepínam na staru metodu..."
                USE_STRUCTURE=false
            }
        fi
        if [ "$USE_STRUCTURE" = true ]; then
            echo "[*] Pouzivam python3 pre parsovanie JSON..."
            USE_JQ=false
        fi
    else
        USE_JQ=true
    fi
    
    # Parsovanie JSON / JSON elemzes
    if [ "$USE_JQ" = true ]; then
        # Použiť jq pre parsovanie
        TOTAL_FILES=$(echo "$STRUCTURE_JSON" | jq '.files | length')
    else
        # Alternatívny parser bez jq
        TOTAL_FILES=$(echo "$STRUCTURE_JSON" | grep -o '"source"' | wc -l)
    fi
    
    if [ "$TOTAL_FILES" -eq 0 ]; then
        echo "[!] Ziadne subory v structure.json"
        echo "[!] Zaloha zachovana v: $BACKUP_DIR"
        exit 1
    fi
    
    CURRENT_FILE=0
    echo ""
    echo "=========================================="
    echo "Stahovanie: ${TOTAL_FILES} suborov"
    echo "=========================================="
    echo ""
    
    # Stiahnutie a inštalácia súborov podľa structure.json
    # Letoltes es telepites structure.json szerint
    for i in $(seq 0 $((TOTAL_FILES - 1))); do
        if [ "$USE_JQ" = true ]; then
            SOURCE=$(echo "$STRUCTURE_JSON" | jq -r ".files[$i].source")
            DESTINATION=$(echo "$STRUCTURE_JSON" | jq -r ".files[$i].destination")
            PERMISSIONS=$(echo "$STRUCTURE_JSON" | jq -r ".files[$i].permissions")
        else
            # Alternatívny parser pomocou python3 - parsovanie jedným volaním
            read -r SOURCE DESTINATION PERMISSIONS < <(echo "$STRUCTURE_JSON" | python3 -c "
import sys, json
try:
    data = json.load(sys.stdin)
    f = data['files'][$i]
    print(f'{f[\"source\"]} {f[\"destination\"]} {f[\"permissions\"]}')
except:
    print('')
" 2>/dev/null)
        fi
        
        [ -z "$SOURCE" ] && continue
        
        CURRENT_FILE=$((CURRENT_FILE + 1))
        PERCENT=$((CURRENT_FILE * 100 / TOTAL_FILES))
        
        echo "[${CURRENT_FILE}/${TOTAL_FILES}] (${PERCENT}%) ${SOURCE} -> ${DESTINATION}"
        
        # Vytvorenie cieľového adresára / Cel konyvtar letrehozasa
        DEST_DIR=$(dirname "$DESTINATION")
        if [ ! -d "$DEST_DIR" ]; then
            mkdir -p "$DEST_DIR"
        fi
        
        # Stiahnutie suboru / Fajl letoltese
        MAX_DOWNLOAD_ATTEMPTS=3
        DOWNLOAD_SUCCESS=false
        # Sanitizovanie názvu pre dočasný súbor / Sanitized file name
        SAFE_SOURCE=$(basename "$SOURCE")
        TEMP_FILE="/tmp/edudisplej_download_$$_${SAFE_SOURCE}"
        
        for attempt in $(seq 1 $MAX_DOWNLOAD_ATTEMPTS); do
            if curl -sL --max-time 60 --connect-timeout 10 \
                "${INIT_BASE}/download.php?streamfile=${SOURCE}" \
                -o "$TEMP_FILE" 2>&1; then
                
                # Kontrola ci sa subor stiahol / Ellenorzes hogy letoltodott-e
                if [ -f "$TEMP_FILE" ] && [ -s "$TEMP_FILE" ]; then
                    DOWNLOAD_SUCCESS=true
                    break
                else
                    echo "    [!] Subor je prazdny alebo sa nevytvoril"
                    if [ $attempt -lt $MAX_DOWNLOAD_ATTEMPTS ]; then
                        sleep 2
                        rm -f "$TEMP_FILE"
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
            echo "[!] CHYBA: Subor $SOURCE sa nepodarilo stiahnut po ${MAX_DOWNLOAD_ATTEMPTS} pokusoch"
            echo "[!] Obnovujem zalohu..."
            rm -f "$TEMP_FILE"
            rm -rf "$TARGET_DIR"
            mv "$BACKUP_DIR" "$TARGET_DIR"
            echo "[!] Zaloha obnovena"
            exit 1
        fi
        
        # Oprava konca riadkov / Sorvegek javitasa
        sed -i 's/\r$//' "$TEMP_FILE"
        
        # Presunúť na cieľové miesto / Celhelyre mozgatas
        mv -f "$TEMP_FILE" "$DESTINATION"
        
        # Nastavenie opravneni / Jogok beallitasa
        chmod "$PERMISSIONS" "$DESTINATION"
        
        # Kontrola a oprava shebang pre shell skripty / Shebang ellenorzes shell scripteknel
        if [[ "${SOURCE}" == *.sh ]]; then
            FIRST_LINE="$(head -n1 "$DESTINATION" || true)"
            if [[ "${FIRST_LINE}" != "#!"* ]]; then
                sed -i '1i #!/bin/bash' "$DESTINATION"
            fi
        fi
    done
    
else
    # Stara metoda: pouzivat getfiles / Regi modszer: getfiles hasznalata
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
fi

# ============================================================================
# SERVICE INSTALLATION / SZOLGALTATASOK TELEPITESE
# ============================================================================

install_services() {
    echo ""
    echo "=========================================="
    echo "Service fajlok telepitese / Installing services"
    echo "=========================================="
    echo ""
    
    # Check if structure.json has services section
    if [ "$USE_STRUCTURE" != true ] || [ -z "$STRUCTURE_JSON" ]; then
        echo "[*] Struktura JSON nie je dostupna, preskakujem service instalaciu"
        return 0
    fi
    
    # Parse services from structure.json
    if ! command -v python3 >/dev/null 2>&1; then
        echo "[!] VAROVANIE: python3 nie je dostupny, preskakujem service instalaciu"
        return 0
    fi
    
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
    
    # Install each service
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
        
        # Copy service file
        if cp "$SOURCE_PATH" "$DEST_PATH" 2>/dev/null; then
            chmod 644 "$DEST_PATH"
            echo "  [✓] Skopirovany do: $DEST_PATH"
        else
            echo "  [!] CHYBA: Nepodarilo sa skopirovat service file"
            continue
        fi
        
        # Reload systemd
        if systemctl daemon-reload 2>/dev/null; then
            echo "  [✓] systemd daemon-reload"
        else
            echo "  [!] VAROVANIE: daemon-reload zlyhal"
        fi
        
        # Verify service exists
        if systemctl list-unit-files | grep -q "^$name"; then
            echo "  [✓] Service existuje v systemd"
        else
            echo "  [!] CHYBA: Service nebol najdeny v systemd"
            continue
        fi
        
        # Enable service if required
        if [ "$enabled" = "True" ] || [ "$enabled" = "true" ]; then
            if systemctl enable "$name" 2>/dev/null; then
                echo "  [✓] Service enabled (automaticky start pri boote)"
            else
                echo "  [!] VAROVANIE: enable zlyhal"
            fi
        else
            echo "  [*] Service nie je enabled (manualne ovladanie)"
        fi
        
        # Start service if required
        if [ "$autostart" = "True" ] || [ "$autostart" = "true" ]; then
            # Stop first if already running
            systemctl stop "$name" 2>/dev/null || true
            sleep 1
            
            if systemctl start "$name" 2>/dev/null; then
                echo "  [✓] Service spusteny"
                
                # Wait a moment and check status
                sleep 2
                if systemctl is-active --quiet "$name"; then
                    echo "  [✓] Service bezi aktivne"
                else
                    echo "  [!] VAROVANIE: Service nie je aktivny"
                    echo "  [!] Skontrolujte logy: journalctl -u $name -n 20"
                fi
            else
                echo "  [!] CHYBA: Nepodarilo sa spustit service"
                echo "  [!] Skontrolujte logy: journalctl -u $name -n 20"
            fi
        else
            echo "  [*] Service nie je spusteny (autostart vypnuty)"
        fi
        
        echo ""
        
    done <<< "$SERVICES_JSON"
    
    echo "[✓] Service instalacia dokoncena"
    echo ""
}

# After file installation, install services
install_services

# ============================================================================
# REFRESH LOOP PLAYER / LOOP LEJATSZO FRISSITESE
# ============================================================================

echo ""
echo "=========================================="
echo "[*] Modulok frissitese es loop player ujrageneralasa"
echo "=========================================="

DOWNLOAD_SCRIPT="${INIT_DIR}/edudisplej-download-modules.sh"
TERMINAL_SCRIPT="${INIT_DIR}/edudisplej_terminal_script.sh"

if [ -f "$DOWNLOAD_SCRIPT" ]; then
    chmod +x "$DOWNLOAD_SCRIPT" 2>/dev/null || true
    echo "[*] Futtatom: $DOWNLOAD_SCRIPT"
    if bash "$DOWNLOAD_SCRIPT"; then
        echo "[✓] Modulok frissitve"
    else
        echo "[!] Hiba a modulok frissitese kozben"
    fi
else
    echo "[!] Nem talalhato: $DOWNLOAD_SCRIPT"
fi

if [ -f "$TERMINAL_SCRIPT" ]; then
    chmod +x "$TERMINAL_SCRIPT" 2>/dev/null || true
    echo "[*] Kiosk terminal script inditasa (background)"
    nohup "$TERMINAL_SCRIPT" >/opt/edudisplej/logs/update_terminal_script.log 2>&1 &
else
    echo "[!] Nem talalhato: $TERMINAL_SCRIPT"
fi

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
    mapfile -t temp_pids < <(pgrep -x "$process" 2>/dev/null || true)
    # Filter out empty values and add to main array
    for pid in "${temp_pids[@]}"; do
        [[ -n "$pid" ]] && KIOSK_PIDS+=("$pid")
    done
done

if [ ${#KIOSK_PIDS[@]} -gt 0 ]; then
    # TERM signal first
    for pid in "${KIOSK_PIDS[@]}"; do
        kill -TERM "$pid" 2>/dev/null || true
    done
    
    sleep 2
    
    # Force kill if still running
    for pid in "${KIOSK_PIDS[@]}"; do
        if kill -0 "$pid" 2>/dev/null; then
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
