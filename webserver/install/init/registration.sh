#!/bin/bash
# registration.sh - Device registration to remote server
# All text is in Slovak (without diacritics) or English

# Source common functions if not already sourced
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "${TRANS_SK+x}" ]]; then
    source "${SCRIPT_DIR}/common.sh"
fi

# Registration configuration
REGISTRATION_URL="https://server.edudisplej.sk/api/register.php"
REGISTRATION_FILE="${EDUDISPLEJ_HOME}/.registration.json"
REGISTRATION_LOG="${EDUDISPLEJ_HOME}/registration.log"

# =============================================================================
# Registration Functions
# =============================================================================

# Get primary MAC address
get_primary_mac() {
    local mac
    # Try to get MAC from eth0 first, then wlan0, then any interface
    for iface in eth0 wlan0 $(ls /sys/class/net/ 2>/dev/null | grep -v lo); do
        if [[ -f "/sys/class/net/$iface/address" ]]; then
            mac=$(cat "/sys/class/net/$iface/address" 2>/dev/null)
            if [[ -n "$mac" && "$mac" != "00:00:00:00:00:00" ]]; then
                echo "$mac"
                return 0
            fi
        fi
    done
    
    # Fallback: use ip command
    mac=$(ip link show | grep -A1 "state UP" | grep ether | head -1 | awk '{print $2}')
    if [[ -n "$mac" ]]; then
        echo "$mac"
        return 0
    fi
    
    return 1
}

# Check if device is already registered
is_already_registered() {
    if [[ -f "$REGISTRATION_FILE" ]]; then
        if grep -q '"registered":true' "$REGISTRATION_FILE" 2>/dev/null; then
            return 0
        fi
    fi
    return 1
}

# Register device to remote server
register_device() {
    print_info "Registering device to server..."
    
    # Check if already registered
    if is_already_registered; then
        print_success "Device already registered (skipping)"
        return 0
    fi
    
    # Get device information
    local hostname
    hostname=$(hostname)
    
    local mac
    mac=$(get_primary_mac)
    
    if [[ -z "$mac" ]]; then
        print_error "Unable to determine MAC address"
        return 1
    fi
    
    print_info "Hostname: $hostname"
    print_info "MAC: $mac"
    
    # Prepare JSON payload
    local json_payload
    json_payload=$(cat <<EOF
{
    "hostname": "$hostname",
    "mac": "$mac"
}
EOF
)
    
    # Send registration request
    local response
    local http_code
    
    print_info "Sending registration to $REGISTRATION_URL"
    
    response=$(curl -w "\n%{http_code}" -s -X POST \
        -H "Content-Type: application/json" \
        -d "$json_payload" \
        "$REGISTRATION_URL" 2>&1)
    
    http_code=$(echo "$response" | tail -n1)
    local response_body
    response_body=$(echo "$response" | sed '$d')
    
    # Log the response
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Registration attempt" >> "$REGISTRATION_LOG"
    echo "  Hostname: $hostname" >> "$REGISTRATION_LOG"
    echo "  MAC: $mac" >> "$REGISTRATION_LOG"
    echo "  HTTP Code: $http_code" >> "$REGISTRATION_LOG"
    echo "  Response: $response_body" >> "$REGISTRATION_LOG"
    echo "" >> "$REGISTRATION_LOG"
    
    # Check response
    if [[ "$http_code" == "200" ]]; then
        # Parse response to check success
        if echo "$response_body" | grep -q '"success":true'; then
            print_success "Device registered successfully"
            
            # Extract device ID if available
            local device_id
            device_id=$(echo "$response_body" | grep -o '"id":[0-9]*' | cut -d':' -f2)
            
            # Save registration status to local file
            cat > "$REGISTRATION_FILE" <<EOF
{
    "registered": true,
    "timestamp": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
    "hostname": "$hostname",
    "mac": "$mac",
    "device_id": ${device_id:-null},
    "server_response": $response_body
}
EOF
            
            print_success "Registration saved to $REGISTRATION_FILE"
            return 0
        else
            print_error "Registration failed: $(echo "$response_body" | grep -o '"message":"[^"]*"' | cut -d'"' -f4)"
            return 1
        fi
    else
        print_error "Registration failed with HTTP code: $http_code"
        if [[ -n "$response_body" ]]; then
            print_error "Response: $response_body"
        fi
        return 1
    fi
}

# Show registration status
show_registration_status() {
    if is_already_registered; then
        print_success "Device is registered"
        if [[ -f "$REGISTRATION_FILE" ]]; then
            local device_id
            device_id=$(grep -o '"device_id":[0-9]*' "$REGISTRATION_FILE" 2>/dev/null | cut -d':' -f2)
            local timestamp
            timestamp=$(grep -o '"timestamp":"[^"]*"' "$REGISTRATION_FILE" 2>/dev/null | cut -d'"' -f4)
            
            if [[ -n "$device_id" && "$device_id" != "null" ]]; then
                echo "  Device ID: $device_id"
            fi
            if [[ -n "$timestamp" ]]; then
                echo "  Registered at: $timestamp"
            fi
        fi
    else
        print_warning "Device is not registered"
    fi
}

# Force re-registration (remove local registration file)
force_reregister() {
    if [[ -f "$REGISTRATION_FILE" ]]; then
        rm -f "$REGISTRATION_FILE"
        print_info "Registration file removed. Device will re-register on next boot."
    else
        print_info "No registration file found."
    fi
}
