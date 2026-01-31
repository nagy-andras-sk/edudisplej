#!/bin/bash
# EduDisplej Sync Service
# Handles registration and synchronization with control panel
# =============================================================================

# Configuration
API_URL="${EDUDISPLEJ_API_URL:-http://control.edudisplej.sk/api.php}"
SYNC_INTERVAL=300  # Default 5 minutes
CONFIG_DIR="/opt/edudisplej"
CONFIG_FILE="$CONFIG_DIR/kiosk.conf"

# Ensure config directory exists
mkdir -p "$CONFIG_DIR"

# Get MAC address
get_mac_address() {
    # Get the first non-loopback network interface MAC address
    ip link show | grep -A1 "state UP" | grep "link/ether" | head -1 | awk '{print $2}' | tr -d ':'
}

# Get hostname
get_hostname() {
    hostname
}

# Get hardware info
get_hw_info() {
    cat << EOF
{
    "hostname": "$(hostname)",
    "os": "$(lsb_release -ds 2>/dev/null || echo 'Unknown')",
    "kernel": "$(uname -r)",
    "architecture": "$(uname -m)",
    "cpu": "$(grep 'model name' /proc/cpuinfo | head -1 | cut -d: -f2 | xargs)",
    "memory": "$(free -h | awk '/^Mem:/ {print $2}')",
    "uptime": "$(uptime -p)"
}
EOF
}

# Register kiosk
register_kiosk() {
    local mac=$(get_mac_address)
    local hostname=$(get_hostname)
    local hw_info=$(get_hw_info)
    
    echo "Registering kiosk..."
    echo "MAC: $mac"
    echo "Hostname: $hostname"
    
    response=$(curl -s -X POST "$API_URL?action=register" \
        -H "Content-Type: application/json" \
        -d "{\"mac\":\"$mac\",\"hostname\":\"$hostname\",\"hw_info\":$hw_info}")
    
    if echo "$response" | grep -q '"success":true'; then
        kiosk_id=$(echo "$response" | grep -o '"kiosk_id":[0-9]*' | cut -d: -f2)
        echo "KIOSK_ID=$kiosk_id" > "$CONFIG_FILE"
        echo "MAC=$mac" >> "$CONFIG_FILE"
        echo "Registration successful! Kiosk ID: $kiosk_id"
        return 0
    else
        echo "Registration failed: $response"
        return 1
    fi
}

# Sync with server
sync_kiosk() {
    local mac=$(get_mac_address)
    local hostname=$(get_hostname)
    local hw_info=$(get_hw_info)
    
    response=$(curl -s -X POST "$API_URL?action=sync" \
        -H "Content-Type: application/json" \
        -d "{\"mac\":\"$mac\",\"hostname\":\"$hostname\",\"hw_info\":$hw_info}")
    
    if echo "$response" | grep -q '"success":true'; then
        # Extract sync interval
        new_interval=$(echo "$response" | grep -o '"sync_interval":[0-9]*' | cut -d: -f2)
        if [ -n "$new_interval" ]; then
            SYNC_INTERVAL=$new_interval
        fi
        
        # Check if screenshot requested
        screenshot_requested=$(echo "$response" | grep -o '"screenshot_requested":[a-z]*' | cut -d: -f2)
        if [ "$screenshot_requested" = "true" ]; then
            echo "Screenshot requested, capturing..."
            capture_screenshot
        fi
        
        # Download and update modules
        sync_modules
        
        echo "Sync successful (interval: ${SYNC_INTERVAL}s)"
        return 0
    else
        echo "Sync failed: $response"
        return 1
    fi
}

