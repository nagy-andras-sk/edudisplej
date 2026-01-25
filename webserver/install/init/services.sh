#!/bin/bash
# services.sh - systemd service helpers for EduDisplej

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "${TRANS_SK+x}" ]]; then
    source "${SCRIPT_DIR}/common.sh"
fi

SERVICE_NAME="chromiumkiosk.service"
SERVICE_PATH="/etc/systemd/system/${SERVICE_NAME}"

ensure_chromium_kiosk_service() {
    print_info "Checking ${SERVICE_NAME}..."

    local unit_content
    read -r -d '' unit_content << 'EOF'
[Unit]
Description=Chromium Kiosk Service
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=/opt/edudisplej/init
ExecStart=/usr/bin/xinit /opt/edudisplej/init/xclient.sh -- :0 vt1 -nolisten tcp
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF

    if [[ ! -f "${SERVICE_PATH}" ]]; then
        print_info "Creating ${SERVICE_NAME}"
        echo "${unit_content}" > "${SERVICE_PATH}" || {
            print_error "Failed to write ${SERVICE_PATH}"
            return 1
        }
        chmod 644 "${SERVICE_PATH}"
        systemctl daemon-reload || true
        print_success "${SERVICE_NAME} created"
    else
        print_info "${SERVICE_NAME} exists"
    fi
    return 0
}

start_chromium_kiosk_service() {
    print_info "Starting ${SERVICE_NAME}"
    systemctl daemon-reload || true
    systemctl start "${SERVICE_NAME}" || {
        print_error "Failed to start ${SERVICE_NAME}"
        return 1
    }
    print_success "${SERVICE_NAME} started"
}

is_chromium_kiosk_active() {
    systemctl is-active --quiet "${SERVICE_NAME}"
}

restart_chromium_kiosk_service() {
    print_info "Restarting ${SERVICE_NAME}"
    systemctl daemon-reload || true
    systemctl restart "${SERVICE_NAME}" || {
        print_error "Failed to restart ${SERVICE_NAME}"
        return 1
    }
    print_success "${SERVICE_NAME} restarted"
}

start_or_restart_chromium_kiosk_service() {
    ensure_chromium_kiosk_service || return 1
    if is_chromium_kiosk_active; then
        restart_chromium_kiosk_service
    else
        start_chromium_kiosk_service
    fi
}

stop_chromium_kiosk_service() {
    print_info "Stopping ${SERVICE_NAME}"
    systemctl stop "${SERVICE_NAME}" || {
        print_error "Failed to stop ${SERVICE_NAME}"
        return 1
    }
    print_success "${SERVICE_NAME} stopped"
}

enable_chromium_kiosk_service() {
    print_info "Enabling ${SERVICE_NAME}"
    systemctl enable "${SERVICE_NAME}" || {
        print_error "Failed to enable ${SERVICE_NAME}"
        return 1
    }
    print_success "${SERVICE_NAME} enabled"
}

disable_chromium_kiosk_service() {
    print_info "Disabling ${SERVICE_NAME}"
    systemctl disable "${SERVICE_NAME}" || {
        print_error "Failed to disable ${SERVICE_NAME}"
        return 1
    }
    print_success "${SERVICE_NAME} disabled"
}
