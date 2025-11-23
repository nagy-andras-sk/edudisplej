#!/bin/bash
#Kiosk - XORG Config

# Allow Xorg for non-console users (Xorg.wrap)
configure_xwrapper() {
    # Install legacy wrapper (provides /etc/Xwrapper.config handling)
    sudo apt update
    sudo apt install -y xserver-xorg-legacy

    # Set allowed_users=anybody explicitly (no interactive dpkg)
    echo "allowed_users=anybody" | sudo tee /etc/Xwrapper.config >/dev/null
    echo "needs_root_rights=yes" | sudo tee -a /etc/Xwrapper.config >/dev/null
    # Ellenorzes
    sudo chmod 644 /etc/Xwrapper.config
}

# Ensure edudisplej user has proper group memberships
ensure_user_groups() {
    # Add edudisplej user to necessary groups for X and device access
    sudo usermod -a -G video,input,tty edudisplej 2>/dev/null || true
    # On newer systems, also add to render group if it exists
    getent group render >/dev/null && sudo usermod -a -G render edudisplej 2>/dev/null || true
}

# Install deps only if not already installed or if PACKAGES_INSTALLED != 1
ensure_kiosk_deps() {
    local installed_flag=0
    if [ -f "$CONFIG" ]; then
        . "$CONFIG"
        [ "$PACKAGES_INSTALLED" = "1" ] && installed_flag=1
    fi
    # if flag is set and binaries exist, skip installing
    if [ $installed_flag -eq 1 ] && command -v chromium >/dev/null 2>&1 && \
       command -v openbox >/dev/null 2>&1 && command -v xinit >/dev/null 2>&1 && \
       command -v unclutter >/dev/null 2>&1 && command -v xmessage >/dev/null 2>&1; then
        # still ensure xwrapper is configured and user groups are set
        configure_xwrapper
        ensure_user_groups
        return 0
    fi

    sudo apt update
    sudo apt install -y xserver-xorg x11-xserver-utils x11-utils xinit openbox unclutter chromium
    configure_xwrapper
    ensure_user_groups

    # persist PACKAGES_INSTALLED=1
    if grep -q '^PACKAGES_INSTALLED=' "$CONFIG" 2>/dev/null; then
        sudo sed -i 's/^PACKAGES_INSTALLED=.*/PACKAGES_INSTALLED=1/' "$CONFIG"
    else
        echo "PACKAGES_INSTALLED=1" | sudo tee -a "$CONFIG" >/dev/null
    fi
}