# Sync modules from server
sync_modules() {
    local mac=$(get_mac_address)
    local kiosk_id=$(grep "KIOSK_ID=" "$CONFIG_FILE" 2>/dev/null | cut -d= -f2)
    
    echo "Syncing modules..."
    
    response=$(curl -s -X POST "$API_URL?action=modules" \
        -H "Content-Type: application/json" \
        -d "{\"mac\":\"$mac\",\"kiosk_id\":$kiosk_id}")
    
    if echo "$response" | grep -q '"success":true'; then
        # Save module configuration
        echo "$response" > "$CONFIG_DIR/modules.json"
        
        # Check if configured
        is_configured=$(echo "$response" | grep -o '"is_configured":[a-z]*' | cut -d: -f2)
        
        if [ "$is_configured" = "false" ]; then
            echo "Kiosk not configured, showing unconfigured screen"
            update_content_unconfigured
        else
            echo "Kiosk configured, updating content"
            update_content_configured
        fi
        
        return 0
    else
        echo "Failed to sync modules: $response"
        return 1
    fi
}

# Update content for unconfigured kiosk
update_content_unconfigured() {
    local content_dir="$CONFIG_DIR/content"
    mkdir -p "$content_dir"
    
    # Download unconfigured.html from server
    curl -s -o "$content_dir/index.html" \
        "http://server.edudisplej.sk/unconfigured.html" || {
        echo "Failed to download unconfigured.html"
        return 1
    }
    
    # Store device info in localStorage via JS
    local mac=$(get_mac_address)
    local device_id=$(grep "DEVICE_ID=" "$CONFIG_FILE" 2>/dev/null | cut -d= -f2 || echo "N/A")
    
    cat >> "$content_dir/index.html" << EOF
<script>
localStorage.setItem('edudisplej_device_id', '$device_id');
localStorage.setItem('edudisplej_mac', '$mac');
</script>
EOF
    
    echo "Unconfigured content updated"
}

# Update content for configured kiosk
update_content_configured() {
    local content_dir="$CONFIG_DIR/content"
    mkdir -p "$content_dir"
    
    # Parse modules from modules.json
    local modules=$(cat "$CONFIG_DIR/modules.json" | grep -o '"modules":\[.*\]' | sed 's/"modules"://')
    
    if [ -z "$modules" ] || [ "$modules" = "[]" ]; then
        echo "No modules configured"
        update_content_unconfigured
        return 1
    fi
    
    # Download module HTML files
    echo "Downloading module content..."
    
    # Create index.html with module rotation logic
    create_module_loader "$content_dir"
    
    echo "Configured content updated"
}

# Create module loader HTML
create_module_loader() {
    local content_dir="$1"
    local modules_json=$(cat "$CONFIG_DIR/modules.json")
    
    cat > "$content_dir/index.html" << 'EOF'
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EduDisplej</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            overflow: hidden;
            background: #000;
        }
        iframe {
            width: 100vw;
            height: 100vh;
            border: none;
        }
    </style>
</head>
<body>
    <iframe id="contentFrame" src=""></iframe>
    <script>
        const modulesConfig = %MODULES_JSON%;
        let currentIndex = 0;
        const frame = document.getElementById('contentFrame');
        
        function loadNextModule() {
            if (modulesConfig.modules.length === 0) {
                window.location.href = '/unconfigured.html';
                return;
            }
            
            const module = modulesConfig.modules[currentIndex];
            const moduleUrl = '/modules/' + module.module_key + '.html';
            
            console.log('Loading module:', module.module_key);
            frame.src = moduleUrl;
            
            // Schedule next module
            const duration = (module.duration_seconds || 10) * 1000;
            setTimeout(() => {
                currentIndex = (currentIndex + 1) % modulesConfig.modules.length;
                loadNextModule();
            }, duration);
        }
        
        // Start rotation
        loadNextModule();
        
        // Reload page every hour to get config updates
        setTimeout(() => window.location.reload(), 60 * 60 * 1000);
    </script>
</body>
</html>
EOF
    
    # Replace %MODULES_JSON% with actual JSON
    sed -i "s|%MODULES_JSON%|$modules_json|g" "$content_dir/index.html"
}


