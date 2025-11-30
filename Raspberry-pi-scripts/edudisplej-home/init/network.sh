#!/bin/bash
# network.sh - Network configuration (Wi-Fi, static IP)
# All text is in Slovak (without diacritics) or English

# Source common functions if not already sourced
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "${TRANS_SK+x}" ]]; then
    source "${SCRIPT_DIR}/common.sh"
fi

# =============================================================================
# Network Information Functions
# =============================================================================

# Get current IP address
get_current_ip() {
    local iface="${1:-}"
    if [[ -n "$iface" ]]; then
        ip -4 addr show "$iface" 2>/dev/null | grep -oP '(?<=inet\s)\d+(\.\d+){3}'
    else
        hostname -I 2>/dev/null | awk '{print $1}'
    fi
}

# Get default gateway
get_gateway() {
    ip route | grep default | awk '{print $3}' | head -1
}

# Get DNS servers
get_dns() {
    cat /etc/resolv.conf 2>/dev/null | grep nameserver | awk '{print $2}' | head -1
}

# List available network interfaces
list_interfaces() {
    ip link show | grep -E "^[0-9]+:" | awk -F': ' '{print $2}' | grep -v lo
}

# Check if connected to network
is_connected() {
    local ip
    ip=$(get_current_ip)
    [[ -n "$ip" ]]
}

# =============================================================================
# Wi-Fi Functions
# =============================================================================

# List available Wi-Fi networks
list_wifi_networks() {
    if command -v nmcli &> /dev/null; then
        nmcli -t -f SSID device wifi list 2>/dev/null | sort -u | grep -v "^$"
    elif command -v iwlist &> /dev/null; then
        sudo iwlist wlan0 scan 2>/dev/null | grep ESSID | cut -d'"' -f2 | sort -u
    fi
}

# Connect to Wi-Fi network using NetworkManager
connect_wifi_nmcli() {
    local ssid="$1"
    local password="$2"
    
    print_info "$(t network_connecting)"
    
    if nmcli device wifi connect "$ssid" password "$password" 2>/dev/null; then
        print_success "$(t network_connected)"
        return 0
    else
        print_error "$(t network_failed)"
        return 1
    fi
}

# Connect to Wi-Fi network using wpa_supplicant
connect_wifi_wpa() {
    local ssid="$1"
    local password="$2"
    local wpa_conf="/etc/wpa_supplicant/wpa_supplicant.conf"
    
    print_info "$(t network_connecting)"
    
    # Create wpa_supplicant configuration
    sudo bash -c "cat >> $wpa_conf << EOF

network={
    ssid=\"${ssid}\"
    psk=\"${password}\"
}
EOF"
    
    # Restart wpa_supplicant
    sudo systemctl restart wpa_supplicant 2>/dev/null
    sleep 3
    
    # Get IP address
    sudo dhclient wlan0 2>/dev/null
    sleep 2
    
    if is_connected; then
        print_success "$(t network_connected)"
        return 0
    else
        print_error "$(t network_failed)"
        return 1
    fi
}

# Main Wi-Fi connection function
connect_wifi() {
    local ssid="$1"
    local password="$2"
    
    if command -v nmcli &> /dev/null; then
        connect_wifi_nmcli "$ssid" "$password"
    else
        connect_wifi_wpa "$ssid" "$password"
    fi
}

# =============================================================================
# Static IP Functions
# =============================================================================

