#!/bin/bash
# EduDisplej Module Downloader
# Downloads loop configuration and module files for kiosk display
# =============================================================================

set -euo pipefail

# Configuration
API_BASE_URL="${EDUDISPLEJ_API_URL:-https://control.edudisplej.sk}"
CONFIG_DIR="/opt/edudisplej"
LOCAL_WEB_DIR="${CONFIG_DIR}/localweb"
MODULES_DIR="${LOCAL_WEB_DIR}/modules"
CONFIG_FILE="${CONFIG_DIR}/kiosk.conf"
CONFIG_JSON="${CONFIG_DIR}/data/config.json"
LOOP_FILE="${MODULES_DIR}/loop.json"
LOOP_PLAYER="${LOCAL_WEB_DIR}/loop_player.html"
TOKEN_FILE="${CONFIG_DIR}/lic/token"
ASSETS_DIR="${LOCAL_WEB_DIR}/assets"

# Logging - all output to stderr to avoid interfering with function return values
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >&2
}

log_error() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: $*" >&2
}

log_success() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] SUCCESS: $*" >&2
}

# Token handling
get_api_token() {
    if [ -f "$TOKEN_FILE" ]; then
        tr -d '\n\r' < "$TOKEN_FILE"
        return 0
    fi
    return 1
}

is_auth_error() {
    local response="$1"
    echo "$response" | grep -qi '"message"[[:space:]]*:[[:space:]]*"Invalid API token"\|"Authentication required"\|"Unauthorized"\|"Company license is inactive"\|"No valid license key"'
}

reset_to_unconfigured() {
    log_error "Authorization failed - switching to unconfigured mode"
    rm -rf "${MODULES_DIR}" 2>/dev/null || true
    mkdir -p "${MODULES_DIR}" 2>/dev/null || true
    rm -f "${LOOP_FILE}" 2>/dev/null || true
    create_unconfigured_page
    return 1
}

# Create directories
mkdir -p "$MODULES_DIR"

# Load device ID from config
load_device_id() {
    local device_id=""

    if [ -f "$CONFIG_FILE" ]; then
        # Source config file
        source "$CONFIG_FILE" 2>/dev/null || true
        device_id="${DEVICE_ID:-}"
    fi

    if [ -z "$device_id" ] && [ -f "$CONFIG_JSON" ]; then
        if command -v jq >/dev/null 2>&1; then
            device_id=$(jq -r '.device_id // empty' "$CONFIG_JSON" 2>/dev/null)
        else
            device_id=$(grep -o '"device_id":"[^"]*"' "$CONFIG_JSON" | cut -d'"' -f4 | head -1)
        fi
    fi

    if [ -z "$device_id" ]; then
        log_error "DEVICE_ID not found in config"
        return 1
    fi

    echo "$device_id"
}

# Get loop configuration
get_loop_config() {
    local device_id="$1"
    
    log "Fetching loop configuration..."
    
    local token
    token=$(get_api_token) || { reset_to_unconfigured; return 1; }

    local response=$(curl -s -X POST "${API_BASE_URL}/api/kiosk_loop.php" \
        -H "Authorization: Bearer $token" \
        -d "device_id=${device_id}" \
        --max-time 30)

    if is_auth_error "$response"; then
        reset_to_unconfigured
        return 1
    fi
    
    # Check if successful
    if echo "$response" | grep -q '"success":true'; then
        log_success "Loop configuration retrieved"
        echo "$response"
        return 0
    else
        log_error "Failed to get loop configuration"
        log_error "Response: $response"
        return 1
    fi
}

# Download single module
download_module() {
    local device_id="$1"
    local module_name="$2"
    local module_dir_key="${3:-$module_name}"
    local module_dir="${MODULES_DIR}/${module_dir_key}"
    
    log "Downloading module: $module_name"
    
    # Create module directory
    mkdir -p "$module_dir"
    
    # Request module files
    local token
    token=$(get_api_token) || { reset_to_unconfigured; return 1; }

    local response=$(curl -s -X POST "${API_BASE_URL}/api/download_module.php" \
        -H "Authorization: Bearer $token" \
        -d "device_id=${device_id}&module_name=${module_name}" \
        --max-time 60)

    if is_auth_error "$response"; then
        reset_to_unconfigured
        return 1
    fi
    
    # Check if successful
    if ! echo "$response" | grep -q '"success":true'; then
        log_error "Failed to download module $module_name"
        log_error "Response: $response"
        return 1
    fi
    
    # Parse and save files
    if command -v jq >/dev/null 2>&1; then
        # Use jq for JSON parsing
        local files_count=$(echo "$response" | jq -r '.file_count')
        log "Module has $files_count files"
        
        local last_update=$(echo "$response" | jq -r '.last_update')
        
        local i=0
        while [ $i -lt $files_count ]; do
            local file_path=$(echo "$response" | jq -r ".files[$i].path")
            local file_content=$(echo "$response" | jq -r ".files[$i].content")
            local file_size=$(echo "$response" | jq -r ".files[$i].size")
            
            # Create subdirectories if needed
            local file_dir=$(dirname "${module_dir}/${file_path}")
            mkdir -p "$file_dir"
            
            # Decode and save file
            echo "$file_content" | base64 -d > "${module_dir}/${file_path}"
            
            log "  ✓ ${file_path} (${file_size} bytes)"
            
            i=$((i + 1))
        done
        
        # Save metadata
        cat > "${module_dir}/.metadata.json" <<EOF
{
    "module_name": "$module_name",
    "last_update": "$last_update",
    "downloaded_at": "$(date '+%Y-%m-%d %H:%M:%S')",
    "files_count": $files_count
}
EOF
        
        log_success "Module $module_name downloaded successfully"
        return 0
    else
        log_error "jq is required for JSON parsing. Install: sudo apt-get install jq"
        return 1
    fi
}

