#!/bin/bash
# EduDisplej Self-Heat monitor script
# Keeps critical services online and repairs known status-file permissions.

set -euo pipefail

STATUS_ROOT="/opt/edudisplej"
HEALTH_STATUS_FILE="${STATUS_ROOT}/health_status.json"

CRITICAL_UNITS=(
    "edudisplej-sync.service"
    "edudisplej-health.service"
    "edudisplej-command-executor.service"
    "edudisplej-kiosk.service"
    "edudisplej-watchdog.service"
    "edudisplej-screenshot-service.service"
)

unit_exists() {
    local unit_name="$1"
    systemctl list-unit-files "$unit_name" >/dev/null 2>&1 || [ -f "/etc/systemd/system/${unit_name}" ]
}

# Health service runs as edudisplej user, so this file must stay writable by that user.
fix_health_status_permissions() {
    mkdir -p "${STATUS_ROOT}"
    touch "${HEALTH_STATUS_FILE}" || true
    chown edudisplej:edudisplej "${HEALTH_STATUS_FILE}" 2>/dev/null || true
    chmod 664 "${HEALTH_STATUS_FILE}" 2>/dev/null || true
}

main() {
    fix_health_status_permissions

    for unit_name in "${CRITICAL_UNITS[@]}"; do
        if ! unit_exists "$unit_name"; then
            continue
        fi

        if ! systemctl is-active --quiet "$unit_name"; then
            systemctl restart "$unit_name" 2>/dev/null || true
        fi
    done
}

main
