#!/bin/bash
# EduDisplej Terminal Script - Display system info and provide shell
# =============================================================================

clear

# Display banner
cat << 'EOF'
▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄▄ 
██░▄▄▄██░▄▄▀██░██░██░▄▄▀█▄░▄██░▄▄▄░██░▄▄░██░█████░▄▄▄█████░
██░▄▄▄██░██░██░██░██░██░██░███▄▄▄▀▀██░▀▀░██░█████░▄▄▄█████░
██░▀▀▀██░▀▀░██▄▀▀▄██░▀▀░█▀░▀██░▀▀▀░██░█████░▀▀░██░▀▀▀██░▀▀░
▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀ 
             ___
             /  _\
             | /\_|
          __-'' _'
         ----'-.
            |#\#)_,_
            )##\__ _\__.-.
    -  .-  (###)   '---.  `.
 -   __\____`.#\(      )  L(|
   .'__//\    \#)`-._.' / \\==.
  /_/_//\_\_  /#/  ### / //\\ \
   |(________(##)___/-' '| (_) |
____\___/_________________\___/___________
ART By Veronica Karlsson


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

# 5 másodperc várakozás
echo "  5 másodperc múlva elindítva: ../main/main.sh"
sleep 5

# main.sh elindítása
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
exec bash "$SCRIPT_DIR/main/main.sh"