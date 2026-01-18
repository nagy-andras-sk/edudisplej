#!/bin/bash
# hwinfo.sh - Hardware information collector
# Collects system hardware information and stores in hwinfo.conf

EDUDISPLEJ_HOME="/opt/edudisplej"
HWINFO_FILE="${EDUDISPLEJ_HOME}/hwinfo.conf"

# Generate hardware information
generate_hwinfo() {
    local temp_file="${HWINFO_FILE}.tmp"
    
    echo "# EduDisplej Hardware Information" > "$temp_file"
    echo "# Generated: $(date '+%Y-%m-%d %H:%M:%S')" >> "$temp_file"
    echo "" >> "$temp_file"
    
    # System Information
    echo "[SYSTEM]" >> "$temp_file"
    echo "HOSTNAME=$(hostname)" >> "$temp_file"
    echo "KERNEL=$(uname -r)" >> "$temp_file"
    echo "ARCHITECTURE=$(uname -m)" >> "$temp_file"
    echo "OS=$(cat /etc/os-release 2>/dev/null | grep PRETTY_NAME | cut -d'"' -f2 || echo 'Unknown')" >> "$temp_file"
    echo "" >> "$temp_file"
    
    # CPU Information
    echo "[CPU]" >> "$temp_file"
    if [[ -f /proc/cpuinfo ]]; then
        local cpu_model=$(grep "model name" /proc/cpuinfo | head -1 | cut -d':' -f2 | xargs || grep "Hardware" /proc/cpuinfo | head -1 | cut -d':' -f2 | xargs || echo "Unknown")
        local cpu_cores=$(grep -c "^processor" /proc/cpuinfo || echo "1")
        echo "CPU_MODEL=$cpu_model" >> "$temp_file"
        echo "CPU_CORES=$cpu_cores" >> "$temp_file"
        
        # Check for NEON support (ARM)
        if grep -qi "neon" /proc/cpuinfo 2>/dev/null; then
            echo "CPU_NEON=yes" >> "$temp_file"
        else
            echo "CPU_NEON=no" >> "$temp_file"
        fi
    fi
    
    # CPU Temperature (Raspberry Pi specific)
    if command -v vcgencmd >/dev/null 2>&1; then
        local cpu_temp=$(vcgencmd measure_temp 2>/dev/null | cut -d'=' -f2 || echo "N/A")
        echo "CPU_TEMP=$cpu_temp" >> "$temp_file"
    fi
    echo "" >> "$temp_file"
    
    # Memory Information
    echo "[MEMORY]" >> "$temp_file"
    if [[ -f /proc/meminfo ]]; then
        local mem_total=$(grep "MemTotal:" /proc/meminfo | awk '{print $2}' || echo "0")
        local mem_free=$(grep "MemFree:" /proc/meminfo | awk '{print $2}' || echo "0")
        local mem_available=$(grep "MemAvailable:" /proc/meminfo | awk '{print $2}' || echo "0")
        
        # Convert to MB
        mem_total=$((mem_total / 1024))
        mem_free=$((mem_free / 1024))
        mem_available=$((mem_available / 1024))
        
        echo "MEM_TOTAL_MB=$mem_total" >> "$temp_file"
        echo "MEM_FREE_MB=$mem_free" >> "$temp_file"
        echo "MEM_AVAILABLE_MB=$mem_available" >> "$temp_file"
    fi
    echo "" >> "$temp_file"
    
    # Disk Information
    echo "[DISK]" >> "$temp_file"
    local disk_info=$(df -h / | tail -1)
    local disk_total=$(echo "$disk_info" | awk '{print $2}')
    local disk_used=$(echo "$disk_info" | awk '{print $3}')
    local disk_free=$(echo "$disk_info" | awk '{print $4}')
    local disk_percent=$(echo "$disk_info" | awk '{print $5}')
    
    echo "DISK_TOTAL=$disk_total" >> "$temp_file"
    echo "DISK_USED=$disk_used" >> "$temp_file"
    echo "DISK_FREE=$disk_free" >> "$temp_file"
    echo "DISK_USED_PERCENT=$disk_percent" >> "$temp_file"
    echo "" >> "$temp_file"
    
    # Network Information
    echo "[NETWORK]" >> "$temp_file"
    
    # Primary MAC address
    local primary_mac=""
    for iface in eth0 wlan0; do
        if [[ -f "/sys/class/net/$iface/address" ]]; then
            local mac=$(cat "/sys/class/net/$iface/address" 2>/dev/null)
            if [[ -n "$mac" && "$mac" != "00:00:00:00:00:00" ]]; then
                if [[ "$iface" == "eth0" ]]; then
                    echo "ETH0_MAC=$mac" >> "$temp_file"
                    [[ -z "$primary_mac" ]] && primary_mac="$mac"
                elif [[ "$iface" == "wlan0" ]]; then
                    echo "WLAN0_MAC=$mac" >> "$temp_file"
                    [[ -z "$primary_mac" ]] && primary_mac="$mac"
                fi
            fi
        fi
    done
    echo "PRIMARY_MAC=$primary_mac" >> "$temp_file"
    
    # IP addresses
    local eth0_ip=$(ip -4 addr show eth0 2>/dev/null | grep -oP '(?<=inet\s)\d+(\.\d+){3}' | head -1 || echo "N/A")
    local wlan0_ip=$(ip -4 addr show wlan0 2>/dev/null | grep -oP '(?<=inet\s)\d+(\.\d+){3}' | head -1 || echo "N/A")
    
    echo "ETH0_IP=$eth0_ip" >> "$temp_file"
    echo "WLAN0_IP=$wlan0_ip" >> "$temp_file"
    
    # Gateway
    local gateway=$(ip route | grep default | awk '{print $3}' | head -1 || echo "N/A")
    echo "GATEWAY=$gateway" >> "$temp_file"
    
    # WiFi SSID
    if command -v iwgetid >/dev/null 2>&1; then
        local ssid=$(iwgetid -r 2>/dev/null || echo "N/A")
        echo "WIFI_SSID=$ssid" >> "$temp_file"
    fi
    echo "" >> "$temp_file"
    
    # Display Information
    echo "[DISPLAY]" >> "$temp_file"
    if [[ -n "${DISPLAY:-}" ]]; then
        echo "DISPLAY=$DISPLAY" >> "$temp_file"
        
        # Try to get resolution
        if command -v xrandr >/dev/null 2>&1; then
            local resolution=$(DISPLAY=:0 xrandr 2>/dev/null | grep '*' | awk '{print $1}' | head -1 || echo "N/A")
            echo "RESOLUTION=$resolution" >> "$temp_file"
        fi
    else
        echo "DISPLAY=N/A" >> "$temp_file"
        echo "RESOLUTION=N/A" >> "$temp_file"
    fi
    echo "" >> "$temp_file"
    
    # Raspberry Pi Specific Information
    if command -v vcgencmd >/dev/null 2>&1; then
        echo "[RASPBERRY_PI]" >> "$temp_file"
        
        # Model
        if [[ -f /proc/device-tree/model ]]; then
            local rpi_model=$(cat /proc/device-tree/model 2>/dev/null | tr -d '\0')
            echo "MODEL=$rpi_model" >> "$temp_file"
        fi
        
        # Serial number
        local serial=$(cat /proc/cpuinfo | grep Serial | cut -d':' -f2 | xargs 2>/dev/null || echo "N/A")
        echo "SERIAL=$serial" >> "$temp_file"
        
        # Firmware version
        local firmware=$(vcgencmd version 2>/dev/null | head -1 || echo "N/A")
        echo "FIRMWARE=$firmware" >> "$temp_file"
        
        # Voltage
        local voltage=$(vcgencmd measure_volts 2>/dev/null | cut -d'=' -f2 || echo "N/A")
        echo "VOLTAGE=$voltage" >> "$temp_file"
        
        # Throttling status
        local throttled=$(vcgencmd get_throttled 2>/dev/null | cut -d'=' -f2 || echo "N/A")
        echo "THROTTLED=$throttled" >> "$temp_file"
        
        echo "" >> "$temp_file"
    fi
    
    # Browser Information
    echo "[BROWSER]" >> "$temp_file"
    
    # Check installed browsers
    for browser in chromium-browser chromium epiphany-browser firefox-esr; do
        if command -v "$browser" >/dev/null 2>&1; then
            local version=$("$browser" --version 2>/dev/null | head -1 || echo "installed")
            echo "BROWSER_${browser^^}=$version" >> "$temp_file"
        else
            echo "BROWSER_${browser^^}=not_installed" >> "$temp_file"
        fi
    done
    
    # Current browser from config
    if [[ -f "${EDUDISPLEJ_HOME}/edudisplej.conf" ]]; then
        local current_browser=$(grep "^BROWSER_BIN=" "${EDUDISPLEJ_HOME}/edudisplej.conf" 2>/dev/null | cut -d'=' -f2 | tr -d '"' || echo "auto")
        echo "BROWSER_CURRENT=$current_browser" >> "$temp_file"
    fi
    echo "" >> "$temp_file"
    
    # EduDisplej Information
    echo "[EDUDISPLEJ]" >> "$temp_file"
    
    # Version
    if [[ -f "${EDUDISPLEJ_HOME}/init/edudisplej-init.sh" ]]; then
        local version=$(grep "CURRENT_VERSION=" "${EDUDISPLEJ_HOME}/init/edudisplej-init.sh" | cut -d'=' -f2 | tr -d '"' || echo "unknown")
        echo "VERSION=$version" >> "$temp_file"
    fi
    
    # Mode
    if [[ -f "${EDUDISPLEJ_HOME}/.mode" ]]; then
        local mode=$(cat "${EDUDISPLEJ_HOME}/.mode" 2>/dev/null || echo "unknown")
        echo "MODE=$mode" >> "$temp_file"
    fi
    
    # Uptime
    local uptime_seconds=$(cat /proc/uptime | cut -d'.' -f1)
    local uptime_hours=$((uptime_seconds / 3600))
    echo "UPTIME_HOURS=$uptime_hours" >> "$temp_file"
    
    echo "" >> "$temp_file"
    
    # Move temp file to final location
    mv "$temp_file" "$HWINFO_FILE"
    chmod 644 "$HWINFO_FILE"
    
    echo "[hwinfo] Hardware information updated: $HWINFO_FILE"
}

# Main
case "${1:-generate}" in
    generate)
        generate_hwinfo
        ;;
    show)
        if [[ -f "$HWINFO_FILE" ]]; then
            cat "$HWINFO_FILE"
        else
            echo "Hardware info file not found. Run: $0 generate"
            exit 1
        fi
        ;;
    *)
        echo "Usage: $0 {generate|show}"
        exit 1
        ;;
esac
