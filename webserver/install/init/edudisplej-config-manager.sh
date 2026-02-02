#!/bin/bash
# EduDisplej Configuration Manager
# Helper script for managing /opt/edudisplej/data/config.json
# =============================================================================

set -euo pipefail

CONFIG_DIR="/opt/edudisplej"
DATA_DIR="${CONFIG_DIR}/data"
CONFIG_FILE="${DATA_DIR}/config.json"

# Create directories
mkdir -p "$DATA_DIR"

# Initialize config.json if it doesn't exist
init_config() {
    if [ -f "$CONFIG_FILE" ]; then
        echo "Config file already exists: $CONFIG_FILE"
        return 0
    fi
    
    echo "Creating new config file: $CONFIG_FILE"
    
    cat > "$CONFIG_FILE" <<'EOF'
{
    "company_name": "",
    "company_id": null,
    "device_id": "",
    "token": "",
    "sync_interval": 300,
    "last_update": "",
    "last_sync": "",
    "screenshot_enabled": false,
    "last_screenshot": "",
    "module_versions": {},
    "service_versions": {}
}
EOF
    
    chmod 644 "$CONFIG_FILE"
    echo "Config file created successfully"
}

# Update a config value
update_config() {
    local key="$1"
    local value="$2"
    
    if [ ! -f "$CONFIG_FILE" ]; then
        init_config
    fi
    
    if ! command -v jq >/dev/null 2>&1; then
        echo "Error: jq is required for config updates"
        return 1
    fi
    
    local temp_file=$(mktemp)
    
    # Handle different value types
    if [[ "$value" =~ ^[0-9]+$ ]]; then
        # Numeric value
        jq --arg k "$key" --argjson v "$value" '.[$k] = $v' "$CONFIG_FILE" > "$temp_file"
    elif [ "$value" = "true" ] || [ "$value" = "false" ]; then
        # Boolean value
        jq --arg k "$key" --argjson v "$value" '.[$k] = $v' "$CONFIG_FILE" > "$temp_file"
    elif [ "$value" = "null" ]; then
        # Null value
        jq --arg k "$key" '.[$k] = null' "$CONFIG_FILE" > "$temp_file"
    else
        # String value
        jq --arg k "$key" --arg v "$value" '.[$k] = $v' "$CONFIG_FILE" > "$temp_file"
    fi
    
    mv "$temp_file" "$CONFIG_FILE"
    echo "Updated $key = $value"
}

# Get a config value
get_config() {
    local key="$1"
    
    if [ ! -f "$CONFIG_FILE" ]; then
        echo ""
        return 1
    fi
    
    if command -v jq >/dev/null 2>&1; then
        jq -r ".$key // empty" "$CONFIG_FILE" 2>/dev/null
    else
        # Fallback without jq
        grep "\"$key\"" "$CONFIG_FILE" | sed 's/.*: *"\?\([^",]*\)"\?.*/\1/' | head -1
    fi
}

# Display current config
show_config() {
    if [ ! -f "$CONFIG_FILE" ]; then
        echo "Config file not found: $CONFIG_FILE"
        return 1
    fi
    
    if command -v jq >/dev/null 2>&1; then
        jq '.' "$CONFIG_FILE"
    else
        cat "$CONFIG_FILE"
    fi
}

# Migrate from old config files
migrate_from_old_config() {
    echo "Migrating from old configuration files..."
    
    # Initialize config if not exists
    if [ ! -f "$CONFIG_FILE" ]; then
        init_config
    fi
    
    # Migrate from kiosk.conf
    if [ -f "${CONFIG_DIR}/kiosk.conf" ]; then
        echo "Migrating from kiosk.conf..."
        
        if grep -q "DEVICE_ID=" "${CONFIG_DIR}/kiosk.conf"; then
            local device_id=$(grep "DEVICE_ID=" "${CONFIG_DIR}/kiosk.conf" | cut -d= -f2)
            update_config "device_id" "$device_id"
        fi
    fi
    
    # Migrate from sync_status.json
    if [ -f "${CONFIG_DIR}/sync_status.json" ]; then
        echo "Migrating from sync_status.json..."
        
        if command -v jq >/dev/null 2>&1; then
            local company_name=$(jq -r '.company_name // empty' "${CONFIG_DIR}/sync_status.json" 2>/dev/null)
            if [ -n "$company_name" ]; then
                update_config "company_name" "$company_name"
            fi
        fi
    fi
    
    echo "Migration completed"
}

# Main
case "${1:-help}" in
    init)
        init_config
        ;;
    update)
        if [ $# -lt 3 ]; then
            echo "Usage: $0 update <key> <value>"
            exit 1
        fi
        update_config "$2" "$3"
        ;;
    get)
        if [ $# -lt 2 ]; then
            echo "Usage: $0 get <key>"
            exit 1
        fi
        get_config "$2"
        ;;
    show)
        show_config
        ;;
    migrate)
        migrate_from_old_config
        ;;
    *)
        echo "EduDisplej Configuration Manager"
        echo ""
        echo "Usage: $0 <command> [options]"
        echo ""
        echo "Commands:"
        echo "  init                  Initialize config.json"
        echo "  update <key> <value>  Update a config value"
        echo "  get <key>             Get a config value"
        echo "  show                  Display current configuration"
        echo "  migrate               Migrate from old config files"
        echo ""
        exit 1
        ;;
esac
