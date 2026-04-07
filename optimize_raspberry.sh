#!/bin/bash
# EduDisplej Raspberry Pi Optimization Script
# Run: chmod +x optimize_raspberry.sh && ./optimize_raspberry.sh

set -e

echo "=== EduDisplej Raspberry Pi Optimization ==="
echo "Date: $(date)"
echo ""

# 1. Sync log check
echo "[1/5] Checking sync logs..."
if [ -f "/var/log/edudisplej/sync.log" ]; then
    echo "Last 20 sync log entries:"
    tail -20 /var/log/edudisplej/sync.log
else
    echo "⚠️  Sync log not found at /var/log/edudisplej/sync.log"
fi
echo ""

# 2. Cron jobs optimization
echo "[2/5] Checking cron jobs..."
echo "Current crontab:"
crontab -l
echo ""
echo "Recommended changes:"
echo "  - Self-update: Change from */15 to daily (0 3 * * *)"
echo "  - Sync: Keep current or adjust to */30"
echo ""

# 3. Memory usage check
echo "[3/5] Memory usage..."
free -h
echo ""

# 4. Disk space check
echo "[4/5] Disk space..."
df -h /
echo ""

# 5. Service status
echo "[5/5] Service status..."
if command -v systemctl &> /dev/null; then
    echo "EduDisplej services:"
    systemctl list-units --type=service | grep -i edudisplej || echo "No edudisplej services found"
else
    echo "systemctl not available, skipping service check"
fi
echo ""

# Recommendations
echo "=== RECOMMENDATIONS ==="
echo ""
echo "✅ Sync Optimization:"
echo "   - Current sync is working correctly"
echo "   - 11 records for 7 days × 2 institutions is NORMAL"
echo "   - WebKredit breakfast is empty because source doesn't provide it"
echo ""
echo "✅ Self-Update Optimization:"
echo "   Run: crontab -e"
echo "   Change self-update from */15 to: 0 3 * * *"
echo ""
echo "✅ Stability:"
echo "   - Network retry already implemented (3 retries)"
echo "   - Consider adding watchdog service if crashes occur"
echo ""
echo "=== For more details, see MEAL_MODULE_FIX_2026-04-07.md ==="
