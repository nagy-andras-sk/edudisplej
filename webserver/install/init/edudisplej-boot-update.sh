#!/bin/bash
# edudisplej-boot-update.sh - Boot-time Core Version Check and Auto-Update
# Ellenőrzi a core verziót bootkor és automatikusan frissít, ha szükséges
# =============================================================================

set -euo pipefail

# Configuration
TARGET_DIR="/opt/edudisplej"
INIT_DIR="${TARGET_DIR}/init"
VERSION_FILE="${TARGET_DIR}/VERSION"
TOKEN_FILE="${TARGET_DIR}/lic/token"
LOG_DIR="${TARGET_DIR}/logs"
LOG_FILE="${LOG_DIR}/boot_update.log"
LOCK_FILE="/tmp/edudisplej_boot_update.lock"
VERSIONS_URL="https://install.edudisplej.sk/init/versions.json"
FALLBACK_VERSIONS_LOCAL="${INIT_DIR}/versions.json"
UPDATE_SCRIPT="${INIT_DIR}/update.sh"
UPDATE_TIMEOUT=600  # 10 minutes max for update
API_BASE_URL="${EDUDISPLEJ_API_URL:-https://control.edudisplej.sk}"
DEVICE_SYNC_API="${API_BASE_URL}/api/v1/device/sync.php"
LAST_SYNC_RESPONSE="${TARGET_DIR}/last_sync_response.json"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Ensure directories exist
mkdir -p "$LOG_DIR" "$INIT_DIR"

# Logging function
log_boot() {
    local level="$1"
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    local log_line="[$timestamp] [$level] $message"
    
    echo "$log_line" >> "$LOG_FILE"
    
    case "$level" in
        INFO)
            echo -e "${BLUE}[BOOT-UPDATE]${NC} $message"
            ;;
        SUCCESS)
            echo -e "${GREEN}[BOOT-UPDATE]${NC} ✅ $message"
            ;;
        WARNING)
            echo -e "${YELLOW}[BOOT-UPDATE]${NC} ⚠️  $message"
            ;;
        ERROR)
            echo -e "${RED}[BOOT-UPDATE]${NC} ❌ $message"
            ;;
    esac
}

