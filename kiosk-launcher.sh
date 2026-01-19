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

# Beállítások
URL="${1:-https://example.com}"   # átírhatod fix URL-re vagy add át paraméterként
COUNT_FROM=5

# Terminál kinézet: kurzor elrejt, tisztít
tput civis || true
clear

# ASCII felirat (figlet)
if command -v figlet >/dev/null 2>&1; then
  figlet -w 120 "EDUDISPLEJ"
else
  echo "==== EDUDISPLEJ ===="
fi
echo

# Rövid leírás
echo "Indítás folyamatban... A böngésző ${COUNT_FROM} másodperc múlva elindul."
echo "URL: ${URL}"
echo

# Visszaszámlálás
for ((i=COUNT_FROM; i>=1; i--)); do
  printf "\rIndítás %2d..." "$i"
  sleep 1
done
echo -e "\rIndítás most!     "
sleep 0.3

# Képernyőkímélő/energiagazdálkodás tiltása (ha X alatt fut)
if command -v xset >/dev/null 2>&1; then
  xset -dpms
  xset s off
  xset s noblank
fi

# Egérkurzor eltüntetése (háttérbe)
if command -v unclutter >/dev/null 2>&1; then
  (unclutter -idle 1 -root >/dev/null 2>&1 & ) || true
fi

# Böngésző indítása fullscreenben (Epiphany ARMv6-kompatibilis)
epiphany-browser --fullscreen "${URL}" &

# Opcionális: ha a fullscreen mégsem lenne aktív, „rácsapunk" egy F11-et.
sleep 3
if command -v xdotool >/dev/null 2>&1; then
  xdotool key --window "$(xdotool getactivewindow 2>/dev/null || true)" F11 || true
fi

# Opcionális watchdog: ha bezárják az Epiphany-t, újraindítja
while true; do
  sleep 2
  if ! pgrep -x "epiphany-browser" >/dev/null; then
    epiphany-browser --incognito --fullscreen "${URL}" &
    sleep 3
    if command -v xdotool >/dev/null 2>&1; then
      xdotool key --window "$(xdotool getactivewindow 2>/dev/null || true)" F11 || true
    fi
  fi
done

# Visszaállítjuk a kurzort, ha valaki megszakítja (Ctrl+C)
trap 'tput cnorm || true' EXIT