# Save loop configuration
save_loop_config() {
    local loop_response="$1"

    log "Saving loop configuration..."

    if command -v jq >/dev/null 2>&1; then
        local loop_data=$(echo "$loop_response" | jq '.loop_config // []')
        local offline_plan=$(echo "$loop_response" | jq '.offline_plan // null')
        local loop_last_update=$(echo "$loop_response" | jq -r '.loop_last_update // empty')
        local loop_plan_version=$(echo "$loop_response" | jq -r '.loop_plan_version // 0')
        local active_scope=$(echo "$loop_response" | jq -r '.active_scope // "base"')
        local active_time_block_id=$(echo "$loop_response" | jq -r '.active_time_block_id // empty')
        [ -z "$loop_last_update" ] && loop_last_update="$(date '+%Y-%m-%d %H:%M:%S')"

        if [ "$offline_plan" = "null" ] || [ -z "$offline_plan" ]; then
            offline_plan=$(jq -n --argjson base "$loop_data" '{base_loop: $base, time_blocks: []}')
        fi

        local active_time_block_json="null"
        if [ -n "$active_time_block_id" ] && [ "$active_time_block_id" != "null" ]; then
            active_time_block_json="$active_time_block_id"
        fi

        cat > "$LOOP_FILE" <<EOF
{
    "last_update": "$loop_last_update",
    "loop_plan_version": $loop_plan_version,
    "active_scope": "$active_scope",
    "active_time_block_id": $active_time_block_json,
    "loop": $loop_data,
    "offline_plan": $offline_plan
}
EOF

        log_success "Loop configuration saved to $LOOP_FILE"
        log "  Last update: $loop_last_update"

        cat > "${MODULES_DIR}/.download_info.json" <<EOF
{
    "last_download": "$(date '+%Y-%m-%d %H:%M:%S')",
    "loop_last_update": "$loop_last_update"
}
EOF

        local modules_count=$(echo "$loop_data" | jq -r 'length')
        local base_count=$(echo "$offline_plan" | jq -r '.base_loop | length')
        local blocks_count=$(echo "$offline_plan" | jq -r '.time_blocks | length')
        log "Active loop contains $modules_count modules"
        log "Offline plan: base=$base_count modules, blocks=$blocks_count"

        local i=0
        while [ $i -lt $modules_count ]; do
            local mod_name=$(echo "$loop_data" | jq -r ".[$i].module_name")
            local duration=$(echo "$loop_data" | jq -r ".[$i].duration_seconds")
            log "  $((i+1)). $mod_name (${duration}s)"
            i=$((i + 1))
        done

        return 0
    else
        log_error "jq is required for JSON parsing"
        return 1
    fi
}

canonicalize_module_key() {
    local raw="$(echo "${1:-}" | tr '[:upper:]' '[:lower:]' | xargs)"
    case "$raw" in
        meal_menu) echo "meal-menu" ;;
        room_occupancy) echo "room-occupancy" ;;
        default_logo) echo "default-logo" ;;
        image_gallery) echo "image-gallery" ;;
        *) echo "$raw" ;;
    esac
}

cleanup_stale_local_content() {
    if ! command -v python3 >/dev/null 2>&1; then
        log_error "python3 not available - stale content cleanup skipped"
        return 1
    fi

    local cleanup_output
    if ! cleanup_output=$(python3 - "$MODULES_DIR" "$ASSETS_DIR" "$LOOP_FILE" "$@" <<'PY'
import json
import shutil
import sys
from pathlib import Path

modules_dir = Path(sys.argv[1])
assets_dir = Path(sys.argv[2])
loop_file = Path(sys.argv[3])
desired_dirs = {str(item).strip() for item in sys.argv[4:] if str(item).strip()}

removed_modules = 0
removed_asset_files = 0
removed_asset_dirs = 0

if modules_dir.exists() and modules_dir.is_dir():
    for entry in modules_dir.iterdir():
        if not entry.is_dir():
            continue
        if entry.name in desired_dirs:
            continue
        shutil.rmtree(entry, ignore_errors=True)
        removed_modules += 1

def iter_loop_items(payload):
    direct = payload.get('loop')
    if isinstance(direct, list):
        for item in direct:
            if isinstance(item, dict):
                yield item

    offline_plan = payload.get('offline_plan')
    if not isinstance(offline_plan, dict):
        return

    base_loop = offline_plan.get('base_loop')
    if isinstance(base_loop, list):
        for item in base_loop:
            if isinstance(item, dict):
                yield item

    time_blocks = offline_plan.get('time_blocks')
    if isinstance(time_blocks, list):
        for block in time_blocks:
            if not isinstance(block, dict):
                continue
            loops = block.get('loops')
            if not isinstance(loops, list):
                continue
            for item in loops:
                if isinstance(item, dict):
                    yield item

keep_meal_rel_files = set()
if loop_file.exists() and loop_file.is_file():
    try:
        payload = json.loads(loop_file.read_text(encoding='utf-8', errors='ignore'))
    except Exception:
        payload = {}

    for item in iter_loop_items(payload if isinstance(payload, dict) else {}):
        settings = item.get('settings')
        if not isinstance(settings, dict):
            continue
        for field in ('offlinePrefetchedTodayFile', 'offlinePrefetchedTomorrowFile'):
            raw = str(settings.get(field) or '').strip()
            if not raw:
                continue
            marker = '../../assets/'
            if marker in raw:
                rel = raw.split(marker, 1)[1].strip().replace('\\', '/')
            else:
                rel = raw.replace('\\', '/').lstrip('./')
                if rel.startswith('assets/'):
                    rel = rel[len('assets/'):]
            if rel.startswith('meal-menu/'):
                keep_meal_rel_files.add(rel)

meal_assets_root = assets_dir / 'meal-menu'
if meal_assets_root.exists() and meal_assets_root.is_dir():
    for file_path in meal_assets_root.rglob('*'):
        if not file_path.is_file():
            continue
        rel = file_path.relative_to(assets_dir).as_posix()
        if rel in keep_meal_rel_files:
            continue
        try:
            file_path.unlink()
            removed_asset_files += 1
        except Exception:
            pass

    for dir_path in sorted(meal_assets_root.rglob('*'), key=lambda p: len(p.parts), reverse=True):
        if not dir_path.is_dir():
            continue
        try:
            next(dir_path.iterdir())
            continue
        except StopIteration:
            try:
                dir_path.rmdir()
                removed_asset_dirs += 1
            except Exception:
                pass

    try:
        next(meal_assets_root.iterdir())
    except StopIteration:
        try:
            meal_assets_root.rmdir()
            removed_asset_dirs += 1
        except Exception:
            pass

print(f"removed_modules={removed_modules} removed_asset_files={removed_asset_files} removed_asset_dirs={removed_asset_dirs}")
PY
    ); then
        log_error "Stale content cleanup failed"
        return 1
    fi

    log "Stale content cleanup summary: $cleanup_output"
    return 0
}

