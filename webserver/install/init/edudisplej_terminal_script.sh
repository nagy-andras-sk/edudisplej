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
LOOP_FILE="${LOCAL_WEB_DIR}/modules/loop.json"
OFF_MODE_POLL_INTERVAL=5

mkdir -p "$(dirname "$TERMINAL_LOG")" 2>/dev/null || true

log_terminal() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$TERMINAL_LOG"
}

resolve_local_schedule_mode() {
    if [ ! -f "$LOOP_FILE" ]; then
        echo "UNKNOWN"
        return 0
    fi

    if ! command -v python3 >/dev/null 2>&1; then
        echo "UNKNOWN"
        return 0
    fi

    python3 - "$LOOP_FILE" <<'PY'
import json
import sys
from datetime import datetime

loop_file = sys.argv[1]

try:
    with open(loop_file, 'r', encoding='utf-8', errors='ignore') as fh:
        data = json.load(fh)
except Exception:
    print('UNKNOWN')
    raise SystemExit(0)

def parse_time_to_seconds(value):
    text = str(value or '00:00:00')
    parts = text.split(':')
    try:
        h = int(parts[0]) if len(parts) > 0 else 0
        m = int(parts[1]) if len(parts) > 1 else 0
        s = int(parts[2]) if len(parts) > 2 else 0
    except Exception:
        return 0
    return (h * 3600) + (m * 60) + s

def is_block_active(block, now):
    if not isinstance(block, dict):
        return False

    if int(block.get('is_active', 1) or 1) != 1:
        return False

    block_type = str(block.get('block_type', 'weekly')).lower()

    if block_type == 'datetime_range':
        start_raw = str(block.get('start_datetime') or '').strip().replace('T', ' ')
        end_raw = str(block.get('end_datetime') or '').strip().replace('T', ' ')
        if not start_raw or not end_raw:
            return False
        try:
            start_dt = datetime.strptime(start_raw[:19], '%Y-%m-%d %H:%M:%S')
            end_dt = datetime.strptime(end_raw[:19], '%Y-%m-%d %H:%M:%S')
        except ValueError:
            return False
        return start_dt <= now < end_dt

    if block_type == 'date':
        specific_date = str(block.get('specific_date') or '').strip()
        if specific_date and specific_date != now.strftime('%Y-%m-%d'):
            return False

    if block_type == 'weekly':
        days_mask = str(block.get('days_mask') or '').strip()
        if days_mask:
            allowed_days = set()
            for token in days_mask.split(','):
                token = token.strip()
                if not token:
                    continue
                try:
                    day = int(token)
                except ValueError:
                    continue
                if 1 <= day <= 7:
                    allowed_days.add(day)
            if allowed_days and now.isoweekday() not in allowed_days:
                return False

    start_seconds = parse_time_to_seconds(block.get('start_time', '00:00:00'))
    end_seconds = parse_time_to_seconds(block.get('end_time', '23:59:59'))
    now_seconds = now.hour * 3600 + now.minute * 60 + now.second

    if start_seconds <= end_seconds:
        return start_seconds <= now_seconds <= end_seconds
    return now_seconds >= start_seconds or now_seconds <= end_seconds

def block_sort_key(block):
    btype = str(block.get('block_type', 'weekly')).lower()
    if btype == 'datetime_range':
        block_type_weight = 3
    elif btype == 'date':
        block_type_weight = 2
    else:
        block_type_weight = 1
    priority = int(block.get('priority', 100) or 100)
    display_order = int(block.get('display_order', 0) or 0)
    block_id = int(block.get('id', 0) or 0)
    return (-block_type_weight, -priority, display_order, block_id)

def normalize_loop_items(items):
    return [item for item in (items or []) if isinstance(item, dict)]

plan = data.get('offline_plan') if isinstance(data.get('offline_plan'), dict) else {}
base_loop = normalize_loop_items(plan.get('base_loop') if isinstance(plan, dict) else None)
if not base_loop:
    base_loop = normalize_loop_items(data.get('loop'))

time_blocks = [b for b in (plan.get('time_blocks') if isinstance(plan, dict) else []) if isinstance(b, dict)]
now = datetime.now()
active_blocks = [b for b in time_blocks if is_block_active(b, now)]

if active_blocks:
    active_blocks.sort(key=block_sort_key)
    winner = active_blocks[0]
    winner_loop = normalize_loop_items(winner.get('loops'))
    active_loop = winner_loop if winner_loop else base_loop
else:
    active_loop = base_loop

if not active_loop:
    print('ACTIVE')
    raise SystemExit(0)

for item in active_loop:
    key = str(item.get('module_key') or '').strip().lower()
    if key == 'turned-off':
        print('TURNED_OFF')
        raise SystemExit(0)
    if key:
        print('ACTIVE')
        raise SystemExit(0)

print('ACTIVE')
PY
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
    local off_state_logged=false

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

        local local_mode
        local_mode=$(resolve_local_schedule_mode)
        if [ "$local_mode" = "TURNED_OFF" ]; then
            pkill -f '^surf( |$)' 2>/dev/null || true
            pkill -f '/usr/bin/surf' 2>/dev/null || true
            if [ "$off_state_logged" != true ]; then
                log_terminal "Local loop mode TURNED_OFF - surf paused, Openbox stays running"
                off_state_logged=true
            fi
            sleep "$OFF_MODE_POLL_INTERVAL"
            continue
        fi

        if [ "$off_state_logged" = true ]; then
            log_terminal "Local loop mode ACTIVE - restarting surf"
            off_state_logged=false
        fi

        log_terminal "Launching surf fullscreen: file://${LOOP_PLAYER}"
        surf -F "file://${LOOP_PLAYER}" || true
        log_terminal "Surf browser exited"
        sleep "$SURF_RESTART_DELAY"
    done
}

main "$@"
