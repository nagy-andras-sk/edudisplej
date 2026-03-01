#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
PHP_BIN="${PHP_BIN:-$(command -v php)}"

if [ -z "${PHP_BIN:-}" ]; then
  echo "[ERROR] php binary not found"
  exit 1
fi

UNIFIED_CRON_LINE="*/5 * * * * ${PHP_BIN} ${PROJECT_ROOT}/cron.php --maintenance-min-interval-minutes=15 --email-min-interval-minutes=5 --email-limit=50 >> ${PROJECT_ROOT}/logs/maintenance-cron.log 2>&1"

( crontab -l 2>/dev/null | grep -v "run_maintenance.php" | grep -v "run_email_queue.php"; echo "$UNIFIED_CRON_LINE" ) | crontab -

echo "[OK] Installed unified cron job:"
echo "  - $UNIFIED_CRON_LINE"
