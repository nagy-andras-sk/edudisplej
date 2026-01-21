#!/bin/bash
# EduDisplej Terminal Script - Display system info and provide shell
# =============================================================================

clear

# Display banner
cat << 'EOF'
╔═══════════════════════════════════════════════════════════════════════════╗
║                                                                           ║
║   ███████╗██████╗ ██╗   ██╗██████╗ ██╗███████╗██████╗ ██╗     ███████╗   ║
║   ██╔════╝██╔══██╗██║   ██║██╔══██╗██║██╔════╝██╔══██╗██║     ██╔════╝   ║
║   █████╗  ██║  ██║██║   ██║██║  ██║██║███████╗██████╔╝██║     █████╗     ║
║   ██╔══╝  ██║  ██║██║   ██║██║  ██║██║╚════██║██╔═══╝ ██║     ██╔══╝     ║
║   ███████╗██████╔╝╚██████╔╝██████╔╝██║███████║██║     ███████╗███████╗   ║
║   ╚══════╝╚═════╝  ╚═════╝ ╚═════╝ ╚═╝╚══════╝╚═╝     ╚══════╝╚══════╝   ║
║                                                                           ║
╚═══════════════════════════════════════════════════════════════════════════╝

  System Ready / Systém pripravený
  ═══════════════════════════════════════

EOF

# System information
echo "  System Information:"
echo "  ─────────────────────────────────────────────────────"
echo "  Hostname:    $(hostname)"
echo "  Date/Time:   $(date '+%Y-%m-%d %H:%M:%S')"
echo "  Display:     ${DISPLAY:-not set}"

# Screen resolution
if command -v xrandr >/dev/null 2>&1 && [ -n "${DISPLAY:-}" ]; then
    RES=$(xrandr 2>/dev/null | grep '\*' | awk '{print $1}' | head -1)
    if [ -n "$RES" ] && [[ "$RES" =~ ^[0-9]+x[0-9]+$ ]]; then
        echo "  Resolution:  $RES"
    fi
fi

# IP address
IP=$(hostname -I 2>/dev/null | awk '{print $1}')
[ -n "$IP" ] && echo "  IP Address:  $IP"

echo "  ─────────────────────────────────────────────────────"
echo ""
echo "  Logs: /tmp/openbox-autostart.log, /opt/edudisplej/session.log"
echo ""
echo "  ═══════════════════════════════════════════════════════"
echo ""

# Interactive shell
exec bash --login