enrich_loop_runtime_metadata() {
    if [ ! -f "$LOOP_FILE" ]; then
        log_error "Loop file not found for metadata enrichment: $LOOP_FILE"
        return 1
    fi

    python3 - "$LOOP_FILE" "$MODULES_DIR" <<'PY'
import json
import sys
from pathlib import Path

loop_file = Path(sys.argv[1])
modules_dir = Path(sys.argv[2])

try:
    data = json.loads(loop_file.read_text(encoding='utf-8', errors='ignore'))
except Exception as exc:
    print(f"Failed to parse loop file: {exc}", file=sys.stderr)
    sys.exit(1)

stats = {
    'patched': 0,
    'removed': 0,
}

def detect_main_file(module_key: str) -> str:
    key = (module_key or '').strip()
    if not key:
        return ''

    module_path = modules_dir / key
    if not module_path.exists() or not module_path.is_dir():
        return ''

    for candidate in ('live.html', 'index.html'):
        if (module_path / candidate).is_file():
            return candidate

    m_prefixed = sorted([p.name for p in module_path.glob('m_*.html') if p.is_file()])
    if m_prefixed:
        return m_prefixed[0]

    any_html = sorted([p.name for p in module_path.glob('*.html') if p.is_file()])
    if any_html:
        return any_html[0]

    return ''

def normalize_entry(entry):
    if not isinstance(entry, dict):
        return None

    module_key = str(entry.get('module_key') or '').strip()
    if not module_key:
        return None

    if module_key.lower() == 'turned-off':
        entry['module_folder'] = str(entry.get('module_folder') or '').strip()
        entry['module_main_file'] = str(entry.get('module_main_file') or '').strip()
        return entry

    module_folder = str(entry.get('module_folder') or '').strip()
    if not module_folder:
        module_folder = module_key
        entry['module_folder'] = module_folder
        stats['patched'] += 1

    module_main_file = str(entry.get('module_main_file') or '').strip()
    if not module_main_file:
        module_main_file = detect_main_file(module_key)
        if module_main_file:
            entry['module_main_file'] = module_main_file
            stats['patched'] += 1

    if not module_main_file:
        return None

    local_path = modules_dir / module_folder / module_main_file
    if not local_path.is_file():
        detected = detect_main_file(module_key)
        if detected:
            entry['module_folder'] = module_key
            entry['module_main_file'] = detected
            stats['patched'] += 1
            local_path = modules_dir / module_key / detected

    if not local_path.is_file():
        return None

    return entry

def normalize_list(entries):
    normalized = []
    for item in entries or []:
        patched = normalize_entry(item)
        if patched is None:
            stats['removed'] += 1
            continue
        normalized.append(patched)
    return normalized

data['loop'] = normalize_list(data.get('loop') or [])

offline_plan = data.get('offline_plan') or {}
offline_plan['base_loop'] = normalize_list(offline_plan.get('base_loop') or [])

time_blocks = offline_plan.get('time_blocks') or []
for block in time_blocks:
    block['loops'] = normalize_list(block.get('loops') or [])

offline_plan['time_blocks'] = time_blocks
data['offline_plan'] = offline_plan

if not data.get('loop') and offline_plan.get('base_loop'):
    data['loop'] = offline_plan['base_loop']

loop_file.write_text(json.dumps(data, ensure_ascii=False, indent=4), encoding='utf-8')
print(f"patched={stats['patched']} removed={stats['removed']}")
PY

    log_success "Loop runtime metadata enriched"
    return 0
}

