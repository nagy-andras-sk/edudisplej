#!/bin/bash
ensure_nm() {
    if ! command -v nmcli >/dev/null 2>&1; then
        sudo apt update && sudo apt install -y network-manager
        sudo systemctl enable NetworkManager
        sudo systemctl restart NetworkManager
    fi
}

wifi_setup() {
    SSID=$(whiptail --inputbox "$(T wifi_ssid)" 8 60 --title "Wi-Fi" 3>&1 1>&2 2>&3)
    PASS=$(whiptail --passwordbox "$(T wifi_pass)" 8 60 --title "Wi-Fi" 3>&1 1>&2 2>&3)
    if [ -n "$SSID" ]; then
        if command -v nmcli >/dev/null 2>&1; then
            nmcli radio wifi on || true
            nmcli dev wifi connect "$SSID" password "$PASS" ifname wlan0 || nmcli dev wifi connect "$SSID" password "$PASS"
        else
            sudo cp /etc/wpa_supplicant/wpa_supplicant.conf /etc/wpa_supplicant/wpa_supplicant.conf.bak 2>/dev/null || true
            sudo bash -c "cat >> /etc/wpa_supplicant/wpa_supplicant.conf" <<EOF
network={
    ssid="$SSID"
    psk="$PASS"
    key_mgmt=WPA-PSK
}
EOF
            sudo systemctl restart wpa_supplicant || true
            sudo dhclient -v wlan0 || true
        fi
        whiptail --infobox "$(T wifi_done)" 8 60
        sleep 2
    fi
}

net_setup() {
    ensure_nm || true
    CH=$(whiptail --menu "$(T net_mode)" 15 60 2 \
        "dhcp" "$(T net_dhcp)" \
        "static" "$(T net_static)" 3>&1 1>&2 2>&3)
    if [ "$CH" = "dhcp" ]; then
        if command -v nmcli >/dev/null 2>&1; then
            CONN=$(nmcli -t -f NAME con show | head -n1)
            nmcli con mod "$CONN" ipv4.method auto ipv6.method ignore
            nmcli con up "$CONN" || true
        fi
    elif [ "$CH" = "static" ]; then
        IP=$(whiptail --inputbox "$(T ip_addr)" 8 60 --title "Network" 3>&1 1>&2 2>&3)
        GW=$(whiptail --inputbox "$(T gateway)" 8 60 --title "Network" 3>&1 1>&2 2>&3)
        DNS=$(whiptail --inputbox "$(T dns)" 8 60 --title "Network" 3>&1 1>&2 2>&3)
        if command -v nmcli >/dev/null 2>&1; then
            CONN=$(nmcli -t -f NAME con show | head -n1)
            nmcli con mod "$CONN" ipv4.method manual ipv4.addresses "$IP" ipv4.gateway "$GW" ipv4.dns "$DNS" ipv6.method ignore
            nmcli con up "$CONN" || true
        else
            sudo sed -i '/^interface .*$/,/^$/d' /etc/dhcpcd.conf
            {
              echo "interface eth0"
              echo "static ip_address=$IP"
              echo "static routers=$GW"
              echo "static domain_name_servers=$DNS"
            } | sudo tee -a /etc/dhcpcd.conf >/dev/null
            sudo systemctl restart dhcpcd || true
        fi
    fi
    whiptail --infobox "$(T net_applied)" 8 60
    sleep 2
}
