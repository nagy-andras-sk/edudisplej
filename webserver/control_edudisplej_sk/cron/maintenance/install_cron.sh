#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
PHP_BIN="${PHP_BIN:-$(command -v php)}"

if [ -z "${PHP_BIN:-}" ]; then
  echo "[ERROR] php binary not found"
  exit 1
fi

CRON_LINE="*/5 * * * * ${PHP_BIN} ${SCRIPT_DIR}/run_maintenance.php >> ${PROJECT_ROOT}/logs/maintenance-cron.log 2>&1"

( crontab -l 2>/dev/null | grep -v "run_maintenance.php"; echo "$CRON_LINE" ) | crontab -

echo "[OK] Installed cron job: $CRON_LINE"
