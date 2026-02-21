#!/bin/bash
# edudisplej-hostname.sh - Automatic hostname configuration based on MAC address
# Sets hostname to: edudisplej-XXXXXX (last 6 chars of MAC address)
# =============================================================================

set -euo pipefail

EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
HOSTNAME_FLAG="${EDUDISPLEJ_HOME}/.hostname_configured"

# Source common functions if available
if [[ -f "${INIT_DIR}/common.sh" ]]; then
    source "${INIT_DIR}/common.sh"
else
    # Simple fallback logging functions
    print_info() { echo "[INFO] $1"; }
    print_success() { echo "[SUCCESS] $1"; }
    print_error() { echo "[ERROR] $1"; }
    print_warning() { echo "[WARNING] $1"; }
    
    # Fallback get_mac_address if common.sh not available
    get_mac_address() {
        if [[ -f /sys/class/net/eth0/address ]]; then
            cat /sys/class/net/eth0/address 2>/dev/null | tr -d ':' | tr '[:lower:]' '[:upper:]'
        elif command -v ip >/dev/null 2>&1; then
            ip link show | grep -A1 "state UP" | grep "link/ether" | head -1 | awk '{print $2}' | tr -d ':' | tr '[:lower:]' '[:upper:]'
        fi
    }
    
    get_mac_suffix() {
        local mac="$1"
        echo "${mac: -6}"
    }
fi

# =============================================================================
# Hostname Configuration
# =============================================================================

configure_hostname() {
    print_info "Configuring hostname based on MAC address..."
    
    # Get MAC address using shared function
    local mac=""
    if ! mac=$(get_mac_address); then
        print_error "Could not determine MAC address"
        return 1
    fi
    
    print_info "Primary MAC address: $mac"
    
    # Get suffix (last 6 chars)
    local mac_suffix=$(get_mac_suffix "$mac" | tr '[:lower:]' '[:upper:]')
    if [[ ! "$mac_suffix" =~ ^[A-F0-9]{6}$ ]]; then
        print_error "Invalid MAC suffix generated: $mac_suffix"
        return 1
    fi
    print_info "MAC suffix: $mac_suffix"
    
    # Generate expected hostname
    local expected_hostname="edudisplej-${mac_suffix}"
    print_info "Expected hostname: $expected_hostname"
    
    # Get current hostname
    local current_hostname=$(hostname)
    print_info "Current hostname: $current_hostname"
    
    # Double check: verify if hostname is already correct
    if [[ "$current_hostname" == "$expected_hostname" ]]; then
        print_success "Hostname already configured correctly: $expected_hostname"
        touch "$HOSTNAME_FLAG"
        return 0
    fi
    
    # Check if hostname needs modification
    # Only modify if:
    # 1. hostname is just "edudisplej" (without MAC suffix)
    # 2. hostname doesn't start with "edudisplej-"
    # 3. hostname starts with "edudisplej-" but has wrong MAC suffix
    local needs_update=false
    
    if [[ "$current_hostname" == "edudisplej" ]]; then
        print_warning "Hostname is just 'edudisplej' without MAC suffix - needs update"
        needs_update=true
    elif [[ ! "$current_hostname" =~ ^edudisplej- ]]; then
        print_warning "Hostname does not follow edudisplej-XXXXXX format - needs update"
        needs_update=true
    elif [[ "$current_hostname" =~ ^edudisplej-[A-Fa-f0-9]{6}$ ]] && [[ "$current_hostname" != "$expected_hostname" ]]; then
        print_warning "Hostname has wrong MAC suffix - needs update"
        needs_update=true
    else
        print_info "Hostname format seems valid, skipping update to avoid conflicts"
        touch "$HOSTNAME_FLAG"
        return 0
    fi
    
    if [[ "$needs_update" == false ]]; then
        print_info "Hostname does not need update"
        touch "$HOSTNAME_FLAG"
        return 0
    fi
    
    # At this point, we confirmed hostname needs update
    print_info "Proceeding with hostname update..."
    
    # Set hostname
    print_info "Setting new hostname to: $expected_hostname"
    
    # Method 1: Use hostnamectl (systemd)
    local hostname_set=false
    if command -v hostnamectl >/dev/null 2>&1; then
        if hostnamectl set-hostname "$expected_hostname" 2>/dev/null; then
            print_success "Hostname set via hostnamectl"
            hostname_set=true
        else
            print_warning "hostnamectl failed, trying alternative method"
        fi
    fi
    
    # Method 2: Direct hostname command
    if [[ "$hostname_set" == false ]]; then
        if ! hostname "$expected_hostname" 2>/dev/null; then
            print_warning "Failed to set hostname via hostname command"
        else
            hostname_set=true
        fi
    fi
    
    # Verify hostname was set
    if [[ "$hostname_set" == false ]]; then
        print_error "Failed to set hostname using available methods"
        return 1
    fi
    
    # Double-check: verify hostname was actually changed
    local verify_hostname=$(hostname)
    if [[ "$verify_hostname" != "$expected_hostname" ]]; then
        print_error "Hostname verification failed - hostname is: $verify_hostname, expected: $expected_hostname"
        return 1
    fi
    
    print_success "Hostname successfully changed and verified: $expected_hostname"
    
    # Update /etc/hostname
    echo "$expected_hostname" > /etc/hostname
    print_success "Updated /etc/hostname"
    
    # Update /etc/hosts
    if grep -q "127.0.1.1" /etc/hosts; then
        # Replace existing 127.0.1.1 entry
        sed -i "s/^127\.0\.1\.1.*/127.0.1.1\t${expected_hostname}/" /etc/hosts
    else
        # Add new entry
        echo "127.0.1.1	${expected_hostname}" >> /etc/hosts
    fi
    print_success "Updated /etc/hosts"
    
    # Mark as configured
    touch "$HOSTNAME_FLAG"
    
    print_success "=========================================="
    print_success "Hostname configured: $expected_hostname"
    print_success "=========================================="
    
    return 0
}