ensure_kiosk_autostart() {
    local url="${1:-$DEFAULT_URL}"
    ensure_kiosk_deps

    CHROMIUM_BIN=$(command -v chromium)
    [ -z "$CHROMIUM_BIN" ] && CHROMIUM_BIN="/usr/bin/chromium"

    # Ephemeral profile (cacheless)
    sudo mkdir -p /run/edukiosk
    sudo mount -t tmpfs -o size=256m tmpfs /run/edukiosk 2>/dev/null || true
    sudo chown -R edudisplej:edudisplej /run/edukiosk

    # Prepare log file
    sudo touch /var/log/kioskchrome.log
    sudo chown edudisplej:edudisplej /var/log/kioskchrome.log

    # X client wrapper that starts Openbox and Chromium with retry logic
    cat <<EOF | sudo tee /home/edudisplej/init/xclient.sh >/dev/null
#!/bin/bash
# X client wrapper for kiosk: start Openbox, then launch Chromium with retries.
set -e
export DISPLAY=:0

# Disable screensaver / DPMS, hide cursor
xset s off
xset -dpms
xset s noblank
unclutter -idle 0.1 -root &

# Start Openbox as WM
openbox-session &

# Small delay to let X settle
sleep 1

LOG=/var/log/kioskchrome.log
URL="$url"
CHROMIUM_BIN="$CHROMIUM_BIN"

attempt=1
max_attempts=3
while [ \$attempt -le \$max_attempts ]; do
    echo "[INFO] (\$attempt/\$max_attempts) launching chromium at \$(date) URL: \$URL" | tee -a "\$LOG"

    "\$CHROMIUM_BIN" --kiosk "\$URL" \
      --start-fullscreen \
      --noerrdialogs --disable-infobars \
      --disable-features=TranslateUI \
      --no-first-run --incognito \
      --user-data-dir=/run/edukiosk/profile \
      --disk-cache-dir=/run/edukiosk/cache \
      --disk-cache-size=1 \
      --autoplay-policy=no-user-gesture-required \
      --window-position=0,0 \
      --enable-features=OverlayScrollbar \
      --disable-restore-session-state \
      --disable-session-crashed-bubble \
      --password-store=basic \
      --enable-logging=stderr --v=1 \
      2>&1 | tee -a "\$LOG"

    exit_code=\${PIPESTATUS[0]}
    if [ "\$exit_code" -eq 0 ]; then
        echo "[INFO] chromium exited cleanly (code=\$exit_code) at \$(date)" | tee -a "\$LOG"
        break
    else
        echo "[WARN] chromium exit code=\$exit_code (attempt \$attempt) at \$(date)" | tee -a "\$LOG"
        if [ "\$attempt" -lt "\$max_attempts" ]; then
            echo "[INFO] waiting 20s before retry..." | tee -a "\$LOG"
            sleep 20
        else
            echo "[ERROR] chromium failed to start after \$max_attempts attempts." | tee -a "\$LOG"
            xmessage -center -default "OK" "Chromium failed after \$max_attempts attempts.
Check log: \$LOG
Press any key or close this window to exit." >/dev/null 2>&1
            exit 1
        fi
    fi
    attempt=\$((attempt+1))
done
EOF

    sudo chmod +x /home/edudisplej/init/xclient.sh
    sudo chown edudisplej:edudisplej /home/edudisplej/init/xclient.sh
}

create_kiosk_service() {
    # ensure log file exists and owned by user
    sudo touch /var/log/kioskchrome.log
    sudo chown edudisplej:edudisplej /var/log/kioskchrome.log

    cat <<'EOF' | sudo tee /etc/systemd/system/kioskchrome.service >/dev/null
[Unit]
Description=EduDisplej Kiosk Chrome (Openbox + Chromium)
After=network-online.target systemd-user-sessions.service
Wants=network-online.target

[Service]
Type=simple
User=edudisplej
Group=edudisplej
Environment=HOME=/home/edudisplej
Environment=XDG_RUNTIME_DIR=/run/user/1000
WorkingDirectory=/home/edudisplej
StandardOutput=journal
StandardError=journal
PAMName=login
TTYPath=/dev/tty7
# Run xinit with our X client wrapper
ExecStart=/usr/bin/xinit /home/edudisplej/init/xclient.sh -- :0 vt7 -nolisten tcp
Restart=no

[Install]
WantedBy=multi-user.target
EOF

    sudo systemctl daemon-reload
    sudo systemctl enable kioskchrome.service
}

auto_set_edserver_mode() {
    echo "MODE=EDSERVER" > "$CONFIG"
    echo "KIOSK_URL=$DEFAULT_URL" >> "$CONFIG"
    echo "LANG=$LANGUAGE" >> "$CONFIG"
    echo "EDUDISPLEJ_SERVER" > "$MODEFILE"
    echo "$(T auto_server_set) $DEFAULT_URL" | tee -a "$LOGFILE"

    ensure_kiosk_autostart "$DEFAULT_URL"
    create_kiosk_service

    # Banner (per-letter) + automatic start + reboot without OK
    ascii_letter_banner "$(T ed_banner)"
    echo "$(T will_reboot)"
    sleep 2
    sudo systemctl start kioskchrome.service || true
    sudo reboot
}
