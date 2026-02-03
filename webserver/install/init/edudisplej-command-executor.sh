#!/bin/bash
# EduDisplej Command Executor Service
# Fetches and executes commands from control panel
# =============================================================================

SERVICE_VERSION="1.0.0"

set -euo pipefail

# Source common functions if available
INIT_DIR="/opt/edudisplej/init"
if [[ -f "${INIT_DIR}/common.sh" ]]; then
    source "${INIT_DIR}/common.sh"
fi

# Configuration
API_BASE_URL="${EDUDISPLEJ_API_URL:-https://control.edudisplej.sk}"
GET_COMMANDS_API="${API_BASE_URL}/api/kiosk/get_commands.php"
COMMAND_RESULT_API="${API_BASE_URL}/api/kiosk/command_result.php"
CONFIG_DIR="/opt/edudisplej"
DATA_DIR="${CONFIG_DIR}/data"
CONFIG_FILE="${DATA_DIR}/config.json"
TOKEN_FILE="${CONFIG_DIR}/lic/token"
LOG_DIR="${CONFIG_DIR}/logs"
LOG_FILE="${LOG_DIR}/command_executor.log"
COMMAND_TIMEOUT=300  # 5 minutes timeout per command
DEBUG="${EDUDISPLEJ_DEBUG:-false}"

# Create directories
mkdir -p "$CONFIG_DIR" "$DATA_DIR" "$LOG_DIR"

# Logging functions
log() {
    local level="INFO"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [$level] $*" | tee -a "$LOG_FILE"
}

log_debug() {
    if [ "$DEBUG" = true ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] [DEBUG] $*" | tee -a "$LOG_FILE"
    fi
}

log_error() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [ERROR] $*" | tee -a "$LOG_FILE" >&2
}

log_success() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [SUCCESS] $*" | tee -a "$LOG_FILE"
}

# Get API token
get_api_token() {
    if [ -f "$TOKEN_FILE" ]; then
        cat "$TOKEN_FILE" | tr -d '\n'
    else
        return 1
    fi
}

# Get pending commands
fetch_commands() {
    local token="$1"
    
    log_debug "Fetching pending commands..."
    
    local response=$(curl -s -H "Authorization: Bearer $token" \
        --max-time 10 --connect-timeout 5 \
        "$GET_COMMANDS_API" 2>/dev/null || echo "{\"success\": false}")
    
    echo "$response"
}

# Execute a single command
execute_command() {
    local command_id="$1"
    local command_type="$2"
    local command="$3"
    
    log "Executing command $command_id ($command_type)"
    
    local output=""
    local error=""
    local status="executed"
    
    case "$command_type" in
        reboot)
            log "Initiating system reboot..."
            # Execute reboot (ignoring return since process will be killed)
            sudo shutdown -r now 2>&1 || true
            output="Reboot initiated"
            ;;
        
        enable_fast_loop)
            log "Enabling fast loop mode..."
            if touch "${CONFIG_DIR}/.fast_loop_enabled" 2>/dev/null; then
                output="Fast loop enabled"
                log_success "Fast loop enabled"
            else
                error="Failed to enable fast loop"
                status="failed"
                log_error "$error"
            fi
            ;;
        
        disable_fast_loop)
            log "Disabling fast loop mode..."
            if rm -f "${CONFIG_DIR}/.fast_loop_enabled" 2>/dev/null; then
                output="Fast loop disabled"
                log_success "Fast loop disabled"
            else
                error="Failed to disable fast loop"
                status="failed"
                log_error "$error"
            fi
            ;;
        
        restart_service)
            log "Restarting service: $command"
            if systemctl restart "$command" 2>&1; then
                output="Service restarted: $command"
                log_success "Service restarted: $command"
            else
                error="Failed to restart service: $command"
                status="failed"
                log_error "$error"
            fi
            ;;
        
        custom)
            log "Executing custom command..."
            # Execute command with timeout
            if output=$(timeout "$COMMAND_TIMEOUT" bash -c "$command" 2>&1); then
                status="executed"
                log_success "Command executed successfully"
            else
                local exit_code=$?
                if [ $exit_code -eq 124 ]; then
                    error="Command timeout (${COMMAND_TIMEOUT}s)"
                    status="timeout"
                else
                    error="Command failed with exit code: $exit_code"
                    status="failed"
                fi
                log_error "$error"
            fi
            ;;
        
        *)
            error="Unknown command type: $command_type"
            status="failed"
            log_error "$error"
            ;;
    esac
    
    echo "$command_id|$status|$output|$error"
}

# Report command result
report_command_result() {
    local token="$1"
    local command_id="$2"
    local status="$3"
    local output="$4"
    local error="$5"
    
    local payload=$(cat <<EOF
{
    "command_id": $command_id,
    "status": "$status",
    "output": $(echo -n "$output" | jq -Rs .),
    "error": $(echo -n "$error" | jq -Rs .)
}
EOF
)
    
    log_debug "Reporting command result: $command_id -> $status"
    
    local response=$(curl -s -X POST \
        -H "Authorization: Bearer $token" \
        -H "Content-Type: application/json" \
        -d "$payload" \
        --max-time 10 --connect-timeout 5 \
        "$COMMAND_RESULT_API" 2>/dev/null || echo "{\"success\": false}")
    
    if echo "$response" | grep -q '"success"[[:space:]]*:[[:space:]]*true'; then
        log_success "Command result reported: $command_id"
        return 0
    else
        log_error "Failed to report command result: $response"
        return 1
    fi
}

# Main loop
main() {
    log "Command Executor Service started (version: $SERVICE_VERSION)"
    
    # Get API token
    local token=""
    token=$(get_api_token) || {
        log_error "Failed to read API token - service cannot operate"
        sleep 60
        return 1
    }
    
    # Check for pending commands
    local response=$(fetch_commands "$token")
    
    if ! echo "$response" | grep -q '"success"[[:space:]]*:[[:space:]]*true'; then
        log_debug "No commands available or API error"
        return 1
    fi
    
    # Parse commands using jq if available, otherwise use grep
    if command -v jq >/dev/null 2>&1; then
        local commands=$(echo "$response" | jq -r '.commands[] | "\(.id)|\(.type)|\(.command)"' 2>/dev/null || echo "")
    else
        # Fallback: simple grep-based parsing (not ideal but works for simple JSON)
        local commands=$(echo "$response" | grep -o '"id":[0-9]*' | head -10)
    fi
    
    if [ -z "$commands" ]; then
        log_debug "No pending commands"
        return 0
    fi
    
    # Execute each command
    while IFS='|' read -r cmd_id cmd_type cmd; do
        [ -z "$cmd_id" ] && continue
        
        # Execute and get results
        local result=$(execute_command "$cmd_id" "$cmd_type" "$cmd")
        IFS='|' read -r r_id r_status r_output r_error <<< "$result"
        
        # Report result
        report_command_result "$token" "$r_id" "$r_status" "$r_output" "$r_error"
        
    done <<< "$commands"
}

# Trap signals
trap 'log "Command Executor Service stopped"; exit 0' SIGTERM SIGINT

# Main loop - check for commands every 30 seconds
while true; do
    main
    sleep 30
done