# Set static IP using NetworkManager
set_static_ip_nmcli() {
    local interface="$1"
    local ip="$2"
    local gateway="$3"
    local dns="$4"
    
    print_info "Setting static IP ${ip} on ${interface}..."
    
    # Get current connection name
    local conn_name
    conn_name=$(nmcli -t -f NAME,DEVICE connection show --active | grep "${interface}" | cut -d':' -f1)
    
    if [[ -z "$conn_name" ]]; then
        print_error "No active connection found on ${interface}"
        return 1
    fi
    
    # Set static IP
    nmcli connection modify "$conn_name" ipv4.addresses "${ip}/24"
    nmcli connection modify "$conn_name" ipv4.gateway "$gateway"
    nmcli connection modify "$conn_name" ipv4.dns "$dns"
    nmcli connection modify "$conn_name" ipv4.method manual
    
    # Restart connection
    nmcli connection down "$conn_name" 2>/dev/null
    nmcli connection up "$conn_name"
    
    print_success "Static IP configured successfully"
    return 0
}

# Set static IP using dhcpcd
set_static_ip_dhcpcd() {
    local interface="$1"
    local ip="$2"
    local gateway="$3"
    local dns="$4"
    local dhcpcd_conf="/etc/dhcpcd.conf"
    
    print_info "Setting static IP ${ip} on ${interface}..."
    
    # Backup config
    sudo cp "$dhcpcd_conf" "${dhcpcd_conf}.bak" 2>/dev/null
    
    # Remove existing static config for this interface
    sudo sed -i "/^interface ${interface}/,/^interface\|^$/d" "$dhcpcd_conf" 2>/dev/null
    
    # Add new static config
    sudo bash -c "cat >> $dhcpcd_conf << EOF

interface ${interface}
static ip_address=${ip}/24
static routers=${gateway}
static domain_name_servers=${dns}
EOF"
    
    # Restart dhcpcd
    sudo systemctl restart dhcpcd 2>/dev/null
    sleep 3
    
    print_success "Static IP configured successfully"
    return 0
}

# Main static IP function
set_static_ip() {
    local interface="$1"
    local ip="$2"
    local gateway="$3"
    local dns="$4"
    
    if command -v nmcli &> /dev/null; then
        set_static_ip_nmcli "$interface" "$ip" "$gateway" "$dns"
    else
        set_static_ip_dhcpcd "$interface" "$ip" "$gateway" "$dns"
    fi
}

# =============================================================================
# Interactive Network Menu
# =============================================================================

# Show network settings menu
show_network_menu() {
    local choice
    
    while true; do
        clear_screen
        echo "$(t network_wifi_setup)"
        echo "================================"
        echo ""
        echo "$(t network_current_ip) $(get_current_ip)"
        echo ""
        echo "  1. $(t network_wifi_setup)"
        echo "  2. $(t network_static_ip)"
        echo "  3. $(t menu_exit)"
        echo ""
        read -rp "$(t menu_select) " choice
        
        case "$choice" in
            1)
                configure_wifi_interactive
                ;;
            2)
                configure_static_ip_interactive
                ;;
            3)
                return 0
                ;;
            *)
                print_error "$(t menu_invalid)"
                sleep 1
                ;;
        esac
    done
}

# Interactive Wi-Fi configuration
configure_wifi_interactive() {
    local ssid
    local password
    
    echo ""
    echo "Available networks:"
    list_wifi_networks
    echo ""
    
    read -rp "$(t network_enter_ssid) " ssid
    read -rsp "$(t network_enter_password) " password
    echo ""
    
    if [[ -n "$ssid" && -n "$password" ]]; then
        connect_wifi "$ssid" "$password"
    fi
    
    wait_for_enter
}

# Interactive static IP configuration
configure_static_ip_interactive() {
    local interface
    local ip
    local gateway
    local dns
    
    echo ""
    echo "Available interfaces:"
    list_interfaces
    echo ""
    
    read -rp "Interface (eth0/wlan0): " interface
    read -rp "$(t network_enter_ip) " ip
    read -rp "$(t network_enter_gateway) " gateway
    read -rp "$(t network_enter_dns) " dns
    
    if [[ -n "$interface" && -n "$ip" && -n "$gateway" && -n "$dns" ]]; then
        set_static_ip "$interface" "$ip" "$gateway" "$dns"
    fi
    
    wait_for_enter
}
