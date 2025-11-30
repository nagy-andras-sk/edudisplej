#!/bin/bash
# display.sh - Display resolution settings
# All text is in Slovak (without diacritics) or English

# Source common functions if not already sourced
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "${TRANS_SK+x}" ]]; then
    source "${SCRIPT_DIR}/common.sh"
fi

# =============================================================================
# Display Information Functions
# =============================================================================

# Available resolutions
RESOLUTIONS=(
    "1920x1080"
    "1680x1050"
    "1600x900"
    "1440x900"
    "1366x768"
    "1280x1024"
    "1280x720"
    "1024x768"
    "800x600"
)

# Get current resolution
get_current_resolution() {
    if [[ -n "$DISPLAY" ]] && command -v xrandr &> /dev/null; then
        xrandr 2>/dev/null | grep '\*' | awk '{print $1}' | head -1
    else
        echo "unknown"
    fi
}

# Get available display outputs
get_display_outputs() {
    if command -v xrandr &> /dev/null; then
        xrandr 2>/dev/null | grep " connected" | awk '{print $1}'
    else
        echo "HDMI-1"
    fi
}

# =============================================================================
# Resolution Functions
# =============================================================================

# Set display resolution using xrandr
set_resolution_xrandr() {
    local resolution="$1"
    local output="${2:-$(get_display_outputs | head -1)}"
    
    print_info "Setting resolution to ${resolution} on ${output}..."
    
    export DISPLAY=:0
    
    if xrandr --output "$output" --mode "$resolution" 2>/dev/null; then
        print_success "$(t display_applied)"
        return 0
    else
        print_error "Failed to set resolution"
        return 1
    fi
}

# Set resolution in config.txt (Raspberry Pi specific)
set_resolution_config() {
    local resolution="$1"
    local config_file="/boot/config.txt"
    
    # Parse resolution
    local width="${resolution%x*}"
    local height="${resolution#*x}"
    
    print_info "Setting resolution in boot config..."
    
    # Backup config
    sudo cp "$config_file" "${config_file}.bak" 2>/dev/null
    
    # Remove existing framebuffer settings
    sudo sed -i '/^framebuffer_width/d' "$config_file" 2>/dev/null
    sudo sed -i '/^framebuffer_height/d' "$config_file" 2>/dev/null
    sudo sed -i '/^hdmi_cvt/d' "$config_file" 2>/dev/null
    sudo sed -i '/^hdmi_group/d' "$config_file" 2>/dev/null
    sudo sed -i '/^hdmi_mode/d' "$config_file" 2>/dev/null
    
    # Add new settings
    sudo bash -c "cat >> $config_file << EOF

# EduDisplej display settings
framebuffer_width=${width}
framebuffer_height=${height}
hdmi_group=2
hdmi_mode=82
EOF"
    
    print_success "Resolution configured. Reboot required."
    return 0
}

# Set resolution (main function)
set_resolution() {
    local resolution="$1"
    
    if [[ -n "$DISPLAY" ]] && command -v xrandr &> /dev/null; then
        set_resolution_xrandr "$resolution"
    else
        set_resolution_config "$resolution"
    fi
}

# =============================================================================
# Interactive Display Menu
# =============================================================================

# Show display settings menu
show_display_menu() {
    local choice
    
    while true; do
        clear_screen
        echo "$(t display_settings)"
        echo "================================"
        echo ""
        echo "$(t display_current_res) $(get_current_resolution)"
        echo ""
        echo "$(t display_select_res)"
        echo ""
        
        local i=1
        for res in "${RESOLUTIONS[@]}"; do
            echo "  ${i}. ${res}"
            ((i++))
        done
        echo "  0. $(t menu_exit)"
        echo ""
        
        read -rp "$(t menu_select) " choice
        
        if [[ "$choice" == "0" ]]; then
            return 0
        elif [[ "$choice" -ge 1 && "$choice" -le ${#RESOLUTIONS[@]} ]]; then
            local selected_res="${RESOLUTIONS[$((choice-1))]}"
            set_resolution "$selected_res"
            wait_for_enter
        else
            print_error "$(t menu_invalid)"
            sleep 1
        fi
    done
}
