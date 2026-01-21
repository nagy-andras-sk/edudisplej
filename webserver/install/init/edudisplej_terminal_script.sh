#!/bin/bash
# edudisplej_terminal_script.sh - Main terminal display script
# This script runs in the terminal window shown on the main display
# =============================================================================

# Clear screen and hide cursor
tput civis 2>/dev/null || true
clear

# Display banner
echo ""
echo "╔═══════════════════════════════════════════════════════════════════════════╗"
echo "║                                                                           ║"
echo "║   ███████╗██████╗ ██╗   ██╗██████╗ ██╗███████╗██████╗ ██╗     ███████╗   ║"
echo "║   ██╔════╝██╔══██╗██║   ██║██╔══██╗██║██╔════╝██╔══██╗██║     ██╔════╝   ║"
echo "║   █████╗  ██║  ██║██║   ██║██║  ██║██║███████╗██████╔╝██║     █████╗     ║"
echo "║   ██╔══╝  ██║  ██║██║   ██║██║  ██║██║╚════██║██╔═══╝ ██║     ██╔══╝     ║"
echo "║   ███████╗██████╔╝╚██████╔╝██████╔╝██║███████║██║     ███████╗███████╗   ║"
echo "║   ╚══════╝╚═════╝  ╚═════╝ ╚═════╝ ╚═╝╚══════╝╚═╝     ╚══════╝╚══════╝   ║"
echo "║                                                                           ║"
echo "╚═══════════════════════════════════════════════════════════════════════════╝"
echo ""
echo "  System je pripraveny / System is ready"
echo "  ═══════════════════════════════════════"
echo ""

# Display system information
echo "  System information:"
echo "  ─────────────────────────────────────────────────────"
echo "  Hostname:    $(hostname)"
echo "  Date/Time:   $(date '+%Y-%m-%d %H:%M:%S')"
echo "  Display:     ${DISPLAY:-not set}"

# Show screen resolution if available
if command -v xrandr >/dev/null 2>&1 && [ -n "${DISPLAY:-}" ]; then
    RESOLUTION=$(xrandr 2>/dev/null | grep '\*' | awk '{print $1}' | head -1)
    if [ -n "$RESOLUTION" ]; then
        echo "  Resolution:  $RESOLUTION"
    fi
fi

# Show IP address if available
IP_ADDR=$(hostname -I 2>/dev/null | awk '{print $1}')
if [ -n "$IP_ADDR" ]; then
    echo "  IP Address:  $IP_ADDR"
fi

echo "  ─────────────────────────────────────────────────────"
echo ""
echo "  Logs:"
echo "    - Openbox:  /tmp/openbox-autostart.log"
echo "    - Init:     /opt/edudisplej/session.log"
echo "    - Watchdog: /tmp/edudisplej-watchdog.log"
echo ""
echo "  ═══════════════════════════════════════════════════════"
echo ""

# Keep terminal open with an interactive bash shell
# This allows users to run commands if needed
exec bash --login
