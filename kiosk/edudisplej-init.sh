#!/bin/bash

set -euo pipefail

EDUDISPLEJ_HOME="/opt/edudisplej"
INIT_DIR="${EDUDISPLEJ_HOME}/init"
CONFIG_FILE="${EDUDISPLEJ_HOME}/edudisplej.conf"
FLAG="${EDUDISPLEJ_HOME}/.kiosk_system_configured"
LOG="${EDUDISPLEJ_HOME}/session.log"

[ -f "${INIT_DIR}/common.sh" ] && source "${INIT_DIR}/common.sh" || true

exec >> "$LOG" 2>&1

[ -f "$FLAG" ] && exit 0

detect_user() {
    local u=""
    [ -n "${SUDO_USER:-}" ] && [ "${SUDO_USER}" != "root" ] && u="$SUDO_USER"
    [ -z "$u" ] && u="$(awk -F: '$3==1000{print $1}' /etc/passwd | head -1)"
    [ -z "$u" ] && u="$(awk -F: '($3>=1000 && $1!="nobody"){print $1; exit}' /etc/passwd)"
    echo "$u"
}

CONSOLE_USER="$(detect_user)"
[ -z "$CONSOLE_USER" ] && CONSOLE_USER="root"
USER_HOME="$(getent passwd "$CONSOLE_USER" | cut -d: -f6)"
[ -z "$USER_HOME" ] && USER_HOME="/home/$CONSOLE_USER"

log "Init: user=$CONSOLE_USER home=$USER_HOME"

mkdir -p "${EDUDISPLEJ_HOME}/localweb" "${EDUDISPLEJ_HOME}/logs" "${EDUDISPLEJ_HOME}/data" "${EDUDISPLEJ_HOME}/lic"

for dm in lightdm lxdm sddm gdm3 gdm xdm; do
    systemctl disable --now "${dm}.service" 2>/dev/null || true
    systemctl mask "${dm}.service" 2>/dev/null || true
done

mkdir -p /etc/X11
cat > /etc/X11/Xwrapper.config << 'XEOF'
allowed_users=anybody
needs_root_rights=yes
XEOF

usermod -a -G tty,video,input "$CONSOLE_USER" 2>/dev/null || true

SUDOERS="/etc/sudoers.d/edudisplej"
cat > "$SUDOERS" << SEOF
Defaults:$CONSOLE_USER !requiretty
$CONSOLE_USER ALL=(ALL) NOPASSWD: ${INIT_DIR}/edudisplej_sync_service.sh
SEOF
chmod 0440 "$SUDOERS"
visudo -c -f "$SUDOERS" 2>/dev/null || rm -f "$SUDOERS"

cat > "$USER_HOME/.xinitrc" << 'XEOF'
#!/bin/bash
xrandr --auto 2>/dev/null || true
xset -dpms 2>/dev/null || true
xset s off 2>/dev/null || true
xset s noblank 2>/dev/null || true
exec openbox-session
XEOF
chmod +x "$USER_HOME/.xinitrc"
chown "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.xinitrc"

mkdir -p "$USER_HOME/.config/openbox"
cat > "$USER_HOME/.config/openbox/autostart" << AEOF
#!/bin/bash
xset -dpms 2>/dev/null || true
xset s off 2>/dev/null || true
command -v unclutter >/dev/null 2>&1 && unclutter -idle 1 &
command -v xsetroot >/dev/null 2>&1 && xsetroot -solid black 2>/dev/null || true

LOOP="${EDUDISPLEJ_HOME}/localweb/modules/loop.json"
WAITING="${INIT_DIR}/waiting_registration.html"
CLOCK="${INIT_DIR}/clock.html"

if [ -f "\$LOOP" ]; then
    URL="file://\$LOOP"
elif [ -f "\$WAITING" ]; then
    URL="file://\$WAITING"
elif [ -f "\$CLOCK" ]; then
    URL="file://\$CLOCK"
else
    URL="about:blank"
fi

surf -F "\$URL" &

while true; do sleep 60; done
AEOF
chmod +x "$USER_HOME/.config/openbox/autostart"
chown -R "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.config"

systemctl enable getty@tty1.service 2>/dev/null || true
systemctl daemon-reload 2>/dev/null || true

touch "$FLAG"
log "Init complete"
exit 0
