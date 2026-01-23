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
echo " OK Logs: /tmp/openbox-autostart.log, /opt/edudisplej/session.log"
echo ""
echo "  ═══════════════════════════════════════════════════════"
echo ""

# Countdown na spustenie prehliadača
for i in {10..1}; do
    echo "  Spustenie prehliadača za: ${i} sekúnd..."
    sleep 1
done

echo ""
echo "  Spustenie prehliadača: /opt/edudisplej/localweb/clock.html"

# Determine which browser to use
BROWSER_CMD=""
if command -v chromium-browser >/dev/null 2>&1; then
    BROWSER_CMD="chromium-browser"
elif command -v chromium >/dev/null 2>&1; then
    BROWSER_CMD="chromium"
fi

if [ -n "$BROWSER_CMD" ]; then
    echo "  Používaný prehliadač: $BROWSER_CMD"
    # Launch Chromium with optimized flags for low resources and no D-Bus dependency
    $BROWSER_CMD \
        --kiosk \
        --no-sandbox \
        --disable-gpu \
        --disable-software-rasterizer \
        --disable-dev-shm-usage \
        --disable-features=TranslateUI \
        --disable-sync \
        --disable-background-networking \
        --disable-default-apps \
        --disable-extensions \
        --disable-infobars \
        --noerrdialogs \
        --disable-session-crashed-bubble \
        --incognito \
        --check-for-update-interval=31536000 \  # 1 year in seconds (disable updates)
        file:///opt/edudisplej/localweb/clock.html &
else
    echo "  CHYBA: Chromium sa nenašiel!"
    echo "  Nainštalujte: sudo apt-get install chromium-browser"
fi

# Interactive shell
exec bash --login
