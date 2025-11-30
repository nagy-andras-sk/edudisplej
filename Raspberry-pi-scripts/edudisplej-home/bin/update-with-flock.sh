#!/bin/sh
# flock megoldás — ha a flock elérhető (util-linux)
LOCK_FD=9
LOCK_FILE="/var/lock/edudisplej-update.lock"
LOCK_DIR="${LOCK_FILE%/*}"

if [ ! -w "$LOCK_DIR" ]; then
  LOCK_FILE="/tmp/edudisplej-update.lock"
fi

(
  flock -n "$LOCK_FD" || { echo "Update már fut. Kilépés."; exit 0; }

  # --- ide jön az update logika ---
  # cd /opt/edudisplej || exit 1
  # git pull origin main || exit 1
  # ./install.sh || exit 1

) 9>>"$LOCK_FILE"
