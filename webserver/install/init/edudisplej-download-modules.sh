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
    local module_dir_key="$module_name"
    case "$module_name" in
        clock|datetime|dateclock)
            module_dir_key="datetime"
            ;;
        default-logo)
            module_dir_key="default"
            ;;
        *)
            module_dir_key="$module_name"
            ;;
    esac
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
        # Parse loop data and add metadata
        local loop_data=$(echo "$loop_response" | jq '.loop_config')
        local loop_last_update=$(echo "$loop_response" | jq -r '.loop_last_update // empty')
        [ -z "$loop_last_update" ] && loop_last_update="$(date '+%Y-%m-%d %H:%M:%S')"
        
        # Create loop config with timestamp
        cat > "$LOOP_FILE" <<EOF
{
    "last_update": "$loop_last_update",
    "loop": $loop_data
}
EOF
        
        log_success "Loop configuration saved to $LOOP_FILE"
        log "  Last update: $loop_last_update"
        
        # Store download info locally
        cat > "${MODULES_DIR}/.download_info.json" <<EOF
{
    "last_download": "$(date '+%Y-%m-%d %H:%M:%S')",
    "loop_last_update": "$loop_last_update"
}
EOF
        
        # Pretty print loop info
        local modules_count=$(echo "$loop_data" | jq -r 'length')
        log "Loop contains $modules_count modules:"
        
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
                this.currentIndex = 0;
                this.timer = null;
                this.frame = document.getElementById('module-frame');
                this.errorDisplay = document.getElementById('error-display');
                this.debugTerminal = document.getElementById('debug-terminal');
                this.debugTerminalBody = document.getElementById('debug-terminal-body');
                this.debugModeEnabled = false;
                this.debugModeTimer = null;
                this.debugLogTimer = null;
                
                this.init();
            }
            
            async init() {
                this.log('Loop Player initializing...');
                
                try {
                    await this.loadLoopConfig();
                    
                    if (!this.loopConfig || !this.loopConfig.loop || this.loopConfig.loop.length === 0) {
                        this.redirectToUnconfigured('No modules configured in loop');
                        throw new Error('No modules configured in loop');
                    }
                    
                    this.log('Loaded ' + this.loopConfig.loop.length + ' modules');
                    this.log('Last update: ' + this.loopConfig.last_update);
                    
                    // Start periodic check for configuration updates
                    this.startUpdateChecker();
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
                // Check for configuration updates every 30 seconds
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
                }, 30000); // Check every 30 seconds
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
                    
                    // Validate loop configuration
                    if (!this.loopConfig || !this.loopConfig.loop) {
                        this.redirectToUnconfigured('Invalid loop configuration');
                        throw new Error('Invalid loop configuration format - missing "loop" or "loop_config" field');
                    }
                    
                    if (!Array.isArray(this.loopConfig.loop) || this.loopConfig.loop.length === 0) {
                        this.redirectToUnconfigured('Empty loop configuration');
                        throw new Error('Loop configuration is empty or not an array');
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
            
            playCurrentModule() {
                if (!this.loopConfig || !this.loopConfig.loop || this.loopConfig.loop.length === 0) {
                    this.showError('Loop configuration is empty', new Error('No modules to play'));
                    return;
                }
                
                const module = this.loopConfig.loop[this.currentIndex];
                const duration = parseInt(module.duration_seconds) * 1000;
                
                this.log('Playing module ' + (this.currentIndex + 1) + '/' + this.loopConfig.loop.length + ': ' + module.module_name + ' (' + module.duration_seconds + 's)');
                
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
                let basePath = '';
                let mainFile = '';
                
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
                
                // Determine module path and main file
                switch (moduleKey) {
                    case 'clock':
                    case 'datetime':
                    case 'dateclock':
                        basePath = 'modules/datetime';
                        mainFile = 'm_datetime.html';
                        break;
                    case 'default-logo':
                        basePath = 'modules/default';
                        mainFile = 'm_default.html';
                        break;
                    default:
                        basePath = 'modules/' + moduleKey;
                        mainFile = 'm_' + moduleKey + '.html';
                        break;
                }
                
                // Build URL with parameters
                const params = new URLSearchParams(settings);
                const url = basePath + '/' + mainFile + (params.toString() ? '?' + params.toString() : '');
                
                return url;
            }
            
            nextModule() {
                this.currentIndex++;
                
                // Loop back to start
                if (this.currentIndex >= this.loopConfig.loop.length) {
                    this.currentIndex = 0;
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
                const lastModule = (this.loopConfig && this.loopConfig.loop && this.loopConfig.loop.length > 0)
                    ? this.loopConfig.loop[this.currentIndex] : null;
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
                        loop_count: (this.loopConfig.loop || []).length
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
        apt-get update && apt-get install -y jq || {
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
    
    # Extract module list (use module_key, not module_name to avoid spaces)
    local modules=$(echo "$loop_response" | jq -r '.loop_config[].module_key' | sort -u)
    
    # Download each module
    for module in $modules; do
        download_module "$device_id" "$module" || {
            log_error "Failed to download module: $module"
        }
    done
    
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