# =============================================================================
# Main Execution
# =============================================================================

# Get current system state
current_hostname=$(hostname)
mac=$(get_mac_address 2>/dev/null || echo "")

# Validate MAC address
if [ -z "$mac" ]; then
    print_error "Cannot determine MAC address, skipping hostname configuration"
    exit 1
fi

mac_suffix=$(get_mac_suffix "$mac" | tr '[:lower:]' '[:upper:]')
if [[ ! "$mac_suffix" =~ ^[A-F0-9]{6}$ ]]; then
    print_error "Invalid MAC suffix generated: $mac_suffix"
    exit 1
fi
expected_hostname="edudisplej-${mac_suffix}"

print_info "=== Hostname Check ==="
print_info "Current hostname: $current_hostname"
print_info "Expected hostname: $expected_hostname"

# Check 1: Is hostname already correct?
if [ "$current_hostname" = "$expected_hostname" ]; then
    print_success "✓ Hostname already configured correctly: $expected_hostname"
    touch "$HOSTNAME_FLAG"
    exit 0
fi

# Check 2: Is hostname just "edudisplej" without suffix?
if [ "$current_hostname" = "edudisplej" ]; then
    print_warning "⚠ Hostname is just 'edudisplej' without MAC suffix - reconfiguration needed"
    rm -f "$HOSTNAME_FLAG"
fi

# Check 3: Does hostname not follow the edudisplej-XXXXXX pattern?
if [[ ! "$current_hostname" =~ ^edudisplej- ]]; then
    print_warning "⚠ Hostname does not follow edudisplej-XXXXXX pattern - reconfiguration needed"
    rm -f "$HOSTNAME_FLAG"
fi

# Check 4: If flag exists but hostname is wrong, remove flag
if [ -f "$HOSTNAME_FLAG" ] && [ "$current_hostname" != "$expected_hostname" ]; then
    print_warning "⚠ Hostname flag exists but hostname is incorrect - reconfiguration needed"
    rm -f "$HOSTNAME_FLAG"
fi

# Check if already configured
if [ -f "$HOSTNAME_FLAG" ]; then
    print_info "Hostname already configured (flag exists)"
    exit 0
fi

# Run configuration
if configure_hostname; then
    print_success "Hostname configuration completed successfully"
    exit 0
else
    print_error "Hostname configuration failed"
    exit 1
fi