prefetch_loop_assets_and_meal_json() {
    if [ ! -f "$LOOP_FILE" ]; then
        log_error "Loop file not found for asset prefetch: $LOOP_FILE"
        return 1
    fi

    if ! command -v python3 >/dev/null 2>&1; then
        log_error "python3 not available - meal JSON prefetch skipped"
        return 1
    fi

    local token
    token=$(get_api_token) || {
        log_error "Meal prefetch skipped: missing API token"
        return 1
    }

    mkdir -p "$ASSETS_DIR"

    local prefetch_output
    if ! prefetch_output=$(python3 - "$LOOP_FILE" "$ASSETS_DIR" "$API_BASE_URL" "$token" <<'PY'
import datetime
import hashlib
import json
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

loop_file = Path(sys.argv[1])
assets_dir = Path(sys.argv[2])
api_base = sys.argv[3].rstrip('/')
token = sys.argv[4]

try:
    data = json.loads(loop_file.read_text(encoding='utf-8', errors='ignore'))
except Exception as exc:
    print(f'failed_to_parse_loop:{exc}')
    raise SystemExit(1)

def as_bool(value, default=False):
    if value is None:
        return default
    if isinstance(value, bool):
        return value
    text = str(value).strip().lower()
    if text in {'1', 'true', 'yes', 'on'}:
        return True
    if text in {'0', 'false', 'no', 'off'}:
        return False
    return default

def as_int(value, default=0):
    try:
        return int(value)
    except Exception:
        return default

def iter_loop_items(root_data):
    direct = root_data.get('loop')
    if isinstance(direct, list):
        for item in direct:
            if isinstance(item, dict):
                yield item

    offline_plan = root_data.get('offline_plan')
    if not isinstance(offline_plan, dict):
        return

    base_loop = offline_plan.get('base_loop')
    if isinstance(base_loop, list):
        for item in base_loop:
            if isinstance(item, dict):
                yield item

    time_blocks = offline_plan.get('time_blocks')
    if isinstance(time_blocks, list):
        for block in time_blocks:
            if not isinstance(block, dict):
                continue
            loops = block.get('loops')
            if not isinstance(loops, list):
                continue
            for item in loops:
                if isinstance(item, dict):
                    yield item

def fetch_meal_payload(config, target_date, exact_date=False):
    query = {
        'action': 'menu',
        'site_key': config['site_key'],
        'institution_id': str(config['institution_id']),
        'date': target_date,
        'exact_date': '1' if exact_date else '0',
        'show_breakfast': '1' if config['show_breakfast'] else '0',
        'show_snack_am': '1' if config['show_snack_am'] else '0',
        'show_lunch': '1' if config['show_lunch'] else '0',
        'show_snack_pm': '1' if config['show_snack_pm'] else '0',
        'show_dinner': '1' if config['show_dinner'] else '0',
        'source_type': config['source_type'],
    }
    if config['company_id'] > 0:
        query['company_id'] = str(config['company_id'])

    url = f"{api_base}/api/meal_plan.php?{urllib.parse.urlencode(query)}"
    request = urllib.request.Request(url, headers={
        'Authorization': f'Bearer {token}',
        'User-Agent': 'EduDisplejSync/1.0 (+meal-prefetch)',
        'Accept': 'application/json, text/plain, */*'
    })
    try:
        with urllib.request.urlopen(request, timeout=12) as response:
            payload = json.loads(response.read().decode('utf-8', errors='ignore'))
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, urllib.error.HTTPError):
        return None

    if not isinstance(payload, dict) or payload.get('success') is not True:
        return None
    data = payload.get('data')
    return data if isinstance(data, dict) else None

meal_groups = {}
meal_items_found = 0

for item in iter_loop_items(data):
    module_key = str(item.get('module_key') or '').strip().lower()
    if module_key not in {'meal-menu', 'meal_menu'}:
        continue

    settings = item.get('settings')
    if not isinstance(settings, dict):
        continue

    meal_items_found += 1
    config = {
        'company_id': as_int(settings.get('companyId'), 0),
        'site_key': str(settings.get('siteKey') or 'jedalen.sk').strip() or 'jedalen.sk',
        'institution_id': as_int(settings.get('institutionId'), 0),
        'source_type': 'manual' if str(settings.get('sourceType') or 'server').strip().lower() == 'manual' else 'server',
        'show_breakfast': as_bool(settings.get('showBreakfast'), True),
        'show_snack_am': as_bool(settings.get('showSnackAm'), True),
        'show_lunch': as_bool(settings.get('showLunch'), True),
        'show_snack_pm': as_bool(settings.get('showSnackPm'), False),
        'show_dinner': as_bool(settings.get('showDinner'), False),
        'layout_mode': str(settings.get('layoutMode') or 'classic').strip().lower() or 'classic',
        'show_tomorrow_square': as_bool(settings.get('showTomorrowInSquare'), True),
    }

    if config['institution_id'] <= 0:
        continue

    fingerprint = json.dumps(config, sort_keys=True, ensure_ascii=False, separators=(',', ':'))
    key = hashlib.sha1(fingerprint.encode('utf-8')).hexdigest()[:14]
    if key not in meal_groups:
        meal_groups[key] = {'config': config, 'settings_refs': []}
    meal_groups[key]['settings_refs'].append(settings)

today_date = datetime.date.today().isoformat()
tomorrow_date = (datetime.date.today() + datetime.timedelta(days=1)).isoformat()
meal_assets_root = assets_dir / 'meal-menu'
meal_assets_root.mkdir(parents=True, exist_ok=True)

prefetched_groups = 0
prefetched_files = 0
patched_settings = 0

for key, group in meal_groups.items():
    config = group['config']
    target_dir = meal_assets_root / key
    target_dir.mkdir(parents=True, exist_ok=True)

    today_payload = fetch_meal_payload(config, today_date, exact_date=False)
    tomorrow_payload = None
    if config['layout_mode'] == 'square_dual_day' and config['show_tomorrow_square']:
        tomorrow_payload = fetch_meal_payload(config, tomorrow_date, exact_date=True)

    today_rel = ''
    tomorrow_rel = ''

    if isinstance(today_payload, dict):
        (target_dir / 'today.json').write_text(json.dumps(today_payload, ensure_ascii=False, indent=2), encoding='utf-8')
        today_rel = f"../../assets/meal-menu/{key}/today.json"
        prefetched_files += 1

    if isinstance(tomorrow_payload, dict):
        (target_dir / 'tomorrow.json').write_text(json.dumps(tomorrow_payload, ensure_ascii=False, indent=2), encoding='utf-8')
        tomorrow_rel = f"../../assets/meal-menu/{key}/tomorrow.json"
        prefetched_files += 1

    if not today_rel and not tomorrow_rel:
        continue

    prefetched_groups += 1
    saved_at = datetime.datetime.utcnow().replace(microsecond=0).isoformat() + 'Z'

    inline_prefetched = None
    if config['layout_mode'] == 'square_dual_day':
        inline_prefetched = {
            'today': today_payload if isinstance(today_payload, dict) else None,
            'tomorrow': tomorrow_payload if isinstance(tomorrow_payload, dict) else None,
        }
    elif isinstance(today_payload, dict):
        inline_prefetched = today_payload

    for settings in group['settings_refs']:
        settings['offlinePrefetchedTodayFile'] = today_rel
        settings['offlinePrefetchedTomorrowFile'] = tomorrow_rel
        settings['offlinePrefetchedMenuSavedAt'] = saved_at
        if inline_prefetched is not None:
            settings['offlinePrefetchedMenuData'] = inline_prefetched
        patched_settings += 1

loop_file.write_text(json.dumps(data, ensure_ascii=False, indent=4), encoding='utf-8')
print(f"meal_items={meal_items_found} groups={len(meal_groups)} prefetched_groups={prefetched_groups} files={prefetched_files} patched={patched_settings}")
PY
    ); then
        log_error "Meal JSON prefetch failed"
        return 1
    fi

    log "Meal prefetch summary: $prefetch_output"
    log_success "Loop asset prefetch + meal JSON cache completed"
    return 0
}