# Capture and upload screenshot
capture_screenshot() {
    local mac=$(get_mac_address)
    local screenshot_file="/tmp/edudisplej_screenshot_$(date +%s).png"
    local display="${DISPLAY:-:0}"
    
    # Capture screenshot using scrot or import (ImageMagick)
    if command -v scrot >/dev/null 2>&1; then
        DISPLAY="$display" scrot "$screenshot_file" 2>/dev/null
    elif command -v import >/dev/null 2>&1; then
        DISPLAY="$display" import -window root "$screenshot_file" 2>/dev/null
    else
        echo "Screenshot tool not available (scrot or imagemagick required)"
        return 1
    fi
    
    if [ -f "$screenshot_file" ]; then
        # Convert to base64
        screenshot_base64=$(base64 -w 0 "$screenshot_file")
        
        # Upload
        response=$(curl -s -X POST "$API_URL?action=screenshot" \
            -H "Content-Type: application/json" \
            -d "{\"mac\":\"$mac\",\"screenshot\":\"data:image/png;base64,$screenshot_base64\"}")
        
        # Clean up
        rm -f "$screenshot_file"
        
        if echo "$response" | grep -q '"success":true'; then
            echo "Screenshot uploaded successfully"
            return 0
        else
            echo "Screenshot upload failed: $response"
            return 1
        fi
    else
        echo "Failed to capture screenshot"
        return 1
    fi
}

# Main sync loop
main() {
    echo "EduDisplej Sync Service Starting..."
    echo "API URL: $API_URL"
    
    # Check if already registered
    if [ ! -f "$CONFIG_FILE" ]; then
        echo "Kiosk not registered, registering now..."
        register_kiosk || {
            echo "Failed to register, will retry on next sync"
        }
    fi
    
    # Main loop
    while true; do
        echo "---"
        echo "$(date): Syncing..."
        
        if sync_kiosk; then
            echo "Next sync in ${SYNC_INTERVAL} seconds"
        else
            echo "Sync failed, retrying in ${SYNC_INTERVAL} seconds"
        fi
        
        sleep "$SYNC_INTERVAL"
    done
}

# Handle arguments
case "${1:-}" in
    start)
        main
        ;;
    register)
        register_kiosk
        ;;
    sync)
        sync_kiosk
        ;;
    screenshot)
        capture_screenshot
        ;;
    *)
        echo "Usage: $0 {start|register|sync|screenshot}"
        echo "  start      - Start sync service loop"
        echo "  register   - Register kiosk once"
        echo "  sync       - Sync once"
        echo "  screenshot - Capture and upload screenshot"
        exit 1
        ;;

# Create named pipe for logging
SYNC_LOG_PIPE="/tmp/edudisplej_sync.log"
mkfifo "$SYNC_LOG_PIPE" 2>/dev/null || true

# Logging function
log_sync_status() {
    local status="$1"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] $status" | tee -a "$SYNC_LOG_PIPE"
}

# Override sync functions to use logging
sync_kiosk() {
    local mac=$(get_mac_address)
    local hostname=$(get_hostname)
    local hw_info=$(get_hw_info)
    
    log_sync_status "🔄 Syncing kiosk..."
    
    response=$(curl -s -X POST "$API_URL?action=sync" \
        -H "Content-Type: application/json" \
        -d "{\"mac\":\"$mac\",\"hostname\":\"$hostname\",\"hw_info\":$hw_info}")
    
    if echo "$response" | grep -q '"success":true'; then
        new_interval=$(echo "$response" | grep -o '"sync_interval":[0-9]*' | cut -d: -f2)
        if [ -n "$new_interval" ]; then
            SYNC_INTERVAL=$new_interval
        fi
        
        screenshot_requested=$(echo "$response" | grep -o '"screenshot_requested":[a-z]*' | cut -d: -f2)
        if [ "$screenshot_requested" = "true" ]; then
            log_sync_status "📸 Screenshot requested"
            capture_screenshot
        fi
        
        log_sync_status "✅ Sync OK (${SYNC_INTERVAL}s)"
        return 0
    else
        log_sync_status "❌ Sync failed"
        return 1
    fi
}

# Update main loop
main() {
    log_sync_status "🚀 EduDisplej Sync Service started"
    
    if [ ! -f "$CONFIG_FILE" ]; then
        log_sync_status "📝 Registering kiosk..."
        register_kiosk || log_sync_status "⚠️ Registration failed"
    fi
    
    while true; do
        sync_kiosk
        sleep "$SYNC_INTERVAL"
    done
}
