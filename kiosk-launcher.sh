#!/bin/bash
# kiosk-launcher.sh - Terminal launcher for Epiphany browser kiosk mode
# This script is designed to run in xterm and provides:
# - ASCII banner display
# - Countdown timer
# - Epiphany browser in fullscreen
# - Watchdog to restart browser if closed
# - DPMS/screensaver disable
# - Cursor hiding
set -euo pipefail

# Configuration
URL="${1:-https://example.com}"
COUNT_FROM=5

# Function to ensure fullscreen with F11
ensure_fullscreen() {
  if command -v xdotool >/dev/null 2>&1; then
    xdotool key --window "$(xdotool getactivewindow 2>/dev/null || true)" F11 || true
  fi
}

# Terminal appearance: hide cursor, clear screen
tput civis || true
clear

# ASCII banner (figlet)
if command -v figlet >/dev/null 2>&1; then
  figlet -w 120 "EDUDISPLEJ"
else
  echo "==== EDUDISPLEJ ===="
fi
echo

# Brief description
echo "Starting... Browser will launch in ${COUNT_FROM} seconds."
echo "URL: ${URL}"
echo

# Countdown
for ((i=COUNT_FROM; i>=1; i--)); do
  printf "\rStarting in %2d..." "$i"
  sleep 1
done
echo -e "\rStarting now!     "
sleep 0.3

# Disable screensaver/power management (if running under X)
if command -v xset >/dev/null 2>&1; then
  xset -dpms
  xset s off
  xset s noblank
fi

# Hide mouse cursor (background)
if command -v unclutter >/dev/null 2>&1; then
  unclutter -idle 1 -root >/dev/null 2>&1 &
fi

# Restore cursor if interrupted (Ctrl+C)
trap 'tput cnorm || true' EXIT

# Launch browser in fullscreen (Epiphany is ARMv6-compatible)
epiphany-browser --fullscreen "${URL}" &

# Optional: ensure fullscreen is active with F11
sleep 3
ensure_fullscreen

# Optional watchdog: restart Epiphany if it closes
while true; do
  sleep 2
  if ! pgrep -x "epiphany-browser" >/dev/null; then
    epiphany-browser --fullscreen "${URL}" &
    sleep 3
    ensure_fullscreen
  fi
done
