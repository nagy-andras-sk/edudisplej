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

prefetch_loop_assets_and_meal_json() {
    if [ ! -f "$LOOP_FILE" ]; then
        log_error "Loop file not found for asset prefetch: $LOOP_FILE"
        return 1
    fi

    mkdir -p "$ASSETS_DIR"

    local token
    token=$(get_api_token) || { reset_to_unconfigured; return 1; }

    python3 - "$LOOP_FILE" "$ASSETS_DIR" "$MODULES_DIR" "$token" <<'PY'
import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlparse

loop_file = Path(sys.argv[1])
assets_dir = Path(sys.argv[2])
modules_dir = Path(sys.argv[3])
token = sys.argv[4]

assets_dir.mkdir(parents=True, exist_ok=True)

try:
    data = json.loads(loop_file.read_text(encoding='utf-8', errors='ignore'))
except Exception as exc:
    print(f"Failed to parse loop file: {exc}", file=sys.stderr)
    sys.exit(1)

stats = {
    'assets_scanned': 0,
    'assets_downloaded': 0,
    'assets_reused': 0,
    'assets_failed': 0,
    'meal_json_written': 0,
    'settings_patched': 0,
}

URL_RE = re.compile(r'^https?://', re.IGNORECASE)
ALLOWED_KEYWORDS = (
    '/uploads/',
    '/module_asset',
    '/module-assets',
    '/api/module_asset',
    '/api/module-assets',
)


def iter_all_entries(obj):
    if not isinstance(obj, dict):
        return
    for item in obj.get('loop') or []:
        if isinstance(item, dict):
            yield item
    offline = obj.get('offline_plan') or {}
    for item in offline.get('base_loop') or []:
        if isinstance(item, dict):
            yield item
    for block in offline.get('time_blocks') or []:
        if not isinstance(block, dict):
            continue
        for item in block.get('loops') or []:
            if isinstance(item, dict):
                yield item


def sanitize_ext_from_url(url: str) -> str:
    try:
        path = urlparse(url).path or ''
    except Exception:
        path = ''
    ext = Path(path).suffix.lower().strip()
    if ext and 1 < len(ext) <= 10 and re.match(r'^\.[a-z0-9]+$', ext):
        return ext
    return '.bin'


def should_cache_url(url: str) -> bool:
    if not url or not URL_RE.search(url):
        return False
    lower = url.lower()
    return any(keyword in lower for keyword in ALLOWED_KEYWORDS)


def local_asset_path_for(url: str) -> Path:
    digest = hashlib.sha1(url.encode('utf-8')).hexdigest()
    ext = sanitize_ext_from_url(url)
    return assets_dir / f"{digest}{ext}"


def download_to_file(url: str, target: Path) -> bool:
    if target.is_file() and target.stat().st_size > 0:
        stats['assets_reused'] += 1
        return True

    target.parent.mkdir(parents=True, exist_ok=True)
    cmd = [
        'curl', '-sS', '--fail', '--location', '--connect-timeout', '10', '--max-time', '90',
        '-H', f'Authorization: Bearer {token}',
        '-o', str(target),
        url,
    ]
    try:
        proc = subprocess.run(cmd, capture_output=True, text=True)
    except Exception:
        stats['assets_failed'] += 1
        return False

    if proc.returncode != 0:
        try:
            target.unlink(missing_ok=True)
        except Exception:
            pass
        stats['assets_failed'] += 1
        return False

    if not target.is_file() or target.stat().st_size <= 0:
        try:
            target.unlink(missing_ok=True)
        except Exception:
            pass
        stats['assets_failed'] += 1
        return False

    stats['assets_downloaded'] += 1
    return True


def rewrite_url_to_local(url: str, local_file: Path) -> str:
    return 'file://' + local_file.as_posix()


def maybe_patch_url(value):
    if not isinstance(value, str):
        return value, False
    candidate = value.strip()
    if not should_cache_url(candidate):
        return value, False
    stats['assets_scanned'] += 1
    local_target = local_asset_path_for(candidate)
    if not download_to_file(candidate, local_target):
        return value, False
    return rewrite_url_to_local(candidate, local_target), True


def patch_settings_assets(settings):
    if not isinstance(settings, dict):
        return settings

    for key, value in list(settings.items()):
        if isinstance(value, str):
            patched, changed = maybe_patch_url(value)
            if changed:
                settings[key] = patched
                stats['settings_patched'] += 1
                continue

            key_lower = key.lower()
            if key_lower.endswith('json') and ('url' in key_lower or 'image' in key_lower or 'asset' in key_lower):
                try:
                    parsed = json.loads(value)
                except Exception:
                    parsed = None
                if isinstance(parsed, list):
                    out = []
                    list_changed = False
                    for entry in parsed:
                        if isinstance(entry, str):
                            p, ch = maybe_patch_url(entry)
                            out.append(p)
                            list_changed = list_changed or ch
                        else:
                            out.append(entry)
                    if list_changed:
                        settings[key] = json.dumps(out, ensure_ascii=False)
                        stats['settings_patched'] += 1

        elif isinstance(value, list):
            changed = False
            out = []
            for entry in value:
                if isinstance(entry, str):
                    p, ch = maybe_patch_url(entry)
                    out.append(p)
                    changed = changed or ch
                else:
                    out.append(entry)
            if changed:
                settings[key] = out
                stats['settings_patched'] += 1

    return settings


def normalize_meal_payload(payload, fallback_date: str):
    if not isinstance(payload, dict):
        return None
    meals = payload.get('meals') if isinstance(payload.get('meals'), list) else []
    normalized = {
        'institution_name': str(payload.get('institution_name') or ''),
        'menu_date': str(payload.get('menu_date') or fallback_date),
        'meals': meals,
        'source_type': str(payload.get('source_type') or ''),
        'updated_at': str(payload.get('updated_at') or ''),
    }
    return normalized


def write_meal_json_files(entry):
    module_key = str(entry.get('module_key') or '').strip().lower()
    if module_key not in ('meal-menu', 'meal_menu'):
        return

    settings = entry.get('settings') if isinstance(entry.get('settings'), dict) else {}
    prefetched = settings.get('offlinePrefetchedMenuData')
    if not isinstance(prefetched, dict):
        return

    module_folder = str(entry.get('module_folder') or '').strip() or module_key
    target_dir = modules_dir / module_folder / 'offline'
    target_dir.mkdir(parents=True, exist_ok=True)

    fallback_today = str(prefetched.get('menu_date') or '')
    today_payload = None
    tomorrow_payload = None

    if isinstance(prefetched.get('today'), dict) or isinstance(prefetched.get('tomorrow'), dict):
        today_payload = normalize_meal_payload(prefetched.get('today'), fallback_today)
        tomorrow_payload = normalize_meal_payload(prefetched.get('tomorrow'), '')
    else:
        today_payload = normalize_meal_payload(prefetched, fallback_today)

    if today_payload is not None:
        today_file = target_dir / 'mai.json'
        today_file.write_text(json.dumps(today_payload, ensure_ascii=False, indent=2), encoding='utf-8')
        stats['meal_json_written'] += 1
        settings['offlinePrefetchedTodayFile'] = 'offline/mai.json'

    if tomorrow_payload is not None:
        tomorrow_file = target_dir / 'holnapi.json'
        tomorrow_file.write_text(json.dumps(tomorrow_payload, ensure_ascii=False, indent=2), encoding='utf-8')
        stats['meal_json_written'] += 1
        settings['offlinePrefetchedTomorrowFile'] = 'offline/holnapi.json'

    entry['settings'] = settings


for item in iter_all_entries(data):
    settings = item.get('settings') if isinstance(item.get('settings'), dict) else {}
    settings = patch_settings_assets(settings)
    item['settings'] = settings
    write_meal_json_files(item)

loop_file.write_text(json.dumps(data, ensure_ascii=False, indent=4), encoding='utf-8')
print(json.dumps(stats, ensure_ascii=False))
PY

    log_success "Loop asset prefetch + meal JSON cache completed"
    return 0
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
                const params = new URLSearchParams(settings);
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
        [
            .loop_config[]?,
            .preload_modules[]?,
            .offline_plan.base_loop[]?,
            (.offline_plan.time_blocks[]?.loops[]?)
        ]
        | map(select((.module_key // "") != ""))
        | unique_by(.module_key)
        | .[]
        | [.module_key, (.module_folder // .module_key)]
        | @tsv
    ')

    local total_modules=0
    local failed_modules=()

    while IFS=$'\t' read -r module module_dir_key; do
        [ -z "${module:-}" ] && continue
        [ -z "${module_dir_key:-}" ] && module_dir_key="$module"
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
        fi
    done <<< "$module_entries"

    if [ $total_modules -eq 0 ]; then
        log_error "No modules resolved from loop payload; cannot continue"
        exit 1
    fi

    if [ ${#failed_modules[@]} -gt 0 ]; then
        log_error "Required modules failed to download: ${failed_modules[*]}"
        exit 1
    fi

    # Download and localize external assets referenced by module settings
    prefetch_loop_assets_and_meal_json || log_error "Asset prefetch / meal JSON cache failed"

    # Enrich loop entries with runtime metadata detected from downloaded files
    enrich_loop_runtime_metadata || log_error "Loop runtime metadata enrichment failed"
    
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
