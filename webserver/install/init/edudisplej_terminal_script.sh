#!/bin/bash
# EduDisplej Terminal Script - Simplified kiosk launcher
# =============================================================================

set -euo pipefail

SERVICE_VERSION="1.1.1"
CONFIG_DIR="/opt/edudisplej"
INIT_DIR="${CONFIG_DIR}/init"
LOCAL_WEB_DIR="${CONFIG_DIR}/localweb"
KIOSK_CONF="${CONFIG_DIR}/kiosk.conf"
WAITING_PAGE="${INIT_DIR}/waiting_registration.html"
LOOP_PLAYER="${LOCAL_WEB_DIR}/loop_player.html"
TERMINAL_LOG="/opt/edudisplej/logs/terminal_script.log"
MONITOR_INTERVAL=10
SURF_RESTART_DELAY=5

mkdir -p "$(dirname "$TERMINAL_LOG")" 2>/dev/null || true

log_terminal() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$TERMINAL_LOG"
}

render_waiting_page() {
    local target="/tmp/waiting_registration_with_hostname.html"
    local hostname_value
    hostname_value=$(hostname)

    if [ -f "$WAITING_PAGE" ]; then
        cp "$WAITING_PAGE" "$target"
        sed -i "s/%%HOSTNAME%%/$hostname_value/g" "$target" 2>/dev/null || true
        if ! grep -q "$hostname_value" "$target" 2>/dev/null; then
            sed -i "s/<span id=\"hostname\">loading\.\.\.<\/span>/<span id=\"hostname\">$hostname_value<\/span>/g" "$target" 2>/dev/null || true
        fi
        echo "$target"
        return 0
    fi

    cat > "$target" <<EOF
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>EduDisplej</title>
    <style>
        html, body { margin: 0; width: 100%; height: 100%; overflow: hidden; background: #0f172a; color: #fff; font-family: Segoe UI, Arial, sans-serif; }
        body { display: grid; place-items: center; }
        .card { max-width: 720px; padding: 32px; text-align: center; }
        h1 { margin: 0 0 12px 0; font-size: 40px; }
        p { margin: 8px 0; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Ez a kijelzo meg nincs konfigurálva</h1>
        <p>Kérjük, rendeld hozzá a vezérlőpultban.</p>
        <p>Hostname: ${hostname_value}</p>
    </div>
</body>
</html>
EOF
    echo "$target"
}

render_recovery_page() {
    local target="/tmp/edudisplej_recovery.html"
    local message="$1"

    cat > "$target" <<EOF
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>EduDisplej Recovery</title>
    <style>
        html, body { margin: 0; width: 100%; height: 100%; overflow: hidden; background: #070b14; color: #e7eefb; font-family: Segoe UI, Arial, sans-serif; }
        body { display: grid; place-items: center; }
        .card { max-width: 860px; padding: 32px; border: 1px solid rgba(95, 140, 214, 0.35); border-radius: 16px; background: rgba(9, 14, 27, 0.78); text-align: center; }
        h1 { margin: 0 0 12px 0; color: #8ec5ff; }
        p { margin: 0; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <h1>EduDisplej</h1>
        <p>${message}</p>
    </div>
</body>
</html>
EOF
    echo "$target"
}

main() {
    log_terminal "=== EduDisplej Terminal Script ${SERVICE_VERSION} ==="

    while true; do
        if [ ! -f "$KIOSK_CONF" ]; then
            log_terminal "Device not registered - showing waiting page"
            surf -F "file://$(render_waiting_page)" || true
            sleep "$MONITOR_INTERVAL"
            continue
        fi

        if [ ! -f "$LOOP_PLAYER" ]; then
            log_terminal "Loop player missing - showing recovery page"
            surf -F "file://$(render_recovery_page 'A kijelzo tartalom ideiglenesen nem elerheto. A rendszer automatikusan probal helyreallni.')" || true
            sleep "$MONITOR_INTERVAL"
            continue
        fi

        if [[ "$LOOP_PLAYER" == *.json ]]; then
            log_terminal "ERROR: LOOP_PLAYER points to JSON, forcing HTML fallback"
            LOOP_PLAYER="${LOCAL_WEB_DIR}/loop_player.html"
        fi

        log_terminal "Launching surf fullscreen: file://${LOOP_PLAYER}"
        surf -F "file://${LOOP_PLAYER}" || true
        log_terminal "Surf browser exited"
        sleep "$SURF_RESTART_DELAY"
    done
}

main "$@"