# Parse version into a sortable key.
# Timestamp-style core versions outrank semantic versions.
parse_version() {
    local version
    version="$(echo "$1" | tr -d '[:space:]')"
    version="${version#[vV]}"

    if [[ "$version" =~ ^[0-9]{14}$ ]]; then
        printf '2%s\n' "$version"
        return 0
    fi

    if [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        IFS='.' read -r major minor patch <<< "$version"
        printf '1%03d%03d%03d\n' "$major" "$minor" "$patch"
        return 0
    fi

    if [[ "$version" =~ ^[0-9]+$ ]]; then
        printf '1%014d\n' "$version"
        return 0
    fi

    printf '0%s\n' "$(echo "$version" | tr '[:upper:]' '[:lower:]')"
}

# Check if update is needed
check_version_mismatch() {
    local current="$1"
    local target="$2"
    
    if [ -z "$current" ]; then
        log_boot WARNING "Local version file not found or empty"
        return 0  # Version mismatch, update needed
    fi
    
    if [ -z "$target" ]; then
        log_boot WARNING "Target version not available"
        return 1  # Can't determine, skip update
    fi
    
    local current_int=$(parse_version "$current")
    local target_int=$(parse_version "$target")

    if [[ "$current_int" < "$target_int" ]]; then
        return 0  # Update needed
    fi
    
    return 1  # No update needed
}

# Fetch versions from server
fetch_server_versions() {
    local target_version=""
    
    # Try primary remote endpoint
    log_boot INFO "Fetching target version from server..."
    if command -v curl >/dev/null 2>&1; then
        local response
        response=$(curl -s --max-time 10 -f "$VERSIONS_URL" 2>/dev/null || echo "")
        
        if [ -n "$response" ] && echo "$response" | grep -q '"system_version"'; then
            if command -v jq >/dev/null 2>&1; then
                target_version=$(echo "$response" | jq -r '.system_version // empty' 2>/dev/null || echo "")
            else
                # Fallback: grep-based extraction
                target_version=$(echo "$response" | grep -o '"system_version"[[:space:]]*:[[:space:]]*"[^"]*"' | cut -d'"' -f4)
            fi
            
            if [ -n "$target_version" ]; then
                log_boot SUCCESS "Retrieved target version: $target_version"
                echo "$target_version"
                return 0
            fi
        fi
    fi
    
    # Fallback: Try cached sync response
    if [ -f "$LAST_SYNC_RESPONSE" ]; then
        log_boot INFO "Fetching version from cached sync response..."
        if command -v jq >/dev/null 2>&1; then
            target_version=$(jq -r '.latest_system_version // empty' "$LAST_SYNC_RESPONSE" 2>/dev/null || echo "")
        else
            target_version=$(grep -o '"latest_system_version"[[:space:]]*:[[:space:]]*"[^"]*"' "$LAST_SYNC_RESPONSE" 2>/dev/null | head -1 | cut -d'"' -f4)
        fi
        
        if [ -n "$target_version" ]; then
            log_boot SUCCESS "Retrieved target version from cache: $target_version"
            echo "$target_version"
            return 0
        fi
    fi
    
    # Final fallback: Local versions.json on kiosk
    if [ -f "$FALLBACK_VERSIONS_LOCAL" ]; then
        log_boot INFO "Fetching version from local versions.json..."
        if command -v jq >/dev/null 2>&1; then
            target_version=$(jq -r '.system_version // empty' "$FALLBACK_VERSIONS_LOCAL" 2>/dev/null || echo "")
        else
            target_version=$(grep -o '"system_version"[[:space:]]*:[[:space:]]*"[^"]*"' "$FALLBACK_VERSIONS_LOCAL" 2>/dev/null | head -1 | cut -d'"' -f4)
        fi
        
        if [ -n "$target_version" ]; then
            log_boot INFO "Retrieved target version from local fallback: $target_version"
            echo "$target_version"
            return 0
        fi
    fi
    
    log_boot WARNING "Could not determine target version from any source"
    return 1
}

# Get current version from kiosk
get_current_version() {
    if [ -f "$VERSION_FILE" ]; then
        tr -d '\n\r ' < "$VERSION_FILE"
        return 0
    fi
    return 1
}

# Trigger and wait for core update
trigger_core_update() {
    local target_version="$1"
    
    log_boot INFO "Triggering core update to version: $target_version"
    
    if [ ! -x "$UPDATE_SCRIPT" ]; then
        log_boot ERROR "Update script not executable: $UPDATE_SCRIPT"
        return 1
    fi
    
    # Call update script with core-only and boot source
    local update_log="${LOG_DIR}/boot_update_process.log"
    if bash "$UPDATE_SCRIPT" --core-only --source=boot --target-version="$target_version" >> "$update_log" 2>&1; then
        log_boot SUCCESS "Core update completed successfully"
        return 0
    else
        local exit_code=$?
        log_boot ERROR "Core update failed with exit code: $exit_code (see $update_log)"
        return 1
    fi
}

# Wait for update to complete (with timeout)
wait_for_update_completion() {
    local start_time=$(date +%s)
    local timeout=$UPDATE_TIMEOUT
    
    while true; do
        local current_time=$(date +%s)
        local elapsed=$((current_time - start_time))
        
        if [ $elapsed -gt $timeout ]; then
            log_boot WARNING "Update timeout after ${timeout}s - continuing with boot"
            return 1
        fi
        
        # Check if update process is still running
        if ! pgrep -f "update.sh" > /dev/null 2>&1; then
            # Update process finished
            if [ -f "$VERSION_FILE" ]; then
                local updated_version=$(get_current_version)
                log_boot INFO "Update process finished. Current version: $updated_version"
            fi
            return 0
        fi
        
        sleep 2
    done
}

# Main execution
main() {
    log_boot INFO "========================================"
    log_boot INFO "EduDisplej Boot-time Version Check"
    log_boot INFO "========================================"
    
    # Check if already running (prevent multiple instances)
    if [ -f "$LOCK_FILE" ]; then
        local lock_age=$(($(date +%s) - $(stat -c %Y "$LOCK_FILE" 2>/dev/null || date +%s)))
        if [ $lock_age -lt 60 ]; then
            log_boot WARNING "Boot update already in progress, skipping"
            return 0
        fi
        log_boot INFO "Stale lock file removed"
    fi
    
    # Create lock file
    echo "$$" > "$LOCK_FILE"
    trap "rm -f '$LOCK_FILE'" EXIT
    
    # Get current version
    local current_version
    current_version=$(get_current_version || echo "")
    log_boot INFO "Current version: ${current_version:-unknown}"
    
    # Get target version from server
    local target_version
    target_version=$(fetch_server_versions || echo "")
    
    if [ -z "$target_version" ]; then
        log_boot WARNING "Could not determine target version - skipping update check"
        return 0
    fi
    
    log_boot INFO "Target version: $target_version"
    
    # Check if update is needed
    if ! check_version_mismatch "$current_version" "$target_version"; then
        log_boot SUCCESS "Core is up-to-date ($current_version >= $target_version)"
        return 0
    fi
    
    log_boot INFO "Version mismatch detected: $current_version < $target_version"
    log_boot INFO "=== Starting boot-time core update ==="
    
    # Trigger update
    if trigger_core_update "$target_version"; then
        log_boot SUCCESS "Core update successful"
        return 0
    else
        # Update failed, but don't block boot - log warning and continue
        log_boot WARNING "Core update failed - kiosk will start with current version"
        return 1
    fi
}

# Run main function
main
exit_code=$?

log_boot INFO "Boot version check completed (exit code: $exit_code)"
log_boot INFO "========================================"

exit 0  # Always exit 0 to nicht block boot sequence even if update fails
