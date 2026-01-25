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
fi

# =============================================================================
# MAC Address Functions
# =============================================================================

get_primary_mac_address() {
    # Try to get MAC address from primary network interface
    local mac=""
    
    # Method 1: Try eth0 first (wired connection)
    if [[ -f /sys/class/net/eth0/address ]]; then
        mac=$(cat /sys/class/net/eth0/address 2>/dev/null | tr -d ':' | tr '[:lower:]' '[:upper:]')
        if [[ -n "$mac" && "$mac" != "000000000000" ]]; then
            echo "$mac"
            return 0
        fi
    fi
    
    # Method 2: Try wlan0 (wireless connection)
    if [[ -f /sys/class/net/wlan0/address ]]; then
        mac=$(cat /sys/class/net/wlan0/address 2>/dev/null | tr -d ':' | tr '[:lower:]' '[:upper:]')
        if [[ -n "$mac" && "$mac" != "000000000000" ]]; then
            echo "$mac"
            return 0
        fi
    fi
    
    # Method 3: Find first non-loopback interface
    for iface in /sys/class/net/*; do
        local ifname=$(basename "$iface")
        if [[ "$ifname" == "lo" ]]; then
            continue
        fi
        if [[ -f "$iface/address" ]]; then
            mac=$(cat "$iface/address" 2>/dev/null | tr -d ':' | tr '[:lower:]' '[:upper:]')
            if [[ -n "$mac" && "$mac" != "000000000000" ]]; then
                echo "$mac"
                return 0
            fi
        fi
    done
    
    # Method 4: Use ip command as fallback
    if command -v ip >/dev/null 2>&1; then
        mac=$(ip link show | grep -A1 "state UP" | grep "link/ether" | head -1 | awk '{print $2}' | tr -d ':' | tr '[:lower:]' '[:upper:]')
        if [[ -n "$mac" && "$mac" != "000000000000" ]]; then
            echo "$mac"
            return 0
        fi
    fi
    
    return 1
}

get_mac_suffix() {
    local mac="$1"
    # Get last 6 characters of MAC address (last 3 bytes)
    echo "${mac: -6}"
}

# =============================================================================
# Hostname Configuration
# =============================================================================

configure_hostname() {
    print_info "Configuring hostname based on MAC address..."
    
    # Get MAC address
    local mac=""
    if ! mac=$(get_primary_mac_address); then
        print_error "Could not determine MAC address"
        return 1
    fi
    
    print_info "Primary MAC address: $mac"
    
    # Get suffix (last 6 chars)
    local mac_suffix=$(get_mac_suffix "$mac")
    print_info "MAC suffix: $mac_suffix"
    
    # Generate hostname
    local new_hostname="edudisplej-${mac_suffix}"
    print_info "New hostname: $new_hostname"
    
    # Get current hostname
    local current_hostname=$(hostname)
    print_info "Current hostname: $current_hostname"
    
    # Check if already configured
    if [[ "$current_hostname" == "$new_hostname" ]]; then
        print_success "Hostname already configured correctly: $new_hostname"
        touch "$HOSTNAME_FLAG"
        return 0
    fi
    
    # Set hostname
    print_info "Setting new hostname..."
    
    # Method 1: Use hostnamectl (systemd)
    if command -v hostnamectl >/dev/null 2>&1; then
        if hostnamectl set-hostname "$new_hostname" 2>/dev/null; then
            print_success "Hostname set via hostnamectl"
        else
            print_warning "hostnamectl failed, trying alternative method"
        fi
    fi
    
    # Method 2: Direct hostname command
    if ! hostname "$new_hostname" 2>/dev/null; then
        print_error "Failed to set hostname via hostname command"
    fi
    
    # Update /etc/hostname
    echo "$new_hostname" > /etc/hostname
    print_success "Updated /etc/hostname"
    
    # Update /etc/hosts
    if grep -q "127.0.1.1" /etc/hosts; then
        # Replace existing 127.0.1.1 entry
        sed -i "s/^127\.0\.1\.1.*/127.0.1.1\t${new_hostname}/" /etc/hosts
    else
        # Add new entry
        echo "127.0.1.1	${new_hostname}" >> /etc/hosts
    fi
    print_success "Updated /etc/hosts"
    
    # Mark as configured
    touch "$HOSTNAME_FLAG"
    
    print_success "=========================================="
    print_success "Hostname configured: $new_hostname"
    print_success "=========================================="
    
    return 0
}

# =============================================================================
# Main Execution
# =============================================================================

# Check if already configured
if [[ -f "$HOSTNAME_FLAG" ]]; then
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
