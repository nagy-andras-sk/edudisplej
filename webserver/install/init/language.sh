#!/bin/bash
# language.sh - Language selection
# All text is in Slovak (without diacritics) or English

# Source common functions if not already sourced
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "${TRANS_SK+x}" ]]; then
    source "${SCRIPT_DIR}/common.sh"
fi

# =============================================================================
# Language Functions
# =============================================================================

# Available languages
LANGUAGES=("sk" "en")
LANGUAGE_NAMES=("Slovencina (bez diakritiky)" "English")

# Get current language name
get_language_name() {
    local lang="${1:-$CURRENT_LANG}"
    case "$lang" in
        sk) echo "Slovencina" ;;
        en) echo "English" ;;
        *)  echo "Unknown" ;;
    esac
}

# Set language
set_language() {
    local lang="$1"
    
    if [[ "$lang" == "sk" || "$lang" == "en" ]]; then
        CURRENT_LANG="$lang"
        save_config
        print_success "$(t language_changed)"
        return 0
    else
        print_error "Invalid language: $lang"
        return 1
    fi
}

# =============================================================================
# Interactive Language Menu
# =============================================================================

# Show language selection menu
show_language_menu() {
    local choice
    
    while true; do
        clear_screen
        echo "$(t language_select)"
        echo "================================"
        echo ""
        echo "Current / Aktualne: $(get_language_name)"
        echo ""
        echo "  1. $(t language_slovak) (bez diakritiky)"
        echo "  2. $(t language_english)"
        echo "  0. $(t menu_exit)"
        echo ""
        
        read -rp "$(t menu_select) " choice
        
        case "$choice" in
            1)
                set_language "sk"
                wait_for_enter
                ;;
            2)
                set_language "en"
                wait_for_enter
                ;;
            0)
                return 0
                ;;
            *)
                print_error "$(t menu_invalid)"
                sleep 1
                ;;
        esac
    done
}