# Create unconfigured page
create_unconfigured_page() {
    local unconfigured_page="${LOCAL_WEB_DIR}/unconfigured.html"
    cat > "$unconfigured_page" <<'UNCONFIG_EOF'
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>EduDisplej - Unconfigured</title>
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background: #0f172a; color: #fff; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .card { text-align: center; max-width: 720px; padding: 40px; background: rgba(255,255,255,0.06); border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        h1 { margin-bottom: 12px; font-size: 28px; }
        p { opacity: 0.9; line-height: 1.5; }
        .small { margin-top: 16px; font-size: 13px; opacity: 0.7; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Ez a kijelző még nincs konfigurálva</h1>
        <p>Kérjük, rendeld hozzá a kijelzőt a vezérlőpultban.</p>
        <p class="small">EduDisplej • control.edudisplej.sk</p>
    </div>
</body>
</html>
UNCONFIG_EOF
}

# Create loop player HTML
create_loop_player() {
    log "Creating loop player HTML..."
    
    local loop_json="{}"
    if [ -f "$LOOP_FILE" ]; then
        loop_json=$(cat "$LOOP_FILE")
    fi
    
    cat > "$LOOP_PLAYER" <<LOOP_PLAYER_EOF
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EduDisplej Loop Player</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            background: #000;
            font-family: Arial, sans-serif;
        }
        
        #player {
            width: 100%;
            height: 100%;
            position: relative;
        }
        
        #module-frame {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
            background: #000;
        }
        
        #error-display {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(255, 0, 0, 0.9);
            color: white;
            padding: 30px;
            border-radius: 10px;
            text-align: center;
            display: none;
            z-index: 10000;
            max-width: 80%;
        }
        
        #error-display h2 {
            margin-bottom: 15px;
        }
        
        #error-display pre {
            text-align: left;
            background: rgba(0, 0, 0, 0.3);
            padding: 10px;
            border-radius: 5px;
            font-size: 11px;
            overflow: auto;
            max-height: 300px;
        }

        #debug-terminal {
            position: fixed;
            right: 12px;
            bottom: 12px;
            width: min(560px, 45vw);
            height: min(300px, 36vh);
            background: rgba(0, 0, 0, 0.88);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.55);
            border-radius: 8px;
            display: none;
            z-index: 9000;
            overflow: hidden;
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.45);
            font-family: 'Consolas', 'Courier New', monospace;
        }

        #debug-terminal-header {
            font-size: 11px;
            letter-spacing: 0.02em;
            padding: 7px 10px;
            background: rgba(34, 197, 94, 0.12);
            border-bottom: 1px solid rgba(34, 197, 94, 0.35);
            color: #86efac;
        }

        #debug-terminal-body {
            font-size: 11px;
            line-height: 1.35;
            white-space: pre-wrap;
            word-break: break-word;
            overflow: auto;
            height: calc(100% - 32px);
            margin: 0;
            padding: 8px 10px;
        }
    </style>
</head>
<body>
    <div id="player">
        <iframe id="module-frame" src="about:blank"></iframe>
    </div>
    
    <div id="error-display">
        <h2>⚠️ Hiba történt</h2>
        <p id="error-message"></p>
        <pre id="error-details"></pre>
    </div>

    <div id="debug-terminal">
        <div id="debug-terminal-header">DEBUG TERMINAL • sync.log</div>
        <pre id="debug-terminal-body"></pre>
    </div>
    
    <script id="loop-config" type="application/json">
