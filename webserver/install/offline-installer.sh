#!/bin/bash
set -euo pipefail

ONLINE_INSTALLER_URL="${EDUDISPLEJ_ONLINE_INSTALLER_URL:-https://install.edudisplej.sk/install.sh}"
TMP_INSTALLER="/tmp/edudisplej-online-install.sh"
INSTALL_DONE_FLAG="/opt/edudisplej/.offline_installer_done"
FIRST_BOOT_MODE="false"

for arg in "$@"; do
    case "$arg" in
        --first-boot)
            FIRST_BOOT_MODE="true"
            ;;
    esac
done

print_header() {
    clear 2>/dev/null || true
    echo "=========================================="
    echo " EduDisplej Offline Installer Wizard"
    echo "=========================================="
    echo ""
}

print_step() {
    local step="$1"
    local text="$2"
    echo "[$step] $text"
}

print_info() {
    echo "[*] $1"
}

print_ok() {
    echo "[✓] $1"
}

print_warn() {
    echo "[!] $1"
}

require_root() {
    if [ "$(id -u)" -ne 0 ]; then
        echo "[!] Run as root (sudo)."
        exit 1
    fi
}

is_online() {
    if command -v curl >/dev/null 2>&1; then
        if curl -fsSL --max-time 6 --connect-timeout 3 --head "https://install.edudisplej.sk" >/dev/null 2>&1; then
            return 0
        fi
    fi

    ping -c 1 -W 3 1.1.1.1 >/dev/null 2>&1 || ping -c 1 -W 3 8.8.8.8 >/dev/null 2>&1
}

wait_for_internet_wizard() {
    print_step "1/3" "Internet connection"
    echo "Connect this device to internet (Ethernet or Wi-Fi), then continue."
    echo ""

    while true; do
        if is_online; then
            print_ok "Internet is available"
            return 0
        fi

        print_warn "No internet yet"
        echo "Options:"
        echo "  [r] Retry"
        echo "  [n] Open nmtui (if available)"
        echo "  [q] Quit"
        read -rp "Choose (r/n/q): " choice

        case "${choice:-r}" in
            n|N)
                if command -v nmtui >/dev/null 2>&1; then
                    nmtui || true
                else
                    print_warn "nmtui not installed"
                fi
                ;;
            q|Q)
                exit 1
                ;;
            *)
                ;;
        esac
    done
}

ask_activation_key() {
    print_step "2/3" "Activation key"

    while true; do
        read -r -p "Enter activation key: " API_TOKEN
        API_TOKEN="${API_TOKEN//[$'\r\n\t '] }"

        if [ -n "$API_TOKEN" ]; then
            print_ok "Activation key captured"
            return 0
        fi

        print_warn "Activation key cannot be empty"
    done
}

download_online_installer() {
    print_step "3/3" "Download and run online installer"
    print_info "Downloading: $ONLINE_INSTALLER_URL"

    local attempts=0
    while [ $attempts -lt 5 ]; do
        attempts=$((attempts + 1))
        if curl -fsSL --max-time 60 --connect-timeout 8 "$ONLINE_INSTALLER_URL" -o "$TMP_INSTALLER"; then
            if [ -s "$TMP_INSTALLER" ]; then
                chmod +x "$TMP_INSTALLER"
                print_ok "Online installer downloaded"
                return 0
            fi
        fi
        print_warn "Download failed (${attempts}/5), retrying..."
        sleep 2
    done

    print_warn "Could not download online installer"
    return 1
}

run_online_installer() {
    print_info "Starting online installer..."
    if ! bash "$TMP_INSTALLER" --token="$API_TOKEN"; then
        print_warn "Online installer failed"
        return 1
    fi

    print_ok "Online installer completed"
    return 0
}

finish_first_boot_mode() {
    mkdir -p /opt/edudisplej 2>/dev/null || true
    touch "$INSTALL_DONE_FLAG"

    if command -v systemctl >/dev/null 2>&1; then
        systemctl disable edudisplej-offline-installer.service >/dev/null 2>&1 || true
    fi

    print_ok "First-boot offline installer marked as completed"
}

main() {
    require_root
    print_header

    wait_for_internet_wizard
    echo ""

    ask_activation_key
    echo ""

    download_online_installer
    echo ""

    run_online_installer

    if [ "$FIRST_BOOT_MODE" = "true" ]; then
        finish_first_boot_mode
    fi
}

main "$@"
