#!/bin/bash
# EduDisplej - Telepítő / Inštalátor
# Futtatás / Spustenie: sudo bash install.sh --token=<API_TOKEN>

set -euo pipefail

API_TOKEN=""
while [[ $# -gt 0 ]]; do
    case $1 in
        --token=*) API_TOKEN="${1#*=}"; shift ;;
        --token)   API_TOKEN="$2"; shift 2 ;;
        *)         shift ;;
    esac
done

if [ -z "$API_TOKEN" ]; then
    echo "[!] Hiányzó token / Chýba token. Használat / Použitie: --token=<API_TOKEN>"
    exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "[!] Szükséges root jogosultság / Potrebné root oprávnenie: sudo bash install.sh --token=..."
    exit 1
fi

TARGET_DIR="/opt/edudisplej"
INIT_BASE="https://install.edudisplej.sk/init"
AUTH_HEADER=( -H "Authorization: Bearer ${API_TOKEN}" )

echo "=========================================="
echo "  EduDisplej - Telepítés / Inštalácia"
echo "=========================================="

# Csomagok telepítése / Inštalácia balíčkov
for pkg in curl surf ca-certificates; do
    if ! dpkg -s "$pkg" >/dev/null 2>&1; then
        echo "[*] Telepítés / Inštalácia: $pkg"
        apt-get install -y -qq "$pkg"
    fi
done

# Könyvtárak / Adresáre
mkdir -p "${TARGET_DIR}/init" "${TARGET_DIR}/localweb" "${TARGET_DIR}/lic" "${TARGET_DIR}/data" "${TARGET_DIR}/logs"

# Token mentése / Uloženie tokenu
echo "$API_TOKEN" > "${TARGET_DIR}/lic/token"
chmod 600 "${TARGET_DIR}/lic/token"

# Fájlok letöltése a szerverről / Stiahnutie súborov zo servera
echo "[*] Fájlok letöltése / Sťahovanie súborov..."
FILES=$(curl -fsSL "${AUTH_HEADER[@]}" "${INIT_BASE}/download.php?getfiles&token=${API_TOKEN}" | tr -d '\r')

while IFS=";" read -r NAME SIZE _; do
    [ -z "${NAME:-}" ] && continue
    curl -fsSL "${AUTH_HEADER[@]}" "${INIT_BASE}/download.php?streamfile=${NAME}&token=${API_TOKEN}" \
        -o "${TARGET_DIR}/init/${NAME}"
    [[ "${NAME}" == *.sh ]] && chmod +x "${TARGET_DIR}/init/${NAME}"
    [[ "${NAME}" == *.html ]] && cp -f "${TARGET_DIR}/init/${NAME}" "${TARGET_DIR}/localweb/${NAME}"
    echo "  [✓] ${NAME}"
done <<< "$FILES"

# Felhasználó felismerése / Detekcia používateľa
CONSOLE_USER="${SUDO_USER:-$(awk -F: '$3==1000{print $1}' /etc/passwd | head -1)}"
USER_HOME="$(getent passwd "$CONSOLE_USER" | cut -d: -f6)"

# Systemd szolgáltatások / Systemd služby
for SVC in edudisplej-kiosk.service edudisplej-sync.service edudisplej-watchdog.service; do
    SRC="${TARGET_DIR}/init/${SVC}"
    [ -f "$SRC" ] || continue
    sed -e "s/User=edudisplej/User=$CONSOLE_USER/g" \
        -e "s|WorkingDirectory=/home/edudisplej|WorkingDirectory=$USER_HOME|g" \
        -e "s|Environment=HOME=/home/edudisplej|Environment=HOME=$USER_HOME|g" \
        "$SRC" > "/etc/systemd/system/${SVC}"
    chmod 644 "/etc/systemd/system/${SVC}"
done

systemctl daemon-reload
for SVC in edudisplej-sync.service edudisplej-watchdog.service; do
    systemctl enable "$SVC" 2>/dev/null && systemctl start "$SVC" 2>/dev/null || true
done
systemctl enable edudisplej-kiosk.service 2>/dev/null || true
systemctl enable getty@tty1.service 2>/dev/null || true

echo ""
echo "[✓] Kész / Hotovo! Felhasználó / Používateľ: $CONSOLE_USER"
echo "[*] Az újraindítás után automatikusan elindul a kiosk mód."
echo "[*] Po reštarte sa automaticky spustí kiosk mód."
echo ""
read -t 30 -p "Újraindítás most? / Reštartovať teraz? [Y/n]: " r || r="y"
[[ "$r" =~ ^[nN] ]] && echo "Manuális újraindítás / Manuálny reštart: sudo reboot" || { sync; reboot; }