$loop_json
    </script>
    
    <script>
        class LoopPlayer {
            constructor() {
                this.loopConfig = null;
                this.currentLoop = [];
                this.currentLoopId = 'base';
                this.currentIndex = 0;
                this.timer = null;
                this.frame = document.getElementById('module-frame');
                this.errorDisplay = document.getElementById('error-display');
                this.debugTerminal = document.getElementById('debug-terminal');
                this.debugTerminalBody = document.getElementById('debug-terminal-body');
                this.debugModeEnabled = false;
                this.debugModeTimer = null;
                this.debugLogTimer = null;
                this.enablePassiveConfigWatch = (window.EDUDISPLEJ_ENABLE_LOOP_WATCH === true);
                
                this.init();
            }
            
            async init() {
                this.log('Loop Player initializing...');
                
                try {
                    await this.loadLoopConfig();

                    const activeLoop = this.resolveActiveLoop(new Date());
                    if (!activeLoop.items || activeLoop.items.length === 0) {
                        this.redirectToUnconfigured('No modules configured in loop');
                        throw new Error('No modules configured in loop');
                    }

                    this.switchToLoop(activeLoop, true);
                    
                    this.log('Loaded offline plan. Active loop modules: ' + this.currentLoop.length);
                    this.log('Last update: ' + this.loopConfig.last_update);
                    
                    // Optional periodic check for configuration updates
                    if (this.enablePassiveConfigWatch) {
                        this.startUpdateChecker();
                    }
                    this.startDebugMonitor();
                    
                    this.startLoop();
                } catch (error) {
                    this.showError('Failed to initialize loop', error);
                }
            }

            redirectToUnconfigured(reason) {
                this.log('Redirecting to unconfigured page: ' + reason);
                window.location.href = 'unconfigured.html';
            }
            
            startUpdateChecker() {
                // Check for configuration updates every 5 minutes (optional)
                setInterval(async () => {
                    try {
                        const response = await fetch('modules/loop.json', { cache: 'no-store' });
                        if (!response.ok) return;
                        
                        const newConfig = await response.json();
                        const newUpdate = newConfig.last_update;
                        const currentUpdate = this.loopConfig.last_update;
                        
                        if (newUpdate && currentUpdate && newUpdate !== currentUpdate) {
                            this.log('Configuration update detected! Reloading page...');
                            this.log('Old: ' + currentUpdate + ', New: ' + newUpdate);
                            // Wait a moment then reload
                            setTimeout(() => {
                                window.location.reload();
                            }, 2000);
                        }
                    } catch (error) {
                        // Silently ignore errors in update check
                        this.log('Update check failed: ' + error.message);
                    }
                }, 300000); // Check every 5 minutes
            }

            startDebugMonitor() {
                this.checkDebugModeAndUpdate().catch(() => {});

                this.debugModeTimer = setInterval(() => {
                    this.checkDebugModeAndUpdate().catch(() => {});
                }, 4000);

                this.debugLogTimer = setInterval(() => {
                    if (!this.debugModeEnabled) return;
                    this.updateDebugLogTail().catch(() => {});
                }, 3000);
            }

            async checkDebugModeAndUpdate() {
                let isEnabled = false;

                try {
                    let response = await fetch(new URL('last_sync_response.json', window.location.href), { cache: 'no-store' });
                    if (!response.ok) {
                        response = await fetch('file:///opt/edudisplej/last_sync_response.json', { cache: 'no-store' });
                    }

                    if (response.ok) {
                        const syncData = await response.json();
                        isEnabled = !!syncData.debug_mode;
                    }
                } catch (error) {
                    isEnabled = false;
                }

                if (isEnabled === this.debugModeEnabled) {
                    return;
                }

                this.debugModeEnabled = isEnabled;
                this.debugTerminal.style.display = isEnabled ? 'block' : 'none';

                if (isEnabled) {
                    this.debugTerminalBody.textContent = '[debug] Debug mode enabled, loading sync logs...\n';
                    await this.updateDebugLogTail();
                } else {
                    this.debugTerminalBody.textContent = '';
                }
            }

            async updateDebugLogTail() {
                let response = await fetch(new URL('logs/sync.log', window.location.href), { cache: 'no-store' });
                if (!response.ok) {
                    response = await fetch('file:///opt/edudisplej/logs/sync.log?ts=' + Date.now(), { cache: 'no-store' });
                }

                if (!response.ok) {
                    throw new Error('Failed to read sync.log');
                }

                const content = await response.text();
                const lines = content.split('\n').filter(line => line.trim().length > 0);
                const tail = lines.slice(-22);
                this.debugTerminalBody.textContent = tail.join('\n');
                this.debugTerminalBody.scrollTop = this.debugTerminalBody.scrollHeight;
            }
            
            async loadLoopConfig() {
                this.log('Loading loop configuration...');
                
                try {
                    const embedded = document.getElementById('loop-config');
                    if (embedded && embedded.textContent) {
                        const text = embedded.textContent.trim();
                        // Check if embedded config has content (at least more than just {})
                        if (text.length > 4 && text !== '{}') {
                            try {
                                this.loopConfig = JSON.parse(text);
                                // Normalize format: support both "loop" and "loop_config" fields
                                if (this.loopConfig) {
                                    if (!this.loopConfig.loop && this.loopConfig.loop_config) {
                                        this.loopConfig.loop = this.loopConfig.loop_config;
                                    }
                                    // Ensure loop is an array
                                    if (this.loopConfig.loop && Array.isArray(this.loopConfig.loop) && this.loopConfig.loop.length > 0) {
                                        this.log('Using embedded loop configuration');
                                        return this.loopConfig;
                                    }
                                }
                            } catch (parseError) {
                                this.log('Failed to parse embedded config: ' + parseError.message + ', trying to fetch from file...');
                            }
                        }
                    }
                    
                    // Try to load from file
                    let response = await fetch(new URL('modules/loop.json', window.location.href), { cache: 'no-store' });
                    
                    if (!response.ok) {
                        // Fallback for file:// restrictions or unexpected base paths
                        response = await fetch('file:///opt/edudisplej/localweb/modules/loop.json', { cache: 'no-store' });
                    }
                    
                    if (!response.ok) {
                        this.redirectToUnconfigured('Loop config not available');
                        throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                    }
                    
                    this.loopConfig = await response.json();
                    
                    // Normalize format: support both "loop" and "loop_config" fields
                    if (this.loopConfig && !this.loopConfig.loop && this.loopConfig.loop_config) {
                        this.loopConfig.loop = this.loopConfig.loop_config;
                    }

                    if (!this.loopConfig || typeof this.loopConfig !== 'object') {
                        this.redirectToUnconfigured('Invalid loop configuration');
                        throw new Error('Invalid loop configuration format');
                    }

                    if (!Array.isArray(this.loopConfig.loop)) {
                        this.loopConfig.loop = [];
                    }

                    if (!this.loopConfig.offline_plan || typeof this.loopConfig.offline_plan !== 'object') {
                        this.loopConfig.offline_plan = {
                            base_loop: this.loopConfig.loop,
                            time_blocks: []
                        };
                    }

                    if (!Array.isArray(this.loopConfig.offline_plan.base_loop)) {
                        this.loopConfig.offline_plan.base_loop = this.loopConfig.loop;
                    }

                    if (!Array.isArray(this.loopConfig.offline_plan.time_blocks)) {
                        this.loopConfig.offline_plan.time_blocks = [];
                    }

                    if (this.loopConfig.offline_plan.base_loop.length === 0 && this.loopConfig.loop.length === 0) {
                        this.redirectToUnconfigured('Empty loop configuration');
                        throw new Error('Loop configuration is empty');
                    }
                    
                    this.log('Using loop configuration from file');
                    return this.loopConfig;
                } catch (error) {
                    this.redirectToUnconfigured('Cannot load loop.json');
                    throw new Error('Cannot load loop.json: ' + error.message);
                }
            }
            
            startLoop() {
                this.log('Starting loop playback...');
                this.currentIndex = 0;
                this.playCurrentModule();
            }

            parseTimeToSeconds(value) {
                const input = String(value || '00:00:00');
                const parts = input.split(':').map((v) => parseInt(v, 10));
                const h = Number.isFinite(parts[0]) ? parts[0] : 0;
                const m = Number.isFinite(parts[1]) ? parts[1] : 0;
                const s = Number.isFinite(parts[2]) ? parts[2] : 0;
                return (h * 3600) + (m * 60) + s;
            }

            isBlockActive(block, now) {
                if (!block || Number(block.is_active || 1) === 0) {
                    return false;
                }

                const blockType = String(block.block_type || 'weekly').toLowerCase() === 'date' ? 'date' : 'weekly';
                const dateKey = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');

                if (blockType === 'date') {
                    if (String(block.specific_date || '') !== dateKey) {
                        return false;
                    }
                } else {
                    const jsDay = now.getDay();
                    const day = jsDay === 0 ? 7 : jsDay;
                    const allowed = new Set(String(block.days_mask || '').split(',').map((v) => parseInt(v, 10)).filter((v) => v >= 1 && v <= 7));
                    if (allowed.size > 0 && !allowed.has(day)) {
                        return false;
                    }
                }

                const nowSeconds = (now.getHours() * 3600) + (now.getMinutes() * 60) + now.getSeconds();
                const startSeconds = this.parseTimeToSeconds(block.start_time || '00:00:00');
                const endSeconds = this.parseTimeToSeconds(block.end_time || '00:00:00');

                if (startSeconds <= endSeconds) {
                    return nowSeconds >= startSeconds && nowSeconds <= endSeconds;
                }

                return nowSeconds >= startSeconds || nowSeconds <= endSeconds;
            }

            resolveActiveLoop(now) {
                const plan = this.loopConfig && this.loopConfig.offline_plan ? this.loopConfig.offline_plan : null;
                const baseLoop = Array.isArray(plan && plan.base_loop) ? plan.base_loop : (Array.isArray(this.loopConfig && this.loopConfig.loop) ? this.loopConfig.loop : []);
                const timeBlocks = Array.isArray(plan && plan.time_blocks) ? plan.time_blocks : [];

                const candidates = timeBlocks.filter((block) => this.isBlockActive(block, now));
                if (candidates.length === 0) {
                    return { id: 'base', items: baseLoop, source: 'base' };
                }

                candidates.sort((a, b) => {
                    const typeA = String(a.block_type || 'weekly').toLowerCase() === 'date' ? 2 : 1;
                    const typeB = String(b.block_type || 'weekly').toLowerCase() === 'date' ? 2 : 1;
                    if (typeA !== typeB) {
                        return typeB - typeA;
                    }

                    const priorityA = Number(a.priority || 100);
                    const priorityB = Number(b.priority || 100);
                    if (priorityA !== priorityB) {
                        return priorityB - priorityA;
                    }

                    const orderA = Number(a.display_order || 0);
                    const orderB = Number(b.display_order || 0);
                    if (orderA !== orderB) {
                        return orderA - orderB;
                    }

                    return Number(a.id || 0) - Number(b.id || 0);
                });

                const winner = candidates[0];
                const winnerLoop = Array.isArray(winner.loops) ? winner.loops : [];
                if (winnerLoop.length === 0) {
                    return { id: 'base', items: baseLoop, source: 'base' };
                }

                return {
                    id: 'block:' + String(winner.id || ''),
                    items: winnerLoop,
                    source: 'block',
                    block: winner
                };
            }

            switchToLoop(loopSelection, resetIndex) {
                this.currentLoopId = String(loopSelection.id || 'base');
                this.currentLoop = Array.isArray(loopSelection.items) ? loopSelection.items : [];
                if (resetIndex) {
                    this.currentIndex = 0;
                }
            }
            
            playCurrentModule() {
                if (!Array.isArray(this.currentLoop) || this.currentLoop.length === 0) {
                    this.showError('Loop configuration is empty', new Error('No modules to play'));
                    return;
                }
                
                const module = this.currentLoop[this.currentIndex];
                const duration = parseInt(module.duration_seconds) * 1000;
                
                this.log('Playing module ' + (this.currentIndex + 1) + '/' + this.currentLoop.length + ': ' + module.module_name + ' (' + module.duration_seconds + 's)');
                
                // Build module URL
                const moduleUrl = this.buildModuleUrl(module);
                
                this.log('Loading: ' + moduleUrl);
                
                // Load module in iframe
                this.frame.src = moduleUrl;
                
                // Clear any existing timer
                if (this.timer) {
                    clearTimeout(this.timer);
                }
                
                // Schedule next module
                this.timer = setTimeout(() => {
                    this.nextModule();
                }, duration);
            }
            
            buildModuleUrl(module) {
                const moduleKey = module.module_key || '';
                const moduleRenderer = String(module.module_renderer || '').trim();
                const moduleFolder = String(module.module_folder || moduleKey || '').trim();
                const moduleMainFile = String(module.module_main_file || '').trim();
                
                // Parse settings
                let settings = {};
                try {
                    if (module.settings && typeof module.settings === 'string') {
                        settings = JSON.parse(module.settings);
                    } else if (module.settings && typeof module.settings === 'object') {
                        settings = module.settings;
                    }
                } catch (e) {
                    this.log('Warning: Failed to parse settings for ' + module.module_name);
                }
                
                // Determine module path from API runtime metadata
                let modulePath = '';
                if (moduleRenderer) {
                    modulePath = moduleRenderer.replace(/^\/+/, '');
                } else {
                    if (moduleFolder && moduleMainFile) {
                        modulePath = 'modules/' + moduleFolder + '/' + moduleMainFile;
                    } else if (moduleKey) {
                        this.log('Missing module runtime metadata for ' + (module.module_name || moduleKey) + ', applying legacy fallback');
                        modulePath = 'modules/' + moduleKey + '/live.html';
                    } else {
                        this.log('Missing module runtime metadata for ' + (module.module_name || 'unknown module'));
                        return 'unconfigured.html?reason=missing_module_runtime';
                    }
                }
                
                // Build URL with parameters
                const params = new URLSearchParams();
                Object.keys(settings || {}).forEach((key) => {
                    const value = settings[key];
                    if (value === undefined || value === null) {
                        return;
                    }
                    if (typeof value === 'object') {
                        try {
                            params.set(key, JSON.stringify(value));
                        } catch (_) {
                        }
                        return;
                    }
                    params.set(key, String(value));
                });
                const url = modulePath + (params.toString() ? '?' + params.toString() : '');
                
                return url;
            }

            nextModule() {
                this.currentIndex++;
                
                // Loop back to start
                if (this.currentIndex >= this.currentLoop.length) {
                    const boundaryNow = new Date();
                    this.log('Loop boundary reached, evaluating local schedule at ' + boundaryNow.toISOString());
                    const nextLoop = this.resolveActiveLoop(boundaryNow);
                    const currentLoopId = this.currentLoopId;

                    if (nextLoop.id !== currentLoopId) {
                        this.log('Schedule changed: ' + currentLoopId + ' -> ' + nextLoop.id);
                        this.switchToLoop(nextLoop, true);
                    } else {
                        this.currentIndex = 0;
                    }

                    this.log('Loop completed, restarting...');
                }
                
                this.playCurrentModule();
            }
            
            log(message) {
                const timestamp = new Date().toISOString();
                console.log('[' + timestamp + '] [LoopPlayer] ' + message);

                if (this.debugModeEnabled && this.debugTerminalBody) {
                    const current = this.debugTerminalBody.textContent || '';
                    const next = (current + '\n[' + timestamp + '] [loop] ' + message).split('\n').slice(-26).join('\n');
                    this.debugTerminalBody.textContent = next;
                    this.debugTerminalBody.scrollTop = this.debugTerminalBody.scrollHeight;
                }
            }
            
            showError(message, error) {
                console.error('[LoopPlayer ERROR]', message, error);
                
                this.errorDisplay.style.display = 'block';
                document.getElementById('error-message').textContent = message;
                
                const now = new Date().toISOString();
                const errorName = (error && error.name) ? error.name : 'Error';
                const errorMessage = (error && error.message) ? error.message : String(error);
                const errorStack = (error && error.stack) ? error.stack : '';
                const lastModule = (this.currentLoop && this.currentLoop.length > 0)
                    ? this.currentLoop[this.currentIndex] : null;
                const lastModuleInfo = lastModule
                    ? JSON.stringify({
                        module_name: lastModule.module_name,
                        module_key: lastModule.module_key,
                        duration_seconds: lastModule.duration_seconds
                    }, null, 2)
                    : 'N/A';
                const loopInfo = this.loopConfig
                    ? JSON.stringify({
                        last_update: this.loopConfig.last_update,
                        active_loop_id: this.currentLoopId,
                        active_loop_count: (this.currentLoop || []).length,
                        base_loop_count: (((this.loopConfig.offline_plan || {}).base_loop) || []).length,
                        block_count: (((this.loopConfig.offline_plan || {}).time_blocks) || []).length
                    }, null, 2)
                    : 'N/A';
                const locationInfo = JSON.stringify({
                    page: window.location.href,
                    loop_json: 'modules/loop.json'
                }, null, 2);
                
                const details = [
                    'Timestamp: ' + now,
                    'Error Name: ' + errorName,
                    'Error Message: ' + errorMessage,
                    'Page: ' + window.location.href,
                    'Loop Info: ' + loopInfo,
                    'Last Module: ' + lastModuleInfo,
                    'Stack Trace:\n' + (errorStack || '(no stack)')
                ].join('\n\n');
                
                document.getElementById('error-details').textContent = details;
                
                // Hide error after 10 seconds and retry
                setTimeout(() => {
                    this.errorDisplay.style.display = 'none';
                    this.log('Retrying after error...');
                    this.init();
                }, 10000);
            }
        }
        
        // Initialize player when page loads
        window.addEventListener('DOMContentLoaded', () => {
            const player = new LoopPlayer();
        });
        
        // Prevent context menu (right click)
        document.addEventListener('contextmenu', (e) => e.preventDefault());
        
        // Prevent text selection
        document.addEventListener('selectstart', (e) => e.preventDefault());
    </script>
