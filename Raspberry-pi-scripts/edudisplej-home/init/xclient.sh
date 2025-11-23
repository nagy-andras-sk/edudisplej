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
URL="https://www.edudisplej.sk/edserver/demo/client"
CHROMIUM_BIN="/usr/bin/chromium"

attempt=1
max_attempts=3
while [ $attempt -le $max_attempts ]; do
    echo "[INFO] ($attempt/$max_attempts) launching chromium at $(date) URL: $URL" | tee -a "$LOG"

    "$CHROMIUM_BIN" --kiosk "$URL"       --start-fullscreen       --noerrdialogs --disable-infobars       --disable-features=TranslateUI       --no-first-run --incognito       --user-data-dir=/run/edukiosk/profile       --disk-cache-dir=/run/edukiosk/cache       --disk-cache-size=1       --autoplay-policy=no-user-gesture-required       --window-position=0,0       --enable-features=OverlayScrollbar       --disable-restore-session-state       --disable-session-crashed-bubble       --password-store=basic       --enable-logging=stderr --v=1       2>&1 | tee -a "$LOG"

    exit_code=${PIPESTATUS[0]}
    if [ "$exit_code" -eq 0 ]; then
        echo "[INFO] chromium exited cleanly (code=$exit_code) at $(date)" | tee -a "$LOG"
        break
    else
        echo "[WARN] chromium exit code=$exit_code (attempt $attempt) at $(date)" | tee -a "$LOG"
        if [ "$attempt" -lt "$max_attempts" ]; then
            echo "[INFO] waiting 20s before retry..." | tee -a "$LOG"
            sleep 20
        else
            echo "[ERROR] chromium failed to start after $max_attempts attempts." | tee -a "$LOG"
            xmessage -center -default "OK" "Chromium failed after $max_attempts attempts.
Check log: $LOG
Press any key or close this window to exit." >/dev/null 2>&1
            exit 1
        fi
    fi
    attempt=$((attempt+1))
done