</body>
</html>
LOOP_PLAYER_EOF
    
    log_success "Loop player HTML created: $LOOP_PLAYER"
}

# Main download process
main() {
    log "=========================================="
    log "EduDisplej Module Download Starting..."
    log "=========================================="
    
    # Check for jq
    if ! command -v jq >/dev/null 2>&1; then
        log_error "jq is required. Installing..."
        DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none NEEDRESTART_MODE=a \
            apt-get -o Dpkg::Use-Pty=0 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold \
            -o Acquire::Retries=3 -o Acquire::http::Timeout=30 -o Acquire::https::Timeout=30 \
            update < /dev/null \
            && DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none NEEDRESTART_MODE=a \
            apt-get -y -o Dpkg::Use-Pty=0 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold \
            -o Acquire::Retries=3 -o Acquire::http::Timeout=30 -o Acquire::https::Timeout=30 \
            install jq < /dev/null || {
            log_error "Failed to install jq"
            exit 1
        }
    fi
    
    # Load device ID
    local device_id=$(load_device_id)
    if [ -z "$device_id" ]; then
        log_error "Cannot proceed without device ID"
        exit 1
    fi
    
    log "Device ID: $device_id"
    
    # Get loop configuration
    local loop_response=$(get_loop_config "$device_id")
    if [ $? -ne 0 ]; then
        log_error "Failed to get loop configuration"
        exit 1
    fi
    
    # Save loop configuration
    save_loop_config "$loop_response"
    
    # Extract module list from API payload (module_key + module_folder)
    local module_entries
    module_entries=$(echo "$loop_response" | jq -r '
        def canon($k):
            (($k // "") | ascii_downcase | gsub("^\\s+|\\s+$"; "")) as $x
            | if $x == "meal_menu" then "meal-menu"
              elif $x == "room_occupancy" then "room-occupancy"
              elif $x == "default_logo" then "default-logo"
              elif $x == "image_gallery" then "image-gallery"
              else $x
              end;
        [
            .loop_config[]?,
            .preload_modules[]?,
            .offline_plan.base_loop[]?,
            (.offline_plan.time_blocks[]?.loops[]?)
        ]
        | map(.module_key = canon(.module_key))
        | map(.module_folder = canon((.module_folder // .module_key)))
        | map(select((.module_key // "") != ""))
        | unique_by(.module_key)
        | .[]
        | [.module_key, (.module_folder // .module_key)]
        | @tsv
    ')

    local total_modules=0
    local failed_modules=()
    local resolved_module_dirs=()

    while IFS=$'\t' read -r module module_dir_key; do
        [ -z "${module:-}" ] && continue
        module="$(canonicalize_module_key "$module")"
        [ -z "${module_dir_key:-}" ] && module_dir_key="$module"
        module_dir_key="$(canonicalize_module_key "$module_dir_key")"

        if [ "$module" = "turned-off" ]; then
            log "Skipping virtual module: $module"
            continue
        fi

        total_modules=$((total_modules + 1))

        local attempt=1
        local downloaded=false
        while [ $attempt -le 3 ]; do
            if download_module "$device_id" "$module" "$module_dir_key"; then
                downloaded=true
                break
            fi
            log_error "Download attempt ${attempt}/3 failed for module: $module"
            attempt=$((attempt + 1))
            [ $attempt -le 3 ] && sleep 2
        done

        if [ "$downloaded" != true ]; then
            log_error "Failed to download module after retries: $module"
            failed_modules+=("$module")
        else
            resolved_module_dirs+=("$module_dir_key")
        fi
    done <<< "$module_entries"

    if [ $total_modules -eq 0 ]; then
        log "No downloadable modules resolved from loop payload (virtual-only plan)"
    fi

    if [ ${#failed_modules[@]} -gt 0 ]; then
        log_error "Required modules failed to download: ${failed_modules[*]}"
        exit 1
    fi

    # Download and localize external assets referenced by module settings
    prefetch_loop_assets_and_meal_json || log_error "Asset prefetch / meal JSON cache failed"

    # Enrich loop entries with runtime metadata detected from downloaded files
    enrich_loop_runtime_metadata || log_error "Loop runtime metadata enrichment failed"

    # Remove stale module folders and stale meal JSON assets not referenced by current loop
    cleanup_stale_local_content "${resolved_module_dirs[@]}" || log_error "Stale local content cleanup failed"
    
    # Create unconfigured page and loop player
    create_unconfigured_page
    create_loop_player
    
    log "=========================================="
    log_success "All modules downloaded successfully!"
    log "=========================================="
    log "Loop config: $LOOP_FILE"
    log "Modules dir: $MODULES_DIR"
    log "Loop player: $LOOP_PLAYER"
    echo ""
}

# Run main function
main "$@"
